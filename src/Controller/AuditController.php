<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Controller;

use App\Audit\AuditCsvExporter;
use App\Audit\AuditTimelineFilter;
use App\Audit\AuditTimelineReader;
use App\Entity\AssetGroup;
use App\Entity\AssetType;
use App\Entity\Software;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\Vendor;
use App\Entity\Vulnerability;
use App\Repository\AssetGroupRepository;
use App\Repository\AssetTypeRepository;
use App\Repository\SoftwareRepository;
use App\Repository\UserGroupRepository;
use App\Repository\UserRepository;
use App\Repository\VendorRepository;
use App\Repository\VulnerabilityRepository;
use App\Security\Voter\VulnerabilityVoter;
use App\Twig\AuditExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// SEC-NEW-002: Class-level gate ensures any future route added here requires
// full authentication, not only the global access_control fallback in security.yaml.
// Per-entity voter checks in timeline() still apply on top of this class guard.
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/audit')]
final class AuditController extends AbstractController
{
    // null = access checked per-entity via voter; list = type-level "any of these roles" check.
    // ROLE_ISB has oversight over the whole vulnerability domain, so it can also read the audit
    // trail of vendors and asset types (audit L3) — but not of users / user groups (admin-only).
    private const array ENTITY_MAP = [
        'user' => [User::class,          ['ROLE_ADMIN']],
        'vendor' => [Vendor::class,         ['ROLE_SOFTWARE_OWNER', 'ROLE_ISB']],
        'asset_type' => [AssetType::class,      ['ROLE_HARDWARE_OWNER', 'ROLE_ISB']],
        'user_group' => [UserGroup::class,      ['ROLE_ADMIN']],
        'software' => [Software::class,       null],
        'asset_group' => [AssetGroup::class,     null],
        'vulnerability' => [Vulnerability::class,  null],
    ];

    /** Audit action types offered in the global-timeline filter (matches Entry::$type values). */
    private const array ACTIONS = ['insert', 'update', 'remove', 'associate', 'dissociate'];

    private const array LIMITS = [10, 25, 50, 75, 100];

    public function __construct(
        private readonly AuditExtension $auditExtension,
        private readonly AuditTimelineReader $timelineReader,
        private readonly AuditCsvExporter $csvExporter,
        private readonly UserRepository $userRepository,
        private readonly VendorRepository $vendorRepository,
        private readonly SoftwareRepository $softwareRepository,
        private readonly AssetTypeRepository $assetTypeRepository,
        private readonly AssetGroupRepository $assetGroupRepository,
        private readonly UserGroupRepository $userGroupRepository,
        private readonly VulnerabilityRepository $vulnerabilityRepository,
    ) {
    }

    /**
     * Global audit timeline (issue #32) — cross-entity compliance view with action/date/user/
     * entity-type filters. ROLE_ADMIN only: it reads every entity's audit trail without the
     * per-entity access filtering of timeline() below (admin is the only role that may already
     * read the user / user-group audit trail).
     */
    #[Route('', name: 'app_audit_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(Request $request): Response
    {
        $users = $this->timelineReader->distinctUsers();
        $params = $this->resolveListParams($request, $users);

        $result = $this->timelineReader->search($this->buildFilter($params), $params['page'], $params['limit']);
        $total = $result['total'];
        $pages = max(1, (int) ceil($total / $params['limit']));

        return $this->render('audit/index.html.twig', [
            'items' => $result['items'],
            'total' => $total,
            'page' => $params['page'],
            'pages' => $pages,
            'from' => $total > 0 ? ($params['page'] - 1) * $params['limit'] + 1 : 0,
            'to' => min($params['page'] * $params['limit'], $total),
            'params' => $params,
            'query_params' => [
                'limit' => $params['limit'],
                'action' => $params['action'],
                'entity_type' => $params['entity_type'],
                'user' => $params['user'],
                'date_from' => $params['date_from'],
                'date_to' => $params['date_to'],
            ],
            'actions' => self::ACTIONS,
            'entity_types' => array_keys(AuditTimelineReader::ENTITY_TYPES),
            'users' => $users,
            'limits' => self::LIMITS,
        ]);
    }

    /**
     * CSV export of the timeline as currently filtered — the evidence hand-over for an auditor
     * (issue #64). Same ROLE_ADMIN gate and the same filters as the screen; one row per changed
     * field. Streamed, so the whole trail can be exported without holding it in memory.
     */
    #[Route('/export', name: 'app_audit_export', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function export(Request $request): StreamedResponse
    {
        $filter = $this->buildFilter($this->resolveListParams($request, $this->timelineReader->distinctUsers()));

        $locale = $request->getLocale();

        $response = new StreamedResponse(function () use ($filter, $locale): void {
            $handle = fopen('php://output', 'wb');
            if (false === $handle) {
                return;
            }
            $this->csvExporter->write($handle, $filter, $locale);
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('odinshield-audit-%s.csv', date('Y-m-d-Hi')),
        ));

        return $response;
    }

    /**
     * @param array{action: string, entity_type: string, user: string, date_from_obj: ?\DateTimeImmutable, date_to_obj: ?\DateTimeImmutable, ...} $params
     */
    private function buildFilter(array $params): AuditTimelineFilter
    {
        return new AuditTimelineFilter(
            action: 'all' === $params['action'] ? null : $params['action'],
            entityType: 'all' === $params['entity_type'] ? null : $params['entity_type'],
            user: 'all' === $params['user'] ? null : $params['user'],
            dateFrom: $params['date_from_obj'],
            dateTo: $params['date_to_obj'],
        );
    }

    /**
     * Validate every list/filter param against an allow-list — never trust raw input.
     *
     * @param list<string> $validUsers distinct blame_user values from the audit tables
     *
     * @return array{page: int, limit: int, action: string, entity_type: string, user: string, date_from: string, date_to: string, date_from_obj: ?\DateTimeImmutable, date_to_obj: ?\DateTimeImmutable}
     */
    private function resolveListParams(Request $request, array $validUsers): array
    {
        $q = $request->query;

        $limit = (int) $q->get('limit', 25);
        if (!\in_array($limit, self::LIMITS, true)) {
            $limit = 25;
        }

        $action = (string) $q->get('action', 'all');
        if ('all' !== $action && !\in_array($action, self::ACTIONS, true)) {
            $action = 'all';
        }

        $entityType = (string) $q->get('entity_type', 'all');
        if ('all' !== $entityType && !\array_key_exists($entityType, AuditTimelineReader::ENTITY_TYPES)) {
            $entityType = 'all';
        }

        $user = (string) $q->get('user', 'all');
        if ('all' !== $user && !\in_array($user, $validUsers, true)) {
            $user = 'all';
        }

        [$dateFrom, $dateFromObj] = $this->parseDate((string) $q->get('date_from', ''), false);
        [$dateTo, $dateToObj] = $this->parseDate((string) $q->get('date_to', ''), true);

        return [
            'page' => max(1, (int) $q->get('page', 1)),
            'limit' => $limit,
            'action' => $action,
            'entity_type' => $entityType,
            'user' => $user,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'date_from_obj' => $dateFromObj,
            'date_to_obj' => $dateToObj,
        ];
    }

    /**
     * Parse a `Y-m-d` filter value. Invalid → dropped (empty string + null). End-of-range dates
     * snap to 23:59:59 so `created_at <= :date_to` includes that whole day.
     *
     * @return array{0: string, 1: ?\DateTimeImmutable}
     */
    private function parseDate(string $raw, bool $endOfDay): array
    {
        if ('' === $raw) {
            return ['', null];
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if (false === $date) {
            return ['', null];
        }

        return [$raw, $endOfDay ? $date->setTime(23, 59, 59) : $date];
    }

    #[Route('/{type}/{uuid}/timeline', name: 'app_audit_timeline', requirements: ['uuid' => Requirement::UUID_V7], methods: ['GET'])]
    public function timeline(string $type, string $uuid, Request $request): Response
    {
        if (!\array_key_exists($type, self::ENTITY_MAP)) {
            throw $this->createNotFoundException(sprintf('Unknown entity type "%s".', $type));
        }

        [$entityClass, $typeRoles] = self::ENTITY_MAP[$type];

        // SEC-014: Role-gated types use a type-level check first (403 before 404 for unauthorized).
        // Voter-gated types resolve the entity first and then apply per-entity voters so that
        // Software-Responsible and Hardware-Responsible can reach their own entities' audit trails.
        if (null !== $typeRoles) {
            $granted = false;
            foreach ($typeRoles as $role) {
                if ($this->isGranted($role)) {
                    $granted = true;
                    break;
                }
            }
            if (!$granted) {
                throw $this->createAccessDeniedException();
            }
        }

        $entity = match ($type) {
            'user' => $this->userRepository->findOneBy(['uuid' => $uuid]),
            'vendor' => $this->vendorRepository->findOneBy(['uuid' => $uuid]),
            'software' => $this->softwareRepository->findOneBy(['uuid' => $uuid]),
            'asset_type' => $this->assetTypeRepository->findOneBy(['uuid' => $uuid]),
            'asset_group' => $this->assetGroupRepository->findOneBy(['uuid' => $uuid]),
            'user_group' => $this->userGroupRepository->findOneBy(['uuid' => $uuid]),
            'vulnerability' => $this->vulnerabilityRepository->findOneBy(['uuid' => $uuid]),
        };

        if (null === $entity) {
            throw $this->createNotFoundException(sprintf('Entity "%s" with UUID "%s" not found.', $entityClass, $uuid));
        }

        if (null === $typeRoles) {
            match (true) {
                $entity instanceof Software => $this->denyAccessUnlessGranted('SOFTWARE_VIEW', $entity),
                $entity instanceof AssetGroup => $this->denyAccessUnlessGranted('ASSET_GROUP_VIEW', $entity),
                $entity instanceof Vulnerability => $this->denyAccessUnlessGranted(VulnerabilityVoter::VIEW, $entity),
                default => null,
            };
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = min(50, max(1, (int) $request->query->get('page_size', 50)));

        $audit = $this->auditExtension->getEntries($entity, $page, $pageSize);
        $entries = $audit['entries'];
        $total = $audit['total'];
        $hasMore = $total > $pageSize * $page;

        return $this->render('_include/_audit_timeline_items.html.twig', [
            'entries' => $entries,
            'type' => $type,
            'entity_class' => $entityClass,
            'has_more' => $hasMore,
            'next_page' => $page + 1,
            'timeline_url' => $this->generateUrl('app_audit_timeline', ['type' => $type, 'uuid' => $uuid]),
        ]);
    }
}

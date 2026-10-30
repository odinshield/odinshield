<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Twig\Components;

use App\Entity\Software;
use App\Form\SoftwareType;
use App\Security\Voter\SoftwareVoter;
use App\Service\OptimisticLockConflictResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

// SEC-006: The live component endpoint (/live/component/…) is separate from SoftwareController.
// Class-level IS_AUTHENTICATED_REMEMBERED prevents anonymous access (REMEMBERED like SoftwareController,
// SEC-016/#186 — FULLY here would break re-renders and save for a remember-me session); save() enforces per-operation gates
// via SoftwareVoter — SOFTWARE_CREATE for new, SOFTWARE_MODIFY for edit. Both bypass for ROLE_ISB
// and ROLE_SOFTWARE_OWNER; edit additionally permits the Software-Responsible.
#[AsLiveComponent]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class SoftwareForm extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public ?Software $initialFormData = null;

    /** @var array<string, mixed> */
    #[LiveProp]
    public array $returnParams = [];

    /** #211: set by the edit page when it was opened from the detail page (return_to=show). */
    #[LiveProp]
    public bool $returnToShow = false;

    #[LiveProp]
    public string $buttonLabel = 'software.save';

    #[LiveProp]
    public bool $isNew = true;

    /**
     * Captured at mount time and preserved through component re-hydration so the original
     * lockVersion from the user's page load survives to the save action — even if Live
     * Component dehydration re-fetches the entity from DB in between (which would otherwise
     * mask a concurrent edit).
     */
    #[LiveProp]
    public int $lockVersion = 1;

    #[LiveProp]
    public bool $conflict = false;

    #[LiveProp]
    public ?string $lastEditor = null;

    #[LiveProp]
    public ?\DateTimeImmutable $lastEditedAt = null;

    #[PostMount(priority: 10)]
    public function captureLockVersion(): void
    {
        if (null !== $this->initialFormData) {
            $this->lockVersion = $this->initialFormData->getLockVersion();
        }
    }

    /** @return FormInterface<Software> */
    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(SoftwareType::class, $this->initialFormData ?? new Software());
    }

    #[LiveAction]
    public function save(EntityManagerInterface $em, OptimisticLockConflictResolver $conflictResolver): ?Response
    {
        if ($this->isNew) {
            $this->denyAccessUnlessGranted(SoftwareVoter::CREATE);
        } else {
            $this->denyAccessUnlessGranted(SoftwareVoter::MODIFY, $this->initialFormData);
        }

        $this->submitForm();

        /** @var Software $software */
        $software = $this->getForm()->getData();
        $software->setLockVersion($this->lockVersion);
        $em->persist($software);

        try {
            $em->flush();
        } catch (OptimisticLockException) {
            // Discard the user's submitted form values and re-bind the component to the
            // OTHER user's persisted state, so the next render shows what they changed.
            $info = $conflictResolver->resolve($software);
            /** @var Software $fresh */
            $fresh = $info['fresh'];
            $this->initialFormData = $fresh;
            $this->lockVersion = $fresh->getLockVersion();
            $this->conflict = true;
            $this->lastEditor = $info['updatedBy'];
            $this->lastEditedAt = $info['updatedAt'];
            $this->resetForm();

            return null;
        }

        $this->addFlash('success', $this->isNew ? 'software.created' : 'software.updated');

        // An edit can drop one's own view access (e.g. handing over responsibility).
        if (!$this->isNew && $this->returnToShow && $this->isGranted(SoftwareVoter::VIEW, $software)) {
            return $this->redirectToRoute('app_software_show', ['uuid' => $software->getUuid()] + $this->returnParams, Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('app_software_index', $this->returnParams, Response::HTTP_SEE_OTHER);
    }

    #[LiveListener('vendor:created')]
    public function onVendorCreated(#[LiveArg] int $vendor): void
    {
        $this->formValues['vendor'] = (string) $vendor;
    }
}

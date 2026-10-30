<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\UpdateCheck;

use App\Entity\UpdateCheckState;
use App\Repository\UpdateCheckStateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * #223: reads the published version.json once a day and remembers what it said.
 *
 * Expected document (written by deploy/publish-snapshot.sh):
 *   {"version": "2.3.0", "released": "2026-11-02", "security": true, "url": "https://…"}
 *
 * The request is a bare GET — no instance id, no own version, no query string — so the check
 * cannot double as telemetry. It goes through the hardened http_client (https only, no private
 * networks, byte budget, #190). A failure never reaches a user: it is logged as a warning (below
 * the error-mail threshold) and stored on the state row; the last good answer stays in place.
 */
final readonly class UpdateChecker
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private UpdateCheckStateRepository $stateRepository,
        private EntityManagerInterface $entityManager,
        private UpdateNotifier $notifier,
        private LoggerInterface $logger,
        #[Autowire('%app.update_check.enabled%')]
        private bool $enabled,
        #[Autowire('%app.update_check.url%')]
        private string $url,
        #[Autowire('%app.update_check.http_timeout_seconds%')]
        private int $timeoutSeconds,
        #[Autowire('%app_version%')]
        private string $currentVersion,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * One check. Returns false when the source could not be read or was invalid.
     */
    public function run(): bool
    {
        if (!$this->enabled) {
            return true;
        }

        $state = $this->stateRepository->getOrCreate();
        $now = new \DateTimeImmutable();
        $state->setLastRunAt($now);

        try {
            $published = $this->fetch();
        } catch (HttpExceptionInterface|\UnexpectedValueException|\JsonException $e) {
            $state->setLastErrorAt($now)->setLastErrorMessage($e->getMessage());
            $this->entityManager->flush();
            $this->logger->warning('Update check failed — version.json could not be read.', [
                'url' => $this->url,
                'exception' => $e,
            ]);

            return false;
        }

        $state->setLastSuccessAt($now)
            ->setLastErrorAt(null)
            ->setLastErrorMessage(null)
            ->setLatestVersion($published['version'])
            ->setLatestUrl($published['url'])
            ->setLatestSecurity($published['security'])
            ->setLatestReleasedAt($published['released']);

        $update = $this->fromState($state);
        if (null !== $update && $state->getNotifiedVersion() !== $update->version) {
            $this->notifier->notifyAdmins($update);
            $state->setNotifiedVersion($update->version);
        }

        $this->entityManager->flush();

        return true;
    }

    /**
     * Read path for the dashboard: never fetches, never writes. Null when the check is off,
     * has not succeeded yet, or the running version is current.
     */
    public function availableUpdate(): ?AvailableUpdate
    {
        if (!$this->enabled) {
            return null;
        }

        $state = $this->stateRepository->findState();

        return null === $state ? null : $this->fromState($state);
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getCurrentVersion(): string
    {
        return $this->currentVersion;
    }

    private function fromState(UpdateCheckState $state): ?AvailableUpdate
    {
        $latest = $state->getLatestVersion();
        if (null === $latest || !VersionComparator::isNewer($latest, $this->currentVersion)) {
            return null;
        }

        return new AvailableUpdate(
            $latest,
            $this->currentVersion,
            $state->getLatestUrl(),
            $state->isLatestSecurity(),
            $state->getLatestReleasedAt(),
        );
    }

    /**
     * @return array{version: string, url: ?string, security: bool, released: ?\DateTimeImmutable}
     *
     * @throws HttpExceptionInterface
     * @throws \JsonException
     * @throws \UnexpectedValueException when the document does not have the expected shape
     */
    private function fetch(): array
    {
        $response = $this->httpClient->request('GET', $this->url, [
            'timeout' => $this->timeoutSeconds,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $data = json_decode($response->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \UnexpectedValueException('version.json is not a JSON object.');
        }

        $version = $data['version'] ?? null;
        if (!\is_string($version) || !VersionComparator::isValid($version)) {
            throw new \UnexpectedValueException('version.json carries no valid "version".');
        }

        // The link is shown to ISB and admin; only https survives — a javascript: or http: URL
        // from a tampered file is dropped, the version hint stays.
        $url = $data['url'] ?? null;
        $url = \is_string($url) && str_starts_with($url, 'https://') && \strlen($url) <= 2048 ? $url : null;

        $released = $data['released'] ?? null;
        $released = \is_string($released) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $released) : false;

        return [
            'version' => $version,
            'url' => $url,
            'security' => true === ($data['security'] ?? false),
            'released' => false === $released ? null : $released,
        ];
    }
}

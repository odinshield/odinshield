<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Service\Csaf\Fetcher;

use App\Entity\CsafProviderState;
use App\Enum\CsafProviderStatus;
use App\Repository\CsafProviderStateRepository;
use App\Service\Csaf\Exception\CsafFetchException;
use App\Service\Csaf\Exception\CsafParseException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the BSI aggregator descriptor and registers every listed Trusted Provider as
 * a CsafProviderState row. Existing rows are left untouched (idempotent).
 */
final readonly class AggregatorExpander
{
    /**
     * @param list<string> $registeredDisabled provider keys registered as Disabled instead of Enabled (#319, O8 a)
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private CsafProviderStateRepository $stateRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        #[Autowire('%app.csaf.http_timeout_seconds%')]
        private int $timeoutSeconds = 30,
        #[Autowire('%app.csaf.registered_disabled%')]
        private array $registeredDisabled = [],
    ) {
    }

    /**
     * Expand the aggregator at the given URL.
     *
     * @return int Number of newly-registered providers
     */
    public function expand(string $aggregatorUrl): int
    {
        try {
            $response = $this->httpClient->request('GET', $aggregatorUrl, [
                'timeout' => $this->timeoutSeconds,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $payload = $response->getContent();
        } catch (HttpExceptionInterface $e) {
            throw new CsafFetchException('Aggregator fetch failed: '.$e->getMessage(), 0, $e);
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CsafParseException('Aggregator JSON malformed: '.$e->getMessage(), 0, $e);
        }

        // CSAF 2.0 §7.2: aggregator documents list Trusted Providers under `csaf_providers`.
        // `csaf_publishers` is the separate (optional) list of publishers and is merged in here
        // so a single expand() pass covers both — slugifyHost dedupes by host anyway.
        $providers = array_merge(
            \is_array($data['csaf_providers'] ?? null) ? $data['csaf_providers'] : [],
            \is_array($data['csaf_publishers'] ?? null) ? $data['csaf_publishers'] : [],
        );
        if ([] === $providers) {
            return 0;
        }

        $registered = 0;
        foreach ($providers as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $metadataUrl = (string) ($entry['metadata']['url'] ?? ($entry['url'] ?? ''));
            $displayName = (string) ($entry['metadata']['publisher']['name'] ?? ($entry['name'] ?? $metadataUrl));
            if ('' === $metadataUrl) {
                continue;
            }

            // Dedup by URL so a manually-seeded row (e.g. `siemens` via odinshield:csaf:provider-add)
            // is not duplicated when the aggregator lists the same provider under a slugified host key.
            if (null !== $this->stateRepository->findOneBy(['providerMetadataUrl' => $metadataUrl])) {
                continue;
            }

            $providerKey = $this->slugifyHost($metadataUrl);
            if (null !== $this->stateRepository->find($providerKey)) {
                $this->logger->warning('CSAF aggregator: slug collision — different URL already uses this key, skipping', [
                    'providerKey' => $providerKey,
                    'newMetadataUrl' => $metadataUrl,
                ]);
                continue;
            }

            $state = new CsafProviderState($providerKey, $displayName, $metadataUrl);
            // #319, O8 a: a provider whose documents we cannot match (Red Hat, D9) but whose volume
            // eats the run budget starts switched off; an operator with such inventory enables it.
            // Only a new registration — an existing row, enabled or not, is never touched above.
            $disabled = \in_array($providerKey, $this->registeredDisabled, true);
            if ($disabled) {
                $state->setStatus(CsafProviderStatus::Disabled);
            }
            $this->em->persist($state);
            ++$registered;
            $this->logger->info('CSAF aggregator: registered provider', [
                'providerKey' => $providerKey,
                'metadataUrl' => $metadataUrl,
                'status' => $state->getStatus()->value,
            ]);
        }

        if ($registered > 0) {
            $this->em->flush();
        }

        return $registered;
    }

    private function slugifyHost(string $url): string
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            $host = $url;
        }
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $host) ?? $host);
        $slug = trim($slug, '_');

        return '' !== $slug ? substr($slug, 0, 64) : 'unknown';
    }
}

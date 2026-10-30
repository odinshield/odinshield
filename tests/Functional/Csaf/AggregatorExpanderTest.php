<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional\Csaf;

use App\Entity\CsafProviderState;
use App\Enum\CsafProviderStatus;
use App\Repository\CsafProviderStateRepository;
use App\Service\Csaf\Fetcher\AggregatorExpander;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * #319, O8 a: Red Hat is registered switched off — its documents are not matched (D9) while its
 * rebuild volume takes the whole run budget. Only new registrations; an instance where an operator
 * enabled it keeps it enabled.
 */
final class AggregatorExpanderTest extends KernelTestCase
{
    private const string REDHAT_METADATA = 'https://security.access.redhat.com/data/csaf/v2/provider-metadata.json';
    private const string SIEMENS_METADATA = 'https://cert-portal.siemens.com/productcert/csaf/provider-metadata.json';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var ManagerRegistry $registry */
        $registry = self::getContainer()->get('doctrine');
        /** @var EntityManagerInterface $em */
        $em = $registry->getManager();
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    public function testRedHatIsRegisteredDisabledOthersEnabled(): void
    {
        self::assertSame(2, $this->expander()->expand('https://aggregator.test/aggregator.json'));

        self::assertSame(CsafProviderStatus::Disabled, $this->providerStatus('security_access_redhat_com'));
        self::assertSame(CsafProviderStatus::Enabled, $this->providerStatus('cert_portal_siemens_com'));
    }

    public function testAnExistingRedHatRowKeepsItsStatus(): void
    {
        $this->em->persist(new CsafProviderState('security_access_redhat_com', 'Red Hat Product Security', self::REDHAT_METADATA));
        $this->em->flush();

        self::assertSame(1, $this->expander()->expand('https://aggregator.test/aggregator.json'));

        self::assertSame(CsafProviderStatus::Enabled, $this->providerStatus('security_access_redhat_com'));
    }

    public function testTheContainerWiresRedHatAsDisabled(): void
    {
        /** @var list<string> $keys */
        $keys = self::getContainer()->getParameter('app.csaf.registered_disabled');

        self::assertContains('security_access_redhat_com', $keys);
    }

    private function expander(): AggregatorExpander
    {
        $client = new MockHttpClient(new MockResponse((string) json_encode(['csaf_providers' => [
            ['metadata' => ['url' => self::REDHAT_METADATA, 'publisher' => ['name' => 'Red Hat Product Security']]],
            ['metadata' => ['url' => self::SIEMENS_METADATA, 'publisher' => ['name' => 'Siemens ProductCERT']]],
        ]])));
        /** @var CsafProviderStateRepository $repository */
        $repository = self::getContainer()->get(CsafProviderStateRepository::class);

        return new AggregatorExpander($client, $repository, $this->em, new NullLogger(), 30, ['security_access_redhat_com']);
    }

    private function providerStatus(string $key): CsafProviderStatus
    {
        $this->em->clear();
        $state = $this->em->find(CsafProviderState::class, $key);
        self::assertInstanceOf(CsafProviderState::class, $state);

        return $state->getStatus();
    }
}

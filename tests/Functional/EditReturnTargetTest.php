<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional;

use App\Entity\AssetType;
use App\Entity\Software;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\Vendor;
use App\Entity\Vulnerability;
use App\Enum\VulnerabilitySeverity;
use App\Enum\VulnerabilitySource;
use App\Enum\VulnerabilityStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * After saving, the user lands where they started (#211).
 *
 * Editing from a detail page returns to that detail page (`return_to=show`, the only value
 * honoured); editing from a list returns to the list with its filters, as before. A new
 * vulnerability opens on its detail page — under the default sort (risk desc) a low-risk entry
 * can sit pages away — unless its creator may not view it.
 */
final class EditReturnTargetTest extends AbstractWebTestCase
{
    use InteractsWithLiveComponents;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function editableEntities(): iterable
    {
        yield 'asset group' => ['asset-group', 'asset_group'];
        yield 'asset type' => ['asset-type', 'asset_type'];
        yield 'user' => ['user', 'user'];
        yield 'user group' => ['user-group', 'user_group'];
        yield 'vendor' => ['vendor', 'vendor'];
        yield 'vulnerability' => ['vulnerability', 'vulnerability'];
    }

    #[DataProvider('editableEntities')]
    public function testEditFromDetailPageReturnsToDetailPageWithListParams(string $path, string $formName): void
    {
        $this->loginAsAdmin();
        $uuid = $this->seed($path);

        // Follow the edit link the detail page renders — building the URL by hand would skip
        // exactly the link this issue is about.
        $show = $this->client->request('GET', \sprintf('/%s/%s?sort=name&direction=desc', $path, $uuid));
        self::assertResponseIsSuccessful();
        $editHref = $show->filter(\sprintf('a[href^="/%s/%s/edit"]', $path, $uuid))->attr('href');
        self::assertNotNull($editHref);
        self::assertStringContainsString('return_to=show', $editHref);

        $edit = $this->client->request('GET', $editHref);
        self::assertResponseIsSuccessful();
        $detailUrl = \sprintf('/%s/%s', $path, $uuid);
        self::assertStringStartsWith($detailUrl.'?', (string) $edit->filter('.page-header a.btn-ghost-secondary')->attr('href'), 'Back link must lead to the detail page.');
        self::assertSelectorTextContains('.page-header a.btn-ghost-secondary', 'Back to details');
        self::assertStringStartsWith($detailUrl, (string) $edit->filter(\sprintf('form[name="%s"] a.btn-outline-secondary', $formName))->attr('href'), 'Cancel must lead to the detail page.');

        $this->client->submit($edit->filter(\sprintf('form[name="%s"]', $formName))->form());

        self::assertResponseStatusCodeSame(303);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame($detailUrl, parse_url($location, \PHP_URL_PATH));
        self::assertStringContainsString('direction=desc', $location);
    }

    #[DataProvider('editableEntities')]
    public function testEditWithoutReturnTargetStillReturnsToList(string $path, string $formName): void
    {
        $this->loginAsAdmin();
        $uuid = $this->seed($path);

        $edit = $this->client->request('GET', \sprintf('/%s/%s/edit?direction=desc', $path, $uuid));
        self::assertSelectorTextContains('.page-header a.btn-ghost-secondary', 'Back to list');
        $this->client->submit($edit->filter(\sprintf('form[name="%s"]', $formName))->form());

        self::assertResponseStatusCodeSame(303);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame('/'.$path, parse_url($location, \PHP_URL_PATH));
        self::assertStringContainsString('direction=desc', $location);
    }

    #[DataProvider('editableEntities')]
    public function testUnknownReturnTargetIsIgnored(string $path, string $formName): void
    {
        $this->loginAsAdmin();
        $uuid = $this->seed($path);

        $edit = $this->client->request('GET', \sprintf('/%s/%s/edit?return_to=%s', $path, $uuid, rawurlencode('https://evil.example/')));
        $this->client->submit($edit->filter(\sprintf('form[name="%s"]', $formName))->form());

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/'.$path, parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));
    }

    public function testNewVulnerabilityOpensItsDetailPage(): void
    {
        $this->loginAsAdmin();
        $assetGroup = $this->createAssetGroup();

        $crawler = $this->client->request('GET', '/vulnerability/new?direction=desc');
        $this->client->submit($crawler->filter('form[name="vulnerability"]')->form([
            'vulnerability[title]' => 'Brand New',
            'vulnerability[assetGroups]' => [$assetGroup->getUuid()->toRfc4122()],
            'vulnerability[status]' => VulnerabilityStatus::Open->value,
            'vulnerability[severity]' => VulnerabilitySeverity::High->value,
            'vulnerability[discoveredAt]' => '01/15/2026',
        ]));

        self::assertResponseStatusCodeSame(303);
        $vuln = $this->em()->getRepository(Vulnerability::class)->findOneBy(['title' => 'Brand New']);
        self::assertNotNull($vuln);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame('/vulnerability/'.$vuln->getUuid(), parse_url($location, \PHP_URL_PATH));
        self::assertStringContainsString('direction=desc', $location);

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testNewVulnerabilityTheCreatorCannotSeeReturnsToList(): void
    {
        $em = $this->em();
        $assetGroup = $this->createAssetGroup();
        $colleague = $this->user('colleague');
        $creator = $this->user('roleless');
        $this->client->loginUser($creator);

        $crawler = $this->client->request('GET', '/vulnerability/new');
        $this->client->submit($crawler->filter('form[name="vulnerability"]')->form([
            'vulnerability[title]' => 'Handed Over At Creation',
            'vulnerability[assetGroups]' => [$assetGroup->getUuid()->toRfc4122()],
            'vulnerability[status]' => VulnerabilityStatus::Open->value,
            'vulnerability[severity]' => VulnerabilitySeverity::High->value,
            'vulnerability[discoveredAt]' => '01/15/2026',
            'vulnerability[responsibleUser]' => (string) $colleague->getId(),
        ]));

        self::assertResponseStatusCodeSame(303);
        self::assertNotNull($em->getRepository(Vulnerability::class)->findOneBy(['title' => 'Handed Over At Creation']));
        self::assertSame('/vulnerability', parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH), 'A detail page the creator may not view would be a 403.');
    }

    public function testEditThatHandsOverResponsibilityReturnsToList(): void
    {
        $colleague = $this->user('colleague');
        $owner = $this->user('owner');
        $vuln = $this->vulnerability('Mine For Now');
        $vuln->setResponsibleUser($owner);
        $this->em()->flush();
        $this->client->loginUser($owner);

        $edit = $this->client->request('GET', \sprintf('/vulnerability/%s/edit?return_to=show', $vuln->getUuid()));
        self::assertResponseIsSuccessful();
        $this->client->submit($edit->filter('form[name="vulnerability"]')->form([
            'vulnerability[responsibleUser]' => (string) $colleague->getId(),
        ]));

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/vulnerability', parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH), 'The detail page is no longer visible to the former owner.');
    }

    public function testSoftwareEditFromDetailPageReturnsToDetailPage(): void
    {
        $this->loginAsAdmin();
        $software = $this->software();

        $show = $this->client->request('GET', \sprintf('/software/%s?direction=desc', $software->getUuid()));
        $editHref = (string) $show->filter(\sprintf('a[href^="/software/%s/edit"]', $software->getUuid()))->attr('href');
        self::assertStringContainsString('return_to=show', $editHref);
        $edit = $this->client->request('GET', $editHref);
        self::assertSelectorTextContains('.page-header a.btn-ghost-secondary', 'Back to details');
        self::assertStringStartsWith('/software/'.$software->getUuid(), (string) $edit->filter('[data-live-name-value="SoftwareForm"] a.btn-outline-secondary')->attr('href'));

        $fresh = $this->em()->getRepository(Software::class)->findOneBy(['uuid' => $software->getUuid()]);
        self::assertNotNull($fresh);
        $this->assertSoftwareSaveRedirectsTo($fresh, true, '/software/'.$software->getUuid());
    }

    public function testSoftwareEditFromListReturnsToList(): void
    {
        $this->loginAsAdmin();

        $this->assertSoftwareSaveRedirectsTo($this->software(), false, '/software');
    }

    private function assertSoftwareSaveRedirectsTo(Software $software, bool $returnToShow, string $expectedPath): void
    {
        $component = $this->createLiveComponent(
            'SoftwareForm',
            ['initialFormData' => $software, 'isNew' => false, 'returnParams' => ['direction' => 'desc'], 'returnToShow' => $returnToShow],
            $this->client,
        );
        $component->submitForm([
            'software' => [
                'name' => 'Renamed',
                'vendor' => (string) $software->getVendor()->getId(),
                'productKey' => $software->getProductKey(),
                'version' => $software->getVersion(),
                'maintenanceSource' => 'manual',
                'active' => '1',
            ],
        ], 'save');

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame($expectedPath, parse_url($location, \PHP_URL_PATH));
        self::assertStringContainsString('direction=desc', $location);
    }

    private function seed(string $path): string
    {
        $em = $this->em();
        $entity = match ($path) {
            'asset-group' => $this->createAssetGroup(),
            'asset-type' => (new AssetType())->setName('Return Type')->setActive(true),
            'user' => $this->user('editee'),
            'user-group' => (new UserGroup())->setName('Return Team'),
            'vendor' => (new Vendor())->setName('Return Vendor')->setVendorKey('return_vendor')->setActive(true),
            'vulnerability' => $this->vulnerability('Return Vulnerability'),
            default => throw new \LogicException($path),
        };
        $em->persist($entity);
        $em->flush();

        return (string) $entity->getUuid();
    }

    private function user(string $username): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setFirstName('Return');
        $user->setLastName(ucfirst($username));
        $user->setEmail($username.'@example.com');
        $user->setPassword('unused-hash');
        $user->setIsActive(true);
        $user->setLocale('en');
        $user->setMustChangePassword(false);
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function vulnerability(string $title): Vulnerability
    {
        $vuln = new Vulnerability();
        $vuln->setTitle($title);
        $vuln->setSeverity(VulnerabilitySeverity::High);
        $vuln->setStatus(VulnerabilityStatus::Open);
        $vuln->setSource(VulnerabilitySource::Manual);
        $vuln->setDiscoveredAt(new \DateTimeImmutable('2026-01-01'));
        $vuln->addAssetGroup($this->createAssetGroup());
        $this->em()->persist($vuln);
        $this->em()->flush();

        return $vuln;
    }

    private function software(): Software
    {
        $vendor = (new Vendor())->setName('Acme')->setVendorKey('acme')->setActive(true);
        $software = new Software();
        $software->setName('Return Software');
        $software->setVendor($vendor);
        $software->setProductKey('return_software');
        $software->setVersion('1.0.0');
        $software->setActive(true);
        $this->em()->persist($vendor);
        $this->em()->persist($software);
        $this->em()->flush();

        return $software;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        return $em;
    }
}

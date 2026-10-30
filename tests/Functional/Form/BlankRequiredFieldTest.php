<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional\Form;

use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\Vendor;
use App\Entity\Vulnerability;
use App\Enum\VulnerabilitySeverity;
use App\Tests\Functional\AbstractWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Every form field, submitted empty one at a time, must end in a validation error — never in a
 * 500. All forms carry `novalidate`, so the browser does not stop an emptied required field:
 * the value arrived as null, went into a non-nullable setter during data mapping (before
 * validation) and crashed. Found on the demo instance 2026-09-18 (software without vendor).
 */
final class BlankRequiredFieldTest extends AbstractWebTestCase
{
    public function testNoFormFieldCrashesWhenSubmittedEmpty(): void
    {
        $this->loginAsAdmin();
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $assetGroup = $this->createAssetGroup();
        $vendor = (new Vendor())->setName('Acme')->setVendorKey('acme');
        $userGroup = new UserGroup();
        $userGroup->setName('Team');
        $user = new User();
        $user->setUsername('other')->setFirstName('Other')->setLastName('User')->setEmail('other@example.com')->setPassword('unused');
        $vulnerability = new Vulnerability();
        $vulnerability->setTitle('Title');
        $vulnerability->setSeverity(VulnerabilitySeverity::High);
        $vulnerability->setDiscoveredAt(new \DateTimeImmutable());
        $vulnerability->addAssetGroup($assetGroup);
        foreach ([$vendor, $userGroup, $user, $vulnerability] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $urls = [
            '/asset-group/new', '/asset-group/'.$assetGroup->getUuid().'/edit',
            '/asset-type/new', '/asset-type/'.$assetGroup->getAssetType()->getUuid().'/edit',
            '/user/new', '/user/'.$user->getUuid().'/edit',
            '/user-group/new', '/user-group/'.$userGroup->getUuid().'/edit',
            '/vendor/new', '/vendor/'.$vendor->getUuid().'/edit',
            '/vulnerability/new', '/vulnerability/'.$vulnerability->getUuid().'/edit',
            '/settings',
        ];

        $crashes = [];
        foreach ($urls as $url) {
            $fields = $this->fieldPaths($this->formValues($this->client->request('GET', $url)));
            self::assertNotEmpty($fields, sprintf('No form found on %s', $url));

            foreach ($fields as $path) {
                $values = $this->formValues($this->client->request('GET', $url));
                $this->blank($values, $path);
                $this->client->request('POST', $url, $values);

                $status = $this->client->getResponse()->getStatusCode();
                if ($status >= 500) {
                    $crashes[] = sprintf('%s [%s] -> %d', $url, $path, $status);
                }
                $em->clear();
            }
        }

        self::assertSame([], $crashes);
    }

    public function testNewAssetGroupWithoutAssetTypeShowsFieldError(): void
    {
        $this->loginAsAdmin();
        $this->createAssetGroup();

        $values = $this->formValues($this->client->request('GET', '/asset-group/new'));
        $values['asset_group']['name'] = 'Named, but untyped';
        $values['asset_group']['assetType'] = '';
        $this->client->request('POST', '/asset-group/new', $values);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'Asset type is required.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formValues(Crawler $crawler): array
    {
        return $crawler->filter('form[method="post"]:not([action="/logout"])')->first()->form()->getPhpValues();
    }

    /**
     * Leaf paths like `vendor[name]`; list values (multi-selects) count as one leaf.
     *
     * @param array<array-key, mixed> $values
     *
     * @return list<string>
     */
    private function fieldPaths(array $values, string $prefix = ''): array
    {
        $paths = [];
        foreach ($values as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix.'['.$key.']';
            if (str_contains($path, 'token')) {
                continue;
            }
            if (\is_array($value) && [] !== $value && !array_is_list($value)) {
                $paths = [...$paths, ...$this->fieldPaths($value, $path)];
            } else {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private function blank(array &$values, string $path): void
    {
        preg_match_all('/[^\[\]]+/', $path, $matches);
        $keys = $matches[0];
        $last = array_pop($keys);
        $ref = &$values;
        foreach ($keys as $key) {
            $ref = &$ref[$key];
        }
        $ref[$last] = \is_array($ref[$last] ?? null) ? [] : '';
    }
}

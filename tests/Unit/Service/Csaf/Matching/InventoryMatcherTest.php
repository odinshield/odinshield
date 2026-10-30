<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Service\Csaf\Matching;

use App\Entity\Software;
use App\Entity\Vendor;
use App\Service\Csaf\Dto\CsafProduct;
use App\Service\Csaf\Matching\InventoryMatcher;
use App\Service\Csaf\Matching\MatchReason;
use App\Service\Csaf\Matching\VersionRange;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class InventoryMatcherTest extends TestCase
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    private array $log = [];
    private InventoryMatcher $matcher;

    protected function setUp(): void
    {
        $this->log = [];
        $sink = function (mixed $level, string $message, array $context): void {
            $this->log[] = ['level' => $level, 'message' => $message, 'context' => $context];
        };
        $this->matcher = new InventoryMatcher(new class ($sink) extends AbstractLogger {
            public function __construct(private readonly \Closure $sink)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ($this->sink)($level, (string) $message, $context);
            }
        });
    }

    public function testKeysAndVersionInsideRangeIsAPrefixHit(): void
    {
        $sw = $this->software('fortinet', 'fortios', '7.6');
        $result = $this->matcher->match([$this->product('fortinet', 'fortios', '<7.6.4')], [$sw]);

        self::assertCount(1, $result->hits);
        self::assertSame(MatchReason::Prefix, $result->hits[0]->reason);
        self::assertSame([], $result->versionOutside);
    }

    public function testExactVersionIsReportedAsExact(): void
    {
        $sw = $this->software('fortinet', 'fortios', '7.6.3');
        $result = $this->matcher->match([$this->product('fortinet', 'fortios', '7.6.3')], [$sw]);

        self::assertSame(MatchReason::Exact, $result->hits[0]->reason ?? null);
    }

    public function testVersionOutsideRangeIsCountedNotReported(): void
    {
        $sw = $this->software('fortinet', 'fortios', '7.6.5');
        $result = $this->matcher->match([$this->product('fortinet', 'fortios', '<7.6.4')], [$sw]);

        self::assertSame([], $result->hits);
        self::assertCount(1, $result->versionOutside);
    }

    public function testKeyMismatchIsNoHit(): void
    {
        $sw = $this->software('fortinet', 'fortios', '7.6');
        $result = $this->matcher->match([$this->product('fortinet', 'fortiproxy', '<7.6.4')], [$sw]);

        self::assertSame([], $result->hits);
        self::assertSame([], $result->versionOutside);
    }

    public function testAliasOnVendorAndSoftwareBridgesSpelling(): void
    {
        $sw = $this->software('sick', 'coriva_engine', '2026.1.0', vendorAliases: ['SICK AG'], softwareAliases: ['CorivaEngine']);
        $result = $this->matcher->match([$this->product('sick_ag', 'corivaengine', '<=2026.1.0')], [$sw]);

        self::assertCount(1, $result->hits);
    }

    public function testNearMissIsLoggedOnlyNeverReported(): void
    {
        $sw = $this->software('fortinet_net', 'fortios', '7.6');
        $result = $this->matcher->match([$this->product('fortinet', 'fortios', '<7.6.4')], [$sw], ['docId' => 'X']);

        self::assertSame([], $result->hits, 'containment is not a match');
        self::assertCount(1, $result->nearMisses);
        self::assertCount(1, $this->log);
        self::assertSame('notice', $this->log[0]['level']);
        self::assertSame('X', $this->log[0]['context']['docId']);
        self::assertSame('fortinet_net', $this->log[0]['context']['inventoryVendorKey']);
    }

    public function testUnparseableInventoryVersionMatchesEverythingWithReason(): void
    {
        $sw = $this->software('fortinet', 'fortios', 'latest');
        $result = $this->matcher->match([$this->product('fortinet', 'fortios', '<7.4.9')], [$sw]);

        self::assertSame(MatchReason::InventoryUnparseable, $result->hits[0]->reason ?? null);
    }

    public function testUnreadableAdvisoryRangeIsFailOpenWithReason(): void
    {
        $sw = $this->software('fortinet', 'fortios', '7.6');
        $result = $this->matcher->match([$this->product('fortinet', 'fortios', 'all supported builds')], [$sw]);

        self::assertSame(MatchReason::AdvisoryUnparseable, $result->hits[0]->reason ?? null);
    }

    public function testOneHitPerSoftwareEvenWhenSeveralProductsMatch(): void
    {
        $sw = $this->software('fortinet', 'fortios', '7');
        $result = $this->matcher->match([
            $this->product('fortinet', 'fortios', '<7.2.12', 'p1'),
            $this->product('fortinet', 'fortios', '<7.6.4', 'p2'),
        ], [$sw]);

        self::assertCount(1, $result->hits);
        self::assertSame('p1', $result->hits[0]->product->productId);
    }

    public function testProductWithoutIdentityIsIgnored(): void
    {
        $sw = $this->software('fortinet', 'fortios', '7.6');
        $result = $this->matcher->match([$this->product('', 'fortios', '<7.6.4')], [$sw]);

        self::assertSame([], $result->hits);
    }

    public function testMatchKeysIgnoresTheVersion(): void
    {
        $sw = $this->software('fortinet', 'fortios', '7.4');
        $fixed = $this->matcher->matchKeys([$this->product('fortinet', 'fortios', '7.6.4')], [$sw]);

        self::assertCount(1, $fixed);
        self::assertSame($sw, $fixed[0]['software']);
    }

    /**
     * @param list<string> $vendorAliases
     * @param list<string> $softwareAliases
     */
    private function software(string $vendorKey, string $productKey, string $version, array $vendorAliases = [], array $softwareAliases = []): Software
    {
        static $id = 0;
        $vendor = new Vendor();
        $vendor->setName($vendorKey);
        $vendor->setVendorKey($vendorKey);
        $vendor->setAliases($vendorAliases);

        $software = new Software();
        $software->setName($productKey);
        $software->setProductKey($productKey);
        $software->setVersion($version);
        $software->setVendor($vendor);
        $software->setAliases($softwareAliases);
        // The matcher de-duplicates on the id; unit tests never flush, so assign one.
        $ref = new \ReflectionProperty(Software::class, 'id');
        $ref->setValue($software, ++$id);

        return $software;
    }

    private function product(string $vendorToken, string $productToken, string $rangeText, string $id = 'P'): CsafProduct
    {
        return new CsafProduct($id, $vendorToken.' '.$productToken.' '.$rangeText, $vendorToken, $productToken, VersionRange::fromText($rangeText), $rangeText);
    }
}

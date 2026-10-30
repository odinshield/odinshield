<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Service\Csaf;

use App\Service\Csaf\Dto\CsafAdvisory;
use App\Service\Csaf\Exception\CsafParseException;
use App\Service\Csaf\Matching\ComparableVersion;
use App\Service\Csaf\Parser\CsafDocumentParser;
use PHPUnit\Framework\TestCase;

final class CsafDocumentParserTest extends TestCase
{
    private CsafDocumentParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CsafDocumentParser();
    }

    public function testParsesBsiWidExample(): void
    {
        $raw = (string) file_get_contents(__DIR__.'/../../../fixtures/csaf/bsi_wid_example.json');
        $advisory = $this->parser->parse('bsi_wid', 'BSI WID', $raw);

        self::assertSame('WID-SEC-2021-2245', $advisory->documentId);
        self::assertSame('1.0', $advisory->version);
        self::assertCount(2, $advisory->productsById);
        $affected = $advisory->productsById['CSAFPID-001'];
        self::assertSame('cpe:2.3:a:apache:log4j:2.14.1:*:*:*:*:*:*:*', $affected->cpe);
        self::assertSame('apache', $affected->vendorToken, 'identity comes from the CPE when present');
        self::assertSame('log4j', $affected->productToken);
        self::assertSame('2.14.1', $affected->rawVersionText, 'the product_version branch name is the range source');
        self::assertSame('>=2.14.1 <2.14.2', $affected->range->describe());

        self::assertCount(1, $advisory->vulnerabilities);
        $cv = $advisory->vulnerabilities[0];
        self::assertSame('CVE-2021-44228', $cv->cve);
        self::assertSame(10.0, $cv->cvssScore);
        self::assertSame(['CSAFPID-001'], $cv->knownAffectedProductIds);
        self::assertSame(['CSAFPID-002'], $cv->fixedProductIds);
        self::assertSame(['Vendor Fix: Update to Log4j 2.17.0 or later.'], $cv->remediations);
        self::assertNotEmpty($cv->notes);
    }

    public function testRemediationFormatIncludesCategoryUrlDateAndRestartFlag(): void
    {
        $raw = (string) json_encode([
            'document' => [
                'csaf_version' => '2.0',
                'title' => 'Demo',
                'category' => 'csaf_security_advisory',
                'distribution' => ['tlp' => ['label' => 'WHITE']],
                'tracking' => [
                    'id' => 'DEMO-1',
                    'version' => '1',
                    'status' => 'final',
                    'initial_release_date' => '2026-05-01T00:00:00Z',
                    'current_release_date' => '2026-05-01T00:00:00Z',
                ],
            ],
            'vulnerabilities' => [
                [
                    'cve' => 'CVE-2026-1234',
                    'remediations' => [
                        ['category' => 'vendor_fix', 'details' => 'Apply patch 1.2.3', 'url' => 'https://example.test/patch', 'date' => '2026-05-01T00:00:00Z', 'restart_required' => ['category' => 'machine']],
                        ['category' => 'workaround', 'details' => 'Disable affected feature in config'],
                        ['category' => 'none_available', 'details' => 'No fix planned for this branch'],
                        ['category' => 'mitigation', 'details' => 'Restrict network exposure to trusted hosts'],
                    ],
                ],
            ],
        ]);

        $advisory = $this->parser->parse('p', 'Demo Provider', $raw);
        $remediations = $advisory->vulnerabilities[0]->remediations;

        self::assertSame(
            [
                'Vendor Fix: Apply patch 1.2.3 (available 2026-05-01T00:00:00Z) — https://example.test/patch [Restart: machine]',
                'Workaround: Disable affected feature in config',
                'No Fix Available: No fix planned for this branch',
                'Mitigation: Restrict network exposure to trusted hosts',
            ],
            $remediations,
        );
    }

    public function testFixedProductsComeOnlyFromProductStatusAndNothingIsSynthesised(): void
    {
        // BSI WID convention: product_tree carries both the affected (<version range>) and the
        // fixed (<version>) entries, but product_status.fixed is empty. The former "-fixed
        // sibling" heuristic is gone (#134): the fix now derives from the range's upper bound in
        // the upserter, so the parser must report exactly what the document states.
        $raw = (string) file_get_contents(__DIR__.'/../../../fixtures/csaf/bsi_single_edition_range_example.json');
        $advisory = $this->parser->parse('bsi_wid', 'BSI WID', $raw);
        $cv = $advisory->vulnerabilities[0];

        self::assertSame(['T053846'], $cv->knownAffectedProductIds);
        self::assertSame(['T053846-fixed'], $cv->fixedProductIds, 'this fixture lists the fixed product explicitly');
        self::assertSame(['Vendor Fix: Update to 26.1.8.'], $cv->remediations, 'only what the document states');

        // The range node has no CPE, but its fixed sibling has: the identity (not the version)
        // is borrowed, so both nodes are `opnsense`/`opnsense` rather than "Open Source"/"OPNsense".
        $range = $advisory->productsById['T053846'];
        self::assertNull($range->cpe);
        self::assertSame('opnsense', $range->vendorToken);
        self::assertSame('opnsense', $range->productToken);
        self::assertSame('<26.1.8', $range->range->describe());
        self::assertSame('opnsense', $advisory->productsById['T053846-fixed']->vendorToken);
    }

    public function testKnownNotAffectedIsCarriedSeparatelyFromAffected(): void
    {
        $raw = (string) json_encode([
            'document' => [
                'csaf_version' => '2.0',
                'title' => 'Correction',
                'tracking' => ['id' => 'NA-1', 'version' => '2', 'status' => 'final', 'initial_release_date' => '2026-05-01T00:00:00Z'],
            ],
            'vulnerabilities' => [[
                'cve' => 'CVE-2026-5700',
                'product_status' => ['known_affected' => ['A'], 'known_not_affected' => ['N', 'N', ''], 'under_investigation' => ['U']],
            ]],
        ]);
        $cv = $this->parser->parse('p', 'P', $raw)->vulnerabilities[0];

        self::assertSame(['A'], $cv->knownAffectedProductIds);
        self::assertSame(['N'], $cv->knownNotAffectedProductIds, '#57: deduplicated, never mixed into affected; under_investigation stays ignored');
    }

    public function testBranchNamesAreTheIdentityWhenNoSiblingCarriesACpe(): void
    {
        $raw = (string) json_encode([
            'document' => [
                'csaf_version' => '2.0',
                'title' => 'No CPE anywhere',
                'tracking' => ['id' => 'N-1', 'version' => '1', 'status' => 'final', 'initial_release_date' => '2026-05-01T00:00:00Z'],
            ],
            'product_tree' => ['branches' => [[
                'category' => 'vendor', 'name' => 'Open Source',
                'branches' => [[
                    'category' => 'product_name', 'name' => 'OPNsense',
                    'branches' => [
                        ['category' => 'product_version_range', 'name' => '<26.1.8', 'product' => ['product_id' => 'R', 'name' => 'Open Source OPNsense <26.1.8']],
                        ['category' => 'product_version', 'name' => '26.1.8', 'product' => ['product_id' => 'F', 'name' => 'Open Source OPNsense 26.1.8']],
                    ],
                ]],
            ]]],
        ]);
        $advisory = $this->parser->parse('p', 'P', $raw);

        self::assertSame('open_source', $advisory->productsById['R']->vendorToken);
        self::assertSame('opnsense', $advisory->productsById['R']->productToken);
    }

    public function testSkipsInterimDocumentsWithCodedException(): void
    {
        $raw = (string) file_get_contents(__DIR__.'/../../../fixtures/csaf/interim_example.json');

        $this->expectException(CsafParseException::class);
        $this->expectExceptionCode(CsafDocumentParser::SKIP_NON_FINAL);
        $this->parser->parse('bsi_wid', 'BSI WID', $raw);
    }

    public function testMalformedJsonThrows(): void
    {
        $raw = (string) file_get_contents(__DIR__.'/../../../fixtures/csaf/malformed.json');

        $this->expectException(CsafParseException::class);
        $this->parser->parse('bsi_wid', 'BSI WID', $raw);
    }

    public function testRangeNodeWithoutCpeTakesIdentityFromBranchNamesAndRangeFromItsName(): void
    {
        $raw = (string) file_get_contents(__DIR__.'/../../../fixtures/csaf/bsi_version_range_example.json');
        $advisory = $this->parser->parse('bsi_wid', 'BSI WID', $raw);

        // The fixed entry keeps its concrete CPE 2.2 and gets its version from the branch name.
        $fixed = $advisory->productsById['T053833-fixed'];
        self::assertSame('cpe:/a:open_source:gstreamer:1.28.3::core', $fixed->cpe);
        self::assertSame('open_source', $fixed->vendorToken);
        self::assertSame('gstreamer', $fixed->productToken);
        self::assertSame('core 1.28.3', $fixed->rawVersionText);

        // The range entry has no CPE: identity from the vendor/product_name branches, the bound
        // from its own name — no borrowed, wildcarded sibling CPE any more (F1).
        $range = $advisory->productsById['T053833'];
        self::assertNull($range->cpe);
        self::assertSame('open_source', $range->vendorToken);
        self::assertSame('gstreamer', $range->productToken);
        self::assertSame('core <1.28.3', $range->rawVersionText);
        self::assertSame('<1.28.3', $range->range->describe());
        self::assertNull($range->range->parseFailure);

        self::assertSame(['T053833', 'T053834'], $advisory->vulnerabilities[0]->knownAffectedProductIds);
    }

    /**
     * WID-SEC-W-2026-1492 (product tree as published, prose synthetic): three `<x` range nodes
     * without CPE next to three fixed nodes with CPE. Acceptance criterion of #134: the range meets 7.6 and 7.6.3, not 7.6.5.
     */
    public function testBsiMultiRangeDocumentKeepsEveryBound(): void
    {
        $raw = (string) file_get_contents(__DIR__.'/../../../fixtures/csaf/bsi_fortios_multi_range.json');
        $advisory = $this->parser->parse('bsi_wid', 'BSI WID', $raw);

        $affectedIds = $advisory->vulnerabilities[0]->knownAffectedProductIds;
        self::assertCount(3, $affectedIds);
        $ranges = [];
        foreach ($affectedIds as $id) {
            $p = $advisory->productsById[$id];
            self::assertSame('fortinet', $p->vendorToken);
            self::assertSame('fortios', $p->productToken);
            $ranges[] = $p->range->describe();
        }
        self::assertSame(['<7.2.12', '<7.4.9', '<7.6.4'], $ranges);

        $v76 = ComparableVersion::fromString('7.6');
        $v763 = ComparableVersion::fromString('7.6.3');
        $v765 = ComparableVersion::fromString('7.6.5');
        $v74 = ComparableVersion::fromString('7.4');
        self::assertNotNull($v76);
        self::assertNotNull($v763);
        self::assertNotNull($v765);
        self::assertNotNull($v74);
        $meets = static function (CsafAdvisory $a, ComparableVersion $v): bool {
            foreach ($a->vulnerabilities[0]->knownAffectedProductIds as $id) {
                if ($a->productsById[$id]->range->intersects($v)) {
                    return true;
                }
            }

            return false;
        };
        self::assertTrue($meets($advisory, $v76));
        self::assertTrue($meets($advisory, $v763));
        self::assertFalse($meets($advisory, $v765), '7.6.5 is past every bound');
        // 7.4 lies under <7.4.9 (and, read as a plain comparator, under <7.6.4 as well): the
        // document as a whole does affect 7.4.x. The acceptance criterion in #134 ("not 7.4") only
        // holds for a document that carries the 7.6 node alone — see the plan's D5: comparators
        // are read as written, no branch semantics are inferred.
        self::assertTrue($meets($advisory, $v74));
    }

    /**
     * SICK sca-2026-0013 (product tree as published, prose synthetic): two products, zero CPE, range `<=2026.1.0`. Before #134 the
     * importer could never match anything from this provider.
     */
    public function testSickDocumentWithoutAnyCpeIsMatchable(): void
    {
        $raw = (string) file_get_contents(__DIR__.'/../../../fixtures/csaf/sick_corivaengine_no_cpe.json');
        $advisory = $this->parser->parse('sick', 'SICK', $raw);

        $affected = $advisory->productsById['CSAFPID-51000'];
        self::assertNull($affected->cpe);
        self::assertSame('sick_ag', $affected->vendorToken);
        self::assertSame('corivaengine', $affected->productToken);
        self::assertSame('<=2026.1.0', $affected->range->describe());

        $fixed = $advisory->productsById['CSAFPID-52000'];
        self::assertSame('>=2026.1.1 <2026.1.2', $fixed->range->describe());
        self::assertContains('CSAFPID-52000', $advisory->vulnerabilities[0]->fixedProductIds);
    }

    /**
     * Siemens SSA-019113 (product tree as published, prose synthetic): canonical `vers:intdot/` ranges, zero CPE, product names
     * carrying model numbers.
     */
    public function testSiemensDocumentParsesCanonicalVersRanges(): void
    {
        // Siemens published this document with tracking.status "interim"; the fixture keeps
        // that verbatim. The parser skips interim documents by contract, so the test flips the
        // status on the decoded array — product_tree and vulnerabilities are untouched.
        $data = json_decode((string) file_get_contents(__DIR__.'/../../../fixtures/csaf/siemens_s7_1500_vers_range.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $data['document']['tracking']['status'] = 'final';
        $advisory = $this->parser->parse('siemens', 'Siemens', $data);

        $p = $advisory->productsById['2'] ?? null;
        $open = $advisory->productsById['1'] ?? null;
        self::assertNotNull($p);
        self::assertNotNull($open);
        self::assertSame('siemens', $p->vendorToken);
        self::assertSame('simatic_s7_1500_cpu_1518_4_pn_dp_mfp_6es7518_4ax00_1ab0', $p->productToken);
        self::assertSame('vers:intdot/>=3.1.6|<3.1.7', $p->rawVersionText);
        self::assertSame('>=3.1.6 <3.1.7', $p->range->describe());
        self::assertSame('>=3.1.6', $open->range->describe());
    }

    public function testRelationshipProductsAreNotFlattened(): void
    {
        $raw = (string) json_encode([
            'document' => [
                'csaf_version' => '2.0',
                'title' => 'Package on platform',
                'tracking' => ['id' => 'RH-1', 'version' => '1', 'status' => 'final', 'initial_release_date' => '2026-05-01T00:00:00Z'],
            ],
            'product_tree' => [
                'branches' => [[
                    'category' => 'vendor', 'name' => 'Red Hat',
                    'branches' => [
                        ['category' => 'product_name', 'name' => 'Red Hat Enterprise Linux 9', 'product' => ['product_id' => 'RHEL9', 'name' => 'RHEL 9']],
                        ['category' => 'product_version', 'name' => 'openssl-3.0.7', 'product' => ['product_id' => 'PKG', 'name' => 'openssl-3.0.7']],
                    ],
                ]],
                'relationships' => [[
                    'category' => 'default_component_of',
                    'product_reference' => 'PKG',
                    'relates_to_product_reference' => 'RHEL9',
                    'full_product_name' => ['product_id' => 'PKG:RHEL9', 'name' => 'openssl on RHEL 9'],
                ]],
            ],
            'vulnerabilities' => [['cve' => 'CVE-2026-1', 'product_status' => ['known_affected' => ['PKG:RHEL9']]]],
        ]);
        $advisory = $this->parser->parse('redhat', 'Red Hat', $raw);

        self::assertArrayNotHasKey('PKG:RHEL9', $advisory->productsById, 'D9: relationship products are out of matching scope');
        self::assertArrayHasKey('RHEL9', $advisory->productsById);
        self::assertTrue($advisory->productsById['RHEL9']->range->isUnbounded(), 'a product_name leaf states no version');
        self::assertNull($advisory->productsById['RHEL9']->range->parseFailure);
    }

    public function testFullProductNameWithCpeUsesCpeVersion(): void
    {
        $raw = (string) json_encode([
            'document' => [
                'csaf_version' => '2.0',
                'title' => 'Flat',
                'tracking' => ['id' => 'F-1', 'version' => '1', 'status' => 'final', 'initial_release_date' => '2026-05-01T00:00:00Z'],
            ],
            'product_tree' => [
                'full_product_names' => [
                    ['product_id' => 'A', 'name' => 'Log4j 2.14.1', 'product_identification_helper' => ['cpe' => 'cpe:2.3:a:apache:log4j:2.14.1:*:*:*:*:*:*:*']],
                    ['product_id' => 'B', 'name' => 'No identity'],
                ],
            ],
        ]);
        $advisory = $this->parser->parse('p', 'P', $raw);

        self::assertSame('>=2.14.1 <2.14.2', $advisory->productsById['A']->range->describe());
        self::assertArrayNotHasKey('B', $advisory->productsById);
    }

    public function testDocumentNotesExcludeLegalDisclaimerAndProductDescription(): void
    {
        $raw = (string) file_get_contents(__DIR__.'/../../../fixtures/csaf/bsi_single_edition_range_example.json');
        $advisory = $this->parser->parse('bsi_wid', 'BSI WID', $raw);

        $titles = array_column($advisory->documentNotes, 'title');
        self::assertContains('Angriff', $titles, 'summary-category note must survive the filter.');
        self::assertNotContains('Disclaimer', $titles, 'legal_disclaimer must be excluded.');
        self::assertNotContains('Produktbeschreibung', $titles, 'Product description is redundant with the software inventory and must be dropped.');
    }

    public function testUnsupportedCsafVersionThrows(): void
    {
        $raw = json_encode([
            'document' => [
                'csaf_version' => '1.0',
                'title' => 'foo',
                'tracking' => ['id' => 'A', 'version' => '1', 'status' => 'final', 'initial_release_date' => '2026-01-01T00:00:00Z'],
            ],
        ]);
        self::assertIsString($raw);
        $this->expectException(CsafParseException::class);
        $this->parser->parse('p', 'P', $raw);
    }
}

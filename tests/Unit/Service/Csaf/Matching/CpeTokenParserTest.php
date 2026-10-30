<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Unit\Service\Csaf\Matching;

use App\Service\Csaf\Matching\CpeTokenParser;
use PHPUnit\Framework\TestCase;

final class CpeTokenParserTest extends TestCase
{
    private CpeTokenParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CpeTokenParser();
    }

    public function testCpe22(): void
    {
        self::assertSame(
            ['vendor' => 'fortinet', 'product' => 'fortios', 'version' => '7.6.4'],
            $this->parser->parseTokens('cpe:/o:fortinet:fortios:7.6.4'),
        );
    }

    public function testCpe23(): void
    {
        self::assertSame(
            ['vendor' => 'apache', 'product' => 'log4j', 'version' => '2.14.1'],
            $this->parser->parseTokens('cpe:2.3:a:apache:log4j:2.14.1:*:*:*:*:*:*:*'),
        );
    }

    public function testThePartIsNeverReturned(): void
    {
        // F5: a part typo (`a` for an OS) used to hide a product silently. The part is not compared.
        self::assertSame(
            $this->parser->parseTokens('cpe:/a:fortinet:fortios:7.6.3'),
            $this->parser->parseTokens('cpe:/o:fortinet:fortios:7.6.3'),
        );
    }

    public function testWildcardOrEmptyVersionIsNull(): void
    {
        foreach (['cpe:/o:fortinet:fortios:', 'cpe:2.3:o:fortinet:fortios:*:*:*:*:*:*:*:*', 'cpe:2.3:o:fortinet:fortios:-:*:*:*:*:*:*:*'] as $cpe) {
            $tokens = $this->parser->parseTokens($cpe);
            self::assertNotNull($tokens, $cpe);
            self::assertNull($tokens['version'], $cpe);
        }
    }

    public function testEscapedCharactersAreUnescapedAndNormalised(): void
    {
        self::assertSame(
            ['vendor' => 'microsoft', 'product' => 'net_framework', 'version' => '4.8'],
            $this->parser->parseTokens('cpe:2.3:a:microsoft:\\.net_framework:4.8:*:*:*:*:*:*:*'),
        );
    }

    public function testCpeWithoutIdentityIsNull(): void
    {
        self::assertNull($this->parser->parseTokens('cpe:2.3:a:*:*:1.0:*:*:*:*:*:*:*'));
        self::assertNull($this->parser->parseTokens('cpe:/a::product:1.0'));
        self::assertNull($this->parser->parseTokens('not a cpe'));
        self::assertNull($this->parser->parseTokens(''));
    }
}

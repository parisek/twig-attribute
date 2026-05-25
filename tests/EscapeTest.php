<?php

declare(strict_types=1);

namespace Parisek\Twig\Tests;

use Parisek\Twig\Internal\Escape;
use PHPUnit\Framework\TestCase;

final class EscapeTest extends TestCase
{
    public function testEscapesQuotesAndAngles(): void
    {
        $input = '<a href="x">&"\'</a>';
        $expected = '&lt;a href=&quot;x&quot;&gt;&amp;&quot;&#039;&lt;/a&gt;';
        self::assertSame($expected, Escape::html($input));
    }

    public function testSubstitutesInvalidUtf8(): void
    {
        // Raw 0xC3 0x28 — invalid UTF-8 sequence.
        $input = "\xC3\x28";
        // ENT_SUBSTITUTE replaces the bad byte sequence with U+FFFD.
        self::assertSame("\u{FFFD}(", Escape::html($input));
    }

    public function testMatchesHtmlspecialcharsBaseline(): void
    {
        $cases = ['', 'plain', '"', "'", '<>&', "tab\there", "líne\nbreak"];
        foreach ($cases as $case) {
            self::assertSame(
                htmlspecialchars($case, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                Escape::html($case),
                sprintf('byte-match failed for %s', var_export($case, true)),
            );
        }
    }
}

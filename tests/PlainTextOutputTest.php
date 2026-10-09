<?php

declare(strict_types=1);

namespace Parisek\Twig\Tests;

use Parisek\Twig\Internal\PlainTextOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ported from Drupal core's PlainTextOutputTest
 * (core/tests/Drupal/Tests/Component/Render/PlainTextOutputTest.php, 11.x).
 * The upstream test builds its input with FormattableMarkup and Prophecy,
 * which this package does not carry. The inputs are the already-formatted strings.
 */
final class PlainTextOutputTest extends TestCase
{
    #[DataProvider('renderFromHtmlProvider')]
    public function testRenderFromHtml(string $expected, string $input): void
    {
        self::assertSame($expected, PlainTextOutput::renderFromHtml($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function renderFromHtmlProvider(): array
    {
        return [
            // Ported from upstream.
            'simple-text' => ['Giraffes and wombats', 'Giraffes and wombats'],
            'simple-html' => ['Giraffes and wombats', '<a href="/muh">Giraffes</a> and <strong>wombats</strong>'],
            'html-with-quote' => ['Giraffes and quote"s', '<a href="/muh">Giraffes</a> and <strong>quote"s</strong>'],
            'escaped-html-with-quotes' => [
                'The <em> tag makes your text look like "this".',
                'The &lt;em&gt; tag makes your text look like <em>"this"</em>.',
            ],
            // The upstream placeholder case, after FormattableMarkup replaced the placeholders.
            'escaped-html-with-quotes-and-placeholders' => [
                'The <em> tag makes your text look like "this".',
                'The &lt;em&gt; tag makes your text look like <em>"this"</em>.',
            ],
            // Package cases.
            'empty string' => ['', ''],
            'entities are decoded' => ['Tom & Jerry', 'Tom &amp; Jerry'],
            'single quote entity' => ["it's", 'it&#039;s'],
            // PHP default, not contract: HTML 4.01 flags have no &apos; entity.
            'PHP default, not contract: apos entity is kept' => ['it&apos;s', 'it&apos;s'],
            'numeric hex entity' => ['A', '&#x41;'],
            'non-breaking space entity' => ["a\u{A0}b", 'a&nbsp;b'],
            'unknown entity is kept' => ['&bogus;', '&bogus;'],
            'bare ampersand is kept' => ['a & b', 'a & b'],
            'comment is removed' => ['ab', 'a<!-- hidden -->b'],
            'tag with attributes is removed' => ['x', '<span class="a" data-x=\'b\'>x</span>'],
            'script tags go, script text stays' => ['alert(1)', '<script>alert(1)</script>'],
            'less-than followed by space is text' => ['a < b', 'a < b'],
            'multibyte text survives' => ['Příliš žluťoučký kůň', '<b>Příliš</b> žluťoučký kůň'],
            'tags are stripped before entities are decoded' => ['<script>', '&lt;script&gt;'],
            'double-encoded entity is decoded once' => ['&amp;', '&amp;amp;'],
        ];
    }

    public function testAcceptsStringableObject(): void
    {
        $input = new class implements \Stringable {
            public function __toString(): string
            {
                return '<em>&quot;this&quot;</em>';
            }
        };

        self::assertSame('"this"', PlainTextOutput::renderFromHtml($input));
    }

    public function testStrictTypesRejectNonStringInput(): void
    {
        $this->expectException(\TypeError::class);

        PlainTextOutput::renderFromHtml(123);
    }
}

<?php

declare(strict_types=1);

namespace Parisek\Twig\Tests;

use Parisek\Twig\Internal\Escape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EscapeEdgeCasesTest extends TestCase
{
    #[DataProvider('cases')]
    public function testEscape(string $expected, string $input): void
    {
        self::assertSame($expected, Escape::html($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cases(): array
    {
        return [
            'empty string' => ['', ''],
            'plain text is unchanged' => ['plain text 123', 'plain text 123'],
            'all five special characters' => ['&amp;&lt;&gt;&quot;&#039;', '&<>"\''],
            'existing entity is escaped again' => ['&amp;amp; &amp;lt; &amp;#039;', '&amp; &lt; &#039;'],
            'numeric entity is escaped again' => ['&amp;#x41;', '&#x41;'],
            'tag with attribute' => ['&lt;a href=&quot;x&quot;&gt;', '<a href="x">'],
            'event handler breakout' => ['&quot; onmouseover=&quot;alert(1)', '" onmouseover="alert(1)'],
            'NUL byte is kept' => ["a\0b", "a\0b"],
            'NUL byte next to a special character' => ["&lt;\0&gt;", "<\0>"],
            'invalid UTF-8 byte becomes U+FFFD' => ["a\u{FFFD}b", "a\xFFb"],
            'truncated multibyte sequence becomes U+FFFD' => ["a\u{FFFD}", "a\xE2\x82"],
            'overlong encoding becomes U+FFFD' => ["\u{FFFD}\u{FFFD}", "\xC0\xAF"],
            'invalid byte does not stop escaping' => ["\u{FFFD}&lt;b&gt;", "\xFF<b>"],
            'valid multibyte text is unchanged' => ['Příliš žluťoučký kůň €', 'Příliš žluťoučký kůň €'],
            'emoji is unchanged' => ["\u{1F600}", "\u{1F600}"],
            'newline and tab are kept' => ["a\n\tb", "a\n\tb"],
            'non-breaking space is kept' => ["a\u{A0}b", "a\u{A0}b"],
        ];
    }

    public function testEscapingTwiceEscapesAgain(): void
    {
        // Escaping is not idempotent on purpose. The second pass escapes the ampersands.
        $once = Escape::html('<&>');

        self::assertSame('&lt;&amp;&gt;', $once);
        self::assertSame('&amp;lt;&amp;amp;&amp;gt;', Escape::html($once));
    }
}

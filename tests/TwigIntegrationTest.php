<?php

declare(strict_types=1);

namespace Parisek\Twig\Tests;

use Drupal\Component\Attribute\AttributeCollection;
use Parisek\Twig\AttributeExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * End-to-end tests through the Twig extension.
 *
 * Twig compiles a template by name and source, so every mode gets its own
 * template name. Each test builds its own Environment, so the order of tests
 * does not matter.
 */
final class TwigIntegrationTest extends TestCase
{
    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context = [], string|false $autoescape = 'html'): string
    {
        $name = 'integration-' . (false === $autoescape ? 'raw' : $autoescape) . '.twig';
        $twig = new Environment(new ArrayLoader([$name => $template]), ['autoescape' => $autoescape]);
        $twig->addExtension(new AttributeExtension());
        // The documented contract: declare the safe class before rendering.
        AttributeExtension::registerSafeClass($twig);

        return $twig->render($name, $context);
    }

    /**
     * @return array<string, array{0: string|false}>
     */
    public static function escapeModes(): array
    {
        return [
            'autoescape html' => ['html'],
            'autoescape off' => [false],
        ];
    }

    #[DataProvider('escapeModes')]
    public function testQuotesAndAngleBracketsInValuesAreEscapedOnce(string|false $mode): void
    {
        $output = $this->render(
            '<a{{ create_attribute({"title": "say \"hi\" <b>&", "data-x": "it\'s"}) }}>',
            [],
            $mode,
        );

        self::assertSame('<a title="say &quot;hi&quot; &lt;b&gt;&amp;" data-x="it&#039;s">', $output);
    }

    #[DataProvider('escapeModes')]
    public function testAttributeBreakoutInValueStaysInsideTheQuotes(string|false $mode): void
    {
        $output = $this->render('<a{{ create_attribute({"title": "x\" onclick=\"alert(1)"}) }}>', [], $mode);

        self::assertSame('<a title="x&quot; onclick=&quot;alert(1)">', $output);
        self::assertStringNotContainsString('" onclick="', $output);
    }

    #[DataProvider('escapeModes')]
    public function testStringableValueIsConvertedToPlainTextAndEscaped(string|false $mode): void
    {
        $value = new class implements \Stringable {
            public function __toString(): string
            {
                return '<em>Tom &amp; "Jerry"</em>';
            }
        };

        $output = $this->render('<a{{ create_attribute({"title": value}) }}>', ['value' => $value], $mode);

        self::assertSame('<a title="Tom &amp; &quot;Jerry&quot;">', $output);
    }

    #[DataProvider('escapeModes')]
    public function testScalarValuesAndBooleanAttributes(string|false $mode): void
    {
        $output = $this->render(
            '<input{{ create_attribute({"disabled": true, "hidden": false, "checked": true, "tabindex": 0, "alt": ""}) }}>',
            [],
            $mode,
        );

        self::assertSame('<input disabled checked tabindex="0" alt="">', $output);
    }

    #[DataProvider('escapeModes')]
    public function testMergingTwoCollections(string|false $mode): void
    {
        $template = '{% set a = create_attribute({"id": "one", "class": ["x"], "data-a": "1"}) %}'
            . '{% set b = create_attribute({"id": "two", "class": ["y", "x"], "data-b": "2"}) %}'
            . '<p{{ a.merge(b) }}>';

        $output = $this->render($template, [], $mode);

        // Later scalar wins, class lists are appended and then de-duplicated.
        self::assertSame('<p id="two" class="x y" data-a="1" data-b="2">', $output);
    }

    public function testMergeInPhpLeavesTheOtherCollectionUntouched(): void
    {
        $a = new AttributeCollection(['class' => ['x']]);
        $b = new AttributeCollection(['class' => ['y']]);

        $a->merge($b);
        $a->addClass('z');

        self::assertSame(['class' => ['x', 'y', 'z']], $a->toArray());
        self::assertSame(['class' => ['y']], $b->toArray());
    }

    #[DataProvider('escapeModes')]
    public function testAddClassWithSeveralListsAndStrings(string|false $mode): void
    {
        $template = '<div{{ create_attribute({"class": ["a"]}).addClass(["b", "c"], "d", ["a", "e"]) }}>';

        self::assertSame('<div class="a b c d e">', $this->render($template, [], $mode));
    }

    public function testAddClassCreatesTheClassAttribute(): void
    {
        $output = $this->render('<div{{ create_attribute().addClass(["b", "c"], "d") }}>');

        self::assertSame('<div class="b c d">', $output);
    }

    public function testAddClassEscapesClassNames(): void
    {
        $output = $this->render('<div{{ create_attribute().addClass("a\"b", "<c>") }}>');

        self::assertSame('<div class="a&quot;b &lt;c&gt;">', $output);
    }

    public function testRemoveClassAndHasClassInTemplate(): void
    {
        $template = '{% set a = create_attribute({"class": ["a", "b", "c"]}).removeClass("b") %}'
            . '{{ a.hasClass("b") ? "yes" : "no" }}|{{ a.hasClass("c") ? "yes" : "no" }}|<p{{ a }}>';

        self::assertSame('no|yes|<p class="a c">', $this->render($template));
    }

    #[DataProvider('escapeModes')]
    public function testPrintingTheCollectionTwiceGivesTheSameOutput(string|false $mode): void
    {
        $template = '{% set a = create_attribute({"class": ["x", "", "x", "y"], "title": "<t>"}) %}'
            . '[{{ a }}][{{ a }}]';

        $expected = ' class="x y" title="&lt;t&gt;"';

        self::assertSame('[' . $expected . '][' . $expected . ']', $this->render($template, [], $mode));
    }

    public function testPrintingTheSameCollectionFromContextTwice(): void
    {
        $collection = new AttributeCollection(['class' => ['x', 'x'], 'title' => '"q"']);

        $output = $this->render('{{ a }}|{{ a }}', ['a' => $collection]);

        self::assertSame(' class="x" title="&quot;q&quot;"| class="x" title="&quot;q&quot;"', $output);
    }

    #[DataProvider('escapeModes')]
    public function testWithoutOnAListOfNames(string|false $mode): void
    {
        $template = '{% set a = create_attribute({"id": "i", "class": ["c"], "data-x": "y", "title": "t"}) %}'
            . '<p{{ a|without(["class", "id"]) }}>|<p{{ a|without("title", ["data-x"]) }}>|<p{{ a }}>';

        $output = $this->render($template, [], $mode);

        self::assertSame(
            '<p data-x="y" title="t">|<p id="i" class="c">|<p id="i" class="c" data-x="y" title="t">',
            $output,
        );
    }

    public function testWithoutOnAListIgnoresUnknownNames(): void
    {
        $output = $this->render('<p{{ create_attribute({"id": "i"})|without(["nope", "id"]) }}>');

        self::assertSame('<p>', $output);
    }

    public function testWithoutOnAPlainArray(): void
    {
        $output = $this->render('{{ {"a": 1, "b": 2, "c": 3}|without(["a", "c"])|json_encode|raw }}');

        self::assertSame('{"b":2}', $output);
    }

    public function testCollectionFromContextIsNotEscapedAgainAfterRegistration(): void
    {
        $collection = new AttributeCollection(['title' => '<t>', 'hidden' => true]);

        self::assertSame('<p title="&lt;t&gt;" hidden>', $this->render('<p{{ a }}>', ['a' => $collection]));
    }

    public function testAutoescapeModeReachesTheHelper(): void
    {
        // Control for the paired cases above: the same helper must honour the mode.
        $context = ['s' => 'a < b & c'];

        self::assertSame('a &lt; b &amp; c', $this->render('{{ s }}', $context, 'html'));
        self::assertSame('a < b & c', $this->render('{{ s }}', $context, false));
    }

    public function testPlainStringsAreStillEscaped(): void
    {
        $output = $this->render('{{ s }}', ['s' => '<b title="x">']);

        self::assertSame('&lt;b title=&quot;x&quot;&gt;', $output);
    }

    public function testSetAttributeAndRemoveAttributeInTemplate(): void
    {
        $template = '<p{{ create_attribute({"id": "a"}).setAttribute("title", "t").setAttribute("hidden", true).removeAttribute("id") }}>';

        self::assertSame('<p title="t" hidden>', $this->render($template));
    }

    public function testIterationInTemplate(): void
    {
        $template = '{% for name, value in create_attribute({"id": "a", "class": ["b", "c"]}) %}{{ name }}={{ value.value|join(",") }};{% endfor %}';

        self::assertSame('id=a;class=b,c;', $this->render($template));
    }
}

<?php

declare(strict_types=1);

namespace Parisek\Twig\Tests;

use Drupal\Component\Attribute\AttributeCollection;
use Parisek\Twig\AttributeExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class WithoutFilterTest extends TestCase
{
    private function attributes(): AttributeCollection
    {
        return new AttributeCollection([
            'id' => 'socks',
            'class' => ['black-cat', 'white-cat'],
            'data-x' => 'y',
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context = [], string|false $autoescape = false): string
    {
        $twig = new Environment(new ArrayLoader(['t.twig' => $template]), ['autoescape' => $autoescape]);
        $twig->addExtension(new AttributeExtension());

        return $twig->render('t.twig', $context);
    }

    public function testFilterIsRegistered(): void
    {
        $names = array_map(
            static fn($filter) => $filter->getName(),
            (new AttributeExtension())->getFilters(),
        );

        self::assertContains('without', $names);
    }

    public function testRemovesOneName(): void
    {
        $filtered = (new AttributeExtension())->withoutFilter($this->attributes(), 'class');

        self::assertInstanceOf(AttributeCollection::class, $filtered);
        self::assertSame(['id' => 'socks', 'data-x' => 'y'], $filtered->toArray());
    }

    public function testRemovesSeveralNames(): void
    {
        $filtered = (new AttributeExtension())->withoutFilter($this->attributes(), 'class', 'id');

        self::assertSame(['data-x' => 'y'], $filtered->toArray());
    }

    public function testRemovesNamesGivenAsList(): void
    {
        $filtered = (new AttributeExtension())->withoutFilter($this->attributes(), ['class', 'id']);

        self::assertSame(['data-x' => 'y'], $filtered->toArray());
    }

    public function testRemovesNamesGivenAsMixOfStringsAndLists(): void
    {
        $filtered = (new AttributeExtension())->withoutFilter($this->attributes(), 'id', ['class', ['data-x']]);

        self::assertSame([], $filtered->toArray());
    }

    public function testDoesNotMutateTheOriginalCollection(): void
    {
        $original = $this->attributes();
        $before = (string) $original;

        $filtered = (new AttributeExtension())->withoutFilter($original, 'class', 'id');

        self::assertNotSame($original, $filtered);
        self::assertSame($before, (string) $original);
        self::assertTrue($original->hasAttribute('class'));
        self::assertTrue($original->hasAttribute('id'));
    }

    public function testFilteredCopyDoesNotShareClassStorageWithTheOriginal(): void
    {
        $original = $this->attributes();

        $filtered = (new AttributeExtension())->withoutFilter($original, 'id');
        $filtered->addClass('extra');

        self::assertFalse($original->hasClass('extra'));
        self::assertTrue($filtered->hasClass('extra'));
    }

    public function testIgnoresUnknownKey(): void
    {
        $filtered = (new AttributeExtension())->withoutFilter($this->attributes(), 'missing', 'class');

        self::assertSame(['id' => 'socks', 'data-x' => 'y'], $filtered->toArray());
    }

    public function testWithoutKeysReturnsEqualCopy(): void
    {
        $original = $this->attributes();

        $filtered = (new AttributeExtension())->withoutFilter($original);

        self::assertNotSame($original, $filtered);
        self::assertEquals($original, $filtered);
        self::assertSame((string) $original, (string) $filtered);
    }

    public function testFiltersPlainArray(): void
    {
        $original = ['class' => ['a'], 'id' => 'x', 'title' => 't'];

        $filtered = (new AttributeExtension())->withoutFilter($original, 'class', ['title']);

        self::assertSame(['id' => 'x'], $filtered);
        self::assertSame(['class' => ['a'], 'id' => 'x', 'title' => 't'], $original);
    }

    public function testPlainArrayWithUnknownKeyAndNoKeys(): void
    {
        $extension = new AttributeExtension();

        self::assertSame(['a' => 1], $extension->withoutFilter(['a' => 1], 'b'));
        self::assertSame(['a' => 1], $extension->withoutFilter(['a' => 1]));
    }

    public function testRendersWithoutOneName(): void
    {
        $output = $this->render(
            '<cat{{ attributes|without("class") }}></cat>',
            ['attributes' => $this->attributes()],
        );

        self::assertSame('<cat id="socks" data-x="y"></cat>', $output);
    }

    public function testRendersWithoutSeveralNames(): void
    {
        $output = $this->render(
            '<cat{{ attributes|without("class", "id") }}></cat>',
            ['attributes' => $this->attributes()],
        );

        self::assertSame('<cat data-x="y"></cat>', $output);
    }

    public function testRendersWithoutListOfNames(): void
    {
        $output = $this->render(
            '<cat{{ attributes|without(["class", "id"]) }}></cat>',
            ['attributes' => $this->attributes()],
        );

        self::assertSame('<cat data-x="y"></cat>', $output);
    }

    public function testTemplateKeepsOriginalUsable(): void
    {
        $output = $this->render(
            '<cat class="{{ attributes.class }} mine"{{ attributes|without("class") }}></cat>{{ attributes|length }}',
            ['attributes' => $this->attributes()],
        );

        self::assertSame('<cat class="black-cat white-cat mine" id="socks" data-x="y"></cat>3', $output);
    }

    public function testWorksOnCreateAttributeResult(): void
    {
        $output = $this->render('<cat{{ create_attribute({"class": ["a"], "id": "i"})|without("class") }}></cat>');

        self::assertSame('<cat id="i"></cat>', $output);
    }

    public function testEscapingIsUnchanged(): void
    {
        $attributes = new AttributeCollection([
            'title' => '<b>"x" & y</b>',
            'class' => ['drop'],
        ]);

        $output = $this->render('<cat{{ attributes|without("class") }}></cat>', ['attributes' => $attributes]);

        self::assertSame(
            '<cat title="&lt;b&gt;&quot;x&quot; &amp; y&lt;/b&gt;"></cat>',
            $output,
        );
        self::assertSame(
            ' title="&lt;b&gt;&quot;x&quot; &amp; y&lt;/b&gt;"',
            (string) (new AttributeExtension())->withoutFilter($attributes, 'class'),
        );
    }

    public function testStringWithoutKeysStaysHtmlEscaped(): void
    {
        $payload = '<img src=x onerror=alert(1)>';

        self::assertSame(
            '&lt;img src=x onerror=alert(1)&gt;',
            $this->render('{{ val|without }}', ['val' => $payload], 'html'),
        );
    }

    public function testUserDefinedStringableStaysHtmlEscaped(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return '<b>x</b>';
            }
        };

        self::assertSame(
            '&lt;b&gt;x&lt;/b&gt;',
            $this->render('{{ val|without }}', ['val' => $stringable], 'html'),
        );
    }

    public function testCollectionRendersRawWhenAutoescapeIsOff(): void
    {
        self::assertSame(
            ' id="socks" data-x="y"',
            $this->render('{{ attributes|without("class") }}', ['attributes' => $this->attributes()], false),
        );
    }

    public function testCollectionIsEscapedLikeThePlainVariableWhenAutoescapeIsOn(): void
    {
        $context = ['attributes' => $this->attributes()];

        self::assertSame(
            $this->render('{{ attributes }}', $context, 'html'),
            $this->render('{{ attributes|without }}', $context, 'html'),
        );
    }
}

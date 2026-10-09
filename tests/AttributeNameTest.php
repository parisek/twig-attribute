<?php

declare(strict_types=1);

namespace Parisek\Twig\Tests;

use Drupal\Component\Attribute\AttributeCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttributeNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validNames(): iterable
    {
        foreach (['id', 'class', 'data-id', 'aria-label', '@click', ':class', 'x-on:click.prevent', '[hidden]', '(click)', '*ngIf', '#ref', 'v-bind:foo', 'x_y', 'ÄÖ'] as $name) {
            yield $name => [$name];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['x onmouseover'];
        yield 'tab' => ["x\tonmouseover"];
        yield 'newline' => ["x\nonmouseover"];
        yield 'carriage return' => ["x\ronmouseover"];
        yield 'form feed' => ["x\x0Conmouseover"];
        yield 'equals' => ['a=b'];
        yield 'double quote' => ['a"b'];
        yield 'single quote' => ["a'b"];
        yield 'less than' => ['a<b'];
        yield 'greater than' => ['a>b'];
        yield 'slash' => ['a/b'];
        yield 'NUL' => ["a\0b"];
        yield 'control character' => ["a\x01b"];
        yield 'DEL' => ["a\x7Fb"];
        yield 'injection' => ['a="1" onclick'];
    }

    #[DataProvider('validNames')]
    public function testValidNameIsAccepted(string $name): void
    {
        $collection = new AttributeCollection([$name => 'v']);

        self::assertTrue($collection->hasAttribute($name));
        self::assertStringContainsString(' ' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '="v"', (string) $collection);
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNameIsRejectedByTheConstructor(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AttributeCollection([$name => 'v']);
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNameIsRejectedBySetAttribute(string $name): void
    {
        $collection = new AttributeCollection(['id' => 'a']);

        try {
            $collection->setAttribute($name, 'v');
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
            self::assertSame(['id' => 'a'], $collection->toArray());
        }
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNameIsRejectedByArrayAccess(string $name): void
    {
        $collection = new AttributeCollection();

        $this->expectException(\InvalidArgumentException::class);

        $collection[$name] = 'v';
    }

    public function testIntegerNamesFromAListStayValid(): void
    {
        $collection = new AttributeCollection(['a', 'b']);

        self::assertSame([0 => 'a', 1 => 'b'], $collection->toArray());
    }

    public function testMessageNamesTheNameAndStaysShort(): void
    {
        try {
            new AttributeCollection([str_repeat('x', 100) . ' onclick' => 'v']);
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Invalid attribute name', $e->getMessage());
            self::assertStringNotContainsString(str_repeat('x', 41), $e->getMessage());
        }
    }

    public function testMessageDoesNotEchoControlBytes(): void
    {
        try {
            new AttributeCollection(["a\0b\x1b[31m" => 'v']);
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B-\x1F]/', $e->getMessage());
        }
    }

    public function testNamesThatAreNotStringsKeepTheUpstreamBehaviour(): void
    {
        self::assertSame(5, \Parisek\Twig\Internal\AttributeName::assertValid(5));
        self::assertNull(\Parisek\Twig\Internal\AttributeName::assertValid(null));
        self::assertSame(1.5, \Parisek\Twig\Internal\AttributeName::assertValid(1.5));
    }
}

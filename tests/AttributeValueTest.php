<?php

declare(strict_types=1);

namespace Parisek\Twig\Tests;

use Drupal\Component\Attribute\AttributeArray;
use Drupal\Component\Attribute\AttributeBoolean;
use Drupal\Component\Attribute\AttributeCollection;
use Drupal\Component\Attribute\AttributeString;
use Drupal\Component\Attribute\AttributeValueBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Direct tests for the value classes. AttributeTest.php covers them only
 * through AttributeCollection.
 */
final class AttributeValueTest extends TestCase
{
    // AttributeValueBase

    public function testBaseDefaultsToRenderingEmptyAttributes(): void
    {
        self::assertTrue(AttributeValueBase::RENDER_EMPTY_ATTRIBUTE);
        self::assertTrue(AttributeString::RENDER_EMPTY_ATTRIBUTE);
        self::assertTrue(AttributeBoolean::RENDER_EMPTY_ATTRIBUTE);
        self::assertFalse(AttributeArray::RENDER_EMPTY_ATTRIBUTE);
    }

    public function testValuesAreStringable(): void
    {
        self::assertInstanceOf(\Stringable::class, new AttributeString('a', 'b'));
        self::assertInstanceOf(\Stringable::class, new AttributeArray('a', ['b']));
        self::assertInstanceOf(\Stringable::class, new AttributeBoolean('a', true));
    }

    public function testValueReturnsTheRawValueWithoutEscaping(): void
    {
        self::assertSame('<b>"x"</b>', (new AttributeString('title', '<b>"x"</b>'))->value());
        self::assertSame(['a', 'b'], (new AttributeArray('class', ['a', 'b']))->value());
        self::assertTrue((new AttributeBoolean('disabled', true))->value());
        self::assertNull((new AttributeString('title', null))->value());
    }

    public function testStringCastAndRenderDifferByTheName(): void
    {
        $value = new AttributeString('title', 'hello');

        self::assertSame('hello', (string) $value);
        self::assertSame('title="hello"', $value->render());
    }

    /**
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function stringRenderProvider(): array
    {
        return [
            'text' => ['hello', 'title="hello"'],
            'empty string keeps the attribute' => ['', 'title=""'],
            'zero string' => ['0', 'title="0"'],
            'integer' => [42, 'title="42"'],
            'integer zero' => [0, 'title="0"'],
            'float' => [1.5, 'title="1.5"'],
            // isset() is false for null and the cast string is empty, so nothing renders.
            'null renders nothing' => [null, null],
            'double quote' => ['say "hi"', 'title="say &quot;hi&quot;"'],
            'single quote' => ["it's", 'title="it&#039;s"'],
            'angle brackets' => ['<b>x</b>', 'title="&lt;b&gt;x&lt;/b&gt;"'],
            'ampersand' => ['a & b', 'title="a &amp; b"'],
            'existing entity is escaped again' => ['&amp;', 'title="&amp;amp;"'],
            'attribute breakout attempt' => ['x" onclick="alert(1)', 'title="x&quot; onclick=&quot;alert(1)"'],
            'invalid UTF-8' => ["a\xFFb", "title=\"a\u{FFFD}b\""],
        ];
    }

    #[DataProvider('stringRenderProvider')]
    public function testStringRender(mixed $value, ?string $expected): void
    {
        self::assertSame($expected, (new AttributeString('title', $value))->render());
    }

    public function testRenderEscapesTheName(): void
    {
        // The name is HTML-escaped but not validated. That is the current contract.
        self::assertSame('a&quot;b&lt;c="x"', (new AttributeString('a"b<c', 'x'))->render());
        self::assertSame('a&quot;b&lt;c="x"', (new AttributeArray('a"b<c', ['x']))->render());
    }

    public function testNameIsNotPartOfTheStringCast(): void
    {
        self::assertSame('x', (string) new AttributeString('a"b', 'x'));
    }

    public function testRenderOfNullValueIsNull(): void
    {
        self::assertNull((new AttributeString('title', null))->render());
    }

    // AttributeString

    public function testStringCastsNonStringScalars(): void
    {
        self::assertSame('1', (string) new AttributeString('n', 1));
        self::assertSame('1', (string) new AttributeString('n', true));
        self::assertSame('', (string) new AttributeString('n', false));
        self::assertSame('', (string) new AttributeString('n', null));
    }

    public function testStringEscapesOnEveryCast(): void
    {
        $value = new AttributeString('title', '<i>');

        self::assertSame('&lt;i&gt;', (string) $value);
        self::assertSame('&lt;i&gt;', (string) $value);
        self::assertSame('<i>', $value->value());
    }

    // AttributeBoolean

    public function testBooleanTrueRendersTheName(): void
    {
        $value = new AttributeBoolean('disabled', true);

        self::assertSame('disabled', $value->render());
        self::assertSame('disabled', (string) $value);
    }

    public function testBooleanFalseRendersEmptyString(): void
    {
        $value = new AttributeBoolean('disabled', false);

        self::assertSame('', $value->render());
        self::assertSame('', (string) $value);
    }

    public function testBooleanOnlyStrictFalseHidesTheAttribute(): void
    {
        // The check is `=== FALSE`, so other falsy values still print the name.
        self::assertSame('x', (new AttributeBoolean('x', 0))->render());
        self::assertSame('x', (new AttributeBoolean('x', null))->render());
        self::assertSame('x', (new AttributeBoolean('x', ''))->render());
    }

    public function testBooleanEscapesTheName(): void
    {
        self::assertSame('a&quot;b&lt;c&gt;&amp;', (new AttributeBoolean('a"b<c>&', true))->render());
    }

    public function testBooleanValueIsRawNotEscaped(): void
    {
        self::assertTrue((new AttributeBoolean('x', true))->value());
        self::assertFalse((new AttributeBoolean('x', false))->value());
    }

    // AttributeArray

    public function testArrayRendersSpaceSeparatedValues(): void
    {
        $value = new AttributeArray('class', ['a', 'b', 'c']);

        self::assertSame('a b c', (string) $value);
        self::assertSame('class="a b c"', $value->render());
    }

    public function testArrayRendersNothingWhenEmpty(): void
    {
        self::assertNull((new AttributeArray('class', []))->render());
        self::assertSame('', (string) new AttributeArray('class', []));
    }

    public function testArrayDropsEmptyAndFalsyEntries(): void
    {
        $value = new AttributeArray('class', ['a', '', null, false, 0, '0', 'b']);

        self::assertSame('a b', (string) $value);
    }

    public function testArrayRendersNothingWhenAllEntriesAreFalsy(): void
    {
        self::assertNull((new AttributeArray('class', ['', null, '0']))->render());
    }

    public function testArrayRemovesDuplicates(): void
    {
        self::assertSame('a b', (string) new AttributeArray('class', ['a', 'b', 'a', 'b']));
    }

    public function testArrayEscapesEachEntry(): void
    {
        $value = new AttributeArray('class', ['a"b', '<c>']);

        self::assertSame('class="a&quot;b &lt;c&gt;"', $value->render());
    }

    public function testArrayStringCastFiltersTheStoredValue(): void
    {
        $value = new AttributeArray('class', ['a', '', 'a', 'b']);

        (string) $value;

        // The cast writes the cleaned list back. array_unique keeps the first keys.
        self::assertSame([0 => 'a', 3 => 'b'], $value->value());
    }

    public function testArrayCanBePrintedTwiceWithTheSameResult(): void
    {
        $value = new AttributeArray('class', ['a', '', 'a', 'b']);

        self::assertSame($value->render(), $value->render());
        self::assertSame('class="a b"', $value->render());
    }

    public function testArrayAccessReadWriteAppendUnset(): void
    {
        $value = new AttributeArray('class', ['a']);

        $value[] = 'b';
        $value['k'] = 'c';

        self::assertSame('a', $value[0]);
        self::assertSame('b', $value[1]);
        self::assertSame('c', $value['k']);
        self::assertTrue(isset($value['k']));

        unset($value['k']);

        self::assertFalse(isset($value['k']));
        self::assertSame(['a', 'b'], $value->value());
    }

    public function testArrayOffsetExistsIsFalseForNullEntries(): void
    {
        $value = new AttributeArray('class', ['a', null]);

        self::assertTrue(isset($value[0]));
        self::assertFalse(isset($value[1]));
        self::assertFalse(isset($value[2]));
    }

    public function testArrayOffsetSetWithNullKeyAppends(): void
    {
        $value = new AttributeArray('class', [5 => 'a']);

        $value->offsetSet(null, 'b');

        self::assertSame([5 => 'a', 6 => 'b'], $value->value());
    }

    public function testArrayIsIterable(): void
    {
        $value = new AttributeArray('class', ['x' => 'a', 'y' => 'b']);

        self::assertSame(['x' => 'a', 'y' => 'b'], iterator_to_array($value));
        self::assertInstanceOf(\ArrayIterator::class, $value->getIterator());
        self::assertCount(2, $value->getIterator());
    }

    public function testArrayIteratorIsASnapshot(): void
    {
        $value = new AttributeArray('class', ['a']);
        $iterator = $value->getIterator();

        $value[] = 'b';

        self::assertCount(1, $iterator);
    }

    public function testExchangeArrayReturnsTheOldValue(): void
    {
        $value = new AttributeArray('class', ['a', 'b']);

        $old = $value->exchangeArray(['c']);

        self::assertSame(['a', 'b'], $old);
        self::assertSame(['c'], $value->value());
        self::assertSame('c', (string) $value);
    }

    public function testExchangeArrayWithEmptyArrayEmptiesTheValue(): void
    {
        $value = new AttributeArray('class', ['a']);

        $value->exchangeArray([]);

        self::assertNull($value->render());
    }

    // Collection integration of the value classes

    public function testCollectionJsonSerializesToTheRenderedString(): void
    {
        $collection = new AttributeCollection(['id' => 'a"b', 'class' => ['x', 'y'], 'hidden' => true]);

        self::assertSame(
            json_encode(' id="a&quot;b" class="x y" hidden'),
            json_encode($collection),
        );
        self::assertSame(' id="a&quot;b" class="x y" hidden', $collection->jsonSerialize());
    }

    public function testCollectionOmitsFalseBooleansAndEmptyArrays(): void
    {
        $collection = new AttributeCollection(['hidden' => false, 'class' => [], 'id' => 'a']);

        self::assertSame(' id="a"', (string) $collection);
    }

    public function testCollectionKeepsEmptyStringAttribute(): void
    {
        self::assertSame(' alt=""', (string) new AttributeCollection(['alt' => '']));
    }

    public function testCollectionRendersNumericAndNullValues(): void
    {
        $collection = new AttributeCollection(['tabindex' => 0, 'width' => 1.5, 'title' => null]);

        self::assertSame(' tabindex="0" width="1.5"', (string) $collection);
    }

    public function testCollectionClassListDeduplicatesAndEscapes(): void
    {
        $collection = new AttributeCollection(['class' => ['a', 'a', 'b"c']]);

        self::assertSame(' class="a b&quot;c"', (string) $collection);
    }

    public function testCollectionStringClassBecomesAList(): void
    {
        $collection = new AttributeCollection(['class' => 'single']);

        self::assertInstanceOf(AttributeArray::class, $collection['class']);
        self::assertSame(' class="single"', (string) $collection);
    }

    public function testCollectionCopiesValueObjectsUnderTheNewName(): void
    {
        $collection = new AttributeCollection(['data-x' => new AttributeString('other-name', 'v')]);

        self::assertSame(' data-x="v"', (string) $collection);
        self::assertSame(['data-x' => 'v'], $collection->toArray());
    }

    public function testCollectionRefusesToRenderAForeignStorageEntry(): void
    {
        $collection = new class extends AttributeCollection {
            public function __construct()
            {
                $this->storage['broken'] = 'not a value object';
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected type for $value (string).');

        (string) $collection;
    }
}

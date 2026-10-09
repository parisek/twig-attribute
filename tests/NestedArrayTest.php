<?php

declare(strict_types=1);

namespace Parisek\Twig\Tests;

use Parisek\Twig\Internal\NestedArray;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The merge tests are ported from Drupal core's NestedArrayTest
 * (core/tests/Drupal/Tests/Component/Utility/NestedArrayTest.php, 11.x).
 * Only mergeDeep() and mergeDeepArray() exist in this package, so the other
 * upstream tests (getValue, setValue, unsetValue, keyExists, filter) are left out.
 */
final class NestedArrayTest extends TestCase
{
    public function testMergeDeepArray(): void
    {
        $linkOptions1 = [
            'fragment' => 'x',
            'attributes' => ['title' => 'X', 'class' => ['a', 'b']],
            'language' => 'en',
        ];
        $linkOptions2 = [
            'fragment' => 'y',
            'attributes' => ['title' => 'Y', 'class' => ['c', 'd']],
            'absolute' => true,
        ];
        $expected = [
            'fragment' => 'y',
            'attributes' => ['title' => 'Y', 'class' => ['a', 'b', 'c', 'd']],
            'language' => 'en',
            'absolute' => true,
        ];

        self::assertSame($expected, NestedArray::mergeDeepArray([$linkOptions1, $linkOptions2]));
        self::assertSame($expected, NestedArray::mergeDeep($linkOptions1, $linkOptions2));
    }

    public function testMergeImplicitKeysAppend(): void
    {
        self::assertSame(
            ['subkey' => ['X', 'Y', 'X']],
            NestedArray::mergeDeepArray([['subkey' => ['X', 'Y']], ['subkey' => ['X']]]),
        );
    }

    public function testMergeExplicitKeysAppend(): void
    {
        $a = ['subkey' => [0 => 'A', 1 => 'B']];
        $b = ['subkey' => [0 => 'C', 1 => 'D']];

        self::assertSame(
            ['subkey' => [0 => 'A', 1 => 'B', 2 => 'C', 3 => 'D']],
            NestedArray::mergeDeepArray([$a, $b]),
        );
    }

    public function testMergeOutOfSequenceKeysAreRenumbered(): void
    {
        $a = ['subkey' => [10 => 'A', 30 => 'B']];
        $b = ['subkey' => [20 => 'C', 0 => 'D']];

        self::assertSame(
            ['subkey' => [0 => 'A', 1 => 'B', 2 => 'C', 3 => 'D']],
            NestedArray::mergeDeepArray([$a, $b]),
        );
    }

    public function testPreserveIntegerKeysMergesInsteadOfAppending(): void
    {
        $a = ['subkey' => [10 => 'A', 30 => 'B']];
        $b = ['subkey' => [30 => 'C', 0 => 'D']];

        self::assertSame(
            ['subkey' => [10 => 'A', 30 => 'C', 0 => 'D']],
            NestedArray::mergeDeepArray([$a, $b], true),
        );
    }

    public function testTopLevelIntegerKeysAreRenumbered(): void
    {
        self::assertSame(['a', 'b', 'x' => 'c'], NestedArray::mergeDeep([5 => 'a'], [5 => 'b'], ['x' => 'c']));
    }

    public function testScalarOverwritesScalar(): void
    {
        self::assertSame(['k' => 'new'], NestedArray::mergeDeep(['k' => 'old'], ['k' => 'new']));
    }

    public function testScalarOverwritesArray(): void
    {
        self::assertSame(['k' => 'new'], NestedArray::mergeDeep(['k' => ['old']], ['k' => 'new']));
    }

    public function testArrayOverwritesScalar(): void
    {
        self::assertSame(['k' => ['new']], NestedArray::mergeDeep(['k' => 'old'], ['k' => ['new']]));
    }

    public function testNullValueOverwritesAndIsKept(): void
    {
        self::assertSame(['k' => null], NestedArray::mergeDeep(['k' => 'old'], ['k' => null]));
    }

    public function testExistingNullIsReplacedByArray(): void
    {
        // isset() is false for null, so the array wins instead of recursing.
        self::assertSame(['k' => ['x']], NestedArray::mergeDeep(['k' => null], ['k' => ['x']]));
    }

    public function testMergesSeveralLevelsDeep(): void
    {
        $a = ['l1' => ['l2' => ['l3' => ['keep', 'x'], 'only-a' => 1]]];
        $b = ['l1' => ['l2' => ['l3' => ['y'], 'only-b' => 2]]];

        self::assertSame(
            ['l1' => ['l2' => ['l3' => ['keep', 'x', 'y'], 'only-a' => 1, 'only-b' => 2]]],
            NestedArray::mergeDeep($a, $b),
        );
    }

    public function testThreeArraysMergeLeftToRight(): void
    {
        self::assertSame(
            ['k' => 'c', 'list' => ['a', 'b', 'c']],
            NestedArray::mergeDeep(
                ['k' => 'a', 'list' => ['a']],
                ['k' => 'b', 'list' => ['b']],
                ['k' => 'c', 'list' => ['c']],
            ),
        );
    }

    public function testEmptyInput(): void
    {
        self::assertSame([], NestedArray::mergeDeep());
        self::assertSame([], NestedArray::mergeDeepArray([]));
        self::assertSame([], NestedArray::mergeDeep([], []));
    }

    public function testSingleArrayIsReturnedWithRenumberedKeys(): void
    {
        self::assertSame(['a', 'k' => 'b'], NestedArray::mergeDeep([3 => 'a', 'k' => 'b']));
    }

    public function testInputArraysAreNotModified(): void
    {
        $a = ['k' => ['x']];
        $b = ['k' => ['y']];

        NestedArray::mergeDeep($a, $b);

        self::assertSame(['k' => ['x']], $a);
        self::assertSame(['k' => ['y']], $b);
    }

    public function testReferencesInInputAreCopiedNotShared(): void
    {
        $inner = ['x'];
        $a = ['k' => &$inner];

        $result = NestedArray::mergeDeep($a, ['k' => ['y']]);
        $inner[] = 'changed-later';

        self::assertSame(['k' => ['x', 'y']], $result);
    }

    public function testObjectValuesAreKeptByHandle(): void
    {
        $object = new \stdClass();

        $result = NestedArray::mergeDeep(['o' => 1], ['o' => $object]);

        self::assertSame($object, $result['o']);
    }

    /**
     * @param array<array<mixed>> $arrays
     * @param array<mixed> $expected
     */
    #[DataProvider('numericStringKeys')]
    public function testIntegerStringKeysCountAsIntegers(array $arrays, array $expected): void
    {
        self::assertSame($expected, NestedArray::mergeDeepArray($arrays));
    }

    /**
     * @return array<string, array{0: array<array<mixed>>, 1: array<mixed>}>
     */
    public static function numericStringKeys(): array
    {
        return [
            // PHP turns the key '1' into the integer 1, so it is appended.
            'integer-like string key' => [[['1' => 'a'], ['1' => 'b']], ['a', 'b']],
            // A key like '01' stays a string and is overwritten.
            'leading-zero string key' => [[['01' => 'a'], ['01' => 'b']], ['01' => 'b']],
        ];
    }
}

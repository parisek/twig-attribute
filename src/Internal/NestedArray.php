<?php

declare(strict_types=1);

namespace Parisek\Twig\Internal;

/**
 * Minimal array-merge helper inlined from Drupal\Component\Utility\NestedArray.
 *
 * Only the methods AttributeCollection::merge() needs land here. The rest of
 * Drupal's NestedArray (getValue, setValue, unsetValue, keyExists, filter)
 * is intentionally absent — this package never reaches them.
 */
final class NestedArray
{
    /**
     * Merges multiple arrays, recursively, and returns the merged array.
     *
     * This function is similar to PHP's array_merge_recursive() function, but it
     * handles non-array values differently. When merging values that are not both
     * arrays, the latter value replaces the former rather than merging with it.
     *
     * @param array ...$arrays
     *   Arrays to merge.
     *
     * @return array
     *   The merged array.
     *
     * @see NestedArray::mergeDeepArray()
     */
    public static function mergeDeep(array ...$arrays): array
    {
        return self::mergeDeepArray($arrays);
    }

    /**
     * Merges multiple arrays, recursively, and returns the merged array.
     *
     * This function is equivalent to NestedArray::mergeDeep(), except the
     * input arrays are passed as a single array parameter rather than a variable
     * parameter list.
     *
     * @param array $arrays
     *   An array of arrays to merge.
     * @param bool $preserve_integer_keys
     *   (optional) If given, integer keys will be preserved and merged instead of
     *   appended. Defaults to FALSE.
     *
     * @return array
     *   The merged array.
     *
     * @see NestedArray::mergeDeep()
     */
    public static function mergeDeepArray(array $arrays, bool $preserve_integer_keys = false): array
    {
        $result = [];
        foreach ($arrays as $array) {
            foreach ($array as $key => $value) {
                // Renumber integer keys as array_merge_recursive() does unless
                // $preserve_integer_keys is set to TRUE. Note that PHP automatically
                // converts array keys that are integer strings (e.g., '1') to integers.
                if (is_int($key) && !$preserve_integer_keys) {
                    $result[] = $value;
                }
                // Recurse when both values are arrays.
                elseif (isset($result[$key]) && is_array($result[$key]) && is_array($value)) {
                    $result[$key] = self::mergeDeepArray([$result[$key], $value], $preserve_integer_keys);
                }
                // Otherwise, use the latter value, overriding any previous value.
                else {
                    $result[$key] = $value;
                }
            }
        }
        return $result;
    }
}

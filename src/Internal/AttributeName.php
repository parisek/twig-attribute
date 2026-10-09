<?php

declare(strict_types=1);

namespace Parisek\Twig\Internal;

/**
 * Rejects attribute names that can break out of the attribute they name.
 *
 * Drupal core prints the name after HTML text escaping only. Whitespace, "="
 * and NUL pass through, so a name such as `x onmouseover` renders as two
 * attributes. This guard is a deliberate deviation from upstream (see
 * AGENTS.md, "Deliberate deviations").
 *
 * Rejected: the empty string, ASCII whitespace and control characters
 * (NUL included), and the characters `" ' < > / =`. Everything else stays
 * valid, including `@click`, `:class`, `x-on:click.prevent`, `[hidden]`,
 * `(click)`, `*ngIf` and `#ref`.
 */
final class AttributeName
{
    private const UNSAFE = '/[\x00-\x20\x7F"\'<>\/=]/';

    /**
     * Returns the name unchanged, or throws.
     *
     * Integer names (from a list array) are valid. Other non-string values keep
     * the upstream behaviour: PHP rejects them later, in Escape::html().
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValid(mixed $name): mixed
    {
        if (is_int($name)) {
            return $name;
        }
        if (!is_string($name)) {
            return $name;
        }
        if ($name === '' || preg_match(self::UNSAFE, $name) === 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid attribute name %s. A name must not be empty or contain whitespace, control characters or any of " \' < > / =.',
                json_encode(substr($name, 0, 40), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES),
            ));
        }

        return $name;
    }
}

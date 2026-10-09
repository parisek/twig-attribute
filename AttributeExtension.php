<?php

declare(strict_types=1);

namespace Parisek\Twig;

use Drupal\Component\Attribute\AttributeCollection;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AttributeExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter(
                'without',
                [$this, 'withoutFilter'],
                ['is_safe' => ['html']],
            ),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'create_attribute',
                [$this, 'createAttribute'],
                [
                    'needs_environment' => true,
                    'is_safe' => ['html'],
                ],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $attributes  Attribute name => value pairs.
     */
    public function createAttribute(Environment $environment, array $attributes = []): AttributeCollection
    {
        return new AttributeCollection($attributes);
    }

    /**
     * Returns a copy of $element without the given keys.
     *
     * Same semantics as Drupal core's TwigExtension::withoutFilter(): an
     * ArrayAccess object is cloned, so the original keeps all its keys. An
     * array is copied by value. Each extra argument is a key or a nested list
     * of keys to drop; unknown keys are ignored.
     *
     * @param mixed $element  An AttributeCollection (or other ArrayAccess object) or an array.
     * @param mixed ...$keys  Keys to drop, as strings or (nested) arrays of strings.
     */
    public function withoutFilter(mixed $element, mixed ...$keys): mixed
    {
        $filtered = $element instanceof \ArrayAccess ? clone $element : $element;

        // Flattens the mix of strings and arrays in a single pass.
        foreach (new \RecursiveIteratorIterator(new \RecursiveArrayIterator($keys)) as $key) {
            unset($filtered[$key]);
        }

        return $filtered;
    }
}

<?php

declare(strict_types=1);

namespace Parisek\Twig;

use Drupal\Component\Attribute\AttributeCollection;
use Drupal\Component\Attribute\MarkupInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Runtime\EscaperRuntime;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AttributeExtension extends AbstractExtension
{
    /**
     * Declares objects that implement MarkupInterface as trusted HTML in the
     * Twig escaper: their whole __toString() output is printed raw.
     *
     * Call it right after addExtension(), before anything is rendered, when
     * autoescape is on. Twig caches the lookup per exact class on the first
     * escape. An object that Twig already escaped in this environment keeps
     * being escaped, and so does a subclass of AttributeCollection or another
     * MarkupInterface class; only AttributeCollection and the interface are
     * repaired. This fails closed. The extension
     * also calls it on the first create_attribute() or without call, but that
     * is a fallback and depends on the order of calls in the template.
     *
     * The method asks the escaper whether the class is already safe, so it can
     * be called any number of times and again after setSafeClasses() cleared
     * the list. Strings, other Stringable values and arrays stay escaped.
     */
    public static function registerSafeClass(Environment $twig): void
    {
        $escaper = $twig->getRuntime(EscaperRuntime::class);
        $probe = new AttributeCollection(['id' => 'p']);

        // With $autoescape = true the escaper returns a safe object as the
        // raw string. Anything else comes back escaped.
        if ($escaper->escape($probe, 'html', null, true) === (string) $probe) {
            return;
        }

        $escaper->addSafeClass(MarkupInterface::class, ['html']);
        // The escaper caches the lookup per exact class on the first escape
        // and never refreshes it from the interface. The concrete class
        // fixes a cache entry that an earlier escape already wrote.
        $escaper->addSafeClass(AttributeCollection::class, ['html']);
    }

    public function getFilters(): array
    {
        return [
            // The filter itself is not marked safe. The collection is safe
            // because of the class registration above.
            new TwigFilter(
                'without',
                function (Environment $environment, mixed $element, mixed ...$keys): mixed {
                    self::registerSafeClass($environment);

                    return $this->withoutFilter($element, ...$keys);
                },
                ['needs_environment' => true],
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
        self::registerSafeClass($environment);

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

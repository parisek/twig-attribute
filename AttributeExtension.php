<?php

declare(strict_types=1);

namespace Parisek\Twig;

use Drupal\Component\Attribute\AttributeCollection;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AttributeExtension extends AbstractExtension
{
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

    public function createAttribute(Environment $environment, array $attributes = []): AttributeCollection
    {
        return new AttributeCollection($attributes);
    }
}

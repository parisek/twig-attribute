<?php

namespace Parisek\Twig;

use Drupal\Component\Attribute\AttributeCollection;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\Environment;

class AttributeExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                "create_attribute",
                [$this, "createAttribute"],
                [
                    "needs_environment" => true,
                    "is_safe" => ["html"],
                ]
            ),
        ];
    }

    public function createAttribute(Environment $environment, array $attributes = []): object
    {
        return new AttributeCollection($attributes);
    }
}

<?php

namespace Parisek\Twig;

use Drupal\Component\Attribute\AttributeCollection;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AttributeExtension extends AbstractExtension {

  public function getFunctions() {
    return [
      new TwigFunction('create_attribute', [
        $this,
        'createAttribute',
      ], [
        'is_safe' => [
          'html'
        ]
      ]),
    ];
  }

  public function createAttribute(array $attributes = []) {
    return new AttributeCollection($attributes);
  }
}

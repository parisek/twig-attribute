<?php

namespace Twig\Extra\Attribute;

use Drupal\Component\Attribute;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AttributeExtension extends AbstractExtension {

  public function getFunctions() {
    return [
      new TwigFunction('create_attribute', [$this, 'createAttribute']),
    ];
  }

  public function createAttribute(array $attributes = []) {
    return new AttributeCollection($attributes);
  }
}

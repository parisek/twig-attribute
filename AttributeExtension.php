<?php

namespace Parisek\Twig;

use Drupal\Component\Attribute\AttributeCollection;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\Environment;

/**
 * Provides Twig functions for creating HTML attribute collections.
 */
class AttributeExtension extends AbstractExtension {

  /**
   * Returns a list of functions to add to the existing list.
   *
   * @return \Twig\TwigFunction[]
   *   An array of Twig functions.
   */
  public function getFunctions(): array {
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

  /**
   * Creates a new AttributeCollection object.
   *
   * @param \Twig\Environment $environment
   *   The Twig environment.
   * @param array $attributes
   *   An associative array of attributes to initialize the collection with.
   *
   * @return \Drupal\Component\Attribute\AttributeCollection
   *   A new AttributeCollection instance.
   */
  public function createAttribute(Environment $environment, array $attributes = []): AttributeCollection {
    return new AttributeCollection($attributes);
  }
}
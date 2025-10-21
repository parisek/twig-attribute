<?php

namespace Drupal\Component\Attribute;

use Drupal\Component\Utility\Html;

/**
 * Defines the base class for an attribute type.
 *
 * @see \Drupal\Component\Attribute\AttributeCollection
 */
abstract class AttributeValueBase {

  /**
   * Renders '$name=""' if $value is an empty string.
   *
   * @see \Drupal\Component\Attribute\AttributeValueBase::render()
   */
  const RENDER_EMPTY_ATTRIBUTE = TRUE;

  /**
   * The value itself.
   *
   * @var mixed
   */
  protected $value;

  /**
   * The name of the value.
   *
   * @var mixed
   */
  protected $name;

  /**
   * Constructs a \Drupal\Component\Attribute\AttributeValueBase object.
   *
   * @param string $name
   *   The attribute name.
   * @param mixed $value
   *   The attribute value.
   */
  public function __construct(string $name, $value) {
    $this->name = $name;
    $this->value = $value;
  }

  /**
   * Returns a string representation of the attribute.
   *
   * While __toString only returns the value in a string form, render()
   * contains the name of the attribute as well.
   *
   * @return string|null
   *   The string representation of the attribute, or NULL if it should not
   *   be rendered.
   */
  public function render(): ?string {
    $value = (string) $this;
    if ((isset($this->value) && static::RENDER_EMPTY_ATTRIBUTE) || !empty($value)) {
      return Html::escape($this->name) . '="' . $value . '"';
    }
    return null;
  }

  /**
   * Returns the raw value.
   *
   * @return mixed
   *   The raw attribute value.
   */
  public function value() {
    return $this->value;
  }

  /**
   * Implements the magic __toString() method.
   *
   * @return string
   *   The string representation of the attribute value.
   */
  abstract public function __toString(): string;

}

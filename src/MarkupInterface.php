<?php

declare(strict_types=1);

namespace Drupal\Component\Attribute;

/**
 * Marker interface for objects safe to render as already-trusted HTML.
 *
 * Replaces Drupal\Component\Render\MarkupInterface. AttributeCollection
 * still implements this so consumers that do `instanceof MarkupInterface`
 * keep working after the drupal/core-render dependency is dropped.
 */
interface MarkupInterface extends \JsonSerializable, \Stringable
{
}

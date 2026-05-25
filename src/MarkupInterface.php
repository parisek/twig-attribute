<?php

declare(strict_types=1);

namespace Drupal\Component\Attribute;

/**
 * Marker interface for objects safe to render as already-trusted HTML.
 *
 * A local replacement for Drupal\Component\Render\MarkupInterface — the FQCN
 * is different (Drupal\Component\Attribute\MarkupInterface), so consumers that
 * type-check against the original Drupal interface will need to update their
 * imports. AttributeCollection implements this so that `__toString()` and
 * `jsonSerialize()` semantics are advertised at the type level.
 */
interface MarkupInterface extends \JsonSerializable, \Stringable
{
}

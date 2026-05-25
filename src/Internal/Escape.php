<?php

declare(strict_types=1);

namespace Parisek\Twig\Internal;

/**
 * Byte-identical replacement for Drupal\Component\Utility\Html::escape().
 *
 * Uses the same flags Drupal core uses so that AttributeValueBase::render()
 * keeps its existing escape semantics after dropping drupal/core-utility.
 */
final class Escape
{
    public static function html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

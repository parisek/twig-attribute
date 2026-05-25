<?php

declare(strict_types=1);

namespace Parisek\Twig\Internal;

/**
 * Minimal HTML-to-plain-text helper inlined from
 * Drupal\Component\Render\PlainTextOutput. We don't implement
 * Drupal's OutputStrategyInterface — the package doesn't need it, and
 * dragging the interface in would pull drupal/core-render back.
 */
final class PlainTextOutput
{
    public static function renderFromHtml(\Stringable|string $string): string
    {
        return html_entity_decode(strip_tags((string) $string), ENT_QUOTES, 'UTF-8');
    }
}

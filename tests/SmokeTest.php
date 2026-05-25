<?php

declare(strict_types=1);

namespace Parisek\Twig\Tests;

use Parisek\Twig\AttributeExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class SmokeTest extends TestCase
{
    public function testCreateAttributeRendersInTemplate(): void
    {
        $twig = new Environment(new ArrayLoader([
            'tag.twig' => '<div{{ create_attribute({"class": ["a", "b"], "data-x": "y"}) }}></div>',
        ]));
        $twig->addExtension(new AttributeExtension());

        $output = $twig->render('tag.twig');

        self::assertStringContainsString('class="a b"', $output);
        self::assertStringContainsString('data-x="y"', $output);
    }
}

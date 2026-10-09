<?php

declare(strict_types=1);

namespace Parisek\Twig\Tests;

use Drupal\Component\Attribute\AttributeCollection;
use Drupal\Component\Attribute\MarkupInterface;
use Parisek\Twig\AttributeExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Markup;

final class SafeClassTest extends TestCase
{
    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context = [], string|false $autoescape = 'html'): string
    {
        return $this->environment($template, $autoescape)->render('t.twig', $context);
    }

    private function environment(string $template, string|false $autoescape = 'html'): Environment
    {
        $twig = new Environment(new ArrayLoader(['t.twig' => $template]), ['autoescape' => $autoescape]);
        $twig->addExtension(new AttributeExtension());

        return $twig;
    }

    private function stringable(): \Stringable
    {
        return new class implements \Stringable {
            public function __toString(): string
            {
                return '<b>x</b>';
            }
        };
    }

    public function testReadmeExampleIsNotEscapedUnderAutoescape(): void
    {
        $template = <<<'TWIG'
{% set my_attribute = create_attribute() %}
{% set my_classes = ['kittens', 'llamas', isKitten ? 'cats' : 'dogs'] %}
<div{{ my_attribute.addClass(my_classes).setAttribute('id', 'myUniqueId') }}>
TWIG;

        self::assertSame(
            '<div class="kittens llamas cats" id="myUniqueId">',
            $this->render($template, ['isKitten' => true]),
        );
    }

    public function testChainOnVariableMatchesAutoescapeOff(): void
    {
        $template = '{% set a = create_attribute({"class": ["x"]}) %}<div{{ a.addClass("y") }}>';

        self::assertSame('<div class="x y">', $this->render($template));
        self::assertSame($this->render($template, [], false), $this->render($template));
    }

    public function testAttributeValuesAreStillEscapedInsideTheCollection(): void
    {
        $template = '{% set a = create_attribute() %}<div{{ a.setAttribute("title", v) }}>';

        self::assertSame('<div title="&quot;&gt;&lt;script&gt;">', $this->render($template, ['v' => '"><script>']));
    }

    public function testPlainStringStaysEscaped(): void
    {
        $template = '{% set a = create_attribute() %}{{ a.addClass("y") }}{{ s }}';

        self::assertSame(' class="y"&lt;b&gt;', $this->render($template, ['s' => '<b>']));
    }

    public function testUserDefinedStringableStaysEscaped(): void
    {
        $template = '{% set a = create_attribute() %}{{ a }}{{ s }}';

        self::assertSame('&lt;b&gt;x&lt;/b&gt;', $this->render($template, ['s' => $this->stringable()]));
    }

    public function testArrayStaysEscaped(): void
    {
        $template = '{% set a = create_attribute() %}{{ list|join("") }}';

        self::assertSame('&lt;b&gt;', $this->render($template, ['list' => ['<b>']]));
    }

    public function testTwigMarkupStaysSafe(): void
    {
        $template = '{% set a = create_attribute() %}{{ m }}';

        self::assertSame('<b>', $this->render($template, ['m' => new Markup('<b>', 'UTF-8')]));
    }

    public function testOnlyMarkupInterfaceObjectsAreSafe(): void
    {
        $markup = new class implements MarkupInterface {
            public function __toString(): string
            {
                return '<i>';
            }

            public function jsonSerialize(): string
            {
                return '<i>';
            }
        };

        $template = '{% set a = create_attribute() %}{{ m }}{{ s }}';

        self::assertSame('<i>&lt;b&gt;x&lt;/b&gt;', $this->render($template, ['m' => $markup, 's' => $this->stringable()]));
    }

    public function testWithoutFilterOnCollectionIsSafe(): void
    {
        $collection = new AttributeCollection(['class' => ['a'], 'id' => 'i']);

        // No create_attribute() call: the filter registers the safe class itself.
        self::assertSame(' id="i"', $this->render('{{ c|without("class") }}', ['c' => $collection]));
    }

    public function testWithoutFilterOnStringIsEscaped(): void
    {
        self::assertSame('&lt;b&gt;', $this->render('{{ s|without }}', ['s' => '<b>']));
        self::assertSame(
            '&lt;b&gt;x&lt;/b&gt;',
            $this->render('{{ s|without }}', ['s' => $this->stringable()]),
        );
    }

    public function testWithoutFilterItselfIsNotMarkedSafe(): void
    {
        $filter = (new AttributeExtension())->getFilters()[0];

        self::assertSame([], $filter->getSafe(new \Twig\Node\Nodes([])));
    }

    public function testRegisterSafeClassMakesAContextCollectionSafe(): void
    {
        $twig = $this->environment('{{ c }}');
        AttributeExtension::registerSafeClass($twig);

        self::assertSame(' id="i"', $twig->render('t.twig', ['c' => new AttributeCollection(['id' => 'i'])]));
    }

    public function testCollectionFromContextIsEscapedUntilRegistered(): void
    {
        self::assertSame(
            ' id=&quot;i&quot;',
            $this->render('{{ c }}', ['c' => new AttributeCollection(['id' => 'i'])]),
        );
    }

    public function testAutoescapeOffIsUnchanged(): void
    {
        $template = '{% set a = create_attribute({"class": ["x"]}) %}<div{{ a.addClass("y") }}>{{ s }}';

        self::assertSame('<div class="x y"><b>', $this->render($template, ['s' => '<b>'], false));
    }

    public function testRegistrationIsIdempotent(): void
    {
        $twig = $this->environment('{{ c }}');
        AttributeExtension::registerSafeClass($twig);
        AttributeExtension::registerSafeClass($twig);
        $twig->render('t.twig', ['c' => new AttributeCollection()]);

        $runtime = $twig->getRuntime(\Twig\Runtime\EscaperRuntime::class);
        self::assertSame(['html'], $runtime->safeClasses[MarkupInterface::class]);
    }

    public function testOneExtensionInstanceInTwoEnvironments(): void
    {
        $extension = new AttributeExtension();
        $output = [];

        foreach ([1, 2] as $i) {
            $twig = new Environment(new ArrayLoader(['t.twig' => '{{ c }}']), ['autoescape' => 'html']);
            $twig->addExtension($extension);
            AttributeExtension::registerSafeClass($twig);
            $output[] = $twig->render('t.twig', ['c' => new AttributeCollection(['id' => 'i'])]);
        }

        self::assertSame([' id="i"', ' id="i"'], $output);
    }

    public function testRegistrationIsPerEnvironment(): void
    {
        $first = $this->environment('{% set a = create_attribute({"id": "i"}) %}{{ a }}');
        $second = $this->environment('{{ c }}');
        self::assertSame(' id="i"', $first->render('t.twig'));
        self::assertSame(
            ' id=&quot;i&quot;',
            $second->render('t.twig', ['c' => new AttributeCollection(['id' => 'i'])]),
        );
    }
}

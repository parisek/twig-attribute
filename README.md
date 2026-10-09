# parisek/twig-attribute

[![Packagist Version](https://img.shields.io/packagist/v/parisek/twig-attribute)](https://packagist.org/packages/parisek/twig-attribute)
[![PHP Version](https://img.shields.io/packagist/php-v/parisek/twig-attribute)](https://packagist.org/packages/parisek/twig-attribute)
[![Tests](https://img.shields.io/github/actions/workflow/status/parisek/twig-attribute/tests.yml?branch=main&label=tests)](https://github.com/parisek/twig-attribute/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/parisek/twig-attribute)](LICENSE.txt)
[![Twig](https://img.shields.io/badge/Twig-%5E3.27-blue)](https://twig.symfony.com/)

A Twig 3 extension that gives templates a `create_attribute()` function for
collecting, sanitizing, and rendering HTML attributes. It is backed by a vendored
port of Drupal's `Attribute` class.

The package ships its own port (under `Drupal\Component\Attribute`) and does not
depend on Drupal core. The maintainer refreshes the vendored sources from
Drupal 11.x when upstream changes. The API matches what Drupal templates expect.

## Installation

```bash
composer require parisek/twig-attribute
```

Requires PHP ^8.3 and Twig ^3.27. No Drupal dependencies.

## Usage

Plain PHP:

```php
$twig = new \Twig\Environment($loader);
$twig->addExtension(new \Parisek\Twig\AttributeExtension());
```

Symfony service:

```yaml
services:
  Parisek\Twig\AttributeExtension:
    tags: [{ name: twig.extension }]
```

Timber 2 (WordPress). Timber creates the Twig environment itself, so do not
build your own. Add the extension with the
[`timber/twig` filter](https://timber.github.io/docs/v2/guides/extending-twig/#twig-environment),
for example in your theme's `functions.php`:

```php
add_filter( 'timber/twig', function ( \Twig\Environment $twig ) {
    $twig->addExtension( new \Parisek\Twig\AttributeExtension() );

    return $twig;
} );
```

[`parisek/timber-kit`](https://github.com/parisek/timber-kit) already registers
the extension in its `StarterBase`. If you use the kit, you need no extra code.

## In templates

```twig
{% set my_attribute = create_attribute() %}
{% set my_classes = [
  'kittens',
  'llamas',
  isKitten ? 'cats' : 'dogs',
] %}
<div{{ my_attribute.addClass(my_classes).setAttribute('id', 'myUniqueId') }}>
  {{ content }}
</div>
```

Under Twig autoescape, call `AttributeExtension::registerSafeClass($twig)` once,
or the attributes print as `&quot;` text. See [Autoescape](#autoescape).

```twig
<div{{ create_attribute({'class': ['region', 'region--header']}) }}>
  {{ content }}
</div>
```

### Filters

`without` returns a copy of an attribute collection (or a plain array) without
the named keys. It never changes the original. Pass one name, several names or a list.

```twig
<div class="{{ attributes.class }} my-class"{{ attributes|without('class') }}>
  {{ content }}
</div>
{{ attributes|without('class', 'id') }}
{{ attributes|without(['class', 'id']) }}
```

Unknown names are ignored. The behavior matches Drupal's `without` filter.

The filter itself is not marked safe. A collection prints as HTML only after
`registerSafeClass()` ran (see [Autoescape](#autoescape)).

### Autoescape

With Twig autoescape on (for example `'autoescape' => 'html'`), register the safe
class once, right after you add the extension:

```php
$twig->addExtension(new \Parisek\Twig\AttributeExtension());
\Parisek\Twig\AttributeExtension::registerSafeClass($twig);
```

The call is safe to repeat. Call it before anything is rendered. It tells the Twig escaper that objects implementing
the package's `MarkupInterface` are HTML. Strings, other `Stringable` values and
arrays stay escaped.

Timber 2 turns autoescape off by default. A Timber theme, including one that uses
`parisek/timber-kit`, needs nothing.

What to know:

- The extension also calls `registerSafeClass()` on the first `create_attribute()`
  or `without` call. This is a fallback, and it depends on the order of calls. A
  collection from the template context that prints before the first such call is
  escaped (over-escaped, never printed raw). Do not rely on the fallback.
- Twig caches the safe lookup per exact class on the first escape. A
  `MarkupInterface` object that Twig already escaped in that environment keeps
  being escaped, including a subclass of `AttributeCollection` or another
  implementation. Only `AttributeCollection` and the interface are repaired. This
  fails closed: the output is over-escaped, never raw.
- A class that implements `MarkupInterface` is declared trusted HTML. Twig prints
  its whole `__toString()` output raw.
- Attribute names and custom `AttributeValueBase` subclasses are trusted developer
  input, as in Drupal. Never build a name from user input. A name with whitespace,
  `=` or a NUL byte injects attributes. A value subclass that returns markup injects
  markup. Attribute values are escaped.
- Register on the environment you render with. Do not clone an environment (cloning
  is deprecated in Twig 3.30 and not allowed in Twig 4).

The full API (class methods, escape semantics, `without` filter behavior) mirrors
[Drupal's Attribute class](https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21Template%21Attribute.php/class/Attribute/11.x).

## Development

```bash
composer install
composer test      # PHPUnit
composer phpstan   # static analysis
composer cs        # code style check
```

The vendored Drupal port comes from Drupal 11.x core at
`git.drupalcode.org/project/drupal/-/tree/11.x/core/lib/Drupal/Core/Template`.
When upstream changes, copy the relevant files into `.upstream/` (a gitignored
scratch directory) and port them again. [AGENTS.md](AGENTS.md) describes the
refresh steps and the inline shims that let the package drop `drupal/core-render`
and `drupal/core-utility`.

## Use cases

- [Drupal - Pattern Lab](https://patternlab.io/)
- [WordPress - Timber](https://wordpress.org/plugins/timber-library/)
- [Pimcore - Templates](https://pimcore.com/en)
- [parisek/styleguide](https://github.com/parisek/styleguide) - uses this package.

# parisek/twig-attribute

[![Packagist Version](https://img.shields.io/packagist/v/parisek/twig-attribute)](https://packagist.org/packages/parisek/twig-attribute)
[![PHP Version](https://img.shields.io/packagist/php-v/parisek/twig-attribute)](https://packagist.org/packages/parisek/twig-attribute)
[![Tests](https://img.shields.io/github/actions/workflow/status/parisek/twig-attribute/tests.yml?branch=main&label=tests)](https://github.com/parisek/twig-attribute/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/parisek/twig-attribute)](LICENSE.txt)
[![Twig](https://img.shields.io/badge/Twig-%5E3.27-blue)](https://twig.symfony.com/)

A Twig 3 extension that gives templates a `create_attribute()` function for
collecting, sanitizing, and rendering HTML attributes — backed by a vendored,
maintained port of Drupal's `Attribute` class.

The package ships its own port (under `Drupal\Component\Attribute`) rather than
depending on Drupal core. The vendored sources are refreshed from Drupal 11.x
on each release; the API matches what Drupal templates expect.

## Installation

```bash
composer require parisek/twig-attribute
```

Requires PHP ^8.3 and Twig ^3.0. No Drupal dependencies.

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

The call is safe to repeat. It tells the Twig escaper that objects implementing
the package's `MarkupInterface` are HTML. Strings, other `Stringable` values and
arrays stay escaped.

Timber and `parisek/timber-kit` turn autoescape off and need nothing.

What to know:

- The extension also calls `registerSafeClass()` on the first `create_attribute()`
  or `without` call. This is a fallback, and it depends on the order of calls. A
  collection from the template context that prints before the first such call is
  escaped (over-escaped, never printed raw). Do not rely on the fallback.
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

## Upgrading from 1.5.x to 1.6.0

**Action required: none for the vast majority of consumers.** Run
`composer update parisek/twig-attribute`.

What changes under the hood:

- The vendored `Drupal\Component\Attribute\*` classes are refreshed from
  Drupal 11.x core. Existing methods (`addClass`, `setAttribute`,
  `removeAttribute`, `hasClass`, `merge`, `toArray`, `__toString`,
  iterator support) keep their signatures and render output.
- New methods become available — your existing templates ignore them
  unless you opt in:
  - `hasAttribute(string $name): bool` — check existence without throwing.
  - `removeClass(...$classes): static` — symmetric counterpart of `addClass`.
  - `getClass(): AttributeArray` — read the class collection.
  - `jsonSerialize(): string` — JSON encoding support (returns the rendered attribute string).
  - `__clone()` — deep-clone correctness.
- The package now ships its own test suite (`tests/AttributeTest.php`,
  18 methods, pure PHPUnit). Run `vendor/bin/phpunit` to verify the
  install if you want extra confidence.
- Composer constraints tightened to **Twig 3+ and PHP ^8.3**. Both
  were already required transitively by `drupal/core-utility ^10.0 || ^11.0`
  in 1.5.x, so this change doesn't shrink the real install matrix.
- Both `drupal/core-render` and `drupal/core-utility` are **no longer
  required** by this package. `Html::escape()` is inlined as a 5-LOC
  private helper. `NestedArray::mergeDeep` and `PlainTextOutput::renderFromHtml`
  are inlined as minimal `Parisek\Twig\Internal\*` shims.

### Edge cases that may need action

- **You were reaching `Drupal\Component\Render\…` or
  `Drupal\Component\Utility\…` classes through this package's transitive
  install.** Unusual, but possible if you wrote framework-level code
  on top of the Attribute classes. Fix: add the relevant `drupal/core-*`
  package to your own `composer.json` `require`. This is the correct
  long-term shape regardless — relying on transitive availability is
  fragile.
- **You're on PHP < 8.3 or Twig 2.** You couldn't actually install 1.5.x
  cleanly against modern Drupal 10/11 either, so this is more about
  cleaning up your constraints. Bump PHP/Twig in your own project, or
  pin `parisek/twig-attribute` to `1.5.*` to stay on the previous floor.

### Direct PHP usage

If your code does `new \Drupal\Component\Attribute\AttributeCollection(...)`
in PHP (instead of using `create_attribute()` from Twig), the refresh
adds methods but doesn't remove any. Your existing calls keep working
in 1.6.0.

## Development

```bash
composer install
vendor/bin/phpunit              # 41 tests
vendor/bin/phpstan analyse      # level 5
```

Source-of-truth for the vendored Drupal port is Drupal 11.x core at
`git.drupalcode.org/project/drupal/-/tree/11.x/core/lib/Drupal/Core/Template`.
When the upstream changes meaningfully, copy the relevant files into
`.upstream/` (gitignored scratch dir) and re-port. See `docs/refresh-decisions.md`
for the rationale behind the inline shims that let this package drop
`drupal/core-render` and `drupal/core-utility`.

## Use cases

- [Drupal — Pattern Lab](https://patternlab.io/)
- [WordPress — Timber](https://wordpress.org/plugins/timber-library/)
- [Pimcore — Templates](https://pimcore.com/en)
- [parisek/styleguide](https://github.com/parisek/styleguide) — the package that drove the 1.6.0 refresh.

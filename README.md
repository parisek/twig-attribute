# parisek/twig-attribute

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

```twig
<div{{ create_attribute({'class': ['region', 'region--header']}) }}>
  {{ content }}
</div>
```

The full API (class methods, escape semantics, `without` filter behavior) mirrors
[Drupal's Attribute class](https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21Template%21Attribute.php/class/Attribute/11.x).

## Upgrading from 1.0.x to 1.1.0

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
  - `jsonSerialize(): array` — JSON encoding support.
  - `__clone()` — deep-clone correctness.
- The package now ships its own test suite (`tests/AttributeTest.php`,
  18 methods, pure PHPUnit). Run `vendor/bin/phpunit` to verify the
  install if you want extra confidence.
- Composer constraints tightened to **Twig 3+ and PHP ^8.3**. Both
  were already required transitively by `drupal/core-utility ^10.0 || ^11.0`
  in 1.0.x, so this change doesn't shrink the real install matrix.
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
- **You're on PHP < 8.3 or Twig 2.** You couldn't actually install 1.0.x
  cleanly against modern Drupal 10/11 either, so this is more about
  cleaning up your constraints. Bump PHP/Twig in your own project, or
  pin `parisek/twig-attribute` to `1.0.*` to stay on the previous floor.

### Direct PHP usage

If your code does `new \Drupal\Component\Attribute\AttributeCollection(...)`
in PHP (instead of using `create_attribute()` from Twig), the refresh
adds methods but doesn't remove any. Your existing calls keep working
in 1.1.0.

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
- [parisek/styleguide](https://github.com/parisek/styleguide) — the package that drove the 1.1.0 refresh.

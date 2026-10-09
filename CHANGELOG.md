# Changelog

All notable changes to this project are documented here. The format is based
on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `without` Twig filter, with the semantics of Drupal core's `TwigExtension::withoutFilter()`. `{{ attributes|without('class') }}` renders a copy of the collection (or a plain array) without the named keys and leaves the original unchanged. The README and the `AttributeCollection` docblock already described this filter, but the package did not register it.

### Fixed

- An attribute collection kept in a variable, for example `{% set a = create_attribute() %}<div{{ a.addClass("x") }}>`, was escaped a second time under Twig autoescape. New `AttributeExtension::registerSafeClass($twig)` declares `MarkupInterface` objects as HTML in the Twig escaper. Call it right after `addExtension()`, before anything is rendered, when autoescape is on. Objects that Twig already escaped in that environment keep being escaped, including subclasses of `AttributeCollection`. Strings, other `Stringable` values and arrays stay escaped. As a fallback the extension calls it on the first `create_attribute()` or `without` call; that fallback depends on the order of calls. Timber and `timber-kit` (autoescape off) need nothing. A `MarkupInterface` class is trusted HTML; attribute names and custom `AttributeValueBase` subclasses stay trusted developer input, as in Drupal.

## [1.6.1] - 2026-06-01

### Security

- Raised the `twig/twig` floor to `^3.27` so consumers can't resolve a version affected by [CVE-2026-46634](https://symfony.com/cve-2026-46634) — a sandbox escape via `template_from_string()`, fixed in twig 3.27.

### Changed

- Adopted the shared parisek QA tooling: `composer audit` + `composer normalize` CI gates and a PHP-CS-Fixer (`@PER-CS`) `cs` check scoped to the Parisek-authored code (the vendored Drupal `src/` keeps its 2-space style). Dev-only; no consumer impact.

## [1.6.0] - 2026-05-25

### Added
- 543-LOC PHPUnit test suite ported from Drupal 11.x core `AttributeTest.php`
  (18 test methods, pure PHPUnit, no Drupal helpers). The package previously
  shipped zero tests.
- New methods on `AttributeCollection` (additive — existing code unaffected):
  - `hasAttribute(string $name): bool`
  - `removeClass(...$classes): static`
  - `getClass(): AttributeArray`
  - `jsonSerialize(): string` (returns the rendered attribute string)
  - `__clone()` for deep-clone correctness.
- `Parisek\Twig\Internal\Escape::html()` — byte-identical inline replacement for
  Drupal's `Html::escape()` (5 LOC, `htmlspecialchars` with
  `ENT_QUOTES | ENT_SUBSTITUTE | 'UTF-8'`).
- `Parisek\Twig\Internal\NestedArray` (only `mergeDeep` + `mergeDeepArray`)
  and `Parisek\Twig\Internal\PlainTextOutput::renderFromHtml()` inlined for
  the same reason.
- `Drupal\Component\Attribute\MarkupInterface` — local marker interface
  (`extends \JsonSerializable, \Stringable`) so `AttributeCollection`
  preserves the `implements MarkupInterface` contract after the
  `drupal/core-render` dependency is dropped.
- PHPStan level 5 baseline + GitHub Actions matrix on PHP 8.3 + 8.4.

### Changed
- Refreshed vendored `Drupal\Component\Attribute\*` classes from Drupal
  11.x core. Existing method signatures and render output are preserved.
- `AttributeExtension` is now `final` with `declare(strict_types=1)`.
  `createAttribute()` return type narrowed from `object` to
  `AttributeCollection` (safe — class is `final`, no consumer can subclass).

### Removed
- **`drupal/core-render`** dropped from `require`. `MarkupInterface` is
  replaced by a local marker; `PlainTextOutput::renderFromHtml` is inlined.
- **`drupal/core-utility`** dropped from `require`. `Html::escape()` is
  inlined per above; `NestedArray::mergeDeep[Array]` is inlined as
  `Parisek\Twig\Internal\NestedArray`.
- `twig/twig ^2.4` support dropped. Twig 3+ only.

### Semver rationale
Shipped as **1.6.0** rather than 2.0.0 because the tightened constraints
(`php: ^8.3`, `twig/twig: ^3.0`) match what was already implied transitively
by `drupal/core-utility ^10.0 || ^11.0` in 1.5.x — anyone who could install
1.5.x against modern Drupal already had PHP 8.3+ and Twig 3+. The pruned
`drupal/core-*` deps weren't reached by any external consumer through this
package; consumers use the `create_attribute()` Twig function, not the Drupal
classes directly.

#### Upgrading from 1.5.x

Most consumers need no action. Run `composer update parisek/twig-attribute`.
Existing methods keep their signatures and render output. A direct
`new \Drupal\Component\Attribute\AttributeCollection(...)` call in PHP keeps
working, because the refresh adds methods and removes none.

Two cases may need action:

- Your code reaches `Drupal\Component\Render\…` or
  `Drupal\Component\Utility\…` classes through this package's transitive
  install. Add the relevant `drupal/core-*` package to your own
  `composer.json` `require`. Relying on a transitive package is fragile.
- You run PHP below 8.3 or Twig 2. Raise PHP and Twig in your project, or pin
  `parisek/twig-attribute` to `1.5.*`.

# Changelog

All notable changes to this project are documented here. The format is based
on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - YYYY-MM-DD

### Added
- 543-LOC PHPUnit test suite ported from Drupal 11.x core `AttributeTest.php`
  (18 test methods, pure PHPUnit, no Drupal helpers). The package previously
  shipped zero tests.
- New methods on `AttributeCollection` (additive — existing code unaffected):
  - `hasAttribute(string $name): bool`
  - `removeClass(...$classes): static`
  - `getClass(): AttributeArray`
  - `jsonSerialize(): array`
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
Shipped as **1.1.0** rather than 2.0.0 because the tightened constraints
(`php: ^8.3`, `twig/twig: ^3.0`) match what was already implied transitively
by `drupal/core-utility ^10.0 || ^11.0` in 1.0.x — anyone who could install
1.0.x against modern Drupal already had PHP 8.3+ and Twig 3+. The pruned
`drupal/core-*` deps weren't reached by any external consumer through this
package; consumers use the `create_attribute()` Twig function, not the Drupal
classes directly.

If your project was reaching `Drupal\Component\Render\…` or
`Drupal\Component\Utility\…` classes through this package's transitive
install, add the relevant `drupal/core-*` package to your own
`composer.json` `require` — see the README's "Edge cases that may need
action" section.

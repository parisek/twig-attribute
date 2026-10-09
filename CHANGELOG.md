# Changelog

All notable changes to this project are documented here. The format is based
on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `without` Twig filter, with the semantics of Drupal core's `TwigExtension::withoutFilter()`. `{{ attributes|without('class') }}` renders a copy of the collection (or a plain array) without the named keys and leaves the original unchanged. The README and the `AttributeCollection` docblock already described this filter, but the package did not register it.

### Changed

- **Layout change: `AttributeExtension.php` moved** from the repository root to `src/Twig/AttributeExtension.php`. The Composer autoload maps `Parisek\Twig\` to `src/Twig` now. The namespace, the class name and the public API do not change, so code that loads the class through the Composer autoloader needs no change.
  - **Do not load the file by path.** `require_once 'vendor/parisek/twig-attribute/AttributeExtension.php'` is an anti-pattern, and it fails after this update. Use the autoloader (`vendor/autoload.php`) and `new \Parisek\Twig\AttributeExtension()`.
  - **Clear compiled caches after updating.** A compiled Symfony container (`var/cache/`) can hold the old path. Run `bin/console cache:clear`, or delete `var/cache/`, if the container fails to boot after `composer update`.

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
  11.x core. Render output is preserved. Method signatures changed; see
  "Upgrading from 1.5.x" below.
- `AttributeExtension` is now `final` with `declare(strict_types=1)`.
  `createAttribute()` return type narrowed from `object` to
  `AttributeCollection`. The class is `final`, so a subclass of it breaks.

### Removed
- **`drupal/core-render`** dropped from `require`. `MarkupInterface` is
  replaced by a local marker; `PlainTextOutput::renderFromHtml` is inlined.
- **`drupal/core-utility`** dropped from `require`. `Html::escape()` is
  inlined per above; `NestedArray::mergeDeep[Array]` is inlined as
  `Parisek\Twig\Internal\NestedArray`.
- `twig/twig ^2.4` support dropped. Twig 3+ only.

### Semver rationale
Shipped as **1.6.0** rather than 2.0.0. The package is consumed through the
`create_attribute()` Twig function, not through the Drupal classes directly.
1.5.0 declared no PHP constraint and allowed Twig 2, so the install matrix did
shrink. See "Upgrading from 1.5.x" below.

#### Upgrading from 1.5.x

Most consumers need no action. Run `composer update parisek/twig-attribute`.
Render output is the same. These changes can break code that goes beyond
`create_attribute()`:

- PHP: 1.5.0 declared no PHP constraint. 1.6.0 requires PHP ^8.3. On an older
  PHP, pin `parisek/twig-attribute` to `1.5.*`.
- Twig: 1.5.0 allowed `^2.4 || ^3.0`. 1.6.0 requires Twig 3. On Twig 2, pin to
  `1.5.*`.
- `AttributeExtension` is `final`. A subclass of it breaks.
- Method signatures. `offsetGet()`, `offsetSet()`, `offsetUnset()`,
  `offsetExists()`, `getIterator()` and `jsonSerialize()` now declare return
  types, and `offsetSet()` declares `mixed $value`. `addClass()`,
  `removeAttribute()` and `removeClass()` now declare `...$args` instead of
  reading `func_get_args()`. A subclass that overrides these methods needs
  matching signatures. The vendored files now use `declare(strict_types=1)`.
- `offsetSet()` now also accepts an object that implements `\Stringable` but
  not `MarkupInterface`, for an attribute other than `class`. It stores the
  object as plain text, as it did for `MarkupInterface` objects. In 1.5.0 the
  object was not converted.
- `drupal/core-render` and `drupal/core-utility` are no longer required. If your
  code uses `Drupal\Component\Render\...` or `Drupal\Component\Utility\...`
  classes through this package, require the relevant `drupal/core-*` package
  yourself.

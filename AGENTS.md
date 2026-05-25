# AGENTS.md

Operational notes for AI coding agents (Claude Code, Codex, Cursor, …) working on this repo. Treat as authoritative — overrides default assumptions where they conflict.

Tool-specific entrypoint files (`CLAUDE.md`, `.cursorrules`, etc.) just point here so the source of truth stays in one place.

## Maintaining this file

Go-style brevity. Bullets, not paragraphs. Add only what saves the next session real time:

- **Add** a note when you hit a non-obvious gotcha or pin a convention the codebase relies on.
- **Don't add** restatement of README content, narration of what the codebase does, or one-off task context. README owns "what the project does"; AGENTS.md owns "how to work on it".
- **Cap ~150 lines.** Past that, the whole file gets skimmed instead of read.

## Project shape

A Twig 3 extension (`Parisek\Twig\AttributeExtension`) that exposes a `create_attribute()` Twig function, backed by a vendored port of Drupal 11.x's `Attribute` class under `Drupal\Component\Attribute\`.

- `src/` — vendored Drupal sources (`AttributeCollection`, `AttributeValueBase`, `AttributeArray`, `AttributeBoolean`, `AttributeString`, `MarkupInterface`).
- `src/Internal/` — minimal shims that let the package drop `drupal/core-render` + `drupal/core-utility`: `Escape::html()`, `NestedArray::mergeDeep[Array]()`, `PlainTextOutput::renderFromHtml()`.
- `AttributeExtension.php` — root-level, `final`, the Twig extension entrypoint. Tiny wrapper.
- `tests/` — PHPUnit 11. `AttributeTest.php` is the upstream Drupal test ported (alias `AttributeCollection as Attribute`); `EscapeTest.php` byte-matches against `htmlspecialchars`; `SmokeTest.php` exercises the Twig integration end-to-end.
- `.upstream/` — gitignored scratch dir for the next refresh; fetch from `git.drupalcode.org/project/drupal/-/raw/11.x/core/lib/Drupal/Core/Template/`.

PHP ^8.3. Twig ^3.0. No Drupal dependencies, no Symfony dependencies beyond what Twig itself pulls.

## Commands

```bash
composer install
vendor/bin/phpunit              # 41 tests / 110 assertions
vendor/bin/phpstan analyse      # level 5, clean
composer validate --strict
```

`composer.json` carries no `scripts` block — run the binaries directly.

## CI

`.github/workflows/ci.yml` runs `phpunit` + `phpstan` on PHP 8.3 + 8.4 matrix. `.github/workflows/dependency-review.yml` runs on PRs.

## Refreshing from Drupal 11.x upstream

The five source files in `src/` are vendored from Drupal core. When upstream changes meaningfully:

1. Fetch all 6 files (5 sources + the upstream test) into `.upstream/` from `git.drupalcode.org/project/drupal/-/raw/11.x/core/lib/Drupal/Core/Template/` and `core/tests/Drupal/Tests/Core/Template/`.
2. Audit `grep -hE "^use Drupal\\\\(Component|Core)\\\\" .upstream/*.php | sort -u`. Outside symbols must terminate in PHP builtins via inline shim, **not** pull `drupal/core-*` back in.
3. Port file by file: rewrite namespace `Drupal\Core\Template` → `Drupal\Component\Attribute`, rename `class Attribute` → `class AttributeCollection` (BC), swap `Html::escape` → `Escape::html`, drop `#[JsonSchema(...)]` PHP attribute, update `@see` docblocks.
4. The test fixture (`tests/AttributeTest.php`) carries its own local `MarkupInterface` + `Markup` — **do not import from `drupal/core-render`** when refreshing the test; the file is intentionally decoupled.

## Inline shim discipline

The package's whole reason to drop `drupal/core-*` is to terminate every dep chain at PHP builtins.

- `Internal\Escape::html` — `htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`. Byte-identical to Drupal's `Html::escape`.
- `Internal\PlainTextOutput::renderFromHtml(\Stringable|string)` — `html_entity_decode(strip_tags((string) $string), ENT_QUOTES, 'UTF-8')`. Drop `implements OutputStrategyInterface` — we don't carry the interface.
- `Internal\NestedArray` — only `mergeDeep` + `mergeDeepArray`. Don't add `getValue`/`setValue`/`unsetValue`/`keyExists`/`filter` from upstream; nothing in the attribute classes uses them.
- `Drupal\Component\Attribute\MarkupInterface` — at `src/MarkupInterface.php` (public namespace, not `Internal\`), empty `interface … extends \JsonSerializable, \Stringable`. `AttributeCollection` `implements` it.

If a refresh would require a fifth shim or a shim exceeding ~30 LOC, stop and reconsider — the prune-both-drupal-deps strategy assumes shims stay minimal.

## PHPStan level

Level 5, not 6. Level 6 surfaces 17 `missingType.iterableValue` / `missingType.parameter` / `missingType.return` errors against the ported Drupal sources (untyped `array` parameters / no inner generics). Upgrade path:

1. Annotate `src/*.php` with `array<string, mixed>` / `array<int, string>` generics as appropriate.
2. Remove `treatPhpDocTypesAsCertain: false` from `phpstan.neon` (added to silence a spurious `instanceof.alwaysTrue` in `__toString()`).
3. Bump `phpstan.neon` to `level: 6`.

## Per-PR conventions

- **CHANGELOG.md**: every behavior-affecting PR adds an entry under `## [Unreleased]` with [Keep a Changelog](https://keepachangelog.com/) categories.
- **Squash-merge PRs** into `master` so the merge commit subject ends with `(#N)`. The existing tag history (`v1.0.0`–`v1.6.0`) is built on this convention.

## Release process

Currently manual:

1. Stamp the `[Unreleased]` heading in `CHANGELOG.md` to `[X.Y.Z] - YYYY-MM-DD`.
2. `git tag -a vX.Y.Z -m "..."` + `git push origin vX.Y.Z`.
3. Packagist auto-imports (~60s; webhook wired).
4. Create the GitHub Release (`gh release create vX.Y.Z --notes-file <(awk …)`) — use `--latest=false` for back-dated patches so they don't steal the Latest badge.

No release-automation workflow yet. If one lands, mirror `parisek/timber-kit`'s `release-stamp.yml` + `release.yml` shape.

## Style

- Vendored sources in `src/` keep Drupal core's indent (2-space) and brace style. Don't reformat — refresh diffs stay readable.
- Our own code (`src/Internal/`, `src/MarkupInterface.php`, `AttributeExtension.php`, `tests/`) is PSR-12, 4-space indent, `final` by default, `declare(strict_types=1);` at top.
- WHY-not-WHAT comments. Don't reference task numbers / PRs / call sites in code comments — those rot.

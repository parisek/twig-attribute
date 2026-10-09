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
- `AttributeExtension.php` — root-level, `final`, the Twig extension entrypoint. Registers the `create_attribute()` function and the `without` filter.
- `tests/` — PHPUnit 10 or 11 (`composer.json` allows both). `AttributeTest.php` is the upstream Drupal test ported (alias `AttributeCollection as Attribute`); `EscapeTest.php` byte-matches against `htmlspecialchars`; `SmokeTest.php` exercises the Twig integration end-to-end.
- `.upstream/` — gitignored scratch dir for the next refresh; fetch from `git.drupalcode.org/project/drupal/-/raw/11.x/core/lib/Drupal/Core/Template/`.

PHP ^8.3. Twig ^3.27. No Drupal dependencies, no Symfony dependencies beyond what Twig itself pulls.

`composer.lock` is not tracked. This is a library: CI resolves the newest versions that `composer.json` allows, so an upstream break shows up on `main` early.

## Commands

```bash
composer test                       # phpunit — 58 tests / 137 assertions
composer phpstan                    # static analysis — level 8, clean
composer cs                         # php-cs-fixer dry-run (PER-CS) — Parisek code only
composer cs:fix                     # apply code style
composer normalize                  # tidy composer.json
composer audit --abandoned=report   # advisory scan (abandoned reported, not failed)
composer validate --strict
```

PHP-CS-Fixer is scoped to `AttributeExtension.php` + `tests/` only — the
vendored Drupal classes in `src/` keep Drupal's 2-space style on purpose
(see `.php-cs-fixer.dist.php`), so refreshes from upstream stay diff-able.

## CI

`.github/workflows/tests.yml` has three jobs: `PHP 8.3` and `PHP 8.4` (each runs `phpunit` + `phpstan`), `composer hygiene` (advisory audit + `composer normalize` check) and `code style (PER-CS)`. `.github/workflows/dependency-review.yml` runs on PRs.

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

Level 8. PHPStan analyses `AttributeExtension.php` and `src/Internal/` only. It scans `src/` for types but does not analyse it: the vendored Drupal sources keep their upstream docblocks, so analysing them would report untyped `array` parameters that a refresh from upstream would bring back.

## Per-PR conventions

- **CHANGELOG.md**: every behavior-affecting PR adds an entry under `## [Unreleased]` with [Keep a Changelog](https://keepachangelog.com/) categories.
- **Squash-merge PRs** into `main` so the merge commit subject ends with `(#N)`. The release notes build their pull request list from this suffix.

## Release process — DO NOT bypass

Automated by two workflows (mirrors `parisek/timber-kit`). **Never stamp + tag manually** unless the workflow is broken:

1. Trigger **Stamp Release** (Actions tab → `Stamp Release` → Run workflow → enter `X.Y.Z`, no `v` prefix).
2. It validates the version, requires a non-empty `[Unreleased]`, runs `composer test` + `composer phpstan` as guards, stamps `[Unreleased]` → `[X.Y.Z] - DATE` (UTC, leaving a fresh empty `[Unreleased]`), commits `Release X.Y.Z`, tags `vX.Y.Z`, pushes, then dispatches `release.yml`.
3. `release.yml` extracts that tag's CHANGELOG section + the merged-PR list and creates the GitHub Release (`--latest` only when it's the highest semver, so back-dated patches don't steal the badge). Packagist auto-imports the tag (~60s; webhook wired).

`release.yml` also runs on a manual `vX.Y.Z` tag push and via `workflow_dispatch` (re-generate notes for an existing tag).

## Style

- Vendored sources in `src/` keep Drupal core's indent (2-space) and brace style. Don't reformat — refresh diffs stay readable.
- Our own code (`src/Internal/`, `src/MarkupInterface.php`, `AttributeExtension.php`, `tests/`) is PSR-12, 4-space indent, `final` by default, `declare(strict_types=1);` at top.
- WHY-not-WHAT comments. Don't reference task numbers / PRs / call sites in code comments — those rot.

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
- `AttributeExtension.php` — root-level, `final`, the Twig extension entrypoint. Registers the `create_attribute()` function and the `without` filter, and `registerSafeClass()`, which declares `MarkupInterface` (and `AttributeCollection`) HTML-safe in the Twig escaper. The explicit call right after `addExtension()`, before rendering, is the contract; objects (and subclasses) Twig already escaped in that environment stay escaped. Both entry points call it too, as an order-dependent fallback (an extension has no environment hook). Twig caches the safe lookup per exact class on first escape, so the concrete class is registered as well; the probe in `registerSafeClass()` stays valid after `setSafeClasses([])`.
- `tests/` — PHPUnit 10, 11 or 12 (`composer.json` allows all three). `AttributeTest.php` is the upstream Drupal test ported (alias `AttributeCollection as Attribute`); `EscapeTest.php` byte-matches against `htmlspecialchars`; `SmokeTest.php` exercises the Twig integration end-to-end; `SafeClassTest.php` pins the autoescape behaviour (only `MarkupInterface` is safe).
- `scripts/check-upstream.php` — the upstream watch (see "Refreshing"). `tests/UpstreamCheckTest.php` runs it against `tests/fixtures/upstream-watch/upstream/*.php.txt`, a snapshot of the upstream version `src/` was last refreshed from.
- `.upstream/` — gitignored scratch dir for the next refresh; fetch from `git.drupalcode.org/project/drupal/-/raw/11.x/core/lib/Drupal/Core/Template/`.

PHP ^8.3. Twig ^3.27. No Drupal dependencies, no Symfony dependencies beyond what Twig itself pulls.

`composer.lock` is not tracked. This is a library: CI resolves the newest versions that `composer.json` allows, so an upstream break shows up on `main` early.

## Commands

```bash
composer test                       # phpunit
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

`.github/workflows/tests.yml` has three jobs: `test` (legs `PHP 8.3`, `PHP 8.4`, `PHP 8.5` and `PHP 8.4 / Twig ^4.0@alpha`; each runs `phpunit` + `phpstan`), `composer hygiene` (advisory audit + `composer normalize` check) and `code style (PER-CS)`. `.github/workflows/dependency-review.yml` runs on PRs.

- Keep the leg names stable: `PHP 8.3` and `PHP 8.4` may be required checks. The Twig 4 leg adds ` / Twig <constraint>` to its name.
- The Twig 4 leg runs `composer require --no-update "twig/twig:^4.0@alpha"` on the runner, then `composer install` (no lock file, so it resolves fresh). `composer.json` in the repo stays `^3.27`. The leg is not `continue-on-error`: Twig 4 alpha passes today, so a red leg is a real signal.
- Dependabot uses `versioning-strategy: widen` for Composer. This library tracks no `composer.lock`, so Dependabot edits constraints only. `widen` adds the new major to the range and keeps the old lower bound.

## Refreshing from Drupal 11.x upstream

The five source files in `src/` are vendored from Drupal core. When upstream changes meaningfully:

**Watch.** `.github/workflows/upstream-watch.yml` runs weekly (and on `workflow_dispatch`). It runs `php scripts/check-upstream.php` (plain PHP, no dependencies) and, on exit 1, opens or updates ONE issue, "Upstream Drupal changed the vendored Attribute classes", label `dependencies`. It never closes the issue. Two jobs: `check` (read-only token, runs the actions) and `report` (`issues: write`, runs only `gh`). Its actions are pinned to commit SHAs (Dependabot updates them). Exit 2 (download or parse error) fails the job and opens no issue.

- Run it by hand: `php scripts/check-upstream.php`. Exit 0 = no functional drift, 1 = drift or a new `SA-CORE` commit, 2 = error. The Markdown report is on stdout. Options: `--upstream=DIR|URL`, `--src=DIR`, `--commits=api|none|DIR`, `--reviewed=FILE`.
- It normalizes the intended differences in upstream, then compares token by token with `src/`: namespace `Drupal\Core\Template` → `Drupal\Component\Attribute`; class `Attribute` → `AttributeCollection`; `Html::escape` → `Escape::html`; `use` lines for `Html`, `PlainTextOutput`, `NestedArray` swapped to `Parisek\Twig\Internal\*`; `use` lines for `MarkupInterface` and `JsonSchema` dropped; the `#[JsonSchema(...)]` attribute dropped; `declare(strict_types=1)` ignored; `offsetSet($name, mixed $value)` signature. Comments and whitespace never count. A change inside any statement does. The rules are the constants `USE_MAP`, `LINE_MAP` and `DROPPED_ATTRIBUTES` in the script. A new deliberate edit in `src/` needs a new rule there, or the watch reports it as drift.
- `.upstream-reviewed` (JSON: `commit`, `date`, `reviewed_by`, `title`, `files`) records the review boundary. `files` holds the newest reviewed commit id of each of the five files. The script reads each file's upstream history (100 commits per page, at most 20 pages) back to that id. The boundary is the commit id, never a date, so a cherry-pick with an old date still counts. Everything listed before the id is new. An `SA-CORE` title makes the run exit 1 until the marker moves. Other new commits are listed but do not fail the run. If an id is not found within 20 pages, the run exits 1 with "Review boundary lost".
- Downloads: only `https://git.drupalcode.org/` (raw files) and `https://git.drupalcode.org/api/v4/` (API), or a local directory. No redirects, 2 MB cap, timeouts. The report stays under 60,000 bytes (commit titles are cut, control characters removed, HTML-escaped).
- After you review and port upstream changes: run `php scripts/check-upstream.php --mark-reviewed="Your Name"`. It runs the same scan as a check (with no marker file, it reads up to the page cap and gates every `SA-CORE` commit it finds) and writes nothing (exit 1) while any of these holds: functional drift exists; the boundary is lost; an `SA-CORE` commit since the boundary is not acknowledged. Acknowledge each reviewed `SA-CORE` commit with `--ack=ID[,ID...]` (12+ hex characters each); the file then records `acknowledged`. An `--ack` that matches no `SA-CORE` commit gives a warning; a prefix that matches two commits is refused. The write is atomic (temp file, check, rename). `--reset-boundary` accepts a lost boundary, prints a warning and records `boundary_reset`. Use it only after you read the recent upstream history by hand. Commit `.upstream-reviewed` with the port, and refresh the snapshot in `tests/fixtures/upstream-watch/upstream/` (`<Name>.php.txt`) from the same fetch.
- `--allow=PREFIX` is a test hook for the local fake server in `UpstreamCheckTest`. It accepts only `http://127.0.0.1:PORT/` and `http://localhost:PORT/`. The workflow never passes it (a test checks this).

Manual refresh steps:

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

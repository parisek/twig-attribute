# Refresh decisions — 1.6.0

Captured from upstream Drupal 11.x sources fetched on 2026-05-25 (HEAD of `11.x`).

## Outside symbols reached by upstream

| Symbol | Used in | Call sites | Strategy |
|---|---|---|---|
| `Drupal\Component\Utility\Html::escape()` | `AttributeValueBase::render()`, `AttributeString::__toString()`, `AttributeArray::__toString()`, `AttributeBoolean::__toString()` | 4 | Already handled: replace with `Parisek\Twig\Internal\Escape::html()` (Task 2). |
| `Drupal\Component\Render\MarkupInterface` | `Attribute` (implements), `Attribute::createAttributeValue()` (type check guard in the fork — upstream switched to `\Stringable`) | Interface only; class declares `implements MarkupInterface` | Define `Drupal\Component\Attribute\MarkupInterface` as a minimal interface extending `\JsonSerializable, \Stringable` with `__toString(): string`. ~10 LOC. |
| `Drupal\Component\Render\PlainTextOutput::renderFromHtml()` | `Attribute::createAttributeValue()` | 1 | Inline as `Parisek\Twig\Internal\PlainTextOutput::renderFromHtml()`. Body is `html_entity_decode(strip_tags((string) $string), ENT_QUOTES, 'UTF-8')` — zero further Drupal deps. ~10 LOC. |
| `Drupal\Component\Utility\NestedArray::mergeDeep()` | `Attribute::merge()` | 1 | Inline as `Parisek\Twig\Internal\NestedArray` carrying only `mergeDeep()` + `mergeDeepArray()`. Pure PHP, no outside deps. ~30 LOC. |
| `Drupal\Core\Serialization\Attribute\JsonSchema` | `Attribute::__toString()` (PHP attribute `#[JsonSchema(...)]`) | 1 (decorative) | Drop the `#[JsonSchema(...)]` annotation from the ported class. It carries no runtime effect; it exists only for Drupal's JSON Schema discovery tooling. No shim needed. |

## Key upstream diff vs. fork (AttributeCollection.php)

The upstream `Attribute.php` changed the `createAttributeValue()` guard in one notable way:

- **Fork** (`AttributeCollection.php` line 142): `elseif ($value instanceof MarkupInterface)`
- **Upstream** (`Attribute.php` line 152): `elseif ($value instanceof \Stringable)`

This is a deliberate upstream broadening — any `Stringable` object gets its HTML stripped via `PlainTextOutput::renderFromHtml()`, not just `MarkupInterface` objects. The ported class will follow the upstream behaviour (`\Stringable`), which means `MarkupInterface` still works transitively (it extends `\Stringable`), but no import of `MarkupInterface` is required inside `createAttributeValue()`. The class itself still `implements MarkupInterface` for API compatibility.

The upstream `offsetGet()` also adds a lazy-init guard for the `class` key (returns an empty `AttributeArray` instead of `NULL`). The port will adopt this too.

## Dep chain termination

All inline shims terminate at plain PHP builtins:

- `PlainTextOutput::renderFromHtml` → `strip_tags()` + `html_entity_decode()` — PHP core only.
- `NestedArray::mergeDeep/mergeDeepArray` — pure PHP array operations, no further imports.
- `MarkupInterface` — extends `\Stringable` + `\JsonSerializable` — PHP core only.
- `Escape::html()` (already done, Task 2) → `htmlspecialchars()` — PHP core only.

No chain exceeds one level. Escalation threshold not reached.

## Per-symbol inline plan

### `Parisek\Twig\Internal\PlainTextOutput`

File: `src/Internal/PlainTextOutput.php`

Methods needed: `renderFromHtml(string|object $string): string`

Body (from `Html::decodeEntities(strip_tags(...))`, expanding `decodeEntities` inline):
```php
return html_entity_decode(strip_tags((string) $string), ENT_QUOTES, 'UTF-8');
```

Estimated LOC: ~10 (class shell + docblock + method). No further deps.

### `Drupal\Component\Attribute\MarkupInterface`

File: `src/MarkupInterface.php` (public namespace, not `Internal\` — it forms part of the package's public API because consumers may pass objects implementing it)

Interface: extends `\JsonSerializable, \Stringable`, declares `public function __toString(): string`.

Estimated LOC: ~10. No further deps.

### `Parisek\Twig\Internal\NestedArray`

File: `src/Internal/NestedArray.php`

Methods needed: `mergeDeep(...$arrays): array` and `mergeDeepArray(array $arrays, bool $preserve_integer_keys = false): array`.

The remaining methods in upstream `NestedArray` (`getValue`, `setValue`, `unsetValue`, `keyExists`, `filter`) are **not** referenced by the attribute classes and must not be included — keep the shim minimal.

Estimated LOC: ~30 (two method bodies copied verbatim from upstream). No further deps.

### `Drupal\Core\Serialization\Attribute\JsonSchema` — DROP

The `#[JsonSchema(...)]` PHP attribute on `__toString()` is Drupal-internal tooling for JSON Schema discovery. It has no runtime effect. Dropping it from the ported class does not change behaviour. No shim. No import.

## LOC estimate summary

| Shim | Est. LOC |
|---|---|
| `Internal\PlainTextOutput` | ~10 |
| `MarkupInterface` (interface) | ~10 |
| `Internal\NestedArray` (mergeDeep only) | ~30 |
| `Internal\Escape` (already done) | 5 (done) |
| **Total new** | **~50** |

Well within the 120 LOC escalation threshold. No escalation needed.

## Adopted divergences

### PHPStan level 5 (not 6)

Level 6 was attempted first and produced **17 errors**, all `missingType.iterableValue` / `missingType.parameter` / `missingType.return` on ported Drupal source files (`src/AttributeCollection.php`, `src/AttributeArray.php`, `src/AttributeValueBase.php`, `src/Internal/NestedArray.php`, `AttributeExtension.php`). These are missing generic-typed array annotations and untyped constructor parameters inherited verbatim from upstream Drupal 11.x.

Fixing them in Task 10 would touch `src/` files (out of scope for the PHPStan task). The parent `parisek/styleguide` repo also runs PHPStan at level 6 via `phpstan/phpstan ^2.0`, but that codebase uses PHPStan extensions and is fully typed. Since dialing down was explicitly allowed by the task plan when the count exceeds ~5, **level 5** was chosen.

Level 5 produced **0 errors** after two targeted fixes to `src/AttributeCollection.php` (the `@implements ArrayAccess` generic annotation and `offsetSet` parameter type), which were logic-correctness fixes unrelated to the type-annotation gap:

- `@implements \ArrayAccess<string, mixed>` (was `AttributeValueBase`) — `offsetSet` intentionally accepts raw input that is then converted by `createAttributeValue()`.
- `offsetSet($name, mixed $value)` explicit type hint — mirrors the above.
- `treatPhpDocTypesAsCertain: false` in `phpstan.neon` — silences a spurious `instanceof.alwaysTrue` where PHPDoc type narrowing causes PHPStan to see an `instanceof` check that is always true.

Upgrading to level 6 later: annotate the ported `src/` files with `array<string, mixed>` / `array<int, string>` generics as appropriate, remove `treatPhpDocTypesAsCertain: false`, and bump `phpstan.neon` to `level: 6`.

## Other notes

- The upstream `Attribute.php` has `#[\ReturnTypeWillChange]` attributes removed — return types are now explicit throughout. The port will adopt the explicit return types.
- `@internal` markers: none present on the upstream `Attribute*` classes themselves. The `Internal\*` shim classes will be marked `@internal` per package convention.
- The upstream test file (`AttributeTest.php`) imports `Drupal\Core\Render\Markup` and `Drupal\Tests\UnitTestCase` which are Drupal-specific. Task 4 will audit that test and strip/replace those deps before adopting it as the package test baseline.

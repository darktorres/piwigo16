# Mixed Type Audit

PHPStan level 10 at zero errors, but 879 occurrences of `mixed` across 160 files remain.
This document catalogs them by category and describes what it would take to eliminate each.

Counts: lambda 289 · docblock-global-var 104 · array-annotation 611 · return 67 · param 108 · other 93  
**Total: ~1272 (overlapping categories); unique lines ≈ 879**

---

## Category 1 — `fn (mixed $v)` lambda callbacks (289)

### Pattern

```php
array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows)
```

### Why they exist

`fetchFirstColumn()` in Doctrine DBAL returns `list<mixed>`, and `fetchAllAssociative()`
returns `list<array<string,mixed>>`. Until either:

- Doctrine adds typed stubs per query (impossible statically), or
- We use a typed wrapper that returns `list<int>` / `list<string>` directly,

the lambda parameter must be `mixed`.

### Path to fix

**Option A — custom query wrappers** (medium effort, high value):  
Add typed helpers like `DbConnection::fetchIntColumn(string $sql): list<int>` that internally
cast and return a narrowed type. Callers replace:

```php
array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $conn->fetchFirstColumn($sql))
```

with:

```php
DbConnection::fetchIntColumn($sql)  // returns list<int>
```

~100 of the 289 lambdas would disappear. The rest (in batch/admin code over heterogeneous
results) would remain until those queries are also wrapped.

**Option B — PHPStan extension** (high effort, complete):  
Write a `DynamicMethodReturnTypeExtension` that infers the element type of
`fetchFirstColumn()` from the SELECT column's type declaration in the query string.
Brittle and hard to maintain.

**Recommendation**: Option A for the most common patterns; accept the rest.

---

## Category 2 — Global variable `@var` bridges (104)

### Pattern

```php
/** @var array<string, mixed> $page */
$page = &$GLOBALS['page'];
/** @var array<string, mixed> $user */
$user = &$GLOBALS['user'];
```

### Why they exist

`$GLOBALS` is `array<string, mixed>`. The `@var` annotation narrows from `mixed` to
`array<string, mixed>`, which at least lets PHPStan check offset accesses.

### Path to fix

These will disappear automatically once `$page` and `$user` are replaced by typed objects
(e.g. `PageState::current()` already exists; `CurrentUser::get()->rawAttributes` is the
migration path for `$user`; `$lang` has `LanguageStack::lang()`). The remaining global
bridge annotations are in controllers/admin files that still read the globals directly.

**Effort**: Directly tied to the broader global-elimination roadmap. No standalone fix —
removing the `@var` without the typed object would cause PHPStan errors.

---

## Category 3 — `array<mixed>` and `array<string,mixed>` annotations (611)

This is the largest category. It splits into several sub-groups:

### 3a — DB row results (largest sub-group)

```php
/** @return array<string,mixed>|null */
public function findById(int $id): array|null   // returns fetchAssociative() row
```

`fetchAssociative()` returns `array<string,mixed>|false`. These annotations are **correct**
and should stay as-is. Fixing them would require typed value objects (DTO/Entity) for every
DB row shape.

**Path to fix**: Introduce Doctrine entity classes or repository result objects per table.
High effort, architectural decision — not a typing tweak.

### 3b — Config arrays

```php
/** @var array<string,mixed> */
private static array $data = [];
```

Config values are mixed by nature (bool, int, string, array). `array<string,mixed>` is
the correct type here. The 3 specific Config methods (`links()`, `filterPages()`,
`defaultFiltersViews()`) were downgraded to `array<mixed>` because they return
`is_array($v) ? $v : []` where `$v` comes from `Config::$data[key]`. To restore
`array<string,mixed>`, those would need a narrowing cast or initializer with a known shape.

### 3c — EventDispatcher data arrays

```php
/** @return array<mixed> */
public function getCategoryItems(...): array  // dispatches events, returns mixed data
```

EventDispatcher's `dispatch()` returns the type of its second argument via DRTPE. When
that argument is `[]`, it returns `array<never,never>` widened to `array<mixed>`.

**Path to fix**: Make `EventDispatcher::dispatch()` fully generic with `@template T` so
callers that pass `array<string,int>` get back `array<string,int>`. Medium effort.

### 3d — Admin service result arrays

Many `@return array<mixed>` on admin service methods that return raw DB result sets.
Same as 3a — needs DTOs/result objects.

---

## Category 4 — Return type `mixed` (67)

| Location                        | Method                                | Proper type                                 | Effort                                       |
| ------------------------------- | ------------------------------------- | ------------------------------------------- | -------------------------------------------- |
| `Config::raw()`                 | Read any config key                   | `string\|int\|bool\|array<mixed>\|null`     | Low — change annotation only; no code change |
| `Config::confGetParam()`        | Same                                  | Same                                        | Low                                          |
| `CookieService::getCookieVar()` | Cookie value                          | `string\|null` (cookies are always strings) | Low                                          |
| `RequestCache::get/remember()`  | Cache hit                             | Generic `@template T`                       | Medium                                       |
| `PersistentCache::get()`        | Cache hit                             | Generic `@template T`                       | Medium                                       |
| `ImageAdminService::getFs()`    | Filesystem scan                       | `array<mixed>\|string\|false`               | Low                                          |
| `Plugins::performAction()`      | Plugin hook return                    | `list<string>` (errors list)                | Low                                          |
| `CommonBootstrap::readGlobal()` | `$GLOBALS[key]`                       | Stays `mixed` — it's the point              | —                                            |
| Various event handler returns   | `EventDispatcher::dispatch()` generic | `@template`                                 | Medium                                       |

**Total fixable with annotation-only changes**: ~15 of 67.  
**Fixable with `@template` generics on cache/dispatcher**: another ~20.  
**Legitimately mixed**: remaining ~32 (Config raw, EventDispatcher data, globals).

---

## Category 5 — Parameter type `mixed` (108)

### 5a — ID parameters typed `mixed` (should be `int|string`)

```php
public function deleteUser(mixed $userId): void
public function deleteSite(mixed $id): void
public function getGroupname(mixed $groupId): string|false
```

These accept numeric IDs that arrive as either `int` or `string` from different callers.
**Fix**: Change to `int|string`. Callers already narrowed with `(int)` casts internally.
~25 occurrences. **Low effort** — annotation change + verify no callers pass other types.

### 5b — Multi-type aggregation parameters

```php
public function moveCategories(mixed $categoryIds, mixed $newParent = -1): void
public function setTags(mixed $tags, mixed $imageId): void
```

These accept `int|int[]|string[]` — multiple shapes from different callers.
**Fix**: Union types: `int|string|array<int>`. **Medium effort** — needs caller survey.

### 5c — Event data parameters

```php
public function initialize(mixed $inner_sql): void    // CalendarBase
```

Receives SQL fragments or null — legitimately mixed until the calendar API is redesigned.

### 5d — Image library `compose()` overlay

```php
public function compose(mixed $overlay, int $x, int $y, int $opacity): bool
```

`$overlay` is an `ImageInterface` in all concrete implementations, but the interface
declares `mixed` to allow any implementation. **Fix**: Change to `ImageInterface`. **Low**.

### 5e — Reference parameters for output

```php
public function fetchRemote(string $src, mixed &$dest, ...): bool
```

`$dest` is an output parameter written by the method — the caller passes a variable by
reference to receive the result (string or resource). **Fix**: `string|resource &$dest`
or split into separate methods. **Medium**.

---

## Category 6 — Other `mixed` usages (93)

Mostly:

- `__call(string $method, array $arguments): mixed` — magic methods; legitimately mixed
- `array_map` callback typehints in docblocks
- `mixed $value` in cache/config persistence methods — legitimately mixed (stores any PHP value)
- `fn(mixed $v): string` in closures passed to array functions where input is DB column

---

## Summary Table

| Category                        | Count     | Legitimately mixed      | Fixable                                           | Effort     |
| ------------------------------- | --------- | ----------------------- | ------------------------------------------------- | ---------- |
| Lambda `fn(mixed $v)` callbacks | 289       | ~189 (DB column)        | ~100 (typed query wrappers)                       | Medium     |
| Global var `@var` bridges       | 104       | 104                     | 0 (tied to global elimination)                    | Blocked    |
| `array<mixed>` annotations      | 611       | ~450 (DB rows, config)  | ~161 (EventDispatcher generic, Config annotation) | Medium     |
| Return type `mixed`             | 67        | ~32                     | ~35 (annotation + @template)                      | Low–Medium |
| Parameter type `mixed`          | 108       | ~35 (event data, magic) | ~73 (ID unions, overlay typed)                    | Low–Medium |
| Other                           | 93        | ~70                     | ~23                                               | Mixed      |
| **Total**                       | **~1272** | **~880**                | **~392**                                          |            |

## Recommended Next Steps (highest ROI)

1. **`ImageInterface::compose(mixed $overlay)` → `ImageInterface $overlay`** — 4 files, trivial.
2. **`CookieService::getCookieVar()` → `string|null`** — cookies are always strings.
3. **ID parameters `mixed $id`/`$userId` → `int|string`** — ~25 methods, annotation change.
4. **`Config::raw()` → `string|int|bool|array<mixed>|null`** — annotation change.
5. **`EventDispatcher::dispatch()` → `@template T`** — eliminates many downstream `mixed`s.
6. **Typed DB query helpers** (`fetchIntColumn`, `fetchStringColumn`) — removes ~100 lambdas.
7. **`RequestCache`/`PersistentCache` → `@template T`** — typed cache reads.

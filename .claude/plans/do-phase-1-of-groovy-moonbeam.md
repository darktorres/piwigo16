# Phase 1 execution plan — §1.7 campaign, F5-i deep adoption

## Context

Phase 1 of the §1.7 master campaign (`.claude/plans/section-1-7-campaign.md`).
Goal: rewrite `SearchService` + `SearchFilterRenderer` to consume the already-shipped
`SearchRules` VOs end-to-end; add the 2 missing helpers; eliminate the ~100 psalm-info
issues across both files.

**Pre-flight snapshot (2026-05-23)**

| Signal | Value | Target |
|---|---|---|
| `psalm --show-info` total | 1813 | <50 |
| `is_array(.* ?? null)` | 153 | 0 |
| Combined issues in Search{Filter,Service}.php | 98 | 0 |

---

## Files touched

**New:**
- `src/Piwigo/Search/Rules/DateCustom.php`
- `src/Piwigo/Search/Rules/MysqlDateRange.php`
- `src/Piwigo/Search/SearchDetails.php`
- `tests/Unit/Search/Rules/DateCustomTest.php`
- `tests/Unit/Search/Rules/MysqlDateRangeTest.php`

**Modified:**
- `src/Piwigo/Search/SearchService.php`
- `src/Piwigo/Search/SearchFilterRenderer.php`
- `src/Piwigo/Search/FilterRenderContext.php`  (if SearchDetails replaces array)
- `src/Piwigo/Section/SectionInitializer.php`  (SC-4: drops is_array guard)
- `src/Piwigo/Ws/Action/Pwg/Images/SearchHandler.php`  (SC-4: drops is_array guard)

---

## Sub-commit 1 — `feat(search): add DateCustom + MysqlDateRange helpers`

**What to build:**

`MysqlDateRange` — value object for a begin/end MySQL datetime pair:
```php
final readonly class MysqlDateRange {
    public function __construct(
        public string $begin,  // 'YYYY-MM-DD HH:MM:SS'
        public string $end,
    ) {}
    public function toBetweenClause(string $column): string {
        return $column . ' BETWEEN "' . $this->begin . '" AND "' . $this->end . '"';
    }
}
```

`DateCustom` — encapsulates the `buildCustomDateClauses` logic currently in
`SearchService::buildCustomDateClauses()` (lines 481–510).  Extracts it as a static
factory that handles y/m/d coalescing and returns typed `list<MysqlDateRange>` instead
of `list<string>`:
```php
final readonly class DateCustom {
    /** @param list<string> $codes */
    public static function buildRanges(array $codes): array  // list<MysqlDateRange>
}
```

**Unit tests** (`tests/Unit/Search/Rules/DateCustomTest.php`):
- empty list → []
- single year code → one MysqlDateRange with correct begin/end
- year + month for same year → month coalesced (only year range returned)
- year + month for different year → two ranges returned
- day code alone → correct single-day range
- day code whose year is in set → coalesced

**Unit tests** (`tests/Unit/Search/Rules/MysqlDateRangeTest.php`):
- `toBetweenClause('date_available')` returns expected SQL string

**Do NOT swap SearchService yet** — SC-3 does the swap and drops the old private method.

Run `composer dump-autoload` after creating the new classes.

---

## Sub-commit 2a — `refactor(search): SearchFilterRenderer date renders typed`

**Root cause of the ~30 MixedAssignment issues in date renders:**
`normalizeDateData()` declares its return as `array{pre_counters: array<string, int>, list_of_dates: array<mixed>}`.
The `array<mixed>` for `list_of_dates` poisons every subsequent array read in
`renderDatePostedFilter` (lines 401–435) and `renderDateCreatedFilter` (lines 502–533).

**Fix 1: tighten `normalizeDateData` return type.**
The list_of_dates structure built by both render methods shares a shape.
Add a `@phpstan-type` or inline `@var` annotation before the `$list_of_dates = []`
declaration in both render methods:

```php
/** @var array<string, array{count: int, months: array<string, array{count: int, days: array<string, array{count: int}>}>}> $list_of_dates */
$list_of_dates = [];
```

Then change `normalizeDateData`'s return type from `array{…, list_of_dates: array<mixed>}`
to `array{pre_counters: array<string, int>, list_of_dates: array<string, array{count: int, months: array<string, array{count: int, days: array<string, array{count: int}>}>}>}`.

**Fix 2: remove `is_array(...)` guards on the date list traversal.**
With the typed `$list_of_dates`, the guards at lines 410, 413, 416, 418, 423, 511, 514,
517, 519, 523 become redundant and Psalm will flag them as always-true.  Delete them,
leaving direct typed reads.

**Fix 3: add `label` key to the type shape** where `renderDatePostedFilter` adds
`$dp_list[$y]['label']` etc. Either widen the shape or reconstruct the array with label
on first write.  Simplest: build a new `$dp_list_rendered` array that starts with the
right shape including label rather than mutating `$dp_list`.

Expected: ~30 issues resolved.

---

## Sub-commit 2b — `refactor(search): SearchFilterRenderer filter renders typed`

Remaining mixed-access sites in SearchFilterRenderer (not date-related):

**Cat filter** (lines 612–636):
- `$ctx->mySearch->fields['cat']` is `mixed` → replace with `$ctx->rules->cat`
- `$cat_field['words']` becomes `$ctx->rules->cat->categoryIds` (already `list<int>`)
- Drop `is_array($cat_field['words'] ?? null)` guard; use `$ctx->rules->cat->categoryIds` directly
- Keep the `$ctx->mySearch->fields['cat']` write (it feeds the template JSON) but construct it
  directly from typed VO: `['words' => array_map(strval(...), $ctx->rules->cat->categoryIds), 'sub' => $ctx->rules->cat->subIncluded]`

**Added-by filter** (lines 598–600):
- `$added_by_field_raw = is_array($ctx->mySearch->fields['added_by']) ? ... : []` →
  replace with `$ctx->rules->addedBy?->userIds ?? []` which is already `list<int>`
- `$added_by_field_int` → typed directly as `list<int>`

**Filesize filter** (lines 749–756):
- `$fs_min_raw = $ctx->mySearch->fields['filesize_min']` is `mixed` →
  read from `$ctx->rules->fileSize` which has typed `minKb`/`maxKb` int properties
- `FileSizeFilter` already exists with `minKb: int` and `maxKb: int`.  Use them directly.

**Height/width cache reads** (lines 832–835, 875–877):
- `$filter_rows_raw = $item_h->get()` is `mixed`; the cached value is `list<int>` from
  `findDistinctHeightsForFilter` / `findDistinctWidthsForFilter`
- Add `/** @var list<int> $filter_rows_raw */` annotation after the cache `get()` call.
  Rationale comment: cache stores the typed return of `findDistinct*For Filter()`.
- Remove the `is_array(...) ? array_map(intval, ...) : []` wrapper since the cached value
  is already `list<int>`.

**Ratings cache read** (line 692):
- `$ratings_raw = $cache_hit_ratings ? $item_rat->get() : null` → `mixed`
- Add `/** @var array<int, int>|null $ratings_raw */` after the ternary.
  Rationale: cached value is the `$ratings = array_fill(0, 6, 0)` array built below.

**Tags filter** (lines 256, 260, 271):
- `usort($filter_tags, fn (mixed $a, mixed $b): ...)` — `$filter_tags` comes from
  `getAvailableTags()` / `getCommonTags()`; check their return types and narrow
  accordingly.  If they return `list<array{id: int, name: string, ...}>`, drop the
  `is_array($a) ? $a : []` guards.

Expected: ~20–25 issues resolved.

---

## Sub-commit 3 — `refactor(search): SearchService SearchDetails state object`

**Root cause in SearchService:** `$this->searchDetails` is `array<string, mixed>`
(line 47–48).  Per `[[feedback_extract_state_object_not_phpstan_impure]]`, pull these
slots into a typed state object.

**New `SearchDetails` (mutable, not readonly):**
```php
final class SearchDetails {
    public ?SqlFragment $forbidden = null;
    /** @var list<int>|null */
    public ?array $matchingCatIds = null;
    /** @var list<int>|null */
    public ?array $matchingTagIds = null;
    public bool $hasFiltersFilled = false;
    /** @var array<string, list<int>> */
    public array $imageIdsForFilter = [];
    /** @var array<string, list<int>> */
    public array $filterItemsCache = [];
}
```

**Changes in SearchService:**
- Replace `private array $searchDetails` with `private SearchDetails $searchDetails`.
  Initialize in constructor: `$this->searchDetails = new SearchDetails()`.
- `getSearchDetails()`: returns `SearchDetails` (rename to keep public API if callers
  use the array form — see note below).
- `setSearchDetails()`: replace with typed setters or accept SearchDetails directly.
  Current callers: only `SearchFilterRenderer::render()` (line 65 — passes SectionContext's
  `$ctx->searchDetails` array).  Must handle: `SectionContext::$searchDetails` is still
  `array<mixed>` coming from `SectionInitializer`.  
  Strategy: keep a `setSearchDetailsFromArray(array $raw): void` overload during
  migration to avoid breaking SectionContext.  Drop it in SC-4 once caller is fixed.
- `setForbidden(SqlFragment)`: sets `$this->searchDetails->forbidden`.
- `getRegularSearchResults()`: write typed `SearchDetails` instance; return type
  becomes `array{items: list<int>, search_details: SearchDetails}`.
  Per `[[feedback_refactor_multi_use_callers_first]]`, update `SectionInitializer`
  and `SearchHandler` first (they read `search_details` from the return value).
- `getItemsForFilter()`:
  - `$imageIdsForFilter = $this->searchDetails->imageIdsForFilter` (typed directly)
  - `$cache = $this->searchDetails->filterItemsCache` (typed directly)
  - Drop the `is_array(... ?? null)` guards at lines 528, 536, 542, 546, 547, 564.
- `buildCustomDateClauses()`: replace private static body with call to
  `DateCustom::buildRanges($codes)` and map `->toBetweenClause($dateColumn)`.
  Delete the private static method.
- **Pair ([[feedback_cleanup_with_retype]]):** all `is_array($this->searchDetails[...])`
  guards removed in the same commit.

**Callers needing update in this commit:**
- `SectionInitializer::handleSearchSection()` (line 358–360): currently reads
  `$searchResult['search_details']` as array.  Update to accept `SearchDetails` and
  read `.matchingCatIds`, `.matchingTagIds` typed.
- `SearchHandler.php` line 54: `is_array($searchResultArr['items'] ?? null)` → typed
  direct access.

Expected: ~45 issues resolved (the bulk of SearchService's 47 + callsite guards).

---

## Sub-commit 4 — `refactor(search): drop is_* guards at search call sites`

Sweep the call-site files for leftover `is_array` / `is_numeric` guards made redundant
by SC-3's typed return:

- `SearchController.php` line 96: `$fields = is_array($fields_raw) ? $fields_raw : $default_fields`
  — if `$fields_raw` is now typed, drop guard.
- `SectionInitializer.php` line 354–365: any remaining `isset($searchResult['qs'])`
  / `isset($searchResult['search_details'])` patterns — validate and drop.
- `SearchFilterRenderer::render()` lines 61–65: `getSearchDetails()` now returns
  `SearchDetails`; the `$search_details === []` empty-check changes to
  `$this->searchService->getSearchDetails()->imageIdsForFilter === []` or similar.
  Adjust `matching_cat_ids` / `matching_tag_ids` reads at lines 188–215 to typed
  property reads from `SearchDetails`.

Commit must keep `is_array(?? null)` count at or below previous commit's value
(none introduced; 5–10 dropped from callsites).

---

## Ordering within the session

```
SC-1 → composer dump-autoload → composer lint → composer analyse → commit
SC-2a → composer lint → composer analyse → commit
SC-2b → composer lint → composer analyse → commit
SC-3 → composer dump-autoload → composer lint → composer analyse → commit
SC-4 → composer lint → composer analyse → commit
After all 4: composer test (full suite)
```

If SC-2a + SC-2b are under 500 LoC combined they can be a single commit.

---

## Verification (full per §1.7)

```bash
# After each sub-commit
composer lint:php           # Pint — catch unused imports
composer analyse            # PHPStan level-10 + Psalm errorLevel-2

# After all sub-commits
composer test               # 1239-test baseline
vendor/bin/psalm --show-info=true 2>&1 > /tmp/psalm-phase1.txt
grep '^INFO:' /tmp/psalm-phase1.txt | grep -E 'SearchFilter|SearchService' | wc -l
# target: 0

# is_array gate progress
grep -rnE 'is_array\(.*\?\? null\)' src/ | wc -l  # should drop from 153

# Psalm-info total
grep -E '^[0-9]+ issues' /tmp/psalm-phase1.txt  # target: reduce by ≥100
```

Browser smoke (required before merging — F5 plan flags "high-risk"):
- Exercise every filter combination: allwords, tags, dates (preset + custom codes),
  ratios, ratings (if enabled), dimensions, expert, added_by, filetypes.
- Confirm `template/search.latte` renders filter widgets correctly with no JS errors.
- Run with an active search that has `matching_cat_ids` and `matching_tag_ids` to verify
  "Albums found" / "Tags found" blocks still render.

Integration test to stay green:
- `tests/Integration/Repository/SearchRepositoryTest.php`

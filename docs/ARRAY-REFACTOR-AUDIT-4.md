# Array → Enum / VO / DTO Refactor Audit — Round 4

Follow-up to `ARRAY-REFACTOR-AUDIT-3.md`. Fresh grep pass across all 839 src/
files; focuses on shaped `array{...}` annotations, `return [...]` with string
keys, and `array<mixed>` signatures that hide a known structure.

---

## Tier 1 — Named result DTOs (shaped arrays already fully typed)

These are `array{...}` return types where every key and type is known. Pure
additions — no callers need to change because the new class gets a `toArray()`
shim if needed, or callers are already using the keys directly.

| ID  | Location                                           | Current shape                                                                                | Proposed type                                                                 |
| --- | -------------------------------------------------- | -------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| A1  | `Rate/RateRepository.php:200`                      | `array{count: int, average: float\|null}`                                                    | `RateStats` readonly class                                                    |
| A2  | `Image/DerivativeSizeRepository.php:30`            | `array{enabled: array<string, DerivativeParams>, disabled: array<string, DerivativeParams>}` | `PartitionedDerivativeSizes` readonly class                                   |
| A3  | `Image/DerivativeSettingsRepository.php:31`        | `array{quality: int, watermark: WatermarkParams, custom: array<string, int>}`                | `DerivativeSettings` readonly class                                           |
| A4  | `Admin/Extensions/IgnoredUpdatesRepository.php:39` | `array{plugins: list<string>, themes: list<string>, languages: list<string>}`                | `IgnoredExtensionLists` readonly class                                        |
| A5  | `Admin/Upload/UploadService.php:576`               | `array{width: int, height: int, filesize: int\|float}`                                       | `ImageFileInfo` readonly class                                                |
| A6  | `Admin/Updates.php:193,226`                        | `array{minor?: string, major?: string, minor_php?: string, major_php?: string}`              | `AvailableVersions` readonly class (covers both the 2-key and 4-key variants) |
| A7  | `Config/Config.php:1265`                           | `array{RSS: array{max_dates, max_elements, max_cats}, NBM: array{...}}`                      | `NotificationConfig` + inner `NotificationChannelConfig` readonly classes     |

**A7 note:** `CommentsSummary::toArray()` at `Comment/CommentsSummary.php:17` is
an unnecessary escape hatch — the only caller (`Ws/Action/Pwg/Comments/GetListHandler.php:155`)
should receive the `CommentsSummary` object directly and the `toArray()` method
should be dropped.

---

## Tier 2 — Input DTOs (shaped array _parameters_)

Replace an `array{...}`-annotated `$data` / `$search` parameter with a typed
object. The constructor call site changes; the repository internals stay the same.

| ID  | Location                            | Current shape                                                                                                                  | Proposed type                                                                                                 |
| --- | ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------- |
| B1  | `Comment/CommentRepository.php:73`  | `array{author, author_id, anonymous_id, content, validated, image_id, website_url?, email?}` → `insert()`                      | `NewCommentData` readonly class                                                                               |
| B2  | `Comment/CommentRepository.php:134` | `array{content, website_url?: string\|null, validated: bool}` → `update()`                                                     | `CommentUpdateData` readonly class                                                                            |
| B3  | `Search/SearchService.php:175`      | `array{fields?: array<string,mixed>, mode?: string}\|array<string,mixed>` — an intersection type papering over a missing model | `SearchQuery` class; the `&array<string,mixed>` fallback is a code smell that disappears once the type exists |
| B4  | `Search/FilterRenderContext.php:25` | same `$mySearch` intersection type passed through                                                                              | follows B3 — `FilterRenderContext` receives `SearchQuery`                                                     |

---

## Tier 3 — Result union types (ok/error arrays)

Functions returning either `['error' => ...]` or `['info' => ..., 'id' => ...]`
should use a discriminated result object with static constructors.

| ID  | Location                                          | Current                                                                                          | Proposed                                                                 |
| --- | ------------------------------------------------- | ------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------ |
| C1  | `Admin/Category/CategoryAdminService.php:484,536` | `array{error: string}\|array{info: string, id: int}` from `createVirtualCategory()`              | `CreateCategoryResult` with `::error(string)` / `::success(int, string)` |
| C2  | `Admin/Tag/TagAdminService.php:302,310,312`       | `array{info: string, id: int}\|array{error: string}` from `createTag()`                          | `CreateTagResult` same pattern                                           |
| C3  | `Users/UserService.php:518+`                      | `array{error: array{code: int, message: string}}\|array{user_id: int, infos: ..., account: ...}` | `UpdateUserResult` VO                                                    |

---

## Tier 4 — Value Object: SlideshowParams

`PictureService` passes `array{period: int|float, repeat: bool, play: bool}`
through four method signatures and the array keys are hardcoded in
`PictureController` at lines 294, 298, 392, 395, 398, 400, 402.

The encode/decode lifecycle (`getDefault → correct → decode → encode`) maps
cleanly to constructor + instance methods on a `SlideshowParams` readonly class.
Eliminates all string key references in both files.

**Files touched:** `Picture/PictureService.php`, `Controller/PictureController.php`

---

## Tier 5 — Projection rows (CategoryRepository inner shapes)

Shaped arrays produced by raw SQL projections; the inner `array{...}` should
become a named projection class in `Category/Projection/`.

| ID  | Method                                                   | Shape                                                                                                                 | Proposed                                                                                    |
| --- | -------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| D1  | `CategoryRepository::findPhysicalSyncableForSite` `:925` | `array{id: int, id_uppercat: int\|null, uppercats: string, global_rank: string\|null, status: string, visible: bool}` | `PhysicalCategoryRow` projection                                                            |
| D2  | `CategoryRepository::findAdminListPage` `:3043`          | `array{id, name, comment, uppercats, global_rank, dir, status, image_order}` inside `PaginatedResult<array{...}>`     | `AdminCategoryRow` projection                                                               |
| D3  | `Admin/Sync/SiteSyncContext.php:24`                      | `$dbCategories: array<int, array<string, mixed>>` populated by `findPhysicalSyncableForSite`                          | share `PhysicalCategoryRow` (D1); `$dbCategories` becomes `array<int, PhysicalCategoryRow>` |
| D4  | `Admin/Sync/SiteSyncContext.php:18,20`                   | `list<array{path: string, type: string}>` and `list<array{path: string, info: string}>`                               | `SyncError` and `SyncInfo` mini-DTOs                                                        |

---

## Tier 6 — Config spec array (UploadService)

`UploadService::getUploadParamsDef` (`:63`) returns:

```php
array<string, array{
    default: bool|int|string,
    can_be_null: bool,
    min?: int,
    max?: int,
    pattern?: string,
    error_message?: string,
}>
```

The inner shape is a `UploadParamSpec` readonly class. Eliminates the repeated
`isset($spec['min'])` / `isset($spec['pattern'])` guard pattern across the three
callers that iterate over the map.

---

## Not worth typing

- `array<int, int>` keyed ID maps — already narrow, no semantics to add
- `WsHelper::getImageXmlAttributes()` → `list<string>` — already narrow enough
- `PwgImage::getSharpenMatrix()` → `array<int, array<int, float>>` — pure math matrix, arrays are correct
- `@return array<mixed>` entries in `AdminService`, `MetadataAdminService`, `CategoryService`, etc. — these surface large legacy surfaces; the right fix is SQL projection DTOs in a future dedicated pass, not one-off annotation improvements

---

## Suggested execution order

1. **Tier 1** (A1–A7) — pure additions, zero callers to migrate, highest density of improvement per line changed
2. **Tier 2 B1–B2** — `CommentRepository` input DTOs, single-file change
3. **Tier 4** — `SlideshowParams`, self-contained 2-file change
4. **Tier 5 D1/D3** — `PhysicalCategoryRow` + `SiteSyncContext` retype together
5. **Tier 5 D2/D4** — remaining projection rows
6. **Tier 6** — `UploadParamSpec`
7. **Tier 3** (C1–C3) — result unions, require touching controllers, do last
8. **Tier 2 B3–B4** — `SearchQuery`, requires the widest refactor surface

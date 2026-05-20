# Array → Enum / VO / DTO Refactor Audit — Round 3

Follow-up to `ARRAY-REFACTOR-AUDIT-2.md`. All 33 round-2 items are resolved
(31 done, 2 dropped: B4 MimeExtension — open set; C8 ImageInsertRow — disjoint
callers). D3 `UserFieldsMap` was also confirmed done during research (already a
typed class). This round picks up adoption gaps, newly discovered DTO shapes,
and the remaining SqlFragment missing site.

Every count, file path, and line number was re-verified by direct grep/Read
against `src/` (no subagents, per repo convention).

## Status legend

- 🟥 **Critical** — discriminator used 10+ sites, no typed equivalent yet
- 🟧 **High** — typed equivalent exists but the codebase ignores it
- 🟨 **Medium** — recurring shape worth a dedicated class
- 🟩 **Low** — single-cluster, do it as you touch the surrounding code

## What rounds 1 and 2 retired

For context — closed items not re-listed by accident:

Round 1: A1 `Section`, A2 `ExtensionAction`, A11 `ExtensionType`,
A13 `ActivityAction`, B2 `OrderSpec`, C1 `SqlFragment`, C3 `PluginRecord`,
C5 `ActivityRow`, C7 `TelemetryTechnical`/`AppStat`/`ActivityGroup`.

Round 2: A1' `UserStatus` (partial), A3 `Privacy` write path, A4–A18 enums,
B1 `MailAddress`, B3 `PermissionLevel`, B5 `Credentials`, B6 `LastVisit`,
C2 `SqlFilterClause`, C4 `ExtensionStat`, C6 `RatingScore`,
C9/C10 `SqlFragment` completions, C11 `PaginatedResult`,
C12 `TelemetryGeneralStats`, C13 4× `HistoryRepository` DTOs,
D1 `ActivityDetails`, D2 `SearchInfo`, D3 `UserFieldsMap`, D4 `ParamDefinition`.

---

## A. Enum adoption gaps

### A1 🟩 `UserStatus` — 3 remaining raw-string comparisons

`UserStatus` enum exists and has **39 adoption sites**. Three `in_array`
gates still compare raw strings:

- `src/Piwigo/Users/AuthService.php:341` —
  `in_array($key['status'], ['normal', 'generic'])`
- `src/Piwigo/Users/AuthService.php:402` —
  `in_array($userStatus, ['normal', 'generic'])`
- `src/Piwigo/Users/UserBootstrap.php:170` —
  `in_array($status, ['guest', 'generic'], true)`

**Refactor**: replace with typed comparisons —
`in_array(UserStatus::tryFrom($key['status']), [UserStatus::Normal, UserStatus::Generic])`.

### A2 🟨 `Privacy` — SQL literals and row-default strings in repositories

`Privacy` enum is fully adopted in `CategoryAdminService`, WS params, and
`CategoryRepository`'s read path. Nine raw-string sites remain on the write path:

SQL `WHERE` literals (replace with `Privacy::Private->value`):
- `src/Piwigo/Category/CategoryRepository.php:533` — `->where("status = 'private'")`
- `src/Piwigo/Category/CategoryRepository.php:2260` — same
- `src/Piwigo/Permission/PermissionRepository.php:184` —
  `c.status = 'private'` (inside raw SQL string)

Row-default string assignments (replace with `Privacy::Public->value`):
- `CategoryRepository.php:957, 2382, 2528, 2597, 3011, 3072` —
  `'status' => is_string($row['status'] ?? null) ? $row['status'] : 'public'`

**Refactor**: mechanical string-constant swap per site. No behavioural change —
`Privacy::Private->value === 'private'` and `Privacy::Public->value === 'public'`.

---

## B. Named DTO shapes (small, 1–2 consumers each)

### B1 🟨 `CommentsSummary` DTO

`src/Piwigo/Comment/CommentRepository.php:344` — `findCommentsSummary()` returns
`array{all_comments: int, validated: int, pending: int}`.

Consumer: `src/Piwigo/Ws/Action/Pwg/Comments/GetListHandler.php:88-98` —
accesses `$summary['all_comments']`, `$summary['pending']`, `$summary['validated']`.

**Refactor**:
```php
final readonly class CommentsSummary {
    public function __construct(
        public int $allComments,
        public int $validated,
        public int $pending,
    ) {}
}
```
`findCommentsSummary()` returns `CommentsSummary`; consumer reads typed properties.

### B2 🟩 `CommentDateRange` DTO

`src/Piwigo/Comment/CommentRepository.php:407` — `findCommentDateRange()` returns
`array{started_at: ?string, ended_at: ?string}`.

Consumer: `src/Piwigo/Ws/Action/Pwg/Comments/GetListHandler.php:144`.

**Refactor**:
```php
final readonly class CommentDateRange {
    public function __construct(
        public ?string $startedAt,
        public ?string $endedAt,
    ) {}
}
```
B1 and B2 share one commit (same file, same consumer).

### B3 🟩 `LoungeEntry` DTO

`src/Piwigo/Image/LoungeRepository.php:50` — `findOldestEntry()` returns
`array{image_id: int, date_available: string, dbnow: string}|null`.

Consumer: `src/Piwigo/Admin/Category/CategoryAdminService.php:696`.

**Refactor**: `final readonly class LoungeEntry { int $imageId; string $dateAvailable; string $dbnow; }`.

### B4 🟩 `FailedJob` DTO

`src/Piwigo/Job/MessengerRepository.php:20` — `findFailedJobById()` returns
`array{body: string, headers: string}|null`.

Consumer: `src/Piwigo/Controller/Admin/BatchManagerController.php:1418`.

**Refactor**: `final readonly class FailedJob { string $body; string $headers; }`.

### B5 🟩 `MaxIdAndCount` DTO

`src/Piwigo/Image/ImageRepository.php:244` — `findMaxIdAndCount()` returns
`array{0: int, 1: int}` (positional pair: next-id, count).

Consumer: `src/Piwigo/Ws/Action/Pwg/GetMissingDerivativesHandler.php:44` —
`[$maxId, $imageCount] = $this->imageRepository->findMaxIdAndCount()`.

**Refactor**:
```php
final readonly class MaxIdAndCount {
    public function __construct(
        public int $nextId,   // MAX(id) + 1
        public int $total,    // COUNT(*)
    ) {}
}
```
Named fields (`nextId`, `total`) are more expressive than positional `[0]`/`[1]`.

### B6 🟩 `LastUploadedCategoryInfo` DTO

`src/Piwigo/Image/ImageRepository.php:200` — `findLastUploadedCategoryInfo()` returns
`array{category_id: int, uppercats: string}|null`.

Consumer: `src/Piwigo/Admin/Upload/DirectPreparer.php:107`.

**Refactor**: `final readonly class LastUploadedCategoryInfo { int $categoryId; string $uppercats; }`.

---

## C. SearchRepository filter row DTOs (concentrated: 7 methods, 1 consumer)

All 7 `SearchRepository` filter methods returning `list<array<string, mixed>>`
are consumed **exclusively by `SearchFilterRenderer`**. Each has a fixed 2–3
field shape. Six DTO classes cover all seven (two methods share `ImageDateRow`).

### C1 🟨 `AuthorCountRow` — `{author: string, counter: int}`

`src/Piwigo/Search/SearchRepository.php:139` — `findAuthorsForFilter()`.
Consumer: `src/Piwigo/Search/SearchFilterRenderer.php:318` and `:325`
(cache-miss and cache-hit paths).
Key accesses: `$author['author']` (:329), row passed as `$filter_rows` to template.

**Refactor**:
```php
final readonly class AuthorCountRow {
    public function __construct(
        public string $author,
        public int    $counter,
    ) {}
}
```

### C2 🟨 `ImageDateRow` — `{id: int, date: string}`

`SearchRepository.php:191` — `findImageDatePostedRows()`.
`SearchRepository.php:207` — `findImageDateCreatedRows()`.
Identical shape — both consumed in `SearchFilterRenderer` (`:368`, `:467`).
Key accesses: `$row['date']` at `:370, :375, :468, :470, :475`.

**Refactor**: `final readonly class ImageDateRow { int $id; string $date; }`.
Both repo methods return `list<ImageDateRow>`.

### C3 🟨 `AddedByCountRow` — `{counter: int, added_by_id: int}`

`SearchRepository.php:223` — `findAddedByForFilter()`.
Consumer: `SearchFilterRenderer.php:559` and `:566`.

**Refactor**: `final readonly class AddedByCountRow { int $counter; int $addedById; }`.

### C4 🟨 `ImageRatingRow` — `{id: int, rating_score: float|null}`

`SearchRepository.php:287` — `findRatingsForFilter()`.
Consumer: `SearchFilterRenderer.php:691-701`.
Key accesses: `$row['rating_score']` at `:695, :701`.

**Refactor**: `final readonly class ImageRatingRow { int $id; float|null $ratingScore; }`.

### C5 🟨 `ImageFilesizeRow` — `{id: int, filesize: int|null}`

`SearchRepository.php:303` — `findFilesizesForFilter()`.
Consumer: `SearchFilterRenderer.php:731-732`.
Key accesses: `$row['filesize']` at `:732`.

**Refactor**: `final readonly class ImageFilesizeRow { int $id; int|null $filesize; }`.

### C6 🟨 `ImageDimensionRow` — `{id: int, width: int, height: int}`

`SearchRepository.php:319` — `findRatiosForFilter()`.
Consumer: `SearchFilterRenderer.php:785-786`.
Key accesses: `$row['width']` at `:785`, `$row['height']` at `:786`.

**Refactor**: `final readonly class ImageDimensionRow { int $id; int $width; int $height; }`.

**Suggested commit**: one commit covering all 6 DTOs (`src/Piwigo/Search/`)
+ the 7 repo method return-type changes + the `SearchFilterRenderer` property-access
updates. All files are in the `Search` namespace with a single consumer.

---

## D. PermissionRepository access row DTOs

### D1 🟨 `UserCatAccess` — `{user_id: int, cat_id: int}`

`src/Piwigo/Permission/PermissionRepository.php:18` — `findUserCategoryAccess()`.
`src/Piwigo/Permission/PermissionRepository.php:40` — `findGroupUserCategoryAccess()`.
Same 2-column shape. 4 consumer sites:
- `src/Piwigo/Ws/Action/Pwg/Permissions/GetListHandler.php:37` —
  `$row['cat_id']`, `$row['user_id']`
- `src/Piwigo/Ws/Action/Pwg/Permissions/GetListHandler.php:44` — same keys
- `src/Piwigo/Controller/Admin/MaintenanceController.php:1543` — `findUserCategoryAccess`

**Refactor**:
```php
final readonly class UserCatAccess {
    public function __construct(
        public int $userId,
        public int $catId,
    ) {}
}
```

### D2 🟨 `GroupCatAccess` — `{group_id: int, cat_id: int}`

`src/Piwigo/Permission/PermissionRepository.php:62` — `findGroupCategoryAccess()`.
3 consumer sites:
- `src/Piwigo/Ws/Action/Pwg/Permissions/GetListHandler.php:51` —
  `$row['cat_id']`, `$row['group_id']`
- `src/Piwigo/Controller/Admin/MaintenanceController.php:1534`

**Refactor**: `final readonly class GroupCatAccess { int $groupId; int $catId; }`.
D1 and D2 share one commit (same source file, same two consumers).

### D3 🟩 `CatUppercatRank` — `{cat_id: int, uppercats: string, global_rank: string|null}`

`src/Piwigo/Permission/PermissionRepository.php:157` —
`findGroupAuthorizedCategoriesForUser()`.
Consumer: `src/Piwigo/Controller/Admin/UsersController.php:328`.

**Refactor**: `final readonly class CatUppercatRank { int $catId; string $uppercats; ?string $globalRank; }`.

---

## E. SqlFragment missing site

### E1 🟩 `PermissionService::buildImageAccessClauses` → `SqlFragment`

`src/Piwigo/Users/PermissionService.php:221-262` — private method returning
`array{list<string>, list<mixed>, list<ParameterType|ArrayParameterType>}`.

The method always yields **0 or 1 clauses** (never a multi-clause join), making it
a natural `SqlFragment` — the `where` field is either `''` or the single clause.

Single caller at `:177`:
```php
// current
[$addClauses, $addParams, $addTypes] = $this->buildImageAccessClauses($fieldName, $user);
array_push($clauses, ...$addClauses);
array_push($params, ...$addParams);
array_push($types, ...$addTypes);

// after
$frag = $this->buildImageAccessClauses($fieldName, $user);
if ($frag->where !== '') {
    $clauses[] = $frag->where;
}
array_push($params, ...$frag->params);
array_push($types, ...$frag->types);
```

**Refactor**: change return type to `SqlFragment`; update the one caller. Private
method, no external impact.

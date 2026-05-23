# Array → Enum / VO / DTO Refactor Audit

Codebase-wide survey of arrays that hide a typed primitive. Focus on
high-recurrence, high-leverage shapes; each entry lists where it
lives today and what would replace it. Driven by direct grep against
`src/`, not subagents.

## Status legend

- 🟥 **Critical** — discriminator used 10+ sites, no typed equivalent yet
- 🟧 **High** — typed equivalent exists but the codebase ignores it
- 🟨 **Medium** — recurring shape worth a dedicated class
- 🟩 **Low** — single-cluster, do it as you touch the surrounding code

---

## A. Enum opportunities

### A1 🟧 `Section` enum exists, has zero adoption

`src/Piwigo/Common/Enum/Section.php` — 9 cases (categories, tags,
search, favorites, recent_pics, recent_cats, most_visited,
best_rated, list). Currently consumed in **0 places**.

`SectionContext::$section` is declared `string` defaulting to
`'categories'` (`src/Piwigo/Section/SectionContext.php:30`). Eight
consumers compare with raw `=== 'categories'` / `=== 'tags'` literals
(SearchFilterRenderer, MenubarRenderer, CategoryCatsRenderer,
ActivityLogger, CategoryDefaultRenderer, GalleryController,
UserService, PictureController).

**Refactor**: retype `SectionContext::$section: Section`; update the
8 consumer comparisons; `SectionInitializer` factory builds via
`Section::from($raw)`. Pairs with the F5-i deep-adoption work since
SearchFilterRenderer is the #1 psalm-info hotspot.

### A2 🟥 `ExtensionAction` enum — `'install'/'activate'/'deactivate'/'uninstall'/'delete'/'update'/'set_default'`

Repeated string compares across 5 files:

- `src/Piwigo/Admin/Plugins.php` — performAction switch on `$action`
- `src/Piwigo/Admin/Themes.php` — same pattern
- `src/Piwigo/Admin/Languages.php` — subset
- `src/Piwigo/Ws/Action/Pwg/Extensions/PluginsPerformActionHandler.php:50`
  — `in_array($input->action, ['activate', 'deactivate'])`
- `src/Piwigo/Ws/Action/Pwg/Extensions/ThemesPerformActionHandler.php:48`
  — same
- `src/Piwigo/Ws/Action/Pwg/Extensions/UpdateHandler.php`

**Refactor**: `final enum ExtensionAction: string` covering all
seven verbs; `Plugins/Themes/Languages::performAction(ExtensionAction $action, …)`.
Each registry rejects unsupported cases via the type system instead
of the current "no-op if unknown" fall-through.

### A3 🟧 `Privacy` enum exists, raw strings still in repos

`src/Piwigo/Common/Enum/Privacy.php` exists and is used in 4 places.
But these still pass raw `'public'` / `'private'`:

- `src/Piwigo/Category/CategoryRepository.php`
- `src/Piwigo/Permission/PermissionRepository.php`
- `src/Piwigo/Admin/Category/CategoryAdminService.php` —
  `setCatStatus(array $cats, string $value)` accepts the literal
- `src/Piwigo/Ws/Action/Pwg/Categories/SetInfoHandler.php:46` —
  validates with `in_array(['private','public'])`

**Refactor**: `CategoryAdminService::setCatStatus(array $cats, Privacy $value)`;
SetInfoHandler converts via `Privacy::tryFrom($status)`. The repos
then accept `Privacy` directly (no more `'public'` string literals
in SQL builders — they emit `$privacy->value`).

### A4 🟨 `CommentModerationAction` enum — `'reject'/'moderate'/'validate'`

`src/Piwigo/Comment/CommentService.php` lines 68, 72, 119, 121,
128, 139, 149, 154, 163, 175 — 10+ branches setting `$commentAction`
to one of three literals. Caller (`Ws/Action/Pwg/Images/AddCommentHandler.php`)
switches on the result.

**Refactor**: `enum CommentModerationAction: string` and return it
from `CommentService::insertUserComment(): CommentModerationAction`.
The handler's `switch ($commentAction)` becomes match-exhaustive.

### A5 🟨 `UploadAddStatus` enum — `'add'/'update'`

Only two cases, but the value flows across three call sites:
`UploadService::addUploadedFile()` (sets `$addStatus = 'add'|'update'`,
line 367/370) and **both** WS upload handlers
(`UploadHandler.php`, `UploadAsyncHandler.php`) initialize their own
`$addStatus = 'add'` and overwrite to `'update'` on a duplicate
match. The string ends up in the JSON response (`'add_status' => $addStatus`).

**Refactor**: tiny `enum UploadAddStatus: string { case Add = 'add';
case Update = 'update'; }`. Internal pass-through is type-safe;
`toArray()` calls `$addStatus->value` for the wire format.

### A6 🟨 `SyncMode` enum — `'files'/'dirs'`

`Controller/Admin/MaintenanceController.php` has 6 sites comparing
`['sync'] == 'files'` and 4 sites with `'dirs'`. Drives the
synchronization dispatch.

**Refactor**: trivial 2-case enum + `SyncMode::from($_POST['sync'])`.

### A7 🟨 `AllwordsField` enum — 7 cases

The literal `['name', 'comment', 'file', 'author', 'tags',
'cat-title', 'cat-desc']` appears verbatim in **4** locations:

- `Controller/SearchController.php:108`
- `Search/SearchService.php:249` — partial (cat-title/cat-desc map)
- `Ws/WsMethodRegistrar.php:1269` — info string
- `Ws/Action/Pwg/Images/FilteredSearchCreateHandler.php:52` — re-typed inline

**Refactor**: enum, then `AllwordsField::values()` for the WS
registrar's info, and `Search/Rules/AllwordsFilter.php` consumes
`list<AllwordsField>` instead of `list<string>`.

### A8 🟨 `DuplicateField` enum — `'filename'/'checksum'/'date'/'dimensions'`

`Controller/Admin/BatchManagerController.php:285` —
`in_array($duplicate_field, ['filename','checksum','date','dimensions'])`,
then `$bmf['duplicates_' . $duplicate_field] = true` builds dynamic
keys. The dynamic key construction would gain type safety from an
enum + a switch.

### A9 🟨 `ExifOrientation` enum — codes 1-8

`Admin/Image/PwgImage.php:295,297,299` — three `in_array($orientation, [3,4]) / [5,6] / [7,8]`
branches mapping orientation codes to (rotate, flip) operations.
The numeric meaning is EXIF-standard (8 cases) but currently lives
as bare ints.

**Refactor**: `enum ExifOrientation: int` with a `rotation(): int` /
`flippedHorizontally(): bool` method per case. Same applies to
`Metadata/MetadataService.php`.

### A10 🟧 Comment status workflow `'all'/'pending'/'validated'`

`Ws/Action/Pwg/Comments/GetListHandler.php:51` validates with
`in_array($input->status, ['all','pending','validated'])`, then
switch-cases on `'pending'` / `'validated'` lines 95/99 to append
SQL WHERE fragments.

**Refactor**: `enum CommentListFilter: string`; the `GetListParams`
field becomes typed; switch becomes match-exhaustive.

### A11 🟨 `ExtensionType` enum exists, partially adopted

`src/Piwigo/Admin/Extensions/ExtensionType.php` is used in 21 sites
(`ExtensionType::tryFrom($typeRaw)` in IgnoreUpdateHandler etc.),
but `UpdateHandler.php:51` still raw-strings on `'plugins'/'themes'/'languages'`.

**Refactor**: change `UpdateParams::$type: string` →
`UpdateParams::$type: ExtensionType` and drop the `in_array(...)`
check in the handler.

### A12 🟩 `UpgradeStatus` enum — `match` in UpdateHandler

`Ws/Action/Pwg/Extensions/UpdateHandler.php:104-110` — `match
($upgradeStatus) { 'ok' => …, 'temp_path_error' => …,
'dl_archive_error' => …, 'archive_error' => …, default => … }`.
Source values come from `Themes::extractThemeFiles()` /
`Languages::extractLanguageFiles()` / `Plugins::performAction(...)[0]`
— each returns a free-form string.

**Refactor**: enum with the four known cases + an `Unknown(string)`
wrapper for the `default` branch.

### A13 🟨 `ActivityAction` enum

`grep ActivityEvent` shows the action slot takes one of `'edit'` (53
uses), `'add'` (9), `'delete'` (5), `'install'`, `'sync'`, `'update'`,
`'configuration'`, `'maintenance_action'`, `'config_section'`,
`'page'`, `'section'`. `ActivityEvent::__construct(...)` declares
`string $action` (`src/Piwigo/Activity/ActivityEvent.php`).

**Refactor**: enum with the closed set; `ActivityEvent` becomes
strongly typed. The 53 `'edit'` literals become `ActivityAction::Edit`.

---

## B. Value-object opportunities

### B1 🟥 `MailAddress` — `array{email: string, name: string}`

`src/Piwigo/Mail/MailService.php` builds and consumes this shape in
**6 places** (lines 115, 121, 123, 141, 451, 478) and threads it
through `pwgMail()`, `formatAddressList()`, etc. The class also
declares `@return array{email: string, name: string}` formally
(line 109).

**Refactor**: `final readonly class MailAddress { public string $email;
public string $name; }` with a `fromString(string $rfc822): self`
parser. Drops every `['email' => ..., 'name' => ...]` builder.

### B2 🟥 `OrderSpec` — `array{field: string, dir: string}`

11 sites with the formal `array{field: string, dir: string}`
shape annotation (`OrderByService.php:40,53,80,105,120` plus 6
consumers including `Config.php:1196`,
`Section/SectionInitializer.php:391`,
`Controller/Admin/ConfigurationController.php:181`).

**Refactor**: `final readonly class OrderSpec { public string $field;
public SortOrder $dir; }` reusing the existing `SortOrder` enum.
`OrderByService` takes `list<OrderSpec>`.

### B3 🟨 `PermissionLevel` VO over `int` from `Config::availablePermissionLevels()`

`Config::availablePermissionLevels()` returns `list<int>` defaulting
to `[0, 1, 2, 4, 8]` (`Config.php:1308-1311`). Used in 10+ sites for
`max(...)` and range checks (`Ws/WsMethodRegistrar.php` 6 occurrences

- `Html/HtmlService.php:534` + `Admin/BatchManager/FilterResolver.php:79`).

**Refactor**: `final readonly class PermissionLevel { ... public static
function tryFrom(int $v): ?self ... }` with the validation embedded.
WS handlers go from `is_numeric($params['min_level']) ? (int) ... : 0`

- `in_array(..., availablePermissionLevels())` to
  `PermissionLevel::tryFrom($input->minLevel)`.

### B4 🟨 `MimeExtension` VO / enum

`'png' / 'jpg' / 'gif' / 'webp' / 'tiff' / 'tif' / 'svg' / 'heic' /
'jpeg' / 'avif'` — appears 50+ times across `Admin/Upload/*`,
`Metadata/MetadataService.php`, `Image/*`. Config exposes
`Config::pictureExtensions()`, `Config::fileExtensions()`,
`Config::formatExtensions()` returning `list<string>`.

**Refactor**: closed enum (jpg/jpeg/png/gif/webp/svg/tiff/tif/heic/avif/...);
Config returns `list<MimeExtension>`. The `strtolower(getExtension($filePath))`

- `in_array(...)` pattern becomes `MimeExtension::tryFromPath($filePath)`.

---

## C. DTO / projection opportunities

### C1 🟥 `SqlFragment` DTO — `array{0: string, 1: list<mixed>, 2: list<ArrayParameterType|ParameterType>}`

The 3-tuple `(whereFragment, params, types)` is the return shape of
`PermissionService::getSqlConditionFandF()` (`Users/PermissionService.php:145`).
Currently destructured with `[$permSql, $permParams, $permTypes] = ...`
in **23 call sites** (grep `getSqlConditionFandF` — every Ws/Action
handler).

**Refactor**: `final readonly class SqlFragment { public string $where;
public array $params; public array $types; }` with a `prefixWith(string)`
helper for the `' AND '` / `'\n  AND'` prepend pattern. Eliminates
the unnamed-position destructuring (the `0/1/2` indices are
position-meaningful but easy to mis-read).

### C2 🟧 `SqlFilterClause` DTO — `array{sql: string, param: mixed, type: ParameterType, kind: string}`

Used 5× in `Ws/Action/Pwg/Comments/GetListHandler.php:54-72` to
build a filter list, then re-destructured inside `$build` closure.
The `'kind'` slot is itself an unfortified string (`'author'`,
`'image'`, `'min_date'`, `'max_date'`, `'search'`).

**Refactor**: small DTO + `SqlFilterKind` enum for `kind`. The
closure becomes a typed `static function build(list<SqlFilterClause>): ...`.

### C3 🟧 `PluginRecord` DTO — `array{id: string, state: string, version: string}`

`PluginRepository::findAll(): list<array{id: string, state: string, version: string}>`
(`Plugin/PluginRepository.php:22`). Consumers in `Admin/Plugins.php`,
`Ws/Action/Pwg/Extensions/PluginsGetListHandler.php`,
`Ws/Action/Pwg/Extensions/UpdateHandler.php` and 4 others read
`['state']`, `['version']`, `['id']` directly.

**Refactor**: `final readonly class PluginRecord { ImageId? No — public
PluginId $id; public PluginState $state; public string $version; }`.
The `state` slot itself is `'active'/'inactive'/'uninstalled'/'new'`
(A2 enum candidate).

### C4 🟨 `ExtensionStat` DTO — `array{ext_counter: int, filesize: int}`

Identical shape returned by **two** different repos for two
different concepts:

- `ImageRepository::countAndFilesizeByExtension(): array<string, array{ext_counter: int, filesize: int}>`
- `ImageFormatRepository::countAndFilesizeByExtension(): array<string, array{ext_counter: int, filesize: int}>`

Consumed by `Controller/Admin/MiscController.php:750-751,756` (data_storage
chart).

**Refactor**: `final readonly class ExtensionStat` shared between
both repos. The "keyed by extension" outer array becomes
`array<string, ExtensionStat>`.

### C5 🟧 `HistoryRow` entity

`History/HistoryRepository.php` returns `list<array<string, mixed>>`
or `list<array<string, mixed>>|null` from **6** methods (lines 121,
277, 292, 325, 354, 430, 452). Consumers read `$row['date']`,
`$row['action']`, `$row['object']`, `$row['object_id']`,
`$row['performed_by']`, `$row['details']`, `$row['user_agent']` —
all as `mixed`. `Ws/Action/Pwg/Activity/GetListHandler.php` is the
heaviest consumer.

**Refactor**: `final readonly class HistoryEntry` with typed slots

- `ActivityAction $action` (A13) + `ActivityObject $object` (existing
  enum). Migration is bigger than a typical projection because the
  `details` blob is JSON — likely needs its own `ActivityDetails`
  union per `action`.

### C6 🟨 `RatingScore` projection — `array{score: ?float, count: int, average: ?float}`

`Ws/Action/Pwg/Images/GetInfoHandler.php` builds
`$rating = ['score' => …, 'count' => 0, 'average' => null]` then
overwrites count/average from `RateRepository::findCountAndAvgByElementId()`.

**Refactor**: `final readonly class RatingScore` + repo returns it
directly. Replaces the 3-line dictionary build + the unsafe
`is_numeric($rating['score']) ? (float) $rating['score'] : 0.0` cast.

### C7 🟨 `TelemetryPayload` sub-shapes are still `array<string, mixed>`

`Telemetry/TelemetryPayload.php` is typed at the top level but four
of its 12 properties are still `array<string, mixed>` (technical,
generalStats) or `array<string, array<string, mixed>>` (activities,
apps). Each is built by a dedicated `buildXxx()` method in
`TelemetryService.php` — those builders are the natural place for
typed return classes.

**Refactor**: introduce `TelemetryTechnical`, `TelemetryGeneralStats`,
`TelemetryActivityCounters`, `TelemetryAppStats` sub-DTOs.
Pairs with the F5-j hardening work (TelemetryService is psalm-info #4).

### C8 🟩 `ImageInsertRow` / `ImageUpdateRow` DTOs

`Admin/Upload/UploadService.php:268,275` builds two ~10-key inline
arrays (`'file' => …, 'name' => …, 'date_available' => …, 'path' => …,
'filesize' => …, …`) that get handed to `ImageRepository::updateById()`
/ `insertNew()`. These shapes recur in `MetadataAdminService` too.

**Refactor**: shared insert/update DTOs (or a single mutable builder).
Lower priority because the call sites are concentrated.

---

## D. Cross-cutting patterns

### D1 🟧 `ActivityDetails` polymorphic payload

`ActivityEvent::__construct(..., array $details = [])` — `$details`
is `array<string, mixed>` everywhere. Per-action the shape is fixed
(e.g. `'install'` carries `theme_id` + `from_version`; `'maintenance'`
carries `maintenance_action`; `'config'` carries `config_section`).

**Refactor**: would need a discriminated-union pattern (sealed
hierarchy per ActivityAction). High-leverage long-term but big
surface — likely defer until A13 lands.

### D2 🟨 `SearchInfo` reload shape

`Search/SearchService::getSearchInfo($pSearchId)` returns
`array<string, mixed>` (search id + forked_from + rules JSON).
Consumed by `FilteredSearchCreateHandler.php:36`.

**Refactor**: `final readonly class SearchInfo { public SearchId $id;
public ?SearchId $forkedFrom; public SearchRules $rules; }`. Sister
DTO to the existing `SearchRules`.

### D3 🟨 `UserFieldsMap` for `Config::userFields()`

Returns `array<string, string>` keyed by `'id'/'username'/'password'/'email'`.
Used in 91 sites (`Config::userFields()['id']` etc.) — most just
read the same four slots.

**Refactor**: `final readonly class UserFieldsMap { public string $id;
public string $username; public string $password; public string $email; }`.
The 91 array-access sites collapse to property access. Forbids
typos at the type level.

### D4 🟨 `WsParam` definition shape

`ParamDefinition::toArray(): array{flags: int, type: int, default?: mixed,
maxValue?: int|float, info?: string}` (`Ws/ParamDefinition.php:68`).
The shape is fixed; the only reason it's an array is the JSON-encode
path. Could become a dedicated DTO consumed by `MethodDefinition`
and re-emitted via `toArray()`.

---

## Suggested sequencing

The highest-value moves are the ones that retire the most
psalm-info issues per LOC changed:

| #   | Refactor                                        | Files touched            | Issues retired (est.)           |
| --- | ----------------------------------------------- | ------------------------ | ------------------------------- |
| 1   | A1 — `Section` enum adoption                    | 9                        | ~30                             |
| 2   | C1 — `SqlFragment` DTO                          | 24                       | ~30 (mixed[]/[]→typed)          |
| 3   | C5 — `HistoryEntry` entity                      | 8                        | ~40 (HistoryRepository residue) |
| 4   | C3 + A2 — `PluginRecord` + `ExtensionAction`    | 5                        | ~25                             |
| 5   | A11 — `ExtensionType` adoption in UpdateHandler | 1                        | ~5                              |
| 6   | B2 — `OrderSpec` VO                             | 11                       | ~15                             |
| 7   | A13 — `ActivityAction` enum                     | 30+ (53 'edit' literals) | ~10                             |
| 8   | C7 — Telemetry sub-DTOs                         | 1 (TelemetryService)     | ~40                             |

Smaller cleanups (A5/A6/A8/A9/C6) can ride along inside touched
files when convenient. The B1 (`MailAddress`) and A4
(`CommentModerationAction`) are local cleanups worth doing because
they remove repeated `is_string($x['email']) ? ...` defensiveness
without much blast radius.

A1 is the cheapest cross-cutting win: the enum already exists, the
SectionContext field already has a default — the entire change is
field-type plus 8 grep-and-replace edits.

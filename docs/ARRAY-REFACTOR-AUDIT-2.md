# Array → Enum / VO / DTO Refactor Audit — Round 2

Follow-up to `ARRAY-REFACTOR-AUDIT.md`. Round 1 retired 8 items
(A1, A2, A11, A13, B2, C1, C3, C5, C7). This round picks up:

1. **Deferred-from-round-1 items**, re-verified against the live codebase
2. **Round-1 follow-ups** — work explicitly punted in those commit bodies
3. **New finds** — shapes the first audit missed

Every count / file path / type was re-verified by grep against `src/`
(no subagents, per repo convention).

## Status legend

- 🟥 **Critical** — discriminator used 10+ sites, no typed equivalent yet
- 🟧 **High** — typed equivalent exists but the codebase ignores it
- 🟨 **Medium** — recurring shape worth a dedicated class
- 🟩 **Low** — single-cluster, do it as you touch the surrounding code

## What round 1 retired

For context — these are the closed items, named so this audit doesn't
re-list them by accident:

- A1 `Section`, A2 `ExtensionAction`, A11 `ExtensionType`,
  A13 `ActivityAction` — enums
- B2 `OrderSpec` — VO
- C1 `SqlFragment`, C3 `PluginRecord`, C5 `ActivityRow`,
  C7 `TelemetryTechnical`/`AppStat`/`ActivityGroup` — DTOs

---

## A. Enum opportunities

### A1' 🟥 `UserStatus` enum exists, has zero adoption

`src/Piwigo/Common/Enum/UserStatus.php` — 5 cases (webmaster/admin/
normal/generic/guest). **Consumed in 0 places** (grep
`use Piwigo\Common\Enum\UserStatus` returns nothing).

Raw string literals appear in **81 sites across 35 files** under
`src/Piwigo/Users/`, `src/Piwigo/Auth/`, `src/Piwigo/Admin/Users/`,
session bootstrap, WS handlers, controllers. Same shape Section had
before A1: enum sitting unused.

Concrete validators that take a list of these literals:

- `UserService.php:571` — `in_array($params['status'], ['guest','generic','normal','admin','webmaster'])`
- `UserBootstrap.php:170` — `in_array($status, ['guest','generic'], true)`
- `AuthService.php:340` — `in_array($key['status'], ['normal','generic'])`

**Refactor**: retype `User::$status: UserStatus`, parse from DB via
`UserStatus::from($row['status'])`, replace `in_array` gates with
`UserStatus::tryFrom(...) === null` style checks. Pairs with B3
(`PermissionLevel`) since both touch the same User edit surface.

### A3 🟧 `Privacy` enum still under-adopted on the write path

Re-verified from round 1. Privacy enum exists and the **read** side
already uses it (`Category` entity, `PictureNavCategoryRow`
projection, two CategoryAdminService private-cat checks,
CategoryRepository's parent-cat resolution). The **write** path still
strings:

- `CategoryRepository.php:530` — `where("status = 'private'")`
- `CategoryRepository.php:2257` — same
- `PermissionRepository.php:146` — `['private', $groupId]` bind list
- `CategoryAdminService::setCatStatus` — accepts `string $value`,
  branches on `'public'/'private'` (lines 294, 298, 300, 302, 304)
- `CategoryAdminService::movePrivacy` (line 470) — string compare
- `CategoryAdminService::createCategory` (lines 498, 513, 521, 530) —
  string assigns/compares
- WS input: `AddParams.php:35`, `SetInfoHandler.php:47`,
  `GetListParams.php:39` — `in_array(['private','public'])`

**Refactor**: `CategoryAdminService::setCatStatus(array $cats, Privacy $value)`,
WS params hold `Privacy` (parsed by `tryFrom` at the boundary),
repositories accept `Privacy` and emit `$privacy->value` to SQL.

### A4 🟨 `CommentModerationAction` enum

`CommentService.php` has **13** `$commentAction = 'reject'/'moderate'/'validate'`
assignments (lines 119, 121, 128, 139, 149, 154, 163, 175, 186, 190,
206, 214, plus the line-72 `'reject'/'moderate'` mode-selector). Plus
the `userCommentCheck($action, $comment): string` signature returns
one of these literals (line 66, 68).

Consumers:

- `PictureCommentRenderer.php:84` — `switch ($comment_action)`
- `CommentsController.php:223-239` — `switch` with `'moderate'/'validate'/'reject'/default-throw`
- `PictureController.php:252` — switches on `updateUserComment` return
- `AddCommentHandler.php:46-52` — switches on `insertUserComment` return

**Refactor**: tiny 3-case enum returned by `insertUserComment` /
`updateUserComment` / `userCommentCheck`; switches become match-exhaustive.

### A5 🟨 `UploadAddStatus` enum — `'add'/'update'` (correction)

Round 1 said "both WS upload handlers". Re-verified: only
`UploadHandler` uses the slot — `UploadAsyncHandler` doesn't. Total
sites:

- `UploadService.php:368/371/376` — return slot
- `UploadHandler.php:112/121/131/122/143` — local + JSON-response `'add_status'`

**Refactor**: 2-case enum; same `->value` round-trip in the WS
response. Small but type-safe.

### A6 🟨 `SyncMode` enum — `'files'/'dirs'`

Re-verified: 10 sites in `MaintenanceController.php` (lines 1158,
1185, 1189, 1360, 1480, 1489, 1528, 1628, 1643, 1648).

**Refactor**: trivial 2-case enum + `SyncMode::tryFrom($_POST['sync'])`.

### A7 🟨 `AllwordsField` enum — 7 cases

Re-verified:

- `SearchController.php:108` — `'fields' => ['file','name','comment','tags','author','cat-title','cat-desc']` (the 7-case list)
- `FilteredSearchCreateHandler.php:52` — `$allwordsFieldsAvailable = [same 7]`
- `WsMethodRegistrar.php:1269` — info string mentioning the 7
- `SearchService.php:248` — `$catFieldsDictionary = ['cat-title' => 'name', 'cat-desc' => 'comment']` plus the in_array filter

**Refactor**: closed enum + `AllwordsField::values()` for the registrar info string; `Search/Rules/AllwordsFilter.php` consumes `list<AllwordsField>`.

### A8 🟨 `DuplicateField` enum — `filename/checksum/date/dimensions`

Re-verified in `BatchManagerController.php`:

- Line 286 — `in_array($duplicate_field, ['filename','checksum','date','dimensions'])` gate
- Lines 185-198 — `if (isset($_POST['filter_duplicates_<field>']))` quartet
- Lines 404-413 — paired build of `$duplicates_on_fields[]` from same set
- Line 287 — `$bmf['duplicates_' . $duplicate_field] = true` (dynamic key)

**Refactor**: enum + a `dbColumn(): string` method per case
(filename→file, checksum→md5sum, date→date_creation, dimensions→
width/height pair).

### A9 🟨 `ExifOrientation` enum — codes 1-8 (scope correction)

Round 1 said "same applies to MetadataService". Re-verified: there's
no orientation handling in `MetadataService.php`. The actual surface
is **PwgImage.php only**:

- Lines 294-300 — three `in_array($orientation, [3,4])` / `[5,6]` /
  `[7,8]` branches mapping orientation → (rotate, flip) pair

**Refactor**: `enum ExifOrientation: int` with a
`rotation(): int` and `flipped(): bool` method per case. Single-file
change, small.

### A10 🟧 `CommentListFilter` enum — `'all'/'pending'/'validated'`

Re-verified in `Ws/Action/Pwg/Comments/GetListHandler.php`:

- Line 45 — `in_array($input->status, ['all','pending','validated'])` gate
- Lines 92/94/96/98 — switch arms on `'pending'`/`'validated'`

**Refactor**: 3-case enum + retype `GetListParams::$status: CommentListFilter`.

### A12 🟨 `UpgradeStatus` enum — wider than audit estimated

Round 1 ranked this 🟩 Low (single cluster) — re-verified it's
actually across **3 source files** that all emit these strings:

- `Plugins.php:597-614` — extractPluginFiles returns one of `ok` /
  `temp_path_error` / `dl_archive_error` / `archive_error`
- `Themes.php:589-601` — extractThemeFiles same set
- `Languages.php:355-367` — extractLanguageFiles same set
- `UpdateHandler.php:98-103` — `match ($upgradeStatus) { ... }` over
  the same 4 literals + `default`

**Refactor**: 4-case enum; the three extract methods return
`UpgradeStatus`. `match` becomes exhaustive without the `default`
branch.

### A14 (new) 🟨 `WatermarkPosition` enum

`WatermarkProcessor.php:89-107` — `switch ($position)` with 5 cases
(`topleft`/`topright`/`middle`/`bottomleft`/`bottomright`). The
position-derived `xpos`/`ypos` mapping is a clean enum-method case.

**Refactor**: enum carries the 2-int `(xpos, ypos)` per case via a
`coords(): array{int, int}` method. The switch becomes a single
property read.

### A15 (new) 🟨 `ConnectionType` for `Session::$connectedWith`

Distinct strings stored in `Session::connectedWith` and compared
across 6 sites: `'pwg_ui'`, `'auth_key'`, `'api_key'`,
`'ws_session_login_api_key'` — plus the special-case
`'pwg.images.uploadAsync'` for an upload-as path
(`UserBootstrap.php:147`).

Compare sites: `PwgServer.php:547`, `ProfileService.php:274`,
`AuthService.php:184/312/314/340/344/374/456`.

**Refactor**: 4-case enum (the WS method-name special case stays a
string overlay since it's actually the method name, not a connection
kind). `AuthService::connectedWithPwgUi()` becomes a single property
compare against the enum case.

### A16 (new) 🟨 `ImageType` enum — `'high'/'picture'/'other'/'none'`

`WsMethodRegistrar.php:1250` defaults `types: ['none', 'picture',
'high', 'other']` for the history-search WS method. The slot is then
written to `history.image_type` (varchar). Comparisons:

- `HistoryRepository.php:200` — `WHERE h.image_type = 'high'`
- `LogHandler.php:55` — `$imageType = $input->isDownload ? 'high' : 'picture'`
- `ActivityLogger.php` — passes `$imageType` through `pageView()` /
  `isLoggingEnabled()` as `?string`

**Refactor**: 4-case enum; the page-view path becomes typed end-to-end.

### A17 (new) 🟧 `PluginState` enum

Punted in C3's commit body. Source: `plugins.state` DB enum
(`'active'/'inactive'`) — but the codebase also synthesizes
`'uninstalled'` (`PluginsGetListHandler.php:26`) for plugins on disk
but not in the DB, and `'new'` is used as a UI tab label
(`ExtensionsController.php:147`, `258-259`).

Raw-string sites (18 across Plugins/PluginRegistry/PluginRecord/
PluginsGetListHandler/Admin/Plugins/ExtensionsController/Telemetry):
all compare `$record->state === 'active'` / `!== 'active'` or assign
'active'/'inactive' to `updateState()`.

**Refactor**: 4-case enum (Active / Inactive / Uninstalled / New);
retype `PluginRecord::$state: PluginState`. The DB column is still
`enum('inactive','active')`, so `Uninstalled`/`New` cases stay
synthesised — same pattern Section uses for ListView.

### A18 (new) 🟨 `UserManagementAction` enum

`PermissionService::canManageComment($action, $authorId)` and
`canManageX()` siblings — `in_array($action, ['delete','edit','validate'])`
gate at `PermissionService.php:94`. Caller-side validation has the
same 3-case shape (`CommentsController.php:195`, `CommentService.php:391`).

**Refactor**: 3-case enum; `PermissionService::canManage*(UserManagementAction $action, int $authorId)`.

---

## B. Value-object opportunities

### B1 🟥 `MailAddress` — `array{email: string, name: string}`

Re-verified: **7 sites** in `MailService.php` (lines 115, 121, 123,
141, 451, 478, plus the `@return array{email, name}` at 109). The
shape is even formally annotated.

**Refactor**: `final readonly class MailAddress { public string $email;
public string $name; static fromString(string $rfc822): self; }`.
Eliminates every `['email' => $x, 'name' => $y]` builder.

### B3 🟨 `PermissionLevel` VO — undercounted

Round 1 said 10+; re-verified at **21 call sites across 9 files**:

- `WsMethodRegistrar.php` × 8
- `UserService.php` × 2
- `Config.php` × 2
- `BatchManagerController.php` × 2
- `GetListHandler.php` (Users) × 2
- `BatchManager/FilterResolver.php` × 1
- `SetPrivacyLevelParams.php` × 1
- `HtmlService.php` × 1
- `UsersController.php` × 1

The recurring pattern: `in_array($input->level, Config::availablePermissionLevels())`
or `max(Config::availablePermissionLevels())` for upper-bound WS param.

**Refactor**: `final readonly class PermissionLevel { public int $value;
static tryFrom(int): ?self; }` validated against
`Config::availablePermissionLevels()` at construction.

### B4 🟨 `MimeExtension` enum / VO

Re-verified: 14 files reference `Config::pictureExtensions()` /
`fileExtensions()` / `formatExtensions()`. The codebase repeats the
ext literals (`jpg`/`jpeg`/`png`/`gif`/`webp`/`tiff`/`tif`/`svg`/
`heic`/`avif`) across uploaders, the metadata service, and the
src-image resolver.

Concrete duplicated patterns:

- `Config::pictureExtensions()` returning `list<string>` — 4 callers
- Per-extension capability checks (e.g., `MetadataAdminService.php:148`
  has `in_array($mime_type, ['image/svg+xml','image/svg'])`)
- Filename suffix matching across upload paths

**Refactor**: closed enum, Config returns `list<MimeExtension>`;
adopt a `MimeExtension::tryFromPath(string $path): ?self` factory.

### B5 (new) 🟨 `Credentials` VO — `array{username, password}`

`UserRepository.php:596` — `@return array{username: string, password: string}|null`.
Currently the only consumer is the rebuild-password flow, but the
shape is a clear DTO.

**Refactor**: `final readonly class Credentials` (or scope to
`StoredCredentials` since it includes a hashed password). Tiny but
clean.

### B6 (new) 🟨 `LastVisit` VO — `array{date: string, time: string}`

`HistoryRepository.php:70` — `findLastVisitByUserId(int): ?array{date, time}`.
Single repo method, single consumer. Same shape used by the
`'last_visit'` user_info field.

**Refactor**: `final readonly class LastVisit` (or fold into a
`DateTimeParts` VO if there are siblings).

---

## C. DTO / projection opportunities

### C2 🟧 `SqlFilterClause` DTO — still applicable

Re-verified in `Ws/Action/Pwg/Comments/GetListHandler.php:51-72`.
5× builders push tuples with the formal shape
`array{sql: string, param: mixed, type: ParameterType, kind: string}`,
then the closure at line 75 re-destructures them.

**Refactor**: small DTO + `SqlFilterKind` enum for `kind` (5 cases:
author / image / min_date / max_date / search).

### C4 🟨 `ExtensionStat` DTO

Re-verified — identical `array{ext_counter: int, filesize: int}` shape
in `ImageRepository::countAndFilesizeByExtension()` (line 555) and
`ImageFormatRepository::countAndFilesizeByExtension()` (line 132).
Consumer at `MiscController.php:750-756` reads both keys.

**Refactor**: `final readonly class ExtensionStat` shared by both
repos. The "keyed by extension" outer array becomes
`array<string, ExtensionStat>`.

### C6 🟨 `RatingScore` projection

Re-verified at `Ws/Action/Pwg/Images/GetInfoHandler.php:102-105`. The
3-key `['score' => ?, 'count' => 0, 'average' => null]` builder is
followed immediately by an `is_numeric($rating['score'])` cast.

**Refactor**: `final readonly class RatingScore`; `RateRepository::
findCountAndAvgByElementId()` could return it directly (currently
returns `array{0: int, 1: float|null}` — the 0/1 numeric tuple is
itself a destructure smell).

### C8 🟩 `ImageInsertRow` / `ImageUpdateRow` DTOs

Re-verified at `UploadService.php:269` (update) and `:276` (insert) —
two ~10-key inline arrays handed to `ImageRepository::updateById()`/
`insertNew()`. Same shape recurs in `MetadataAdminService` for the
sync-metadata path.

**Refactor**: shared insert/update DTOs (or a single mutable
`ImageWrite` builder).

### C9 (new) 🟧 HistoryAdminService missed `SqlFragment` site

C1 (round 1) converted every `PermissionService::getSqlConditionFandF`
call site to `SqlFragment`. Round 1 missed:

`HistoryAdminService.php:67` — `buildHistoryWhereSql()` still returns
`array{0: string, 1: list<mixed>, 2: list<ArrayParameterType|ParameterType>}`.
It's a private helper but has 4 internal callers that destructure
`[$where, $params, $types] = $this->buildHistoryWhereSql(...)`.

**Refactor**: return `SqlFragment` from the helper. ~5-minute fix.

### C10 (new) 🟧 NotificationRepository date-range fragment

`NotificationRepository.php:204` — `dateRangeFragment()` returns
`array{0: list<mixed>, 1: list<ArrayParameterType|ParameterType>, 2: string}`
(field order is **different** from `SqlFragment` — params/types/sql vs
where/params/types). 4 internal callers.

**Refactor**: return `SqlFragment` (normalising the field order); the
4 callers go from positional destructure to property access.

### C11 (new) 🟨 `PaginatedResult<T>` DTO

5 repositories return `['rows' => list<T>, 'total' => int]`:

- `CategoryRepository.php:3054, 3105`
- `ImageRepository.php:1563`
- `UserRepository.php:681`
- (`EmptyLoungeHandler.php` uses the shape too but only `rows`)

**Refactor**: `final readonly class PaginatedResult { public array $rows;
public int $total; }`. PHP doesn't do generic classes — type narrowing
happens at the call site via `@var` on the property — but the runtime
shape is uniform.

### C12 (new) 🟧 `TelemetryGeneralStats` DTO (punted from C7)

`TelemetryPayload::$generalStats` is still `array<string, mixed>` — the
biggest residue from C7. Source: `AdminService::getPwgGeneralStatitics()`
returns `array<string, mixed>` and `TelemetryService::buildGeneralStats()`
augments it with `disk_usage`, `installed_on`, `nb_photos_synced`,
`last_photo_synced`, `last_photo`, plus the per-extension counts the
caller writes back in (`nb_private_plugins`, `nb_plugins`, etc.).

**Refactor**: `final readonly class TelemetryGeneralStats` with typed
fields for the known additions; AdminService::getPwgGeneralStatitics
needs to grow a typed return. Largest deferred item from C7 — multi-step.

### C13 (new) 🟧 HistoryRepository's 6 mixed-array methods

Punted in C5's commit body. `HistoryRepository.php` returns
`list<array<string, mixed>>` from **6 methods** (lines 121, 277, 325,
354, 430, 452). Each returns a different shape:

- `findSummaryByType` — `{year, month, day, hour, nb_pages}`
- `findPageByWhere` — full history-row projection
- `findHourlyGroupingAfterId` — `{year, month, day, hour, count}`
- `findSummariesAtTime` — `{user, ip, date, nb_pages}`
- `findMonthlyRollups` — monthly aggregates
- `findDailyStatsForMonths` — daily stats per month

**Refactor**: per-method DTOs (5-6 small readonly classes). Each
consumer is concentrated in `Admin/History/HistoryAdminService.php` —
fix that one consumer file per DTO.

---

## D. Cross-cutting patterns

### D1 🟧 `ActivityDetails` polymorphic payload — unblocked by A13

Audit round 1 said "defer until A13 lands". A13 has landed —
`ActivityEvent::$action: ActivityAction` is closed. The `$details`
slot is still `array<string, mixed>` across **66 ActivityEvent
constructor sites**.

Per-action shapes (verified by reading details-building patterns):

- `Edit` × ~53 sites — payload varies wildly per object type
- `Add` × 9 — `{plugin_id, version}` for plugins, `{sync: true}` for
  bulk import, sometimes empty
- `Delete` × 5 — `{photo_deletion_mode}` for albums, `{}` elsewhere
- `Config` — `{config_section: string}` + optional `config_action`
- `Maintenance` — `{maintenance_action: string}`
- `Install`/`Update`/`AutoUpdate` — `{from_version, to_version}` (core)
  or `{plugin_id, version}` (plugin) or `{theme_id, version}` (theme)
- `Login`/`Logout` — empty
- `ResetPasswordSuccess`/`Failure*` — empty

**Refactor**: sealed-hierarchy union per `ActivityAction`. Each case
gets its own `ActivityDetails` subclass. High-leverage but big-surface
— ~66 ActivityEvent constructor sites need to pass the right subclass.
ActivityLogger's JSON-encoding path stays unchanged via a
`toJsonArray()` method on each subclass.

### D2 🟨 `SearchInfo` reload shape

Re-verified — `SearchService::getSearchInfo($candidate): ?array`
returns `array<string, mixed>` (search id + forked_from + rules JSON).
Consumers: `FilteredSearchCreateHandler.php:36` (1 external) +
`SearchService.php:135` (1 internal).

**Refactor**: `final readonly class SearchInfo { public SearchId $id;
public ?SearchId $forkedFrom; public SearchRules $rules; }`. The
`SearchRules` DTO already exists.

### D3 🟨 `UserFieldsMap` for `Config::userFields()`

Re-verified: **91 call sites across 29 files**. Returns
`array<string, string>` with keys `'id'`, `'username'`, `'password'`,
`'email'`. Most consumers read the same four slots.

**Refactor**: `final readonly class UserFieldsMap { public string $id;
public string $username; public string $password; public string $email; }`.
91 array-access sites collapse to property access. Forbids typos at
the type level.

### D4 🟨 `ParamDefinition::toArray()` shape

Re-verified — `ParamDefinition.php:68` declares
`array{flags: int, type: int, default?: mixed, maxValue?: int|float, info?: string}`.
The class itself already exists; only its `toArray()` return is
loose. **365 `ParamDefinition::` calls** in `WsMethodRegistrar.php`.

**Refactor**: low — the class is already typed, only the array
projection is loose. Could be retired by having `MethodDefinition`
consume `ParamDefinition` instances directly instead of via the
array form.

---

## Suggested sequencing

Highest leverage first; "Issues retired" is a coarse psalm-info
estimate from grepping `mixed`/`array<string,mixed>` references.

| #   | Refactor                                   | Files              | Issues retired (est.) | Notes                                                    |
| --- | ------------------------------------------ | ------------------ | --------------------- | -------------------------------------------------------- |
| 1   | A1' — `UserStatus` adoption                | 35 (81 sites)      | ~40                   | Enum exists, zero adopt — same shape Round 1 A1 had      |
| 2   | C9 — HistoryAdminService `SqlFragment`     | 1                  | ~10                   | Missed in Round 1 C1; ~5 min fix                         |
| 3   | C10 — NotificationRepository `SqlFragment` | 1                  | ~10                   | Same as C9 — normalise to existing DTO                   |
| 4   | A17 — `PluginState` enum                   | 6                  | ~18                   | Punted in Round 1 C3 commit                              |
| 5   | A3 — Privacy enum write path               | 4                  | ~15                   | Round 1 only retyped the read path                       |
| 6   | D3 — `UserFieldsMap` VO                    | 29 (91 sites)      | ~30                   | Pure consumer collapse                                   |
| 7   | B3 — `PermissionLevel` VO                  | 9 (21 sites)       | ~12                   | Pairs with A1' (User edit surface)                       |
| 8   | A4 — `CommentModerationAction`             | 5                  | ~8                    | Closes the comment-service switch chain                  |
| 9   | A10 — `CommentListFilter`                  | 1                  | ~5                    | Single handler                                           |
| 10  | A12 — `UpgradeStatus` enum                 | 4                  | ~6                    | Extract methods return enum                              |
| 11  | A7 — `AllwordsField`                       | 4                  | ~5                    | Search-form reach                                        |
| 12  | A8 — `DuplicateField`                      | 1                  | ~6                    | Dynamic-key smell goes away                              |
| 13  | A6 — `SyncMode`                            | 1                  | ~5                    | Trivial                                                  |
| 14  | A18 — `UserManagementAction`               | 3                  | ~6                    | PermissionService gates                                  |
| 15  | A5 — `UploadAddStatus`                     | 2                  | ~3                    | Tiny                                                     |
| 16  | A14 — `WatermarkPosition`                  | 1                  | ~5                    | Self-contained switch                                    |
| 17  | A9 — `ExifOrientation`                     | 1                  | ~4                    | Single-file change                                       |
| 18  | A16 — `ImageType`                          | 4                  | ~6                    | History/activity surface                                 |
| 19  | A15 — `ConnectionType`                     | 6                  | ~8                    | Session attribute                                        |
| 20  | B1 — `MailAddress`                         | 1 (7 sites)        | ~5                    | Local cleanup                                            |
| 21  | C2 — `SqlFilterClause` + `SqlFilterKind`   | 1                  | ~6                    | Comments getList                                         |
| 22  | C4 — `ExtensionStat`                       | 3                  | ~4                    | Two repos + MiscController                               |
| 23  | C6 — `RatingScore`                         | 2                  | ~4                    | GetInfoHandler + RateRepository                          |
| 24  | C11 — `PaginatedResult`                    | 5                  | ~5                    | Generic-ish shape                                        |
| 25  | B4 — `MimeExtension` enum                  | 14                 | ~25                   | Largest after A1'                                        |
| 26  | B5 — `Credentials`                         | 1                  | ~2                    | Tiny                                                     |
| 27  | B6 — `LastVisit`                           | 1                  | ~2                    | Tiny                                                     |
| 28  | C8 — `ImageInsertRow`/`UpdateRow`          | 2                  | ~8                    | Sister DTOs                                              |
| 29  | C13 — HistoryRepository per-method DTOs    | 1 src + 1 consumer | ~40                   | 6 DTOs, mostly mechanical                                |
| 30  | C12 — `TelemetryGeneralStats`              | 2                  | ~30                   | Multi-step (touches AdminService)                        |
| 31  | D1 — `ActivityDetails` sealed hierarchy    | 66                 | ~60                   | Largest single change; defer until everything else lands |
| 32  | D2 — `SearchInfo`                          | 2                  | ~5                    | Local cleanup                                            |
| 33  | D4 — `ParamDefinition::toArray()`          | 1                  | ~3                    | Stops the `array{...}` projection                        |

A1' / C9 / C10 are the cheapest cross-cutting wins this round — same
"enum/DTO already exists, retire the residue" pattern that made
round 1's A1 worthwhile.

D1 (ActivityDetails) is the largest item left. It blocks any further
strong typing of the activity-log payload but touches every
`new ActivityEvent(...)` call site. Worth doing once everything else
in this audit lands.

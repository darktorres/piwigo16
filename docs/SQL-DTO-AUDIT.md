# SQL Projection DTO Audit

Repository methods that still return `list<array<string, mixed>>` or an
`array{...}` shape where a named projection class in the domain's
`Projection/` directory would give callers a typed handle. Covers the full
`src/` tree; skips methods that already return typed projections
(`::fromRow(...)` hydration), `SELECT *` blobs whose shape isn't stable, and
methods that map over dynamic admin-configured column names.

---

## ActivityRepository (`Activity/ActivityRepository.php`)

| ID | Method | SELECT shape | Proposed projection |
|----|--------|--------------|---------------------|
| A1 | `findActionCountsByObject()` `:134` | `{object: string, action: string, counter: int}` | `ActionCountRow` |
| A2 | `findCoreUpdateActivities()` `:207` | `{action: string, occured_on: string, details: string\|null}` | `CoreUpdateActivityRow` |
| A3 | `findDailyActionCountsSince()` `:318` | `{activity_day: string, object: string, action: string, activity_counter: int}` | `DailyActionCountRow` |
| A4 | `findAppUserAgentStats()` `:337` | `{user_agent: string, counter: int, first_encounter: string, last_encounter: string}` | `AppUserAgentStatRow` |

**Skip:** `findAllByObjectWithUsername()` — column name for `username` is
dynamic (`$usernameField`); can't be a concrete property.

---

## AuthKeyRepository (`Auth/AuthKeyRepository.php`)

| ID | Method | SELECT shape | Proposed projection |
|----|--------|--------------|---------------------|
| B1 | `findApiKeysByUserId()` `:123` | `SELECT *` from `user_auth_keys` | `ApiKeyRow` — all non-dynamic columns of the table |

**Skip:** `findAuthKeyDetails()` `:65` — `SELECT *` joined against two
admin-configured column names; shape is too dynamic.

---

## CategoryRepository (`Category/CategoryRepository.php`)

All of these already carry `@return list<array{...}>` annotations; the shapes
are known and stable — promotion to projection classes is mechanical.

| ID | Method | Shape | Proposed projection |
|----|--------|-------|---------------------|
| C1 | `findWithPermalinksByIds()` `:777` | `{id: int, permalink: string, uppercats: string, global_rank: string\|null}` | `CategoryPermalinkRow` |
| C2 | `findDeletedPermalinksByIds()` `:808` | `{cat_id: int, permalink: string, date_deleted: string, last_hit: string\|null, hit: int}` | `DeletedPermalinkRow` |
| C3 | `findCategoryLinkRows()` `:1803` | `{id: int, name: string, uppercats: string, global_rank: string\|null}` | `CategoryLinkRow` |
| C4 | `getComputedCategoryRows()` `:1934` | `{cat_id: int, id_uppercat: int\|null, global_rank: string\|null, date_last: string\|null, nb_images: int}` | `ComputedCategoryRow` |
| C5 | `findCommonCategoriesWithPermissions()` `:2043` | `{id: int, uppercats: string, counter: int}` | `CategoryWithCounter` |
| C6 | `findAllWithUppercats()` `:2103` | `{id: int, name: string, permalink: string\|null, id_uppercat: int\|null, uppercats: string, global_rank: string\|null}` | `CategoryBrief` |
| C7 | `findCategoryListingSorted()` `:2361` | `{id: int, name: string, rank: int\|null, status: string, visible: bool, uppercats: string, lastmodified: string}` | `CategoryListingRow` |
| C8 | `findCategoryListing()` `:2502` | `{id: int, name: string, permalink: string\|null, dir: string\|null, rank: int\|null, status: string}` | `CategoryListingBrief` |
| C9 | `findSiteStorageStats()` `:854` | `array<int, {nb_categories: int, nb_images: int}>` keyed by site id | `SiteStorageStat` readonly class |
| C10 | `findDateRangesForCategoriesKeyedById()` `:2652` | `array<int, {from: string\|null, to: string\|null}>` | `CategoryDateRange` readonly class |

**Also in round-4 audit (D1):** `findPhysicalSyncableForSite()` `:925` →
`PhysicalCategoryRow`.

**Partially untyped:** `findIdUppercatsForVisibleIds()` `:1012` and
`findVisibleCategoryIdUppercats()` `:1034` both select `(c.id, c.uppercats)`
and carry `@return list<array<string, mixed>>` — these share the shape
`{id: int, uppercats: string}` and could reuse `CategoryIdUppercats`
(if that doesn't exist yet) rather than adding a new projection.

---

## GroupRepository (`Group/GroupRepository.php`)

| ID | Method | SELECT shape | Proposed projection |
|----|--------|--------------|---------------------|
| D1 | `findWithMemberCounts()` `:198` | `{id: int, name: string, nb_users_of: int}` | `GroupWithCount` |
| D2 | `findAllOrdered()` `:214` | `{id: int, name: string, is_default: int}` | `GroupBrief` |
| D3 | `findUserGroupMembersByGroupIds()` `:94` | `{user_id: int, group_id: int}` | `UserGroupPair` — same shape as UserRepository's pair, consider a shared location |

**Skip:** `findListPage()` `:252` — `SELECT g.*` plus one computed column;
`*` shape is too wide for a focused projection.

---

## ImageFormatRepository (`Image/ImageFormatRepository.php`)

All three list-returning methods select `(image_id, ext)` — a single
projection covers them all.

| ID | Methods | SELECT shape | Proposed projection |
|----|---------|--------------|---------------------|
| E1 | `findByFormatIds()` `:25`, `findAll()` `:61`, `findByImageIds()` `:76` | `{image_id: int, ext: string}` | `ImageFormatPair` |

**Skip:** `findById()` `:116` and `findByImageAndExt()` `:173` — `SELECT *`;
migrate to `ImageFormat` entity when the entity is introduced.

---

## ImageRepository (`Image/ImageRepository.php`)

| ID | Method | SELECT shape | Proposed projection |
|----|--------|--------------|---------------------|
| F1 | `findActivityFeedSummaryByIds()` `:463` | `{id: int, label: string, filesize: ?int, file: string, path: string, representative_ext: ?string}` | `ImageSummaryRow` |
| F2 | `findDerivativeCandidatesBeforeId()` `:521` | `{id: int, path: string, representative_ext: ?string, width: ?int, height: ?int, rotation: ?int}` | `DerivativeCandidateRow` |
| F3 | `findDistinctDimensions()` `:34` | `{width: int, height: int}` | `ImageDimension` VO (two `int` fields, no nulls, simple equality) |

**Note F1:** the method has a stale duplicate `@return list<array<string,
mixed>>` block immediately above the correctly-shaped `@return array<int|string,
array<...>>` block. Fix the duplicate annotation when adding the projection.

---

## NotificationRepository (`Notification/NotificationRepository.php`)

| ID | Method | SELECT shape | Proposed projection |
|----|--------|--------------|---------------------|
| G1 | `findUsersWithoutNotification()` `:33` | `{user_id: int, username: string, mail_address: string}` | `NotifiableUserRow` |
| G2 | `getUserNotifications()` `:91` | `{user_id: int, check_key: string, username: string, mail_address: string, enabled: int, last_send: string\|null, status: string}` | `UserNotificationRow` |
| G3 | `findRecentCategoriesForDate()` `:428` | `{uppercats: string, img_count: int}` | `RecentCategoryRow` |

**Skip:** `findRecentImagesForDate()` `:378` — `SELECT DISTINCT i.*`; wide
`*` with permission join.

---

## RateRepository (`Rate/RateRepository.php`)

| ID | Method | SELECT shape | Proposed projection |
|----|--------|--------------|---------------------|
| H1 | `findRatedImagesAdminPage()` `:110` | `{id, path, file, representative_ext, score, recently_rated, avg_rates, nb_rates, sum_rates}` | `RatedImageRow` |
| H2 | `findAverageByElement()` `:276` | `{element_id: int, avg_rate: float}` | `ElementAvgRate` |
| H3 | `getSumsByElement()` `:305` | `{element_id: int, rcount: int, rsum: int}` | `ElementRateSum` |

**Skip:** `findByElementId()` `:244` and `findAllOrderedByDate()` `:261` —
`SELECT *` from the `rate` table; introduce a `RateRecord` entity instead
when the entity layer covers the `rate` table.

---

## UserRepository (`Users/UserRepository.php`)

| ID | Method | SELECT shape | Proposed projection |
|----|--------|--------------|---------------------|
| I1 | `findByActiveActivationKey()` `:653` | `{user_id: int, status: string, activation_key: string}` | `ActivationKeyRow` |
| I2 | `findAllWithStatus()` `:385` | `{id: int, username: string, status: string}` | `UserStatusRow` |
| I3 | `findByUsernameOrEmail()` `:625` | `{id: int, username: string, email: string, password: string, status: string}` | `UserCredentialRow` (login / auth path only) |
| I4 | `findAdminsForMail()` `:1212` | `{user_id: int, name: string, email: string}` | `UserMailRecipient` — same shape as I5 |
| I5 | `findMailRecipientInfoByIds()` `:1140` | `{user_id: int, name: string, email: string}` | `UserMailRecipient` — share with I4 |
| I6 | `findGroupRecipientsForLanguage()` `:1277` | `{user_id: int, status: string, name: string, email: string}` | `GroupMailRecipient` (or extend `UserMailRecipient` with `status`) |
| I7 | `findUserGroupPairsByUserIds()` `:690` | `{user_id: int, group_id: int}` | `UserGroupPair` — share with GroupRepository D3 |

**Skip:** `findByConfigFields()` `:822` and `findInfoCacheThemeByUserId()`
`:841` — `SELECT ui.*, uc.*, t.name` across three tables; shape is the full
user_infos + user_cache + theme join; too wide for a focused projection without
splitting the call sites.

---

## Suggested projection home per domain

| Domain | Directory |
|--------|-----------|
| Activity | `Activity/Projection/` |
| Auth | `Auth/Projection/` |
| Category | `Category/Projection/` (existing) |
| Group | `Group/Projection/` |
| Image | `Image/Projection/` (or `Image/View/` for display rows) |
| Notification | `Notification/Projection/` |
| Rate | `Rate/Projection/` |
| Users | `Users/Projection/` |
| Shared (UserGroupPair) | `Common/Dto/` |

---

## Suggested execution order

1. **E1** (`ImageFormatPair`) — trivial 2-field pair, three callers share it
2. **D3 / I7** (`UserGroupPair`) — shared pair, two repositories, goes in `Common/Dto/`
3. **H2 / H3** (`ElementAvgRate`, `ElementRateSum`) — aggregate-only, no entity to worry about
4. **A1–A4** — Activity projections, each one-shot
5. **B1** (`ApiKeyRow`) — single repository, single caller
6. **C1–C10** — CategoryRepository batch; do in one PR since they all land in `Category/Projection/`
7. **D1 / D2** (`GroupWithCount`, `GroupBrief`) — Group projections
8. **F1–F3** — ImageRepository projections (F1 needs duplicate-annotation cleanup)
9. **G1–G3** — Notification projections
10. **H1** (`RatedImageRow`) — widest projection in the Rate domain
11. **I1–I7** — UserRepository batch; I4+I5 share the projection so do together

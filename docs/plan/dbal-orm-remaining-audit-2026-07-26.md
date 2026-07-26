# DBAL→ORM migration: what's still not on the ORM, and why

> Companion to `docs/PLAN-REPLAY.md`'s "Remediation — DBAL→ORM migration" section
> (`Executed (2026-07-26)` block). That section narrates the migration's own history and
> corrections; this document is the durable, evidence-checked inventory of the *end state* —
> every repository/table/call site that still touches raw DBAL, with the reason, so a future
> reader doesn't have to re-derive it from commit archaeology. All counts below were re-verified
> against the live source tree while writing this document (`grep -c "public function "` /
> `grep -c "getConnection()"` per file), not recalled from memory.
>
> Commits covered: `c9b0ff0a8` (bulk of Part B), `3e3b61839` (TagRepository identity-map gap),
> `18174c204` (manifest status fix), `cb956266b` (BatchWriter/raw-write audit outside the
> repository layer, `Bootstrap\InfrastructureAccessor`, PermissionRepository reclassification).

## 1. Tables that are never entity-mapped, anywhere, permanently

- **`users`** — every method touching it takes column names as caller-supplied parameters
  (`Config\CurrentConfig::userFields()`, Piwigo's multi-auth column-remapping). Confirmed real
  (loaded from a `config` row on every boot) but confirmed **dead** in this rewrite's own
  install/migration path — no schema variant and no admin UI ever produces a non-default
  mapping. Mapping it to an entity would be modeling a feature this codebase doesn't actually
  support. `Users\UserRepository` owns only `user_infos` (via `UserInfoEntity`); every method
  that touches `users` itself stays on raw DBAL.
- **`image_category`** — deliberately left unmapped by every repository that touches it
  (`Image\ImageRepository`, `Category\CategoryRepository`). Almost every real touch is a
  dynamic-fragment or cross-table-join method; the few clean single-row exceptions didn't
  justify the cross-repository coordination a shared entity would need (same "no single owner"
  problem `UserAccessEntity`/`GroupAccessEntity` solve elsewhere, judged not worth it here given
  how few methods would actually benefit).
- **`config`** — used as a generic key/value store from several unrelated call sites
  (`Image\ImageRepository`'s `empty_lounge_running` install-time lock flag,
  `Admin\MenubarPageRenderer`'s per-menu layout blob). Owned by `Config\ConfigEntry`
  (pre-existing, from before this migration); every other repository that touches it delegates
  to `ConfigRepository` rather than minting a second mapping.

## 2. Repositories that needed zero changes (still `extends AbstractRepository`)

Confirmed via `grep -rl "extends AbstractRepository" src/Piwigo` (8 files, re-checked live):

| Repository | Why |
| --- | --- |
| `Auth\PasswordRepository` | Owns no table — writes one column on `users`, which is permanently unmapped (§1). |
| `Permission\PermissionRepository`'s *original* classification (see §6 — this was wrong) | superseded, see below |
| `Caddie\CaddieRepository` | 1 trivial method, no dynamic SQL, but nothing else in its call graph forced conversion — left as-is rather than converted opportunistically. |
| `Calendar\CalendarRepository` | Every method takes a caller-built SQL fragment (`findRowsByClause`/`queryColumn`-shaped). Genuinely dynamic, not parameterizable in DQL's static-query model. |
| `Mail\MailRecipientRepository` | Pure reads across `users`'s permanently-unmapped dynamic columns (§1) — nothing to map. |
| `Notification\NotificationByMailRepository` | Small, no dynamic SQL, no forcing dependency — left as-is. |
| `Notification\NotificationRepository` | Every method takes a caller-built `$restrictSql`/`$whereSql` fragment — same shape as `SearchRepository`/`CalendarRepository`. |
| `Search\SearchRepository` | Every method takes a whole caller-built query fragment (`findRows(string $rawQuery)`) — the canonical "genuinely dynamic" case. |
| `Section\SectionRepository` | Same dynamic-fragment shape as Calendar/Search. |

**`Permission\PermissionRepository` is *not* in this list anymore.** It was originally
classified "reads only, owns nothing, needs zero changes" — wrong. A `grep -n
"delete(\|insert(\|update("` re-check (prompted by the BatchWriter audit, §5) found two real
write methods, `deleteUserAccess()`/`massInsertUserAccess()`, into `user_access`. It's now
converted to hold `EntityManagerInterface` directly (see §3) and its two writers use DQL bulk
delete / `BatchWriter` over `$this->em->getConnection()`, each followed by `$this->em->clear()`.

## 3. Repositories with no owned entity, but holding `EntityManagerInterface` (touch tables *other* repositories own)

| Repository | Tables touched | Owning entity used |
| --- | --- | --- |
| `Auth\AuthRepository` | `user_auth_keys` | `Auth\UserAuthKeyEntity` (shared with ApiKey, no `repositoryClass`) |
| `Auth\ApiKeyRepository` | `user_auth_keys` | same `UserAuthKeyEntity` |
| `Permission\PermissionRepository` | `categories`, `group_access`, `user_access` | reads via plain DQL/`getConnection()`; writes via `Category\UserAccessEntity` |
| `Permalink\PermalinkRepository` | `images`, `categories` | reads only, no owned table |
| `Metadata\MetadataRepository` | `images` | reads/simple updates via `ImageEntity` |
| `Admin\Maintenance\DbMaintenanceRepository` | many (`history`, `history_summary`, `search`, `sessions`, …) | per-method, only where a clean single-table delete exists |
| `Admin\Extensions\ExtensionRepository` | `user_infos` (theme/language reassignment on delete) | `Users\UserInfoEntity` |

These classes exist specifically because the table they need to write belongs to a different
domain's repository — sharing `EntityManagerInterface` (not `getRepository()`) is the correct
shape when a class needs DQL/connection access to someone else's mapped table without claiming
ownership of it.

## 4. Per-repository raw-vs-total method counts (the "mixed repository" pattern)

Every conversion followed the same rule established by `Config\ConfigRepository`'s own
pre-existing precedent: only single-row/simple-bulk-by-id methods go through DQL; dynamic-SQL,
cross-domain, and generic-column methods stay on raw DBAL inside the same class (reached via
`$this->getEntityManager()->getConnection()` rather than a bare `Connection` property).
Re-counted live (`grep -c "public function "` / `grep -c "getConnection()"`), not recalled:

| Repository | Total methods | Raw-DBAL methods | Notes |
| --- | --- | --- | --- |
| `Category\CategoryRepository` | 65 | 51 | Largest and riskiest single item in the migration — bulk `mass*` writers, `fetchCallerBuiltQuery`, cross-table joins all stay raw. |
| `Image\ImageRepository` | 35 | 25 | Same shape — `mass*` bulk writers, self-referential `incrementVisitCounter`, atomic `tryAcquireLoungeLock` INSERT-IGNORE against `config`. |
| `Rate\RateRepository` | 21 | 18 | Aggregate/statistics-heavy — most methods compute across joins, not single-row lookups. |
| `Users\UserRepository` | 17 | 14 | Only `user_infos`-scoped single-row methods went to DQL; every `users`-table method stays raw (§1). |
| `History\HistoryRepository` | 16 | 15 | `search()` specifically is a caller-built dynamic-WHERE method; the rest of the "clean" majority still ended up raw in practice once each method was checked individually — genuinely history-heavy queries lean on joins/aggregates DQL doesn't simplify. |
| `Admin\Extensions\ExtensionRepository` | 14 | 9 | Genuinely polymorphic across 3 heterogeneous tables (plugin/theme/language) plus dynamic `$type->table()` dispatch. |
| `Comment\CommentRepository` | 11 | 6 | `countAvailableWithConditions()` is a caller-built dynamic-WHERE method; several other stats/aggregate methods followed it onto raw DBAL once checked. |
| `Permalink\PermalinkRepository` | 11 | 7 | No owned table (§3) — most methods are cross-table reads. |
| `Auth\AuthRepository` | 15 | 7 | Shares `user_auth_keys` with ApiKeyRepository; lifecycle/session-shaped methods stayed raw. |
| `Admin\Maintenance\DbMaintenanceRepository` | 9 | 8 | `repairOptimizeAllTables()` runs `OPTIMIZE TABLE`/`REPAIR TABLE` (DDL-adjacent, not ORM-representable at all); most of the rest are per-table bulk deletes across tables this class doesn't own. |
| `Permission\PermissionRepository` | 9 | 7 | Reclassified mid-migration (§2/§6) — cross-cutting reads over tables it doesn't own stay raw; only the two writers it actually owns went to DQL/`BatchWriter`. |
| `Metadata\MetadataRepository` | 5 | 4 | No owned table (§3). |
| `Activity\ActivityRepository` | 7 | 3 | `countByUser()` specifically kept on raw DBAL after a live smoke test proved phpstan-doctrine was **wrong** to claim the nullable `performedBy` FK column is always non-null under `GROUP BY` — see `feedback_phpstan_doctrine_nullable_groupby_wrong` memory. |
| `Group\GroupRepository` | 19 | 3 | Mostly clean; a few group/user cross-joins stay raw. |
| `Tag\TagRepository` | 20 | 9 | `countImagesPerTag()` takes a dynamic `$fandFSql` fragment; `massInsertImageTags()` is a real bulk writer (fixed to `$em->clear()` after, in `3e3b61839` — see §7). |
| `Auth\ApiKeyRepository` | 7 | 0 | Fully clean — shares `UserAuthKeyEntity`, no dynamic SQL anywhere. |
| `Site\SiteRepository` / `Feed\FeedRepository` / `PluginConfig\PluginRepository` | 4 / 4 / 2 | 0 / 0 / 0 | Small, fully clean conversions. |

Repositories not listed above (`Lang`, `Audit`, `Session`, `Config`) predate this migration or
were covered by earlier gap-closure work, not Part B itself.

## 5. Structural exceptions — genuinely can't be pure DQL, not deferred

- `Session\SessionRepository` — implements PHP's own `SessionHandlerInterface`
  (`read()`/`write()`/`destroy()`/`gc()`), fixed signatures the language itself imposes via
  `session_set_save_handler()`. Not an ordinary domain repository.
- `Search\SearchRepository`, `Calendar\CalendarRepository`, `Section\SectionRepository`,
  `Notification\NotificationRepository` — whole-class caller-built dynamic SQL (§2).
- `History\HistoryRepository::search()`, `Tag\TagRepository::countImagesPerTag()`,
  `Comment\CommentRepository::countAvailableWithConditions()`,
  `Admin\Maintenance\DbMaintenanceRepository::repairOptimizeAllTables()` — single dynamic/DDL
  methods inside an otherwise-mixed repository.
- `Admin\Extensions\ExtensionRepository` — 3 heterogeneous tables behind one dynamic
  `$type->table()` dispatch; "exception" here means "3 real entities," not "stays raw SQL."

## 6. Deleted rather than converted

`Menu\MenubarLayoutRepository` — its one method, `saveLayout()`, wrote `param`/`value` rows into
the `config` table that `Config\ConfigEntry` already maps. Minting a second entity for an
already-owned table would have been duplicate mapping, not migration. Deleted outright (along
with its test); its one call site (`Admin\MenubarPageRenderer.php`) now calls
`ConfigRepository::upsert('blk_' . $menu->get_id(), $encodedPositions)` directly, preserving the
original `CachePools::config()->clear()` side effect.

## 7. Raw writes *outside* the repository layer — the BatchWriter/`executeStatement()` audit (`cb956266b`)

Every repository conversion above was self-contained — but several Controller/Ws/Admin classes
bypass their domain's repository entirely and call `BatchWriter`/raw `executeStatement()`
directly against a table some entity now maps. A dedicated audit found and fixed every real
instance of this, and also found the following about what "fixing" one actually requires:

**Key finding: `Piwigo\Db\EntityManagerFactory::build()` is not memoized.** It always returns a
fresh `EntityManager` (`new EntityManager($conn, $config)`), so `EntityManagerFactory::build($conn)
->clear()` protects nothing — there was never anything cached in that instance to go stale. The
only place a genuinely *shared* identity map exists is the DI container's own
`EntityManagerInterface` singleton, reachable only through `Piwigo\Core\Kernel::container()` —
which is itself arch-test-restricted to `Bootstrap/` + `index.php`. New
`Bootstrap\InfrastructureAccessor::entityManager()` was added as the missing accessor so
Controller/Ws/Admin (L4Integration) classes can legally reach that shared instance and clear it.

**33 real call sites fixed across 13 files** (`grep -rn "InfrastructureAccessor::entityManager"
src/Piwigo`, re-verified live): `Admin\BatchManagerGlobalPageRenderer.php`,
`Admin\BatchManagerUnitPageRenderer.php`, `Admin\PictureModifyPageRenderer.php`,
`Admin\Upload\UploadService.php`, `Controller\Admin\SiteUpdateSubController.php`,
`Controller\PictureController.php`, `Controller\ProfileController.php`,
`Controller\ProfileFormHandler.php`, `Ws\PwgCategories.php`, `Ws\PwgCore.php`, `Ws\PwgImages.php`,
`Ws\PwgPermissions.php`, `Ws\PwgTags.php` (the specific `image_tag` write that originally
prompted this audit).

**Two confirmed, deliberate non-fixes** (not oversights — traced and documented rather than
silently skipped):

- `Admin\Install\InstallWizard.php`'s `BatchWriter` writes into `sites` and `users` — runs before
  `DbCredentials::seed(...)`; even if `Kernel::container()` were reachable there, the container's
  `EntityManagerInterface` would wrap a `Connection` built from potentially stale pre-seed
  credentials, not the fresh `$conn` the method actually uses. Clearing it would be both
  architecturally risky and pointless. (`users` is also permanently unmapped, §1.)
- `Users\UserService::checkAndSaveUserInfos()`'s raw writes into `user_group`/`user_infos` — its
  only real caller, `Ws\PwgUsers::userService()`, manually constructs `UserService` via a fully
  isolated `EntityManagerFactory::build(DbConnection::build())->getRepository(...)` chain (no
  container involved at all — re-confirmed live, see that file's `private static function
  userService()`). There is no shared identity map anywhere in that call path to protect; adding
  one would require threading `EntityManagerInterface` through `UserService`'s constructor and
  updating ~20 pre-existing manual `new UserService(...)` sites for no behavioral benefit.

Full verification cadence (PHPStan 0, ECS clean, deptrac 0 violations, Unit/Arch 711, Integration
682, Contract 96, Browser 68) passed after every commit in this list.

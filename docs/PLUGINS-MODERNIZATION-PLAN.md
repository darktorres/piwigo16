# Plan: Modernize the plugins / extensions module

## Context

The plugins/extensions module is the last large slice of the admin tier that predates the §1.4 rewrite. It works, but it carries the legacy procedural shape:

- Three god-classes in `src/Piwigo/Admin/`: `Plugins.php` (726 lines, 14 public methods, 4 public mutable arrays, 15 `mixed` sites), `Themes.php` (692/16), `Languages.php` (385/6) — near-duplicate code that handles lifecycle, filesystem scanning, PEM catalog, and PHP-based sorting under one roof.
- `Plugins::performAction(): mixed` (`src/Piwigo/Admin/Plugins.php:83`) — the one outlier in the trio (`Themes`/`Languages` return typed `array`). Mixed because of recursion (`install→activate`, `restore→uninstall+activate`, `delete→uninstall`) + `PluginInstallErrors::$errors: array<mixed>` event payload.
- WS handlers in `src/Piwigo/Ws/Action/Pwg/Extensions/` reach into the admin services' public mutable arrays directly (`$plugins->fs_plugins`, `$plugins->db_plugins_by_id`) — Demeter violations that block any state-encapsulation pass.
- 10 consumers across `src/` touch those public arrays: `Updates`, `InstallService`, `TelemetryService`, the two `Extensions/*Handler` classes, `ExtensionsController` (1,227 lines), `UpgradeController`, plus the admin services themselves.
- `PluginInterface` + `PluginRegistry` (665 lines) are already modern (§1.4): typed manifests, JSON-Schema validation, lifecycle methods, PSR-14 event subscription, migration ledger. **`Plugins` admin still reads through both `PluginRepository` and `PluginRegistry` in parallel** instead of treating `PluginRegistry` as the SoT.
- `pwg.extensions.*` WS endpoints (6 handlers) skipped during F5-g/h because of these couplings.
- `CheckUpdatesHandler` uses `Session::piwigoNeedsUpdate` / `extensionsNeedUpdate` as a request-scoped cache — the wrong tool; an actual `CacheItemPool` with TTL fits.

The goal: collapse the three near-duplicate god-classes into a small set of typed services, treat `PluginRegistry` / `ThemeRegistry` as the read SoT, route lifecycle through a single typed result, and finish F5-g/h's `pwg.extensions.*` DTO migration on top of that.

Aligns with the §1.4 clean break (v17.0 intentionally broke pre-17 extensions; external plugin compat is not a blocker — see [MODERNIZATION.md](MODERNIZATION.md) and [EXTENSIONS.md](EXTENSIONS.md)).

---

## Target architecture (the idioms)

### A. Lifecycle is one verb that returns a typed result

```php
// src/Piwigo/Extension/Lifecycle/LifecycleAction.php
enum LifecycleAction: string {
    case Install = 'install';
    case Activate = 'activate';
    case Deactivate = 'deactivate';
    case Uninstall = 'uninstall';
    case Update = 'update';
    case Restore = 'restore';
    case Delete = 'delete';
}

// src/Piwigo/Extension/Lifecycle/LifecycleResult.php
final readonly class LifecycleResult {
    /** @param list<string> $errors */
    private function __construct(
        public LifecycleStatus $status,   // Ok | Skipped | Failed
        public ?string $fromVersion,
        public ?string $toVersion,
        public array $errors,
    ) {}
    public static function ok(?string $from, ?string $to): self;
    public static function skipped(string $reason): self;
    public static function failed(string ...$errors): self;
}
```

- `PluginLifecycleService::run(LifecycleAction $action, PluginId $id, ?Revision $rev = null): LifecycleResult` replaces `Plugins::performAction(): mixed`.
- Symmetric `ThemeLifecycleService::run(...)`, `LanguageLifecycleService::run(...)`.
- Internal recursion is direct typed flow — drop the `is_array($installResult) ? $installResult : []` shim at `Plugins.php:149` and `:201`.
- `PluginInterface` lifecycle methods stay `void`; errors surface via `PluginValidationException | PluginDependencyException` caught at the service edge. No `PluginInterface` change.

### B. State reads are typed VOs from registries, not public mutable arrays

```php
// src/Piwigo/Extension/PluginSnapshot.php
final readonly class PluginSnapshot {
    public function __construct(
        public PluginId $id,
        public PluginManifest $manifest,
        public PluginState $state,        // enum: Uninstalled | Inactive | Active
        public ?string $installedVersion, // null if uninstalled
        public bool $hasSettings,
        public bool $needsUpdate,
    ) {}
}

// src/Piwigo/Plugin/PluginCatalog.php
final readonly class PluginCatalog {
    /** @return list<PluginSnapshot> */
    public function all(SortOrder $order = SortOrder::Name): array;
    public function get(PluginId $id): ?PluginSnapshot;
}
```

- `PluginCatalog` composes `PluginRegistry` (manifests, active state) + `PluginRepository` (installed rows) + the per-user `hasSettings` resolution. **Single read SoT.**
- Replaces `Plugins::$fs_plugins`, `$db_plugins_by_id`, `getFsPlugins()`, `getFsPlugin()`, `sortFsPlugins()`.
- Mirror: `ThemeCatalog`, `LanguageCatalog`.

### C. PEM catalog is its own service, decoupled from disk operations

```php
// src/Piwigo/Pem/PemPluginCatalog.php
final readonly class PemPluginCatalog {
    /** @return list<PemExtension> */
    public function listFor(string $piwigoVersion): array;
    public function findIncompatible(string $piwigoVersion): IncompatibilityReport;
    public function downloadRevision(Revision $rev, PluginId $id): DownloadResult;
}
```

- Wraps Symfony `HttpClient` (already a dep).
- Replaces `Plugins::getVersionsToCheck`, `getServerPlugins`, `getMergedExtensions`, `getIncompatiblePlugins`, `invalidateIncompatibleCache`, `extractPluginFiles`.
- Compatibility cache moves to `CacheItemPoolInterface` with TTL — drop the session/file-cache hybrid.

### D. Filesystem scanning is its own service

```php
// src/Piwigo/Plugin/FilesystemPluginScanner.php
final readonly class FilesystemPluginScanner {
    /** @return list<FilesystemPlugin> */ // {id, manifest}
    public function scan(): array;
}
```

- Uses Symfony `Finder` instead of `opendir`/`readdir`.
- Already implicitly done by `PluginRegistry::load()` — pull the scan loop out of `Plugins` admin and reuse the registry's view.

### E. Activity logging via PSR-14, not inline calls

- New typed event `Piwigo\Event\Lifecycle\PluginLifecycleCompleted` carrying `{action, pluginId, result}`.
- `PluginLifecycleService` dispatches; an `ActivityLoggerSubscriber` listens and writes to `ActivityLogger`.
- Drops the `$this->activityLogger->log(new ActivityEvent(...))` body at `Plugins.php:219` and unifies the activity-detail shaping (currently rebuilt by hand in every `case`).

### F. WS endpoints are typed Params + Result DTOs

- The 6 `pwg.extensions.*` handlers all migrate to F5-g/h's pattern.
- Cross-type endpoints (`pwg.extensions.update`, `pwg.extensions.checkUpdates`, `pwg.extensions.ignoreUpdate`) keep their dispatch shape but parse `type: ExtensionType` at the boundary. The handlers fan out to per-type services via a `Map<ExtensionType, LifecycleService>` (small autowired collection, not a string switch).
- `PluginInterface` stays untouched — the WS surface is decoupled from the in-process plugin contract.

### G. Demeter cleanup

- Every consumer reads through a typed accessor: `$catalog->all()`, `$lifecycleService->run(...)`, `$pemCatalog->listFor(...)`.
- The 4 public mutable arrays on `Plugins.php` become `private` + drop. Same for `Themes.php`, `Languages.php`.
- 10 cross-module callers (`Updates`, `InstallService`, `TelemetryService`, `Extensions/*Handler`, `ExtensionsController`, `UpgradeController`, the admin trio itself) get migrated in lockstep.

---

## Phases

Each phase ships independently. Order is dependency-driven, not aesthetic. Sub-numbering attached to F5-g/h where the work folds into the in-flight migration; M-numbers where it doesn't.

| # | Phase | Scope | Depends on |
|---|-------|-------|------------|
| **M1** | `LifecycleAction` enum + `LifecycleResult` VO + `LifecycleStatus` enum | New code only under `src/Piwigo/Extension/Lifecycle/` | — |
| **M2** | `PluginInstallErrors::$errors` retype `array<mixed>` → `list<string>` | Drops 1 mixed; 1-file change | M1 not strictly required |
| **M3** | `PluginLifecycleService` extracted from `Plugins::performAction` | New service, returns `LifecycleResult`; `Plugins::performAction()` retained as a thin shim until M9 | M1, M2 |
| **M4** | `PluginCatalog` (snapshots) | New read SoT composing `PluginRegistry` + `PluginRepository`; `Plugins::$fs_plugins` / `$db_plugins_by_id` become `private` once all 10 callers migrate | M1 (uses `PluginState` enum) |
| **M5** | `PemPluginCatalog` + Symfony `HttpClient` | Extracts the PEM HTTP/cache code from `Plugins.php` | — |
| **M6** | `PluginLifecycleCompleted` event + `ActivityLoggerSubscriber` | Moves `activityLogger->log(...)` calls out of the lifecycle body | M3 |
| **M7** | Mirror for themes: `ThemeLifecycleService`, `ThemeCatalog`, `PemThemeCatalog` | Parallel structure to Plugins; reuses `LifecycleAction`/`Result` | M3, M4, M5 |
| **M8** | Mirror for languages: `LanguageLifecycleService`, `LanguageCatalog`, `PemLanguageCatalog` | Same | M3, M4, M5 |
| **F5-g/h /11** | `IgnoreUpdate` + `CheckUpdates` DTOs | Mechanical lift; no architectural deps | (current WIP `/10` ships first) |
| **F5-g/h /12** | `PluginsGetList` (uses `PluginCatalog`) + `ThemesPerformAction` DTOs | First WS-tier consumer of `PluginCatalog` | M4 (for Plugins side); themes side is mechanical |
| **F5-g/h /13** | `PluginsPerformAction` + `Update` DTOs | Routes via `PluginLifecycleService` | M3 (plus M7/M8 for `Update`'s themes/languages branches) |
| **M9** | Delete legacy surfaces: `Plugins::performAction()` shim, public mutable arrays, dead sort comparators | Once all 10 cross-module callers + 6 WS handlers migrate to typed services | All above |
| **M10** | Session→Cache for `piwigoNeedsUpdate` / `extensionsNeedUpdate` | New `UpdatesCheckCache` (PSR-6 / Symfony Cache); drops the Session-as-cache anti-pattern | — (independent) |

### Phase folding

The minimum cut to unblock F5-g/h is **M1 → M2 → M3 → F5-g/h /11–/13** (drop M9's legacy-removal step; leave the shim in place for the next pass). The rest (M4–M9, M10) is the wider modernization — independent, valuable on its own, and can land in any order after M3.

---

## Critical files

**New (under `src/Piwigo/Extension/` — the shared abstractions):**
- `Lifecycle/{LifecycleAction, LifecycleResult, LifecycleStatus}.php`
- `Lifecycle/{ExtensionLifecycleService}.php` (interface, with `PluginLifecycleService`, `ThemeLifecycleService`, `LanguageLifecycleService` implementing it)
- `{ExtensionState, ExtensionType}.php` — `ExtensionType` enum already exists at `src/Piwigo/Admin/Extensions/ExtensionType.php`; relocate or alias.

**New (under `src/Piwigo/Plugin/`):**
- `PluginCatalog.php`, `PluginSnapshot.php`, `FilesystemPluginScanner.php`
- `PluginLifecycleService.php`
- Update `Event/Lifecycle/PluginInstallErrors.php` (retype) + new `Event/Lifecycle/PluginLifecycleCompleted.php`.

**New (under `src/Piwigo/Pem/`):**
- `PemPluginCatalog.php`, `PemThemeCatalog.php`, `PemLanguageCatalog.php` — or a single `PemCatalog<T>` with three frontends.
- Reuses existing `PemUrlResolver`.

**New (WS DTOs — under `src/Piwigo/Ws/Action/Pwg/Extensions/`):**
- `{IgnoreUpdate, Update, ThemesPerformAction, PluginsPerformAction}Params.php`
- `{CheckUpdates, PluginsGetList}Result.php` + `PluginListItem.php`

**Modified:**
- `src/Piwigo/Admin/Plugins.php` — shrinks dramatically: ~150 LOC of orchestration around the new services, ultimately deleted in M9.
- `src/Piwigo/Admin/Themes.php`, `src/Piwigo/Admin/Languages.php` — same trajectory.
- `src/Piwigo/Ws/Action/Pwg/Extensions/*Handler.php` (×6) — call typed services instead of admin god-classes.
- `src/Piwigo/Controller/Admin/ExtensionsController.php` — 1,227-line consumer; migrate its admin-UI render path to `PluginCatalog::all()` + lifecycle service.
- `src/Piwigo/Controller/UpgradeController.php`, `src/Piwigo/Admin/Updates.php`, `src/Piwigo/Admin/InstallService.php`, `src/Piwigo/Telemetry/TelemetryService.php` — drop their `fs_plugins` / `db_plugins_by_id` reaches.

**Mirror / mirror-mirror:** `src/Piwigo/Theme/{ThemeCatalog, ThemeLifecycleService, ThemeSnapshot}.php` + `src/Piwigo/Language/{LanguageCatalog, LanguageLifecycleService, LanguageSnapshot}.php`.

**Reused (already modern, no change):**
- `PluginInterface`, `ThemeInterface`, `PluginRegistry`, `ThemeRegistry`, `PluginManifest`, `ThemeManifest`, `PluginMigrationRunner`, `PluginMigrationLedger`.
- `IgnoredUpdatesRepository` + `ExtensionType` enum (already typed in F5).
- `PemUrlResolver`.

---

## Verification

After each commit:
- `composer dump-autoload` for every new class.
- `composer lint:php` (Pint).
- `vendor/bin/phpstan analyse` — green.
- `vendor/bin/psalm` (errors-only) — green; `--show-info=true` strictly decreases per step (record delta).
- `vendor/bin/phpunit` — full suite green.

End-to-end smoke (no automated coverage in this area):
- **Plugin lifecycle**: in admin UI as webmaster, install → activate → deactivate → uninstall → reinstall an in-tree plugin (use a fixture under `plugins/` if any ship post-B17; otherwise install via `pwg.extensions.update` against PEM). Verify `LifecycleResult::status` reaches `Ok` and `errors === []` on success; trigger a validation error and confirm `Failed` with the message list.
- **Theme switch**: activate / deactivate / set-default a theme via the admin UI; confirm compiled template invalidation still fires.
- **PEM update flow**: trigger `pwg.extensions.checkUpdates`, then `pwg.extensions.update` for plugins, themes, languages — verify the redirect-and-reactivate dance on a currently-active plugin still works.
- **Ignore-update**: mute one extension, reset all of one type, reset everything — three paths through `IgnoreUpdateHandler`.
- **Telemetry**: `TelemetryService::buildPluginsSnapshot` / `buildThemesSnapshot` still produce the same outbound JSON shape (uses `PluginCatalog` now instead of reading the public arrays).
- **Upgrade**: run `UpgradeController` end-to-end on a copy DB; the install/upgrade pathways that read `fs_plugins` should still see the same set of plugins.
- **OpenAPI**: `/ws/openapi.json` for the 6 `pwg.extensions.*` methods shows typed Params/Result schemas after F5-g/h /11–/13.

---

## Out of scope

- **No change to `PluginInterface` / `ThemeInterface`**. Lifecycle methods stay `void` + exceptions; if non-fatal warnings ever become a need, that's a separate sealed-result-from-lifecycle-method design.
- **No new `plugins/` ships in-tree** — clean-slate v17 stays clean.
- **No engine migration** for any plugin tables (MyISAM→InnoDB sweep is deferred to its own pass).
- **No install-flow rework** in this plan; the install/upgrade controllers consume the new catalog but the install rewrite itself remains parked.
- **PHP-level translation of `_e()` / `l10n()` calls** inside lifecycle messages stays as-is — the typed `errors: list<string>` carries already-translated strings, as the legacy code does today.

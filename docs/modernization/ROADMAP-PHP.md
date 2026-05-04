# Piwigo 16.x — Modernization Roadmap (PHP)

PHP-only modernization work. See [MODERNIZATION.md](MODERNIZATION.md) for architecture context and completed phase summaries; see [ROADMAP-TS.md](ROADMAP-TS.md) and [ROADMAP-CSS.md](ROADMAP-CSS.md) for the other tracks.

---

## #1 — PSR-12 + Pint CI gate

**Status:** ✅ Done &nbsp;|&nbsp; **Size:** S

### Goal

Enforce PSR-12 across the entire PHP codebase via Laravel Pint as a hard CI gate. This is the foundation item — every subsequent rename, file move, and class extraction lands on top of a consistent style baseline.

### Current state

- `vendor/bin/pint` installed (`composer.json` `require-dev`).
- `pint.json` exists with `psr12` preset + project rules (`single_quote`, `ordered_imports`, `no_unused_imports`, `trailing_comma_in_multiline`, `declare_strict_types: false`); `_data`, `language`, `local`, `vendor` excluded. Codebase is baseline-formatted (`pint --test` is the working contract for any new style work).
- `.github/workflows/ci.yml` runs two jobs on push/PR: `style` (Pint `--test`) and `phpstan` (`composer dump-autoload --strict-psr` + `vendor/bin/phpstan analyse`). Direct actions pinned to current majors (`actions/checkout@v6`, `shivammathur/setup-php@v2`, `ramsey/composer-install@v4`); all run on Node 24. The audit job (#4 step 1), Infection job (#28), and TS/CSS jobs land here later.
- `.editorconfig` at repo root pins LF, UTF-8, 4-space PHP indent, 2-space JSON/YAML, trim trailing whitespace, final newline (markdown opts out of trim, `*.bat` opts into CRLF).
- `CONTRIBUTING.md` documents `vendor/bin/pint`, `--dirty`, `--test`, and the optional pre-commit hook.

### Steps

1. **Configure `pint.json`.** ✅ Done — `psr12` preset + project-specific rules.

2. **Baseline-format all PHP.** ✅ Done — current tree passes `vendor/bin/pint --test`.

3. **Stand up `.github/workflows/`.** ✅ Done — `.github/workflows/ci.yml` runs `vendor/bin/pint --test` on push/PR (PHP 8.5, `ramsey/composer-install@v3` for cache). The PHPStan + `--strict-psr` job (#2 / #3) was added alongside it during the same build-out. Remaining future tenants of this file: audit (#4 step 1), Infection (#28), and TS/CSS jobs from ROADMAP-CSS.md and ROADMAP-TS.md.

4. **Add `.editorconfig`.** ✅ Done — root file pins LF, UTF-8, 4-space PHP indent, 2-space JSON/YAML, trim trailing whitespace, final newline; markdown opts out of trim, `*.bat` opts into CRLF.

5. **Document.** ✅ Done — `CONTRIBUTING.md` Style section covers `vendor/bin/pint`, `--dirty`, `--test`, the optional `pre-commit` hook, and the EditorConfig note.

### Verification

```bash
vendor/bin/pint --test     # exits 0 on a clean tree
git ls-files | xargs grep -l $'\r$' || echo "no CRLF files"   # editorconfig honored
```

CI fails any PR introducing PSR-12 violations.

---

## #2 — `declare(strict_types=1)` sweep

**Status:** ✅ Done &nbsp;|&nbsp; **Size:** S

### Goal

Every file under `src/`, `include/`, and `admin/` declares `strict_types=1`. A PHPStan rule fails CI if a new file is added without it.

### Current state

- `grep -rL 'declare(strict_types=1);' src/ admin/ include/ --include='*.php'` is empty — every PHP file in scope has the declaration. No deferral to #17 was needed; the sweep was global.
- `Piwigo\Tools\PhpStan\StrictTypesRequiredRule` lives at `tools/phpstan/StrictTypesRequiredRule.php` and is registered in `phpstan.neon` alongside `NoDynamicNewRule` and `NoGlobalInSrcRule`. It walks `FileNode` and flags any file under `src/`, `include/`, or `admin/` that lacks `declare(strict_types=1);`. PHPStan reports zero hits on the clean tree, and a probe file dropped under `src/Piwigo/` confirmed the rule fires on regression.
- CI enforcement is live: `.github/workflows/ci.yml` runs `vendor/bin/phpstan analyse` in the `phpstan` job, so the rule blocks any push that adds an unguarded file.

### Verification

```bash
grep -rL 'declare(strict_types=1);' src/ admin/ include/ --include='*.php'   # empty (already)
vendor/bin/phpstan analyse --no-progress                     # green, including new rule
```

---

## #3 — PSR-4 strict layout + PascalCase normalization

**Status:** ✅ Done &nbsp;|&nbsp; **Size:** M

### Goal

Composer's `--strict-psr` mode passes clean. Every class in `src/` lives in a PascalCase file matching its class name. Lowercase class names are renamed to PascalCase. The legacy `include/*.class.php` files are gone (their bodies are already in `src/Piwigo/`).

### Current state

All 14 lowercase / mixed-case classes under `src/Piwigo/Admin/` have been renamed to PascalCase, with their files moved via `git mv` to match. Every first-party caller (admin/, include/, top-level entry-points) now references the new names. `composer dump-autoload --strict-psr` exits clean. The 17 legacy `include/*.class.php` and `admin/include/*.class.php` placeholder stubs (5-line `<?php declare(strict_types=1);` files) are still present and slated for deletion in #30; no first-party code depends on them.

**Renames applied:**

| Old | New |
|---|---|
| `Piwigo\Admin\plugins` | `Piwigo\Admin\Plugins` |
| `Piwigo\Admin\themes` | `Piwigo\Admin\Themes` |
| `Piwigo\Admin\languages` | `Piwigo\Admin\Languages` |
| `Piwigo\Admin\tabsheet` | `Piwigo\Admin\Tabsheet` |
| `Piwigo\Admin\updates` | `Piwigo\Admin\Updates` |
| `Piwigo\Admin\DummyPlugin_maintain` | `Piwigo\Admin\DummyPluginMaintain` |
| `Piwigo\Admin\DummyTheme_maintain` | `Piwigo\Admin\DummyThemeMaintain` |
| `Piwigo\Admin\Image\pwg_image` | `Piwigo\Admin\Image\PwgImage` |
| `Piwigo\Admin\Image\image_gd` | `Piwigo\Admin\Image\ImageGd` |
| `Piwigo\Admin\Image\image_imagick` | `Piwigo\Admin\Image\ImageImagick` |
| `Piwigo\Admin\Image\image_ext_imagick` | `Piwigo\Admin\Image\ImageExtImagick` |
| `Piwigo\Admin\Image\imageInterface` | `Piwigo\Admin\Image\ImageInterface` |
| `Piwigo\Admin\Integrity\c13y_internal` | `Piwigo\Admin\Integrity\C13yInternal` |
| `Piwigo\Admin\Integrity\check_integrity` | `Piwigo\Admin\Integrity\CheckIntegrity` |

`rector.php`'s `RenameClassRector` map keeps the legacy unqualified names as **keys** pointing to the new FQN values, so any leftover bare reference (e.g. inside an out-of-tree plugin) gets rewritten correctly when rector is run on it. No `src/Piwigo/Compat/aliases.php` shim is needed — first-party code is fully migrated.

**Out-of-band naming corrections in `src/` (historical context, already in place):**

- `include/Logger.class.php` was moved to `src/Piwigo/Core/Logger.php` (not `src/Piwigo/Log/Logger.php` as the original plan said). The `Log\` namespace doesn't exist.
- `include/totp.class.php` was moved to `src/Piwigo/Auth/PwgTOTP.php` (kept the `Pwg` prefix — not `Totp`).
- `include/block.class.php` was split into `src/Piwigo/Menu/{BlockManager,DisplayBlock,RegisteredBlock}.php`.
- `include/cache.class.php` was split into `src/Piwigo/Cache/{PersistentCache,PersistentFileCache}.php`.

### Steps

1. **Rename `src/` files to PascalCase.** ✅ Done — 14 `git mv` operations against `src/Piwigo/Admin/`.

2. **Rename lowercase classes.** ✅ Done — class declarations, internal self-references, all first-party `use` and `new` sites, the FQN type hint inside `C13yInternal`, the `\Piwigo\Admin\Plugins`-style FQN constructions in `pwg.extensions.php`, and the doc strings in `tools/triggers_list.php` were all updated. Out-of-tree plugins were deliberately left untouched per the original plan; `rector.php`'s rename map points old keys at the new FQNs so any bare legacy reference still gets rewritten if rector runs on it.

3. **Delete the empty `*.class.php` placeholders.** ✅ Done as #30.

4. **Run `composer dump-autoload --strict-psr`.** ✅ Clean.

5. **PHPStan rule.** ✅ Done via CI — `.github/workflows/ci.yml`'s `phpstan` job runs `composer dump-autoload --strict-psr` before `vendor/bin/phpstan analyse`, so any PSR-4 violation fails the push. No custom `Psr4StrictRule` was needed.

### Verification

```bash
composer dump-autoload --strict-psr   # zero warnings
vendor/bin/phpstan analyse            # green
vendor/bin/phpunit                    # green
npx playwright test                   # green
```

---

## #4 — Composer audit + Renovate (or Dependabot)

**Status:** ✅ Done &nbsp;|&nbsp; **Size:** S

### Goal

`composer audit` and `npm audit` fail any PR with a known-vulnerable dependency. Renovate (or Dependabot) opens auto-PRs for dependency updates on a weekly cadence — minor/patch grouped, major requiring manual review.

### Current state

- `.github/workflows/ci.yml` runs an `audit` job alongside `style` and `phpstan`: `composer audit --abandoned=fail` and `npm audit --omit=dev --audit-level=high`. Both exit clean on the current tree.
- `.github/dependabot.yml` is live for the three present ecosystems (composer, npm, github-actions). Weekly Monday-morning runs; minor/patch grouped per ecosystem; majors arrive as separate PRs (default Dependabot behavior, which already requires manual merge — no `automerge` setting needed). Security alerts run independently via Dependabot vulnerability alerts (enabled in repo settings).
- No `renovate.json` (Dependabot was chosen instead).
- `CONTRIBUTING.md` documents the dep-update workflow and the advisory-triage policy (manual bumps, advisory severity tiers, override mechanism).

### Steps

1. **Add audit job to CI.** ✅ Done — third job in `.github/workflows/ci.yml`, runs `composer audit --abandoned=fail` and `npm audit --omit=dev --audit-level=high` on each push. Uses the same action pins as the other jobs (`actions/checkout@v6`, `shivammathur/setup-php@v2`, `ramsey/composer-install@v4`) plus `actions/setup-node@v6` with Node 24 + npm cache.

2. **Add dependency update bot.** ✅ Done — `.github/dependabot.yml` covers composer, npm, and github-actions, weekly on Monday at 06:00 UTC, with minor/patch grouped per ecosystem and labels (`dependencies` + ecosystem name). Majors fall outside the group and arrive as individual PRs requiring manual merge. Dev-dep auto-merge from the Renovate spec was not ported — Dependabot doesn't ship with auto-merge, and adding the corresponding workflow can be a follow-up if churn warrants it.

3. **Document policy** ✅ Done — `CONTRIBUTING.md` Dependencies section covers manual bumps, Dependabot's two layers (weekly grouped PRs + advisory PRs), the per-push audit gate, and a four-step triage flow with a CVSS ≥ 7 / 48-hour patch SLA for known fixes.

### Verification

```bash
composer audit              # exits 0 on a clean tree; non-zero with advisory
npm audit --omit=dev --audit-level=high   # exits 0 on a clean tree
```

A PR introducing a vulnerable dep is blocked. Renovate opens grouped weekly PRs.

---

## #5 — Config schema + `.env` support + install-sentinel relocation

**Status:** Done — bridge + plugin migration shipped &nbsp;|&nbsp; **Size:** L

### What shipped (2026-05-03)

Landed across 21 commits (`e8161cc58` → HEAD). Net delta ≈ -2200 / +1550 lines.

Polish pass after the initial 18-commit landing fixed three downstream
warnings the Apache error log surfaced once the .env-based bootstrap
was actually exercised end-to-end:

- `ConfigLoader::applyDefaults` now skips null-defaulted SCHEMA keys
  (the 22 nullable-string cluster). Seeding literal null had flipped
  `Config::has()` permanently true for those keys, breaking every
  caller that uses `has()` to detect first-run state (maintenance
  pages, admin/configuration first-run persist branches, etc.).
- `admin/configuration.php` first-run `filters_views` persist now
  serializes the array before storing — matches the `?string` shape
  `Config::filtersViews()` returns, so this-request reads agree with
  next-request reads.
- `admin.php:353` coalesces `Config::lastMajorUpdate()` to `''` before
  passing to `strtotime()` — pre-existing latent deprecation that the
  applyDefaults change surfaced more often.

- `Piwigo\Config\Config::SCHEMA` is the single source of truth for all 283
  config keys. Typed accessors below the `<<<CONFIG-ACCESSORS-BEGIN>>>` /
  `<<<CONFIG-ACCESSORS-END>>>` sentinels are generated from SCHEMA by
  `tools/build-config-accessors.php`. Custom accessors with rich defaults
  (`pictureExtensions`, `recentPostDates`, `defaultFiltersViews`,
  `filterPages`, `apiKeyForbiddenMethods`, etc.) are flagged
  `'custom' => true` and live below the END sentinel.
- `getString` / `getInt` / `getBool` are **private**. They throw
  `UnknownConfigKeyException` for keys not in SCHEMA.
- `Config::get()` is gone; `Config::raw($key, $default)` is the single
  public escape hatch for genuinely parametric keys (per-block menu
  configs `blk_*`, `*_running` semaphores, derived `flip_picture_ext`
  caches). `tools/phpstan/ConfigKeyExistsRule.php` flags any literal-key
  call to `raw/has/override/persist/delete` whose key isn't in SCHEMA or
  on its `ALLOWED_RUNTIME_KEYS` allow-list.
- `Piwigo\Config\ConfigLoader` runs at every entry point's boot:
  `applyDefaults($conf)` walks SCHEMA, then `loadEnv($repoRoot)` reads
  `.env` (only — `.env.local` is opt-in for the test runner via
  `tests/bootstrap.php`), then `applyEnvOverrides($conf)` applies the
  curated `ENV_MAPPING` (5 DB vars: HOST/USER/PASSWORD/BASE/PREFIX).
- `Piwigo\Config\ConfigStorage` is the thin OO facade over
  `load_conf_from_db` / `conf_update_param` / `conf_delete_param`.
- `Piwigo\Core\InstallSentinel`: empty stamp file at `local/.installed`
  is the **sole** install signal. No `defined('PHPWG_INSTALLED')`
  fallback. `markInstalled()` / `markUninstalled()` for
  install.php / tests.
- `install.php` writes `.env` atomically (tmp + rename) instead of
  `local/config/database.inc.php`. Fails loudly via `fatal_error` on
  write errors — fixes a long-standing silent-failure that left installs
  half-broken.
- `local/config/database.inc.php` and `include/config_default.inc.php`
  **deleted**. No back-compat include path. The legacy
  `PWG_CHARSET` / `DB_CHARSET` / `DB_COLLATE` constants deleted; literal
  values inlined (utf-8 / utf8 / '') at the 6 use sites.
- `pwg_password_hash` / `pwg_password_verify` / `phpass_verify` deleted.
  `Config::passwordHash()` / `Config::passwordVerify()` accessors and
  their SCHEMA entries deleted. All callsites use native `password_hash($pw,
  PASSWORD_BCRYPT)` and `password_verify($pw, $hash)` directly. Legacy
  `$P$` phpass support intentionally dropped (modernization upgrade
  floor is 16.x; users with phpass hashes need a password reset).
- 4 bundled plugins get per-plugin typed Config classes under
  `src/Piwigo/Plugins/<PluginName>/Config.php` (autoload-friendly, not
  `plugins/<name>/src/`): `LocalFilesEditor`, `NbcThemeChanger`,
  `PiwigoOpenstreetmap`, `PiwigoVideojs`. Each owns its keys via a
  local SCHEMA constant. `user_tags` correctly excluded (no owned keys).
- `tests/Unit/Config/SchemaIntegrityTest` enforces SCHEMA ↔
  generated-accessors sync on every CI run via the new `unit` job in
  `.github/workflows/ci.yml`.
- `docs/config-reference.md` generated from SCHEMA by
  `tools/build-config-reference.php`. README + CONTRIBUTING updated.

### Acknowledged scope changes from the original plan

- The generator does not yet take a `--target` flag — it only handles
  `src/Piwigo/Config/Config.php`. Per-plugin Configs are hand-written
  (small SCHEMAs make this fine; can templatize later if a plugin grows).
- `db_prefix` SCHEMA key + `PIWIGO_DB_PREFIX` env mapping (plan said
  `PIWIGO_DB_TABLE_PREFIX`).
- `ConfigKeyExistsRule` covers the public `raw/has/override/persist/delete`
  surface, not just the (now-private) typed-getter family — that's where
  the actual typo risk lives.
- `.env.local` is reserved for the test runner; runtime `loadEnv` only
  reads `.env`. Standard phpdotenv layered convention deviates here
  because this repo's prior `.env.local` semantics were test-only.
- Charset migration was "delete entirely; inline literals" (`utf-8` /
  `utf8` / `''`) at the 6 use sites. Plan said "hardcode where used OR
  move into SCHEMA — pick per-constant based on whether per-environment
  override is realistic." Inlining was the right call for all six.

### Residual gaps — all closed

All 5 of the originally-flagged gaps have shipped. The two that the
prior pass deferred (bridge removal + plugin migration) landed together
since they were tightly coupled.

**Closed in cleanup pass (commit `2b51d…` ish):**

- ~~**`local/config/config.inc.php` is still loaded.**~~ Removed from all
  5 runtime entry points. `admin/configuration.php`'s `order_by_is_local()`
  helper migrated to text-scan. C13y exif-anomaly warning text reworded.
- ~~**`global $conf;` declarations in i.php / upgrade.php.**~~ Removed.
- ~~**`SchemaIntegrityTest` missing custom-flag assertion.**~~ Added
  `test_custom_flag_matches_accessor_region`.

**Closed in bridge-removal pass:**

- ~~**`Config::attachGlobals()` + `$GLOBALS['conf']` reference bridge.**~~
  Multi-stage migration:
  - Stage 1: `ConfigLoader::applyDefaults()` / `applyEnvOverrides()` lose
    their `$conf` parameter and write directly into `Config::$data`.
    Bootstrap entry points stop creating `$conf = []` and use
    `Config::dbPrefix()` for `$prefixeTable`. `install.php` uses
    `Config::override()` to seed DB credentials. `i.php`'s DB-overlay
    block uses `Config::override()` instead of `$GLOBALS['conf']`.
    `load_conf_from_db()` always writes via `Config::override()`.
  - Stage 2: All 151 plugin `$conf[...]` access sites migrated to the
    per-plugin Config facades (LocalFilesEditor 7, NbcThemeChanger 8,
    PiwigoVideojs 39, PiwigoOpenstreetmap 97). PiwigoVideojs and
    PiwigoOpenstreetmap facades gained lazy-deserialize so the boot-time
    `$conf['xxx_conf'] = safe_unserialize(…)` lines could be removed.
    PiwigoOpenstreetmap facade gained `pin/gpx/batch/communityBm` section
    accessors. `osm_get_js()` / `osm_gen_template()` lost their `$conf`
    parameter and use the typed facades directly; all 12 callers updated.
  - Stage 3: `Config::attachGlobals()` deleted, `Config::$attached` flag
    deleted, `Config::src()` returns `self::$data` unconditionally,
    `Kernel::boot()` no longer calls `Config::attachGlobals()`.
    `KernelBootTest` updated to seed via `Config::loadArray()` and write
    via `Config::override()` instead of `$GLOBALS['conf']`.
- ~~**Plugin internal `$conf['x']` reads.**~~ Migrated as part of the
  bridge-removal sequence above (4 of the 5 bundled plugins; see
  user_tags note in the activation pass below).

**Closed in plugin-activation pass (commit `bc41a5379`).** First end-to-end
exercise of the admin UI's Activate button surfaced three latent bugs not
caught by SchemaIntegrityTest, the Kernel boot tests, or MCP-browser smoke
runs (none of which actually invoke `Plugins::perform_action('activate', …)`):

- ~~**Duplicate `\PluginMaintain` / `\ThemeMaintain` classes with `: void`
  signatures.**~~ `include/functions_plugins.inc.php` defined a second
  root-namespace `\PluginMaintain` (and `\ThemeMaintain`) with typed `: void`
  return signatures. Vendor plugins do `class foo_maintain extends
  PluginMaintain` with no namespace prefix, so they extended the legacy
  duplicate — not the relaxed `\Piwigo\Admin\PluginMaintain` from the
  PSR-4 layout. Result: every OO vendor plugin (piwigo-openstreetmap,
  piwigo-videojs) fataled at file-load with an LSP signature mismatch.
  Fix: deleted both legacy classes; added `class_alias()` so bare
  `PluginMaintain` resolves to `\Piwigo\Admin\PluginMaintain`. Also
  relaxed both src/ classes to drop param/return type declarations
  (kept as `@param`/`@return` phpdoc for PHPStan) since vendor plugins
  use pre-PHP-7 untyped signatures and LSP forbids the typed parent.
- ~~**`DummyPluginMaintain` namespace-resolution bug.**~~ Bare
  `plugin_install($this->plugin_id, …)` inside `namespace Piwigo\Admin`
  resolved to `Piwigo\Admin\plugin_install`, never the global function
  defined in the plugin's `maintain.inc.php`. Result: every procedural
  vendor plugin (LocalFilesEditor, nbc_ThemeChanger) fataled with
  "Call to undefined function Piwigo\Admin\plugin_install()". Fix:
  qualify with leading backslash (`\plugin_install(…)`).
- ~~**Procedural plugins missing some hooks.**~~ Old plugins commonly
  define only a subset of `plugin_install` / `plugin_activate` /
  `plugin_deactivate` / `plugin_uninstall` (LocalFilesEditor: only
  `plugin_uninstall`; user_tags: no `maintain.inc.php` at all). The
  Dummy*Maintain blindly called all four → undefined-function fatal
  for the absent ones. Fix: each Dummy*Maintain method now guards with
  `function_exists()` and no-ops if the global isn't defined.

**Caveat — plugin source is gitignored on this fork.** `/plugins/*` is
in `.gitignore` (only `plugins/index.php` is tracked), so the plugin
migration lives on disk only. If a bundled plugin is reinstalled from
upstream, its raw `$conf['xxx_conf'][...]` reads will silently return
empty (the bridge is gone). Either re-apply the migration after
reinstalls or treat the plugin sources as a vendored fork.

**Caveat — user_tags was excluded from the Stage-2 migration.** Step 9
listed `user_tags` as "no Config class (no owned keys; only reads Piwigo
core keys via the new typed accessors)" — but in practice its on-disk
source still had one raw `$GLOBALS['conf']['data_location']` read in
`plugins/user_tags/src/userTags/Config.php::get_config_file_dir()`,
producing two warnings per plugin-list page load after the bridge was
removed. Fixed on disk (rewritten to `\Piwigo\Config\Config::dataLocation()`)
during the activation pass. Lesson: any 5th-plugin-class assertion ("only
reads core keys, no migration needed") needs to be backed by a grep, not
a code-reading judgment.

Deferred design surface — should be tracked as separate small items if we
want them, not held against #5:

6. No single `ConfigLoader::load()` orchestrator. Three separate methods
   (`applyDefaults`, `loadEnv`, `applyEnvOverrides`) wired manually at
   each of the 4 entry points.
7. No `MissingRequiredConfigException`, no `'required' => true` SCHEMA
   field (Plan Step 4 step 5).
8. No `'description'` or `'sensitive'` SCHEMA fields. `docs/config-
   reference.md` therefore has no descriptions, and there's no
   sensitive-masking for logging.
9. `Config::dumpForLog(): array` (sensitive-masked) listed in Plan
   Step 2 — doesn't exist.
10. DB-config-table overlay is in `load_conf_from_db()` (called from
    `common.inc.php`), not in `ConfigLoader`. Plan Step 4 step 2
    expected the loader to own all 5 layering steps.
11. `ConfigStorage` doesn't take a namespace prefix for plugins (Plan
    Step 5).
12. `Config::raw()` semantic differs from plan. Plan said `raw(): array`
    for bulk read. Shipped: `raw(string $key, mixed $default = null): mixed`
    as the parametric escape hatch.

### Verification of what shipped

- 250/250 PHPUnit tests pass (Unit + Integration combined, 1412 assertions)
- PHPStan level 9 clean
- Pint clean
- Manual MCP-browser e2e: gallery, login, admin (configuration / cat_list /
  user_list / batch_manager_global), profile.php — all 0 console errors

### Goal

`Piwigo\Config\Config` becomes the single source of truth for every Piwigo-core config key. SCHEMA constant inside the class enumerates every key (type, default, optional `env` binding, optional `sensitive` flag, description). The ~140 simple typed accessors are generated from SCHEMA into the same file (between sentinels); the ~10 complex accessors stay hand-written and are flagged `'custom' => true` so the generator skips them. The typed-getter family (`getString/getInt/getBool/getFloat/getArray`) is **private** — only the generated and custom accessors call it. **No `Config::get()` and no `Config::register()`**: every config read in the codebase happens through a typed accessor, and unknown keys at the private-getter layer throw `UnknownConfigKeyException`. `vlucas/phpdotenv` supplies `.env` overrides for the SCHEMA entries with an `env` binding (DB credentials, SMTP creds, secret_key, proxy creds, db table prefix). Install completion signal moves from `define('PHPWG_INSTALLED', true)` (inside `local/config/database.inc.php`) to an empty stamp file at `local/.installed`, decoupling install state from credential storage. `include/config_default.inc.php`, the `$conf` global, and the user PHP override file `local/config/config.inc.php` all stop being load-bearing and are removed from the boot path.

This is a greenfield refactor — no compatibility shims for the legacy `$conf` global or the old `local/config/*.inc.php` files. Each bundled plugin under `plugins/` that holds config keys gets its **own** typed Config class (e.g., `MyPlugin\Config`) following the same SCHEMA-constant + generated-accessors layout, backed by the shared `Piwigo\Config\ConfigStorage` helper with a plugin-namespaced key prefix. The same `tools/build-config-accessors.php` generator handles both Piwigo's Config and any plugin's Config via a `--target` flag. Plugin config never leaks into Piwigo's SCHEMA — clean separation, fully statically analyzable, no runtime extension API needed.

Themes don't touch `$conf` and need no Config class. Extension code that lives in sibling repos (out-of-tree plugins/themes) is **out of scope** for this task — the per-plugin Config pattern is documented for those authors to adopt on their own schedule, but only the 5 bundled plugins under `plugins/` are migrated here: `LocalFilesEditor`, `nbc_ThemeChanger`, `piwigo-openstreetmap`, `piwigo-videojs`, `user_tags`. Of those, `user_tags` owns no config keys and gets no Config class (it only reads Piwigo core keys via the new typed accessors).

### Current state

- `src/Piwigo/Core/Config.php` is 1579 lines: ~150 hand-written typed accessors plus `attachGlobals()` reference-bridge to `$conf`.
- `include/config_default.inc.php` is 1088 lines (209 `$conf['x']` default assignments + comments).
- `local/config/config.inc.php` is included after defaults as a user override stub (usually empty).
- `local/config/database.inc.php` carries DB creds + `define('PHPWG_INSTALLED', true)` + `$prefixeTable` + `PWG_CHARSET`/`DB_CHARSET`/`DB_COLLATE` defines.
- Zero `.env` / phpdotenv support today.
- `Config::get($key, $default)` silently returns the default for unknown keys — typos go undetected.
- 12 raw `Config::get()` callers across 5 first-party files (the typed-accessor escape hatches). 2 raw `$conf['x']` accesses outside `config_default.inc.php`. Bundled plugins/themes don't access `$conf` directly. Several `global $conf;` declarations remain in `admin/` and `include/` (also covered by #6, which this task partially overlaps).

### Steps

1. **Add the dependency.** `composer require vlucas/phpdotenv`.

2. **Move and rewrite Config.** Relocate `src/Piwigo/Core/Config.php` → `src/Piwigo/Config/Config.php` (new namespace `Piwigo\Config\Config`). Reshape the file:
   - `public const SCHEMA` at the top (~150 entries, one row per key with `type`, `default`, optional `env`, optional `sensitive`, optional `description`, optional `custom`, optional `required`).
   - **Public surface**: only typed accessors. The generated typed accessors and the ~10 custom hand-written ones (e.g., `recentPostDates`, `pictureExtensions`, `userFields`) plus state methods `has(string $key): bool`, `override(string $key, mixed $value): void`, `persist(string $key, mixed $value): void`, `dumpForLog(): array` (sensitive-masked), `raw(): array` (bulk read for internal/test use).
   - **Private surface**: `getString/getInt/getBool/getFloat/getArray` typed-getter family — used only by the generated and custom accessors. Each throws `UnknownConfigKeyException` if the key isn't in SCHEMA.
   - **No `Config::get()`**, no `Config::register()`. All config reads in the codebase go through a typed accessor.
   - Generated typed accessors live between sentinel comments (`// === GENERATED ACCESSORS START ===` … `// === GENERATED ACCESSORS END ===`).
   - Delete `attachGlobals()` and the `$conf` reference bridge.
   - Update every `use Piwigo\Core\Config;` callsite in the repo (mechanical sed pass).

3. **Build the generator.** `tools/build-config-accessors.php` accepts a `--target=<path>` flag (defaults to `src/Piwigo/Config/Config.php`). Reads the target class's `SCHEMA` constant via reflection, regenerates only the section between the sentinels. Skips entries with `'custom' => true`. Generates one method per simple SCHEMA entry: `public static function camelCaseKey(): T { return self::getT('key_string'); }`. The same tool is later used by plugins to regenerate their own Config classes.

4. **Wire the boot path.** `src/Piwigo/Config/ConfigLoader.php` runs once during `Kernel::boot()`:
   1. Seed `Config::$data` with SCHEMA defaults.
   2. If `InstallSentinel::isInstalled()`, query the DB `config` table and overlay.
   3. `Dotenv::createImmutable($repoRoot)->safeLoad()`.
   4. For each SCHEMA entry with `'env' => 'NAME'`, if `$_ENV['NAME']` is set, coerce to the entry's `type` and overwrite `Config::$data[$key]`.
   5. Validate `'required' => true` entries; throw `MissingRequiredConfigException` if any unset.

5. **Shared storage helper for plugins.** `src/Piwigo/Config/ConfigStorage.php` is the SCHEMA-driven storage backend any Config class can use. Loads typed values from the shared DB `config` table by key (with optional namespace prefix), applies env bindings, masks sensitive values for logging. Piwigo's own Config delegates its load/persist plumbing here; bundled plugins use it to back their own Config classes. Plugins instantiate it (or call its statics) with their own SCHEMA + namespace prefix.

6. **Install sentinel.** `src/Piwigo/Core/InstallSentinel.php` exposes `isInstalled(): bool` (file existence check on `local/.installed`), `markInstalled(): void` (touch), `markUninstalled(): void` (unlink, for tests). `install.php` calls `markInstalled()` at completion. `install.php`, `upgrade.php`, `i.php`, `index.php` all switch from `defined('PHPWG_INSTALLED')` checks to `InstallSentinel::isInstalled()`.

7. **Drop the legacy file load.** Delete `include/config_default.inc.php` (1088 lines → SCHEMA defaults). Stop including `local/config/config.inc.php` from `include/common.inc.php`. Stop reading `local/config/database.inc.php`'s creds (only the install sentinel mattered there, now relocated). Hardcode UTF-8 / mysqli where the `PWG_CHARSET` / `DB_CHARSET` / `DB_COLLATE` / `dblayer` constants used to inject values, OR move them into SCHEMA — pick per-constant based on whether per-environment override is realistic. `prefixeTable` global becomes `Config::dbTablePrefix(): string` env-bound to `PIWIGO_DB_TABLE_PREFIX` (default `'piwigo_'`).

8. **Migrate remaining `$conf` consumers.** The 2 raw `$conf['x']` accesses outside `config_default.inc.php` (`admin/user_list.php`, `include/functions.inc.php`) move to `Config::xxx()`. The 12 raw `Config::get()` callers across 5 files migrate to the appropriate typed accessors (or get added as new SCHEMA entries with generated accessors if no clustered accessor fits). Drop every remaining `global $conf;` declaration in `admin/` and `include/` — they bind to nothing now. (Overlaps with #6's scope; do it here so the `$conf` removal lands in one shot.)

9. **Migrate the 4 bundled plugins that own config keys.** Per-plugin typed Config class for each, generated via the Step-3 tool with `--target=plugins/<Plugin>/src/Config.php`. Per-plugin SCHEMA breakdown:
   - `plugins/LocalFilesEditor/src/Config.php` — 1 key (`LocalFilesEditor_tabs`, array).
   - `plugins/nbc_ThemeChanger/src/Config.php` — 1 key (`nbc_ThemeChanger`, string with semicolon-separated values).
   - `plugins/piwigo-openstreetmap/src/Config.php` — 1 key (`osm_conf`, nested array).
   - `plugins/piwigo-videojs/src/Config.php` — 5 keys (`vjs_conf`, `vjs_customcss`, `vjs_exiftool_dir`, `vjs_mediainfo_dir`, `vjs_sync`).
   - `plugins/user_tags/` — no Config class (no owned keys; only reads Piwigo core keys).

   Plugin code that reads Piwigo core keys (e.g. `$conf['gallery_title']`, `$conf['file_ext']`) migrates to `Piwigo\Config\Config::galleryTitle()` etc. — same typed-accessor surface, just imported.

   Themes are not migrated (none of the bundled themes touch `$conf`). Out-of-tree plugins and themes living in sibling repos are not in scope for this task.

10. **PHPStan rule.** `tools/phpstan/ConfigKeyExistsRule.php` reads `Config::SCHEMA` and flags any literal-string call to the **private** typed-getter family (`Config::getString/getInt/getBool/getFloat/getArray`) whose key isn't in SCHEMA. The rule's reach extends to any class with a `SCHEMA` constant: when called as `MyPlugin\Config::getString('foo')`, the rule looks up `MyPlugin\Config::SCHEMA`. (External callers can't reach the private getters anyway — the rule is a defense for code inside Config classes.)

11. **CI guard test.** `tests/Unit/Config/SchemaIntegrityTest.php` runs the generator into a tmp file for every Config class in scope (Piwigo's `src/Piwigo/Config/Config.php` + the 4 bundled-plugin Config classes from Step 9) and asserts the committed file matches. Also asserts every SCHEMA entry has either a generated accessor or `'custom' => true`. Failure message points at `tools/build-config-accessors.php`. The list of Config files to check is hard-coded in the test (no globbing) so adding a new bundled plugin is an explicit decision.

12. **`.env.example`** at repo root with the env-bound keys (db cluster, secret_key, smtp cluster, proxy cluster, db_table_prefix), placeholder values, and one-line comments. `.gitignore` adds `.env` and `local/.installed`.

13. **`docs/config-reference.md`** generated from SCHEMA `description` fields across Piwigo's Config and every bundled plugin's Config. Same generator (or a sibling) writes it; committed to repo. Links from README.

14. **README "Configuration" section** covering: load order (defaults → DB → .env), the install sentinel, where secrets belong (`.env`), where admin-tunable values belong (DB).

15. **CONTRIBUTING.md "Plugin Config" section** with a worked example: how a plugin author defines its `Config` class, runs the generator, accesses values via typed accessors, and uses `ConfigStorage` for persistence. References the 4 migrated bundled plugins as canonical examples. Out-of-tree plugin authors (sibling repos) follow the same pattern at their own pace; this doc is what they reference.

16. **Test infrastructure.** `tests/Integration/IntegrationTestCase.php` switches from writing `database.inc.php` to: writing `local/.installed` plus setting the DB-related env vars (`PIWIGO_DB_HOST`, etc.) for the test process. `phpunit.xml.dist` may set defaults via `<env>` entries.

### Out-of-scope clarifications

- Three-mode strict (`strict`/`warn`/`silent`): rejected. Single mode (always throws). The plugin-Config pattern + `Config::register()`-removed design eliminates the use case for soft modes.
- `Config::get()` (untyped escape hatch): rejected. Every read is typed via an accessor or the private typed-getter family.
- `Config::register()` runtime extension API: rejected. Plugins ship their own typed Config class instead — symmetric with how Piwigo's Config is structured. Considered three workarounds and rejected each:
  - Magic `__callStatic`: breaks PHPStan/IDE/autocomplete entirely.
  - Eval / write-PHP-on-activation: filesystem writes from activation hooks are fragile and break read-only deployments.
  - Build-time aggregation that scans plugins/*/schema.php and bakes plugin keys into Piwigo's Config.php: tightly couples core to the installed plugin set, breaks the "ship Piwigo as an immutable artifact" deployment story.
- AST-harvest schema generation: rejected. Hand-written SCHEMA constant is more honest about what metadata we want to carry (`sensitive`, `env`, `description` aren't expressible from accessor signatures alone). The CI guard covers the drift risk.
- Splitting into 5a/5b: rejected. One coherent shipment — strict mode, PHPStan rule, and the per-plugin Config pattern all depend on SCHEMA being the canonical surface, no benefit from staging.

### Verification

```bash
vendor/bin/phpstan analyse --no-progress              # ConfigKeyExistsRule clean
vendor/bin/phpunit                                    # SchemaIntegrityTest + ConfigLoaderTest green

# Generator produces no diff against any committed Config.php (core + plugins):
php tools/build-config-accessors.php --check

test ! -f include/config_default.inc.php             # legacy defaults file deleted
test -f .env.example                                  # env template committed
test -f docs/config-reference.md                      # generated docs committed

# .env precedence check:
PIWIGO_DB_HOST=remote.example.com php -r "
  require 'vendor/autoload.php';
  Piwigo\Config\ConfigLoader::load();
  var_dump(Piwigo\Config\Config::dbHost());
"
# string(19) "remote.example.com"

# Unknown-key strict throw (via a typed accessor that doesn't exist):
php -r "
  require 'vendor/autoload.php';
  Piwigo\Config\ConfigLoader::load();
  Piwigo\Config\Config::definitelyNotARealAccessor();
"
# Fatal: Error  Call to undefined method Piwigo\Config\Config::definitelyNotARealAccessor()

# Bundled plugin Config sanity (one example — repeat for each migrated plugin):
php -r "
  require 'vendor/autoload.php';
  Piwigo\Config\ConfigLoader::load();
  var_dump(LocalFilesEditor\Config::tabs());
"
```

---

## #6 — Eliminate procedural `global` declarations across the codebase

**Status:** ✅ Function-internal globals fully removed (Tier 1 + Tier 2). File-top globals distributed to later tasks — see `docs/modernization/remaining-globals.md`. &nbsp;|&nbsp; **Size:** L

### Goal

Zero function-internal `global $x;` declarations anywhere in `src/`, `include/`, `admin/`. File-top globals in entry-scripts are a separate concern owned by the MVC controller migration (#22) and the pre-boot-includes-to-services work (#17).

### What was done

**Tier 1 — 5 typed-class refactors (33 function-internal sites)**

| New class | Sites removed | Variables replaced |
|---|---|---|
| `Piwigo\Plugins\EventDispatcher` | 4 | `$pwg_event_handlers` |
| `Piwigo\Plugins\LoadedPluginRegistry` | 4 | `$pwg_loaded_plugins` |
| `Piwigo\Notification\MailNotificationContext` | 13 | `$env_nbm` |
| `Piwigo\Cache\RequestCache` | 5 | `$cache` |
| `Piwigo\Core\PageState::addKeyedError` | 5 | `$page['errors']['key']` in `password.php` |
| `Piwigo\Image\ImageDerivativeContext` | 3 | `$page` in `i.php` derivative pipeline |

`add_event_handler()`, `trigger_change/notify()`, `set/get_plugin_data()`, `load_plugin/plugins()` kept as thin wrappers — third-party plugins work unchanged. `$GLOBALS['pwg_event_handlers']` and `$GLOBALS['pwg_loaded_plugins']` reference-bridged for plugins that read the globals directly.

**Tier 2 — LanguageStack + redirect_html (16 function-internal sites)**

| New class / change | Sites removed | Variables replaced |
|---|---|---|
| `Piwigo\Core\LanguageStack` | 13 | `$lang`, `$lang_info`, `$language_files`, `$switch_lang` |
| `Lang::day(int)` / `Lang::month(int)` added | 1 | `$lang['day'][$dow]` in `format_date_legacy` |
| `redirect_html` (Tier 2.2) | 5 | `$lang_info`, `$template`, `$t2`, `$debug`, `$user`, `$lang`, `$page` |
| `$conf_mail` (caught in audit) | 1 | Inlined to `Config::` calls + `RequestCache` template cache |

`LanguageStack` reads/writes `$GLOBALS['lang']` and `$GLOBALS['lang_info']` in-place (not by rebinding) to preserve the `Lang::attachGlobals()` reference bridge. Push-down stack state (`$stack`, `$saved`) is held in private static properties — no `$switch_lang` global.

**Supporting changes**

- `src/Piwigo/Core/Kernel::reset()` now includes `LanguageStack::reset()`
- `NoGlobalInSrcRule` REPLACEMENTS updated for all migrated variables
- `unformat_email()` got a proper `@return array{email: string, name: string}` — a pre-existing type gap exposed when `$conf_mail` (which was `mixed`) stopped suppressing downstream inference

**What remains (not in scope here)**

File-top `global` declarations (~78 lines across 89 files) are not removed here — they are PHPStan typing bridges required until the consuming entry-scripts and pre-boot includes are migrated to typed controllers/services. Full inventory and plan: `docs/modernization/remaining-globals.md`.

- File-top globals in root entry-scripts (15 files) → **#22** (MVC controllers)
- File-top globals in admin entry-scripts (57 files) → **#22**
- Pre-boot includes (`page_header.php`, `page_tail.php`, `section_init.inc.php`, etc.) → **#17**
- `tools/` dev scripts (4 sites) → permanently out of scope

### Steps

1. **Add a `Template` accessor.** ✅ Done.
2. **Migrate `include/` function-internal globals.** ✅ Done (Tier 1 + Tier 2).
3. **Migrate `admin/` function-internal globals.** ✅ Done (Tier 1 `$env_nbm`, Tier 2 `$lang_info`/`$lang` in NBM functions).
4. **Extend the PHPStan rule.** Deferred to when MVC migration eliminates the last file-top globals.
5. **Drop the bootstrap stubs.** Deferred — same blocker.

### Verification

```bash
# Zero function-internal globals for all formerly-guarded variables:
grep -rn "^\s*global \$" include/ admin/ src/ | grep -v "^include/common.inc.php:153"  # only wiring line

# PHPStan level 9 clean:
vendor/bin/phpstan analyse --memory-limit=2G

# E2E green:
npm run test:e2e
```

---

## #7 — Overdue TODO cleanup

**Status:** ✅ Done (34 → 0 actionable markers) &nbsp;|&nbsp; **Size:** S

### Goal

Resolve or formally defer all `TODO`/`FIXME` markers in tracked PHP files.

### What was done

All 18 remaining markers resolved in one pass:

| Disposition | Count | Examples |
|---|---|---|
| **Deleted** — already implemented or dead | 5 | Past-due 2022 `rank`-keyword DB shim; cookie-validation TODO whose check was already on the next line; external-script URL note; `//TODO maybe later` on image_access_type |
| **Explained** — replaced with real comment | 4 | Imagick pre-scale condition; `convert_charset` fallback; stale 2.7 upgrade reference rewritten; `ORDER BY NULL` intent |
| **DEFERRED** — real concern, no current owner | 6 | `Updates.php` plugin-era redirect target; search cat-id access gap; admin URL in gallery filter; category position persistence; stub `cache_size`; image_id/filename precedence |
| **PERF** — performance note, not a bug | 2 | Redundant `get_available_tags()` call; all-rows-before-PHP-count in WS history |

### Verification

```bash
grep -rn "TODO\|FIXME" src/ include/ --include="*.php" | grep -v "vendor\|install/db"
# returns empty — zero actionable markers remain
# DEFERRED and PERF comments are intentional and acceptable
```

---

## #8 — Remove class duplication in `ws_core.inc.php`

**Status:** ✅ Done &nbsp;|&nbsp; **Size:** M

### What was done

`include/ws_core.inc.php` shrunk from 676 lines to 27 lines (constants only). The six class bodies (`PwgError`, `PwgNamedArray`, `PwgNamedStruct`, `PwgRequestHandler`, `PwgResponseEncoder`, `PwgServer`) were deleted; `src/Piwigo/Ws/` is now the single authoritative source, loaded by Composer PSR-4 autoload.

| Change | Detail |
|---|---|
| `include/ws_core.inc.php` | Class bodies stripped; only `define()` constants remain |
| `src/Piwigo/Ws/PwgServer.php` | `setHandler()` made nullable (consistent with `setEncoder()`); properties private |
| `src/Piwigo/Ws/Encoder/PwgResponseEncoder.php` | Dual `instanceof` guards collapsed to single namespaced check |
| `src/Piwigo/Ws/Protocol/PwgRestEncoder.php` | Direct `->_content`/`->_itemName`/`->_xmlAttributes` replaced with `getContent()`/`getItemName()`/`getXmlAttributes()` |
| `include/ws_protocols/rest_encoder.php` | Same getter migration; added `use Piwigo\Ws\PwgNamedArray` + `PwgNamedStruct` |
| `ws.php` | Added `use Piwigo\Ws\PwgServer` |

Enum conversion of `WS_TYPE_*` / `WS_PARAM_*` constants deferred to task #19.

---

## #9 — `@` error-suppression cleanup

**Status:** Done (2026-05-03) &nbsp;|&nbsp; **Size:** M

### Goal

Eliminate every `@` error-suppression site, replacing each with explicit
handling. Each `@` is a hidden contract: it implies the call can fail and
the failure is intentionally ignored. The result is code that is honest
about its failure modes and PHPStan-friendly.

### What was done

A fresh inventory at the start counted **263 actionable `@` sites across
51 files** (the prior audit doc was stale at "136 / 32"). All 263 were
removed in eight tiered commits. Remaining count: **0**.

Replacement patterns by tier:

1. **Array / object key access (~110 sites).** `@$arr['k']` → `$arr['k']
   ?? null`. Accumulators like `@$arr[$k]++` and `@$arr[$k] += $v` rewritten
   as `$arr[$k] = ($arr[$k] ?? 0) + …`. Nested-array assignments dropped
   the `@` entirely (PHP auto-vivifies on assignment).
2. **Local file ops (~60 sites).** Introduced
   `src/Piwigo/Core/Filesystem.php` — small static helpers
   (`tryUnlink`, `tryRmdir`, `tryFileMtime`, `tryFilesize`, `tryRename`,
   `tryChmod`, `tryFopen`) that combine an `is_file`/`is_dir` preflight
   with a tightly scoped `set_error_handler` / `restore_error_handler`
   pair. Inlined the same pattern in low-level helpers (`mkgetdir`,
   InstallSentinel, the upload-prep mkdir).
3. **Network / HTTP (~20 sites).** Rewrote `fetchRemote()` to wrap
   curl, fsockopen, and stream-context `file_get_contents` in scoped
   `set_error_handler` blocks instead of `@`-prefixing each call. Caller
   sites dropped `@`. `@pwg_mail(...)` → `pwg_mail(...)` (function
   already returns `bool`).
4. **Optional includes (~3 sites).** `@include($path)` →
   `if (is_readable($path)) { include $path; }`.
5. **`unserialize` (~15 sites).** All `@unserialize(...)` calls now go
   through `safe_unserialize()` (`include/functions.inc.php`), which
   returns an array (or `[]` on parse failure).
6. **Deprecated / conditional functions (~12 sites).** `@get_magic_quotes_gpc()`
   deleted (the function no longer exists in PHP 8). `@set_time_limit`,
   `@putenv`, `@ini_set` gated with `function_exists`. `@exec(...)`
   simplified to `exec(...)` (function-disabled hosts surface a real
   warning instead of silently broken pipelines).
7. **Image / metadata + headers (~10 sites).** Added
   `pwg_safe_getimagesize()` and `pwg_safe_exif_read_data()` helpers
   alongside `safe_unserialize`. `@header(...)` /
   `@set_status_header(...)` gated with `if (!headers_sent())`.
8. **Residue + lint rule.** The single legitimate edge case
   (install-time `new mysqli()` connection probe in
   `admin/include/functions_install.inc.php`) wraps the call in a tight
   `set_error_handler`/`restore_error_handler` block instead of `@`.
   Added `tools/phpstan/NoErrorSuppressionRule.php` (wired into
   `phpstan.neon`) — fails on any future `@` use, with no allowlist.

Helpers introduced or extended:

- `src/Piwigo/Core/Filesystem.php` (new) — `tryUnlink`, `tryRmdir`,
  `tryFileMtime`, `tryFilesize`, `tryRename`, `tryChmod`, `tryFopen`.
- `pwg_safe_getimagesize()`, `pwg_safe_exif_read_data()` (new) in
  `include/functions.inc.php`.
- `tools/phpstan/NoErrorSuppressionRule.php` (new) — implements
  `PHPStan\Rules\Rule<ErrorSuppress>`.

### Verification

```bash
# Count actionable @ suppressions (excludes PHPDoc/comments, language/, vendor/)
perl -ne 'next if m{^\s*[*/]}; while(m{(?:[\s=(\[,;!&|?:]|^|return\s)\@(\$|[a-zA-Z_]\w*\s*\()}gx){$c++} END{print "TOTAL: $c\n"}' \
  $(find src include admin tools tests -name '*.php') \
  action.php i.php index.php install.php profile.php upgrade.php upgrade_feed.php ws.php feed.php password.php notification.php picture.php comments.php
# Result: 0

vendor/bin/phpstan analyse           # green at level 9 (NoErrorSuppressionRule active)
vendor/bin/phpunit                   # 256 tests / 1693 assertions, all green
```

---

## #10 — Exception hierarchy + eliminate `die()`

**Status:** Done &nbsp;|&nbsp; **Size:** M

### Goal

Define a typed exception hierarchy under `Piwigo\Exception\`. Replace the 27 `die()` calls in `include/` with thrown exceptions. Replace generic `\Exception` throws with appropriate domain exceptions. Wire a top-level catcher in the front controller (item #22) so unhandled exceptions render proper error pages.

### Current state

- **103 `die()` calls** total: 92 in `admin/` (61 files — mostly `if (!is_admin()) die();` permission guards at the top of each entrypoint), 11 in `include/` (`dblayer/`, `picture_comment.inc.php`, install/upgrade flows). The original "27 in include/" estimate undercounted because it excluded the admin permission guards, which behave the same way and need the same exception treatment.
- **7 generic `throw new \Exception(...)`** sites: `include/dblayer/functions_mysqli.inc.php` (2), `admin/include/functions_install.inc.php` (1), `src/Piwigo/Admin/Image/pwg_image.php` (4).
- `src/` already uses typed SPL exceptions (`\RuntimeException`, `\LogicException`, `\BadMethodCallException`).
- `src/Piwigo/Ws/PwgError` exists but is WS-protocol-specific, not a general base.

### Steps

1. **Define the hierarchy.** Under `src/Piwigo/Exception/`:

   ```php
   abstract class PiwigoException extends \RuntimeException {}
   class DbException         extends PiwigoException {}
   class AuthException       extends PiwigoException {}
   class NotFoundException   extends PiwigoException {}
   class ValidationException extends PiwigoException {}
   class ConfigException     extends PiwigoException {}
   class HttpException       extends PiwigoException { public function __construct(public readonly int $statusCode, string $message = '') { … } }
   ```

2. **Replace `die()` in `include/dblayer/`.** Connection failure, query failure, missing config — each becomes a `DbException` with the underlying error message and SQLSTATE.

3. **Replace `die()` in install/upgrade.** Validation failures throw `ValidationException`; missing prerequisites throw `ConfigException`. The install entrypoint catches and renders the existing error UI.

4. **Replace generic `\Exception` throws.** Sweep `include/` for `throw new \Exception(` and pick the right typed subclass.

5. **Top-level handler stub.** Add `Piwigo\Bootstrap\ExceptionHandler::handle(\Throwable $e): void` that logs (via PSR-3 — item #11) and renders an error response. Wire into `set_exception_handler()` in `common.inc.php` for now; the front controller (item #22) takes over later.

### Verification

```bash
grep -rn 'die(' include/ admin/ --include='*.php' | wc -l       # 0 (excluding tests/ and dev/)
grep -rnE 'throw new \\?Exception\(' src/ include/ admin/ \
  --include='*.php' | wc -l                                       # 0
vendor/bin/phpstan analyse                                        # green
```

---

## #11 — PSR-3 Logger

**Status:** Done &nbsp;|&nbsp; **Size:** S

### Goal

The Piwigo logger implements `Psr\Log\LoggerInterface`. Callsites use the standard leveled API (`debug`, `info`, `warning`, `error`, `critical`). Third-party plugins/handlers can be plugged in via the standard interface.

### Current state

- Logger class lives at `src/Piwigo/Core/Logger.php` (the original plan said `src/Piwigo/Log/Logger.php`, but the move went to `Core/`). `include/Logger.class.php` is one of the empty stubs from #30.
- Custom API: `$logger->debug($msg, $file, $array)`, `$logger->error(...)`, etc. — leveled methods exist but accept positional `(message, file, array)` args, not the PSR-3 `(message, context)` signature.
- No `psr/log` dependency in `composer.json`.
- Callers under `src/Piwigo/Admin/{languages,plugins,themes}.php` and `src/Piwigo/Admin/Image/image_ext_imagick.php` reach for the `global $logger` (5 in-scope sites — kept until typed accessor lands; see #6 "Out of scope").

### Steps

1. **Add `psr/log`** to `composer.json` (`require: { "psr/log": "^3.0" }`). `composer update`.

2. **Implement `LoggerInterface`.** `Piwigo\Log\Logger` extends `Psr\Log\AbstractLogger` (provides leveled methods routed to a single `log()`). Implement `log(string $level, string|\Stringable $message, array $context = []): void`.

3. **Adapt the storage layer.** Keep the existing log-to-file or log-to-DB backend; just normalize the level string against `Psr\Log\LogLevel` constants.

4. **Migrate callsites.** Replace `$logger->add('foo', 'error')` with `$logger->error('foo')`. Sweep `include/` and `admin/`. For sites that pass dynamic levels, use `$logger->log($level, $msg)`.

5. **Optionally swap in Monolog.** Once the interface is the contract, the implementation can be replaced with `monolog/monolog` in `src/Piwigo/Core/Logger.php` factory without touching callsites. Defer the actual swap unless there's demand.

### Verification

```php
// In tests/Unit/Log/LoggerTest.php:
$this->assertInstanceOf(\Psr\Log\LoggerInterface::class, $logger);
```

```bash
grep -rn '->add(' include/ admin/ --include='*.php' | grep -i logger   # empty
vendor/bin/phpunit --testsuite Unit
```

---

## #12 — PSR-11 DI container

**Status:** ✅ Done &nbsp;|&nbsp; **Size:** M

### Goal

Replace the static `ServiceLocator` registry with a real PSR-11 dependency-injection container (PHP-DI). Services are configured once in a bootstrap file and resolved with autowiring. New code uses constructor injection; legacy callers go through a thin `ServiceLocator::get()` shim until they're migrated.

### Current state

- `src/Piwigo/Core/ServiceLocator.php` — static `register()` / `get()` over an associative array. Implements PSR-11 shape but no autowiring, no factories, no lifecycle.
- Comment in the file: "NOT a full DI container — static registry."
- Pre-registered: `Config`, `PageState`, possibly `Lang`, `CurrentUser`.

### Steps

1. **Choose container.** PHP-DI (`php-di/php-di ^7`) — autowiring out of the box, definition file syntax, attribute support. Alternative: `symfony/dependency-injection`.

2. **Add to `composer.json`.** `composer require php-di/php-di`.

3. **Create `src/Piwigo/Bootstrap/Container.php`.** Factory that builds the container from `config/container.php` (definitions). Returns a `Psr\Container\ContainerInterface`.

4. **Define services.** In `config/container.php`:

   ```php
   return [
       Piwigo\Core\Config::class      => DI\autowire(),
       Piwigo\Core\PageState::class   => DI\autowire(),
       Piwigo\Log\Logger::class       => DI\factory(/* file/db backend factory */),
       Psr\Log\LoggerInterface::class => DI\get(Piwigo\Log\Logger::class),
       // …
   ];
   ```

5. **Bootstrap.** `Kernel::boot()` builds the container and stashes it on a single static accessor: `Kernel::container()`. `ServiceLocator::get($id)` is rewritten to `Kernel::container()->get($id)`.

6. **Migrate to constructor injection.** New service classes from item #19 receive their dependencies via constructor parameters instead of pulling from the locator. Existing services migrate opportunistically when touched.

### Verification

```php
$config = $container->get(Piwigo\Core\Config::class);
$this->assertInstanceOf(Piwigo\Core\Config::class, $config);
```

```bash
vendor/bin/phpunit --testsuite Unit       # green
npx playwright test                       # green (no behavioral regression)
```

---

## #13 — PHP 8.1–8.5 features: readonly, enum, match

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

Adopt PHP 8.1–8.5 language features where they tighten invariants without changing public API. Targets: `readonly` properties on value objects, `enum` for flag/constant sets, `match` in place of exhaustive `switch` value maps.

### Current state

- `src/` has 68 classes/interfaces; `include/` free functions are out of scope (covered by item #19).
- 3 `readonly` declarations exist in `src/` so far; 0 enums, no `match` adoption beyond a few isolated uses.
- `include/ws_core.inc.php` has 10 `define()` bitmask constants — enum candidates (linked to #8).

### Steps

1. **Readonly properties.** Audit `src/` for classes whose constructor assigns properties that are never written again. Candidates: `Piwigo\Ws\PwgError` (`$_code`, `$_codeText`), `Piwigo\Cache\PersistentFileCache` (path properties), all search Q-token classes. Apply `readonly` to each confirmed write-once property.

2. **Backed enums.** Replace `define()` bitmask constants with `enum` backed by `int`:
   - `WsType: int` — `BOOL = 0x01`, `INT = 0x02`, `FLOAT = 0x04`, `POSITIVE = 0x10`, `NEGATIVE = 0x20`, `NOTNULL = 0x40` (linked to #8).
   - `GraphicsLibrary: string` — `'auto'`, `'imagick'`, `'ext_imagick'`, `'gd'` (from `Config` typed getter).
   - `DerivativeSize: string` — `'small'`, `'medium'`, `'large'`, `'thumb'`, `'xsmall'`, `'xxsmall'`.

3. **Match expressions.** Replace exhaustive `switch ($x) { case A: return 1; case B: return 2; default: throw }` blocks with `match`. Focus on `src/Piwigo/Template/ScriptLoader.php` and `src/Piwigo/Ws/` protocol dispatchers.

4. **Rector sweep.** After manual candidates are done, run Rector with `->withPhpSets(php81: true, php82: true, php83: true, php84: true, php85: true)` in dry-run mode — accept only the readonly and match rewrites; reject any that change public API.

### Verification

```bash
vendor/bin/rector process --dry-run   # "0 files would be changed" after manual pass
vendor/bin/phpstan analyse            # still green
vendor/bin/phpunit --testsuite Unit   # still green
```

---

## #14 — Schema migrations as code

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

Replace the 22 hand-written `install/upgrade_*.php` scripts with versioned migration classes. A `phpwg_migration_versions` table tracks applied migrations. Migrations are runnable forward (`migrate`) and backward (`rollback`). Bootstrap auto-applies pending migrations on first request after upgrade — or via CLI.

### Current state

- **23 hand-written `install/upgrade_*.php` scripts** (1.3.0 → 15.0.0). Each is a top-level PHP file calling `pwg_query()` directly.
- The 16.x modernization floor is 16.0.0 (see auto-memory `project_modernization_floor.md`) — every script for a pre-16 version (1.3.0 through 15.0.0, all 23 of them) is unreachable and should be **deleted outright** before this item starts. Doctrine Migrations only needs to track schema changes from 16.0.0 forward, so the conversion list shrinks to whatever 16.x adds.
- No version tracking table. No rollback. No migration runner class.
- Upgrade flow: `upgrade.php` includes each file in version order based on installed version.

### Steps

1. **Add Doctrine Migrations.**

   ```bash
   composer require doctrine/migrations doctrine/dbal
   ```

   (DBAL is needed by both this item and item #17 — install once.)

2. **Configure `migrations.php`** at the repo root:

   ```php
   return [
       'table_storage' => [
           'table_name' => 'phpwg_migration_versions',
           'version_column_name' => 'version',
           'version_column_length' => 191,
           'executed_at_column_name' => 'executed_at',
           'execution_time_column_name' => 'execution_time',
       ],
       'migrations_paths' => [
           'Piwigo\\Migrations' => 'src/Piwigo/Migrations',
       ],
       'all_or_nothing' => true,
       'check_database_platform' => false,
   ];
   ```

3. **Convert legacy upgrades.** Each `install/upgrade_*.php` becomes a `Version<YYYYMMDDHHMMSS>` class:

   ```php
   namespace Piwigo\Migrations;

   final class Version20260101000000 extends AbstractMigration {
       public function up(Schema $schema): void { /* original upgrade SQL via $this->addSql(...) */ }
       public function down(Schema $schema): void { /* reverse where possible; throw IrreversibleMigration where not */ }
   }
   ```

4. **`Piwigo\Migrations\MigrationRunner`.** `run()` method calls `DependencyFactory::getMigrator()->migrate()`. Hooked into `Kernel::boot()` — auto-applies pending migrations on first request after upgrade (only if `Config::get('auto_migrate', true)`).

5. **CLI entrypoint.** `bin/piwigo` is a Symfony Console application registering the standard Doctrine Migrations commands (`migrations:migrate`, `migrations:status`, `migrations:rollback`, `migrations:diff`).

6. **Decommission legacy files.** Once the migration runner has caught up to the current schema, delete the `install/upgrade_*.php` files. Document the cutover release.

### Verification

```bash
bin/piwigo migrations:status                      # all applied; pending = 0
bin/piwigo migrations:migrate --dry-run           # no changes on a current DB

# On a test DB, rollback + re-apply:
bin/piwigo migrations:execute --down Piwigo\\Migrations\\Version20260101000000
bin/piwigo migrations:execute --up   Piwigo\\Migrations\\Version20260101000000

vendor/bin/phpunit --testsuite Integration
```

---

## #15 — PSR-6 / PSR-16 cache + Redis support

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

The Piwigo cache exposes both `Psr\Cache\CacheItemPoolInterface` (PSR-6) and `Psr\SimpleCache\CacheInterface` (PSR-16). Backends are pluggable via config: file (default), APCu (single-server speed), Redis (multi-server clustering). Custom `PersistentFileCache` becomes a thin wrapper around Symfony's adapter.

### Current state

- `src/Piwigo/Cache/PersistentCache.php` (abstract) + `PersistentFileCache.php` (file-based, MD5-keyed). Custom API.
- **163 references** to the `$persistent_cache` global across the codebase.
- No PSR-6 / PSR-16 in `composer.json`. Single backend (file) hardcoded.
- TTL defaults to 86400s. No Redis or APCu hookup.

### Steps

1. **Add dependencies.**

   ```bash
   composer require psr/cache psr/simple-cache symfony/cache
   ```

   `symfony/cache` provides PSR-6 + PSR-16 implementations and adapters for filesystem, APCu, Redis, PDO, chained, and tag-aware caching.

2. **Refactor `PersistentFileCache`.** Make it a thin wrapper around `Symfony\Component\Cache\Adapter\FilesystemAdapter` — same on-disk layout (so existing caches don't need to be flushed), but the Symfony adapter handles serialization, locking, and PSR-6 compliance.

3. **Add `Piwigo\Cache\CacheFactory`.** Factory returns the right adapter based on `Config::get('cache.backend')`:
   - `'file'` → `FilesystemAdapter` (default; zero-config)
   - `'apcu'` → `ApcuAdapter` (when `apcu_enabled()`)
   - `'redis'` → `RedisAdapter::createConnection(Config::get('cache.redis_url'))`
   - `'chain'` → `ChainAdapter([apcu, redis, file])` (read fast, write slow)

4. **Provide the PSR-16 facade.** `Piwigo\Cache\Simple` wraps the PSR-6 pool with `Symfony\Component\Cache\Psr16Cache` — consumers preferring the simpler `get/set/delete` API use it.

5. **Migrate callsites.** Replace `$persistent_cache->get($key)` with `$container->get(CacheItemPoolInterface::class)->getItem($key)->get()`. Provide a deprecation shim on the old `$persistent_cache` global pointing at the new pool — old code keeps working during transition.

6. **Add `cache.*` keys to the config schema** (item #5): `cache.backend`, `cache.redis_url`, `cache.namespace`, `cache.default_ttl`.

### Verification

```php
// Round-trip on each backend:
$pool = CacheFactory::create('file');
$item = $pool->getItem('test'); $item->set('value'); $pool->save($item);
assert($pool->getItem('test')->get() === 'value');
```

```bash
vendor/bin/phpunit --testsuite Unit       # green
# With Redis available:
PIWIGO_CACHE_BACKEND=redis PIWIGO_CACHE_REDIS_URL=redis://localhost:6379 vendor/bin/phpunit
```

---

## #16 — File storage abstraction (Flysystem)

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

All user-facing file I/O goes through `League\Flysystem\Filesystem`. Named filesystems (`uploads`, `derivatives`, `watermarks`, `themes`, `plugins`, `exports`) configured via DI. Storage backend (local default; optional S3, SFTP, etc.) is a config switch — code does not change when storage moves.

### Current state

- **65 raw filesystem calls** (`move_uploaded_file`, `is_uploaded_file`) plus uncounted `unlink`, `mkdir`, `rename`, `is_writable` sites.
- Hardcoded `_data/i/upload/YYYY/MM/DD/` paths in `admin/include/functions_upload.inc.php`.
- No Flysystem dependency.
- Plugin authors writing files do so directly against the local FS — non-portable.

### Steps

1. **Add dependencies.**

   ```bash
   composer require league/flysystem league/flysystem-local
   # Optional adapters added on demand:
   # composer require league/flysystem-aws-s3-v3
   # composer require league/flysystem-sftp-v3
   ```

2. **Define `Piwigo\Storage\StorageRegistry`.** Exposes named filesystems via `StorageRegistry::get(string $name): FilesystemOperator`. Names: `uploads`, `derivatives`, `watermarks`, `themes`, `plugins`, `exports`, `temp`.

3. **Configure in DI.** `config/storage.php`:

   ```php
   return [
       'uploads'      => fn () => new Filesystem(new LocalFilesystemAdapter('_data/i/upload')),
       'derivatives'  => fn () => new Filesystem(new LocalFilesystemAdapter('_data/i')),
       'watermarks'   => fn () => new Filesystem(new LocalFilesystemAdapter('themes/default/watermarks')),
       'themes'       => fn () => new Filesystem(new LocalFilesystemAdapter('themes')),
       'plugins'      => fn () => new Filesystem(new LocalFilesystemAdapter('plugins')),
       'exports'      => fn () => new Filesystem(new LocalFilesystemAdapter('_data/exports')),
       'temp'         => fn () => new Filesystem(new LocalFilesystemAdapter(sys_get_temp_dir() . '/piwigo')),
   ];
   ```

   Each entry is a closure for lazy instantiation. To switch `uploads` to S3, the closure swaps the adapter — no callsite changes.

4. **Migrate callsites.** Replace raw `move_uploaded_file($_FILES['x']['tmp_name'], $dest)` with:

   ```php
   $uploads = $storage->get('uploads');
   $stream = fopen($_FILES['x']['tmp_name'], 'r');
   $uploads->writeStream($relativePath, $stream);
   ```

   Same pattern for `unlink` → `delete()`, `rename` → `move()`, `mkdir` → adapter-managed, `is_writable` → adapter capability check.

5. **Optional cloud adapters.** Document how to switch a single filesystem (e.g., `derivatives`) to S3 by editing `config/storage.php` and adding the S3 composer dependency. Filesystem names stay the same.

6. **Update plugin documentation** (links in to item #26) — third-party plugins should use `StorageRegistry::get('plugins')->write(...)` instead of raw `file_put_contents`.

### Verification

```bash
# Round-trip a file via local backend:
php -r "
  require 'vendor/autoload.php';
  \$fs = (Piwigo\Bootstrap\Kernel::container())->get(Piwigo\Storage\StorageRegistry::class)->get('temp');
  \$fs->write('test.txt', 'hello');
  echo \$fs->read('test.txt');
"
# 'hello'

# Switch to S3 in storage.php; same code path passes.
vendor/bin/phpunit --testsuite Unit
npx playwright test
```

---

## #17 — DB layer modernization (Doctrine DBAL + repositories)

**Status:** Not started &nbsp;|&nbsp; **Size:** XL

### Goal

Replace 583 raw `pwg_query()` calls with Doctrine DBAL's query builder. Repository classes per domain (`UserRepository`, `CategoryRepository`, `ImageRepository`, `TagRepository`, `CommentRepository`, etc.) encapsulate persistence. Query builder + parameter binding eliminate the SQL-injection footguns inherent in string interpolation. Result rows have declared array shapes; long-term, repositories return typed DTOs.

### Current state

- **492 `pwg_query()` sites across `include/`, `admin/`, `src/`** (down from 583). Zero repository classes in `src/` yet.
- `include/dblayer/functions_mysqli.inc.php` (869 lines) is the procedural wrapper layer.
- SQL strings interpolate PHP variables; some pass through `pwg_db_real_escape_string`, some don't — an injection audit is part of this work.
- `$conf['dblayer']` is always `'mysqli'` (16.x floor).

### Steps

1. **Add Doctrine DBAL.** Already required by item #14 if landed first; otherwise `composer require doctrine/dbal`.

2. **Wrap connection.** `Piwigo\Db\Connection` factory builds DBAL `Connection` from existing config (`db_host`, `db_user`, `db_password`, `db_base`). Registered in DI.

3. **Per domain, build a repository.** Order leaf-to-trunk (smaller dependencies first):
   - `Piwigo\Tag\TagRepository` (~40 query sites)
   - `Piwigo\Comment\CommentRepository`
   - `Piwigo\Search\SearchRepository`
   - `Piwigo\Category\CategoryRepository`
   - `Piwigo\Image\ImageRepository`
   - `Piwigo\Users\UserRepository`
   - `Piwigo\Plugin\PluginRepository`
   - `Piwigo\History\HistoryRepository`
   - `Piwigo\Notification\NotificationRepository`

4. **Method-by-method, port the queries.** For each function in the per-module migration (item #19), the repository method that backs it uses DBAL's query builder:

   ```php
   public function findByIds(array $ids): array {
       return $this->conn->createQueryBuilder()
           ->select('id', 'name', 'date_creation')
           ->from('phpwg_categories')
           ->where('id IN (:ids)')
           ->setParameter('ids', $ids, Connection::PARAM_INT_ARRAY)
           ->executeQuery()
           ->fetchAllAssociative();
   }
   ```

5. **Declare row shapes.** Until DTO migration (deferred), use PHPStan array shapes:

   ```php
   /** @return list<array{id:int, name:string, date_creation:string}> */
   public function findByIds(array $ids): array { … }
   ```

6. **SQL-injection audit fallout.** During the sweep, any remaining `"…WHERE foo='$bar'"` becomes a parameter binding. Cross-reference `error-suppression-audit.md` and tag any injection sites with `// SECURITY: was vulnerable to SQLi` so reviewers can verify.

7. **Drop `pwg_query()` / `pwg_db_*` wrappers** when no callers remain. The `include/dblayer/` directory shrinks to a single bootstrap that builds the DBAL connection.

### Verification

```bash
grep -rc 'pwg_query\(' include/ admin/ src/ --include='*.php' | awk -F: '{s+=$2} END {print s}'
# shrinking each PR; final target: 0

vendor/bin/phpstan analyse           # green at level 9, eventually level 10 (item #27)
vendor/bin/phpunit --testsuite Integration   # green
npx playwright test                  # green
```

---

## #18 — i18n format migration

**Status:** Not started &nbsp;|&nbsp; **Size:** L

### Goal

Translation files move from `$lang['key'] = 'value';` PHP arrays to gettext PO/MO. Lazy-loaded via `Piwigo\Lang\Translator` service. Plurals supported via `ngettext()`. Translator-friendly: PO files are the standard input format for Crowdin, Weblate, Pootle, and other collaboration platforms.

### Current state

- **324 `.lang.php` files** across **73 locales** in `language/<locale>/{common,admin,upgrade}.lang.php` (down from 388 — ~16% reduction, likely from dropped `upgrade.lang.php` files for pre-16 versions).
- Format: `$lang['key_name'] = 'translated value';`. No plural handling beyond ad-hoc `if ($n == 1)` in callers.
- Active locale picked via `include()` of the right `.lang.php` files in `common.inc.php`.
- Free function `l10n($key)` looks up from the global `$lang` array.
- No tooling integration with translation platforms; translators submit PHP files.

### Steps

1. **Decide format.** Recommend gettext PO/MO via `gettext/gettext` (a pure-PHP gettext implementation that doesn't require the `ext-gettext` extension). PO carries plural forms, context (`msgctxt`), and translator notes — JSON does none of these without extension.

2. **Build a converter.** `tools/i18n/php-to-po.php` reads a `.lang.php` file, extracts each `$lang['key'] = 'value'` assignment, and emits a `.po` entry. Output: `language/<locale>/<domain>.po`. Compile to `<domain>.mo` for runtime.

3. **Implement `Piwigo\Lang\Translator`** in `src/Piwigo/Lang/Translator.php`:

   ```php
   public function translate(string $key, array $params = [], ?string $locale = null): string;
   public function translatePlural(string $key, string $keyPlural, int $count, array $params = [], ?string $locale = null): string;
   ```

   Backed by `Gettext\Translator` reading the compiled `.mo` files. Registered in DI.

4. **Replace `l10n()` free function** with a one-line wrapper that delegates to the service. Same for `l10n_dec()`.

5. **Lazy-load.** Boot only loads the active locale's `common.mo`. Admin pages load `admin.mo` on demand. The 73 locales never all load at once.

6. **Migrate template syntax** (depends on items #23 Latte and #26 plugin/theme):
   - Smarty: `{translate $key}` and `{$key|translate}` already exist — point both at the new service.
   - Latte: `{$key|translate}` filter already planned in item #23 step 4.

7. **Document the translator workflow** in `CONTRIBUTING.md` and a new `docs/I18N.md`. Cover: how to add a new key, how to push/pull from Crowdin (or chosen platform), how to compile MO files locally for testing.

8. **Decommission `.lang.php`.** After one full release with both formats supported (translator can submit PO; runtime serves PO/MO), delete the legacy files.

### Verification

```bash
# Every legacy key resolves under the new system:
php tools/i18n/verify-parity.php          # exits 0

# Plurals work:
php -r "echo Piwigo\Lang\Translator::translatePlural('%d photo', '%d photos', 5);"
# "5 photos"

vendor/bin/phpunit --filter TranslatorTest
npx playwright test                        # all locales render
```

---

## #19 — Migrate `include/functions_*.inc.php` to typed service classes

**Status:** Not started &nbsp;|&nbsp; **Size:** XL

### Goal

Move all 366 free functions across the 19 `functions_*.inc.php` modules into typed, namespaced classes under `src/Piwigo/<domain>/`. Each migrated function becomes a static or instance method on a domain class. Free-function wrappers stay during the transition (one-line delegates) so call sites keep working without a sweep.

### Current state

- **19 `functions_*.inc.php` modules** in `include/`, ~366 free functions total.
- Three modules already mix one class with their free functions (`ws_core.inc.php` — covered by item #8; `functions_search.inc.php`; `functions_plugins.inc.php`).
- 9 legacy `.class.php` files are migrated to `src/` by item #3 — they're the home for the new domain classes here.
- DB-layer plumbing (`functions_mysqli.inc.php`, `pwg_query`) is migrated to repositories by item #17.

### Per-module checklist

Counts re-measured against the current tree.

| Module                                                 | Lines | Funcs | Target namespace                                         |
| ------------------------------------------------------ | ----- | ----- | -------------------------------------------------------- |
| `functions_user.inc.php`                               | 2,711 | 63    | `Piwigo\Users\`, `Piwigo\Auth\`                          |
| `functions.inc.php`                                    | 2,820 | 81    | spread by domain — split first                           |
| `functions_search.inc.php`                             | 2,101 | 17    | `Piwigo\Search\`                                         |
| `functions_mail.inc.php`                               | 1,054 | 22    | `Piwigo\Mail\`                                           |
| `functions_url.inc.php`                                | 846   | 21    | `Piwigo\Url\`                                            |
| `functions_category.inc.php`                           | 799   | 17    | `Piwigo\Category\`                                       |
| `functions_html.inc.php`                               | 659   | 23    | `Piwigo\Html\`                                           |
| `functions_notification.inc.php`                       | 615   | 18    | `Piwigo\Notification\`                                   |
| `functions_comment.inc.php`                            | 501   | 8     | `Piwigo\Comment\`                                        |
| `functions_plugins.inc.php`                            | 458   | 12    | `Piwigo\Plugin\`                                         |
| `functions_tag.inc.php`                                | 370   | 9     | `Piwigo\Tag\`                                            |
| `functions_session.inc.php`                            | ?     | 12    | `Piwigo\Session\`                                        |
| `functions_picture.inc.php`                            | ?     | 6     | `Piwigo\Picture\`                                        |
| `functions_metadata.inc.php`                           | ?     | 5     | `Piwigo\Metadata\`                                       |
| `functions_cookie.inc.php`                             | ?     | 3     | `Piwigo\Auth\`                                           |
| `functions_rate.inc.php`                               | ?     | 2     | `Piwigo\Rate\`                                           |
| `functions_filter.inc.php`                             | ?     | 1     | `Piwigo\Filter\`                                         |
| `functions_calendar.inc.php`                           | ?     | 1     | `Piwigo\Calendar\`                                       |
| `dblayer/functions_mysqli.inc.php`                     | 869   | 45    | `Piwigo\Db\` (item #17)                                  |
| `ws_functions/*.php`                                   | —     | —     | `Piwigo\Ws\Method\` — 9 files in `include/ws_functions/` |
| `admin/include/functions.php`                          | 3,671 | ?     | spread by admin domain                                   |
| `admin/include/functions_upload.inc.php`               | 1,033 | ?     | `Piwigo\Admin\Upload\`                                   |
| `admin/include/functions_notification_by_mail.inc.php` | 513   | ?     | `Piwigo\Admin\Mail\`                                     |

### Steps (per module)

1. **Inventory.** `grep -c '^function ' include/functions_<x>.inc.php`. Group functions by logical cluster (lookup/hydration, mutation, validation, etc.).

2. **Map clusters → classes.** Example for `functions_user`:
   - `get_user_by_id`, `get_user_array`, `build_user`, `create_user`, `delete_user` → `Piwigo\Users\UserRepository`
   - `log_user`, `login_user`, `logout_user`, `verify_login` → `Piwigo\Auth\AuthService`
   - `remember_me_token`, `set_remember_me_cookie`, `delete_remember_me_cookie` → `Piwigo\Auth\RememberMeService`
   - `get_user_permissions`, `is_admin`, `is_webmaster`, `can_manage_user` → `Piwigo\Users\PermissionService`
   - `update_user_preferences`, `apply_user_theme` → `Piwigo\Users\PreferencesService`

3. **Move one cluster at a time, leaf-first.**
   a. Create the class under `src/Piwigo/<Domain>/`.
   b. Move the function bodies as instance methods (DI-friendly) or static methods (transitional).
   c. Leave the original free function in `functions_*.inc.php` as a one-line delegate: `function get_user_by_id(int $id) { return Piwigo\Users\UserRepository::get($id); }`.
   d. Add unit tests for the new class.

4. **Tighten types.** Free functions with `@param mixed` get narrowed signatures (`int`, `string`, `int|false`) on the new class methods.

5. **Wire DI.** New class is registered in `config/container.php` (item #12). Constructor takes its dependencies (Config, Logger, repositories from item #17, etc.).

6. **Remove wrappers** once all callers in `include/`, `admin/`, and `src/` have been migrated to the new class.

7. **PHPStan.** New classes are clean at level 9 from day one — write them typed, don't retrofit.

### Pre-boot and admin includes → services

In addition to the `functions_*.inc.php` modules above, several `include/*.inc.php` and `admin/include/*.inc.php` files are procedural scripts that read file-top globals (`$template`, `$user`, `$page`, `$persistent_cache`, `$lang`). They're in scope for this item because the work is identical: extract the body into a typed service class, leave a one-line delegate behind. Once the service exists, the `global` declaration at the top of the file is replaced by constructor-injected (or `Kernel::container()->get(...)`) dependencies.

| File | Becomes | Notes |
|---|---|---|
| `include/section_init.inc.php` | `Piwigo\Section\SectionInitializer::initialize()` | Owns the PATH_INFO / `$page['section']` parsing — also touched by item #22. |
| `include/user.inc.php` | `Piwigo\Users\UserBootstrap` | Builds the `CurrentUser` from session + cookies on each request. |
| `include/filter.inc.php` | `Piwigo\Filter\FilterResolver` | Resolves the active filter from session + URL. |
| `include/ws_core.inc.php` | merge into `Piwigo\Ws\PwgServer` | Already partially classed (item #8). |
| `include/ws_init.inc.php` | merge into `Piwigo\Ws\PwgServer::boot()` | |
| `include/ws_functions/pwg.{categories,extensions,images,php,tags,users}.php` | `Piwigo\Ws\Method\{Categories,Extensions,Images,General,Tags,Users}Endpoints` | One class per file. Coordinate with item #21 if the OpenAPI work lands first. |
| `admin/include/albums_tab.inc.php` | `Piwigo\Admin\Album\AlbumsTabRenderer` | |
| `admin/include/batch_manager_filters.inc.php` | `Piwigo\Admin\BatchManager\FilterResolver` | |
| `admin/include/configuration_sizes_process.inc.php` | `Piwigo\Admin\Config\SizesProcessor` | |
| `admin/include/configuration_watermark_process.inc.php` | `Piwigo\Admin\Config\WatermarkProcessor` | |
| `admin/include/photos_add_direct_prepare.inc.php` | `Piwigo\Admin\Upload\DirectPreparer` | |
| `admin/include/user_tabs.inc.php` | `Piwigo\Admin\Users\UserTabRenderer` | |
| `include/constants.php` | `Piwigo\Core\Config::dbPrefix()` | One typed accessor; `$prefixeTable` global retires. |

Pure rendering includes (`include/page_header.php`, `include/page_tail.php`, `include/picture_comment.inc.php`, `include/picture_metadata.inc.php`, `include/picture_rate.inc.php`, `include/no_photo_yet.inc.php`, `include/search_filters.inc.php`, `include/selected_tags.inc.php`, `include/category_cats.inc.php`, `include/category_default.inc.php`) are **not** in scope here — they become Latte partials under item #23.

### Verification (per module)

```bash
grep -c "^function " include/functions_<x>.inc.php   # shrinking each PR
vendor/bin/phpunit --testsuite Unit                  # new class tests green
npx playwright test                                  # behavioral parity
```

### Verification (track-wide)

```bash
grep -c "^function " include/functions_*.inc.php | awk -F: '{s+=$NF} END {print s}'
# target: 0 (modules become empty shells, eventually deleted)
```

---

## #20 — Background job queue (Symfony Messenger)

**Status:** Not started &nbsp;|&nbsp; **Size:** L

### Goal

Long-running operations dispatch typed messages onto a queue; a worker process consumes them asynchronously. Default transport is Doctrine DBAL (zero-infra: same DB, no Redis required); Redis/AMQP available as opt-in upgrades. Operations that benefit: derivative generation, batch uploads, mass-derivative regeneration after theme change, mailing-list notification sends, full-text reindex.

### Current state

- Zero queue. Two `register_shutdown_function()` hooks for session cleanup.
- Long ops (batch upload of 1000 photos, mass derivative regeneration after upgrade) run inline in the request — risking PHP `max_execution_time` and timing out browser sessions.
- "Async" today is fake: the page emits a JS-driven progress bar that polls a status endpoint and triggers more inline ops.

### Steps

1. **Add dependencies.**

   ```bash
   composer require symfony/messenger symfony/doctrine-messenger
   ```

   Optional: `symfony/redis-messenger`, `symfony/amqp-messenger`.

2. **Define typed messages** under `src/Piwigo/Job/`:
   - `GenerateDerivativeJob(int $imageId, string $size)`
   - `RegenerateAllDerivativesJob(?int $themeId, array $sizes)`
   - `SendNotificationEmailJob(int $userId, string $template, array $params)`
   - `BatchUploadJob(int $batchId)`
   - `ReindexImagesJob(?int $sinceId = null)`

3. **Implement handlers** under `src/Piwigo/Job/Handler/`:

   ```php
   final class GenerateDerivativeHandler {
       public function __construct(private ImageRepository $images, private LoggerInterface $log) {}
       public function __invoke(GenerateDerivativeJob $job): void {
           $img = $this->images->find($job->imageId);
           // … run the derivative
           $this->log->info('derivative.generated', ['id' => $job->imageId, 'size' => $job->size]);
       }
   }
   ```

   Handlers are auto-registered via `#[AsMessageHandler]` attribute.

4. **Bus + transport config.** `config/messenger.php`:

   ```php
   return [
       'transports' => [
           'async' => 'doctrine://default?queue_name=async',
           'failed' => 'doctrine://default?queue_name=failed',
       ],
       'routing' => [
           Piwigo\Job\GenerateDerivativeJob::class      => 'async',
           Piwigo\Job\SendNotificationEmailJob::class   => 'async',
           Piwigo\Job\BatchUploadJob::class             => 'async',
           Piwigo\Job\RegenerateAllDerivativesJob::class => 'async',
       ],
       'failure_transport' => 'failed',
   ];
   ```

5. **Refactor inline call sites.** Anywhere that today loops over images regenerating derivatives, replace with:

   ```php
   foreach ($imageIds as $id) {
       $bus->dispatch(new GenerateDerivativeJob($id, 'medium'));
   }
   // returns immediately; worker picks up
   ```

6. **Worker entrypoint.** `bin/piwigo messenger:consume async --time-limit=3600 --memory-limit=256M`. Document `systemd` and `supervisord` configs in `docs/DEPLOYMENT.md`.

7. **Admin queue UI.** New page under `/admin/queue` shows: pending count, in-progress workers, failed jobs (with `view trace` and `retry`). Reads from `messenger_messages` table.

### Verification

```bash
# Dispatch a job
php -r "
  require 'vendor/autoload.php';
  \$bus = (Piwigo\Bootstrap\Kernel::container())->get(Symfony\Component\Messenger\MessageBusInterface::class);
  \$bus->dispatch(new Piwigo\Job\GenerateDerivativeJob(123, 'small'));
"

# Verify it's queued
mysql piwigo_test -e "SELECT id, queue_name, body FROM messenger_messages;"

# Consume and verify handler ran
bin/piwigo messenger:consume async --limit=1
mysql piwigo_test -e "SELECT * FROM messenger_messages;"  # row removed (or moved to failed)
```

---

## #21 — OpenAPI 3.1 spec for the WS layer

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

An OpenAPI 3.1 specification describes every method registered with `PwgServer::addMethod()`. The spec is auto-generated from the registration metadata — no hand-maintained YAML. Served at `/ws/openapi.json`; Swagger UI at `/ws/docs`. With the spec in place, client SDKs (TypeScript, Python, etc.) can be generated via `openapi-generator-cli`.

### Current state

- 100+ WS methods registered procedurally with array literals in `include/ws_functions/*.php`.
- Documentation exists only in PHP docblocks above the handler functions.
- No machine-readable schema; every external integrator hand-rolls request/response shapes.

### Steps

1. **Typed registration.** Introduce `Piwigo\Ws\MethodDefinition` DTO:

   ```php
   final class MethodDefinition {
       public function __construct(
           public readonly string $name,
           public readonly string $description,
           public readonly array $params,           // ParamDefinition[]
           public readonly string $returns,         // class-string|literal
           public readonly array $tags = [],
           public readonly bool $requiresAuth = false,
       ) {}
   }
   ```

   `PwgServer::addMethod()` accepts both the legacy array shape (BC) and `MethodDefinition` (new). The legacy shape is internally normalized to a `MethodDefinition`.

2. **`Piwigo\Ws\OpenApi\SpecBuilder`.** Walks the registered methods, emits an OpenAPI 3.1 document:
   - Each WS method becomes a path `/ws.json#<methodName>` (or `/ws/<methodName>` if routing supports it post-#22).
   - Param types map from `WsType` enum (item #13) to OpenAPI primitives.
   - Response shapes derived from a per-method response class (typed object the handler returns).

3. **Routes.** `/ws/openapi.json` returns `SpecBuilder::build()->json()`. `/ws/docs` serves the Swagger UI bundle (CDN or vendored).

4. **Per-method enrichment.** PHP attribute on each handler:

   ```php
   #[OpenApi\Method(
       summary: 'Search images by tag, date, or filename.',
       responseClass: ImageSearchResponse::class,
       tags: ['images']
   )]
   public function search(...): ImageSearchResponse { … }
   ```

5. **CI gate.** `vendor/bin/openapi-spec-validator _data/openapi.json` (or equivalent) validates the emitted spec on every push.

6. **Optional: client SDKs.** Document `openapi-generator-cli generate -i /ws/openapi.json -g typescript-axios -o sdk/ts` for downstream integrators.

### Verification

```bash
curl -s http://localhost/ws/openapi.json | jq '.info.title'   # "Piwigo Web Services"
curl -s http://localhost/ws/docs | grep -q 'swagger-ui'        # serves Swagger UI HTML

# Spec is well-formed
vendor/bin/openapi-spec-validator _data/openapi.json           # exits 0
```

---

## #22 — Single front controller + PSR-7/15 routing

**Status:** Not started &nbsp;|&nbsp; **Size:** XL (capstone)

### Goal

All HTTP requests enter through a single `public/index.php` front controller. The controller adapts the request to PSR-7, runs it through a PSR-15 middleware pipeline (error handler → session → auth → CSRF → routing → controller dispatch), and emits a PSR-7 response. The 26 root-level `.php` files and the ~20 admin entrypoints are replaced by controller classes registered in a route table. URL config switches (`question_mark_in_urls`, `php_extension_in_urls`) are dropped — URLs are always rewritten by the web server.

This is the capstone item. It depends on items #1–#12 landing first — especially the exception hierarchy (#10), PSR-3 logger (#11), and PSR-11 container (#12).

### Current state

- **46 entrypoints** total: 26 in repo root (`index.php`, `picture.php`, `admin.php`, `ws.php`, `install.php`, `upgrade.php`, `action.php`, `search.php`, `feed.php`, `password.php`, `profile.php`, `comments.php`, `identification.php`, `register.php`, `tags.php`, `i.php`, `notification.php`, `qsearch.php`, `random.php`, `popuphelp.php`, `about.php`, `check_admin.php`, `nbm.php`, `osmmap.php`, `upgrade_feed.php`, `rector.php`) plus ~20 in `admin/`.
- **No `.htaccess`** at root — no URL rewriting today.
- **Routing** is procedural: `include/section_init.inc.php` parses PATH_INFO / first GET key into `$page['section']`, and `script_basename()` switches on the entrypoint filename.
- `Kernel::boot()` is called from each entrypoint and wires service facades — but it's not a request dispatcher.
- Two URL-format flags in `Config`: `question_mark_in_urls` (default true), `php_extension_in_urls` (default true). Both are legacy compatibility knobs that block proper rewriting.

### Steps

1. **Add PSR-7/15 dependencies.** `composer require nyholm/psr7 nyholm/psr7-server psr/http-message psr/http-server-middleware psr/http-server-handler nikic/fast-route` (or `symfony/routing`).

2. **Create `public/index.php`.**

   ```php
   <?php
   declare(strict_types=1);
   require __DIR__ . '/../vendor/autoload.php';
   $kernel = Piwigo\Bootstrap\Kernel::boot();
   $request = Piwigo\Http\RequestFactory::fromGlobals();
   $response = $kernel->handle($request);
   (new Piwigo\Http\ResponseEmitter())->emit($response);
   ```

3. **Web-server config.**
   - `public/.htaccess` — `RewriteRule ^ index.php [QSA,L]` for everything not matching a real file.
   - `docs/nginx.conf.example` — equivalent `try_files $uri /index.php?$query_string;`.
   - The repo's existing root files become eventual no-ops; for backwards compatibility during the transition, keep a few root shims (`index.php` → `require __DIR__ . '/public/index.php';`).

4. **Route table.** `config/routes.php`:

   ```php
   return function (FastRoute\RouteCollector $r) {
       $r->addGroup('', function ($r) {
           $r->get('/',                         GalleryController::class);
           $r->get('/picture/{id:\d+}[/{slug}]', PictureController::class);
           $r->get('/category/{id:\d+}[/{slug}]', CategoryController::class);
           $r->any('/search',                   SearchController::class);
           $r->any('/identification',           IdentificationController::class);
           $r->any('/register',                 RegisterController::class);
           // …
       });
       $r->addGroup('/admin', function ($r) {
           $r->any('[/{section}[/{action}]]',  AdminController::class);
       });
       $r->addGroup('/ws', function ($r) {
           $r->any('[.{format:json|xml}]',     WsController::class);
       });
   };
   ```

5. **Controllers — Wave A: root entrypoints (15 files).** Move each repo-root `.php` file into `app/Controller/<Name>Controller.php` as a class implementing `__invoke(ServerRequestInterface): ResponseInterface`. The body becomes the existing logic adapted to read from the request and return a response.

   | Entry-script | Becomes |
   |---|---|
   | `index.php` | `IndexController` (or `GalleryController`) |
   | `picture.php` | `PictureController` |
   | `password.php` | `PasswordController` |
   | `profile.php` | `ProfileController` |
   | `comments.php` | `CommentsController` |
   | `feed.php` | `FeedController` |
   | `i.php` | `ImageDerivativeController` |
   | `identification.php` | `IdentificationController` |
   | `install.php` | `InstallController` |
   | `notification.php` | `NotificationController` |
   | `register.php` | `RegisterController` |
   | `search.php` | `SearchController` |
   | `tags.php` | `TagsController` |
   | `upgrade.php` | `UpgradeController` |
   | `ws.php` | `WebServiceController` |

5b. **Controllers — Wave B: admin entrypoints (57 files).** Same pattern under `app/Controller/Admin/`. Routed via the `/admin/...` prefix in step 4. One commit per logical cluster (album management, batch manager, configuration, plugins/themes, users/groups, maintenance, etc.) — not 57 separate commits and not one mega-commit.

5c. **Per-page Context DTOs.** Each controller hands a single typed DTO to its template instead of pushing ~15 file-scope `$category`, `$collection`, `$base_url`, `$picture`, `$related_categories`, `$comment_action`, etc. variables. New DTOs under `src/Piwigo/Page/Context/`:

   | DTO | Owning controller(s) | Properties |
   |---|---|---|
   | `AlbumPageContext` | `IndexController`, `CategoryController` | `category`, `subAlbums`, `photos`, `pagination`, `baseUrl` |
   | `PicturePageContext` | `PictureController` | `picture`, `relatedCategories`, `commentAction`, `urlSelf` |
   | `SearchPageContext` | `SearchController` | `query`, `filters`, `results`, `pagination` |
   | `TagsPageContext` | `TagsController` | `tags`, `selectedTags`, `photos` |
   | `CommentsPageContext` | `CommentsController` | `comments`, `pagination`, `filters` |
   | `FeedPageContext` | `FeedController` | `items`, `feedMeta` |
   | `IdentificationPageContext` | `IdentificationController`, `RegisterController`, `PasswordController` | `errors`, `redirectTo`, `formState` |
   | `ProfilePageContext` | `ProfileController` | `user`, `prefs`, `themes`, `languages` |
   | `NotificationPageContext` | `NotificationController` | `subscriptions`, `formState` |
   | `AdminAlbumPageContext` | `Admin\AlbumController`, `Admin\CategoryModifyController`, `Admin\CategoryPermissionsController` | `category`, `adminBaseUrl`, `permissions` |
   | `AdminPhotoPageContext` | `Admin\PhotoController`, `Admin\PictureModifyController` | `picture`, `adminPhotoBaseUrl` |
   | `BatchManagerContext` | `Admin\BatchManager*Controller` | `collection`, `baseUrl`, `selectedFilters` |
   | `MaintenanceContext` | `Admin\MaintenanceActionsController` | `maintActions`, `lastRun` |
   | `AdminListContext` | generic — `Admin\UserListController`, `Admin\GroupListController`, `Admin\TagsController`, `Admin\PluginsController`, `Admin\ThemesController`, etc. | `items`, `pagination`, `filters`, `baseUrl` |
   | `AdminPageContext` | base class — `pageTitle`, `pageMeta`, `themeAssets`, `flashMessages` | inherited by all admin contexts |

   DTOs are built incrementally — each controller wave creates its own DTO, no need to ship all 15 up front. The Latte partials in item #23 receive these DTOs as `{templateType}` declarations.

6. **Middleware pipeline.** `Piwigo\Http\MiddlewarePipeline` runs:
   1. `ExceptionHandlerMiddleware` (catches `PiwigoException`, renders error response — depends on item #10)
   2. `SecurityHeadersMiddleware` (CSP, X-Frame-Options, etc. — see item #24)
   3. `SessionMiddleware` (start session, attach to request attributes)
   4. `AuthMiddleware` (resolve `CurrentUser`, attach to request)
   5. `CsrfMiddleware` (verify pwg_token on state-changing requests — see item #24)
   6. `RoutingMiddleware` (FastRoute dispatch)
   7. `ControllerInvokerMiddleware` (calls `__invoke` with route args, returns response)

7. **Drop legacy URL flags.** Remove `question_mark_in_urls` and `php_extension_in_urls` from `Config` (with deprecation shim that warns if set). All URLs go through a `Piwigo\Url\UrlGenerator` that emits clean rewritten paths.

8. **Migrate `admin.php` and `ws.php`.** These become `/admin/...` and `/ws/...` route prefixes — same controllers as before, but invoked via the front controller's pipeline.

9. **Delete root shims** once plugins and integrations are confirmed to use clean URLs (likely a release after the cutover).

### Verification

```bash
# Routing sanity
curl -s http://localhost/                      | grep -q '<title>'
curl -s http://localhost/picture/1             | grep -q '<title>'
curl -s -X POST http://localhost/ws.json       -d 'method=pwg.getVersion' | jq .stat   # "ok"
curl -s http://localhost/admin                 | grep -q 'admin'

# Verify single entrypoint
ls *.php | wc -l        # ≤ 1 (just a shim during transition; 0 after cutover)

# E2E suite green
npx playwright test
```

---

## #23 — Replace Smarty with Latte

**Status:** Not started &nbsp;|&nbsp; **Size:** XL

### Goal

Migrate every template from Smarty 5 to [Nette Latte](https://latte.nette.org). Latte buys: native PHP expressions in the syntax, compile-time syntax checking, type-safe templates, escape-by-default with context-aware escaping, sandbox mode for untrusted templates, much better IDE support, and faster compilation. End state: zero `.tpl` files compiled by Smarty; `smarty/smarty` removed from `composer.json`; all templates are `.latte`.

### Current state

- **`smarty/smarty: ^5.0`** in `composer.json`.
- **169 `.tpl` files**: `admin/themes/default/template/` 69, `themes/default/template/` 55, plugins 31, `themes/standard_pages/` 7, plus a handful in includes/standard_pages skins. Zero `.latte` files yet.
- **`src/Piwigo/Template/Template.php`** wraps Smarty and registers ~30+ custom plugins:
  - **Modifiers:** `translate`, `translate_dec`, `sprintf`, `urlencode`, `intval`, `file_exists`, `constant`, `json_encode`, `json_decode`, `htmlspecialchars`, `implode`, `stripslashes`, `in_array`, `ucfirst`, `strstr`, `stristr`, `trim`, `md5`, `strtolower`, `str_ireplace`, `explode`, `ternary`, `get_extent`, `url_is_remote`, `is_null`, `l10n`, `str_replace`, `is_admin`, `is_classic_user`, `get_device`, `is_file`.
  - **Functions:** `combine_script`, `get_combined_scripts`, `combine_css`, `define_derivative`.
  - **Compilers:** `get_combined_css`.
  - **Blocks:** `html_head`, `html_style`, `footer_script`. Of these, only `html_head` is currently called from a template (`themes/default/template/notification.tpl`). `html_style` and `footer_script` have zero in-scope callers — kept implemented in `Template.php` for the future `{html_style}` + nonce path described in `PLAN-inline-assets-extraction.md`.
  - **Filters:** `prefilter_white_space` (whitespace stripper).
- **Bundled plugin templates** under `plugins/*/template/`: `LocalFilesEditor`, `nbc_ThemeChanger`, `piwigo-openstreetmap`, `piwigo-videojs`, `user_tags` ship their own `.tpl` files and rely on the Smarty plugin API.
- **No `.css.tpl` files remain** — the original modus theme that carried `themes/modus/css/base.css.tpl` is no longer in the codebase. Step 4 of "convert templates in waves" below referenced it; that wave is now empty.

### Steps

1. **Add `latte/latte` to `composer.json`.** Keep `smarty/smarty` for the transition window.

2. **Define a `Piwigo\Template\TemplateEngine` interface** with the contract both engines must satisfy: `assign(string $name, mixed $value): void`, `render(string $template, array $params = []): string`, `parse(string $template): string`. Both `Piwigo\Template\SmartyEngine` (existing wrapper, renamed) and `Piwigo\Template\LatteEngine` (new) implement it. `TemplateRegistry::current()` returns the interface.

3. **Implement `LatteEngine`.** Configure Latte with:
   - Strict types in compiled templates (`Latte\Engine::setStrictTypes(true)`).
   - Escape-by-default with HTML context inferred per attribute.
   - Tempdir set to `_data/templates_c/latte/`.
   - Sandbox mode + `Piwigo\Template\Latte\PiwigoPolicy` for plugin-supplied templates that come from untrusted sources.

4. **Port Smarty extensions to Latte equivalents.** Map each registered Smarty plugin to a Latte filter/function/extension:
   - **Most modifiers** (`sprintf`, `urlencode`, `intval`, `htmlspecialchars`, `trim`, `md5`, etc.) — Latte already has these built-in or via filter aliases.
   - **`translate`/`translate_dec`** — Latte filter `|translate` backed by `Piwigo\Lang\Translator` (item #18 will create this).
   - **`l10n`** — same as translate; one filter, one alias.
   - **`combine_script`/`combine_css`/`get_combined_scripts`/`get_combined_css`** — Latte function tags. Implement as `Piwigo\Template\Latte\Extension\AssetExtension`.
   - **`define_derivative`** — Latte function tag in a `DerivativeExtension`.
   - **`html_head`** — Latte `{block}` extension or custom tag. Wire to the existing buffering logic in `Template.php`. Only `themes/default/template/notification.tpl` uses it.
   - **`html_style`/`footer_script`** — zero in-scope callers post-template-extraction; the Latte port can defer porting these until the `{html_style}` + nonce path (per `PLAN-inline-assets-extraction.md`) materializes.
   - **`prefilter_white_space`** — Latte template loader wrapper (run before compilation).

5. **Convert templates in waves.** Order risk-low → risk-high:
   - **Wave 0 — extract layout partials from `include/`.** Ten files in `include/` are pure-rendering procedural scripts that are `include`d for their output (`page_header.php`, `page_tail.php`, `picture_comment.inc.php`, `picture_metadata.inc.php`, `picture_rate.inc.php`, `no_photo_yet.inc.php`, `search_filters.inc.php`, `selected_tags.inc.php`, `category_cats.inc.php`, `category_default.inc.php`). Each becomes a `.latte` partial under `themes/default/template/_partials/`, declared `{templateType}` against the relevant Page Context DTO from item #22 step 5c. New `Piwigo\Page\PageRenderer` exposes `renderHeader(HeaderContext)` / `renderTail(TailContext)` / `renderPartial(string $name, object $ctx)` so callers stop `include`-ing PHP files. This wave unblocks the remaining `global $template, $user, $page, $lang;` declarations in those files and is a hard prerequisite for the controllers in item #22.
   - **Wave 1 — admin templates** (lowest risk, 69 files in `admin/themes/default/template/`). Each `.tpl` → `.latte`. Smarty syntax → Latte syntax. Run the page in the browser after each conversion.
   - **Wave 2 — public theme `default`** (55 files in `themes/default/template/`).
   - **Wave 3 — public theme `standard_pages`** (7 files) and email templates.
   - **Wave 4 — plugin templates** (31 files across 5 bundled plugins). Each plugin gets its own commit.

6. **Mechanical conversion helpers.** Most Smarty-to-Latte syntax is regex-replaceable:
   - `{if $foo}` → `{if $foo}` (compatible)
   - `{foreach from=$arr item=x}` → `{foreach $arr as $x}`
   - `{$x|escape}` → `{$x}` (Latte escapes by default)
   - `{$x|escape:'none'}` → `{$x|noescape}`
   - `{include file=foo.tpl}` → `{include 'foo.latte'}`
   - `{$x|@count}` → `{count($x)}`
   - `{section name=i loop=$arr}` → `{foreach $arr as $i => $val}`
   - Build `tools/smarty-to-latte/convert.php` to apply these rewrites file-by-file. Hand-fix the residue.

7. **Compatibility shim for 3rd-party plugins.** Plugins that haven't migrated their `.tpl` files yet still get rendered by `SmartyEngine`. The dispatcher in `TemplateRegistry::current()` picks the engine based on file extension (`.latte` vs `.tpl`).

8. **Drop Smarty.** Once all bundled `.tpl` files are converted and at least the top-3 plugins have shipped Latte versions: remove `smarty/smarty` from `composer.json`, delete `Piwigo\Template\SmartyEngine`, mark plugins still using Smarty as legacy with a deprecation notice.

### Verification

```bash
find . -name "*.tpl" -not -path "*/_data/*" -not -path "*/vendor/*" -not -path "*/node_modules/*" | wc -l
# baseline: 169 today; target: 0 (all converted to .latte) — or only inside legacy plugin directories during transition

composer show smarty/smarty 2>&1 | grep "not installed"   # after final removal
vendor/bin/phpunit                                         # green
npx playwright test                                        # green (no visual regression)
```

---

## #24 — Security hardening (CSP, rate limiting, brute-force, CSRF)

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

Browser security headers (CSP with per-request nonce, X-Frame-Options, X-Content-Type-Options, Strict-Transport-Security, Permissions-Policy) applied via PSR-15 middleware. Login endpoint rate-limited; brute-force protection locks accounts after threshold. CSRF verification centralized in middleware. Session cookies hardened.

Depends on the front controller (#22) for middleware insertion; depends on the exception hierarchy (#10) for typed responses.

### Current state

- **Zero CSP** headers anywhere.
- **Zero rate limiting** on login (no throttle, no attempt counter).
- **No brute-force protection** — unbounded password guesses, no lockout.
- **CSRF tokens (`pwg_token`)**: 257 references — present and used, but verification is per-controller and ad-hoc; some endpoints forget to check.
- **Password hashing**: `password_hash($pwd, PASSWORD_BCRYPT)` at default cost (10) — solid, no work needed.
- **Session cookies**: `samesite`, `secure`, `httponly` not consistently set; no rotation on login.

### Steps

1. **`SecurityHeadersMiddleware`.** Adds to every response:

   ```php
   $response = $response
       ->withHeader('Content-Security-Policy',
           "default-src 'self'; "
           ."img-src 'self' data: blob:; "
           ."style-src 'self'; "                  // 0 <style> blocks remain (PLAN-inline-assets-extraction)
           ."style-src-elem 'self'; "             // explicit; matches style-src
           ."style-src-attr 'unsafe-inline'; "    // 13 PHP-driven '--var: value' attrs (CSS custom props)
           ."script-src 'self' 'nonce-{$nonce}'; "
           ."frame-ancestors 'self'; "
           ."form-action 'self'")
       ->withHeader('X-Frame-Options', 'SAMEORIGIN')
       ->withHeader('X-Content-Type-Options', 'nosniff')
       ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
       ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
       ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
   ```

   The per-request `$nonce` is also injected into `Template`/`Latte` so `<script>` tags in templates can render `nonce="{$nonce}"`. ROADMAP-TS.md #3 (`{footer_script}` migration) is already complete — 0 `{footer_script}` blocks, 0 inline-JS handlers, 0 bare executable `<script>` blocks remain. The 13 surviving inline `style="…"` attributes are uniform `--var: value` shape (CSS custom properties) and are covered by `style-src-attr`. If a future stricter policy demands `style-src-attr 'none'`, resurrect the existing `{html_style}` mechanism (`Template.php:122,536-547,703-714` — implementation intact, all callers removed) to emit a single nonce'd `<style>` tag per request with the runtime CSS rules keyed by data-attribute selectors.

2. **Login rate limiting.** `composer require symfony/rate-limiter`. Token-bucket strategy:
   - 5 failed attempts per minute per IP → 429 response.
   - 10 failed attempts within 10 minutes per IP+username → account lockout for 15 minutes; email the user.
     Configuration in `config/security.php`.

3. **Brute-force protection.** `phpwg_user_failed_logins` table tracks `(user_id, ip, timestamp)`. After threshold, `AuthService::login()` rejects with `AuthException::accountLocked()` even with the correct password. Admin "Unlock account" action clears the counter.

4. **CSRF middleware.** `CsrfMiddleware` validates `pwg_token` on every state-changing request (POST, PUT, PATCH, DELETE). Replaces ad-hoc per-controller checks. The 257 existing `pwg_token` references collapse — most become no-ops after middleware adoption, since the middleware handles verification centrally.

5. **Session hardening.** `SessionMiddleware` (item #22) sets:

   ```php
   session_set_cookie_params([
       'lifetime' => 0,
       'path'     => '/',
       'samesite' => 'Strict',
       'secure'   => true,
       'httponly' => true,
   ]);
   ```

   On successful login: `session_regenerate_id(true)` to rotate the session ID.

6. **Document policy** in `docs/SECURITY.md`: threat model, CSP override procedure, account-lockout admin actions, how to report a vulnerability.

### Verification

```bash
curl -sI http://localhost/                                    | grep -i content-security-policy
curl -sI http://localhost/                                    | grep -i x-frame-options
curl -sI http://localhost/                                    | grep -i strict-transport-security

# 6th login attempt within a minute returns 429
for i in $(seq 1 6); do
  curl -sw '%{http_code}\n' -o /dev/null \
    -X POST http://localhost/identification \
    -d 'username=test&password=wrong'
done   # expect: 200,200,200,200,200,429

# CSRF without token
curl -sw '%{http_code}\n' -o /dev/null \
  -X POST http://localhost/admin/category_delete \
  -d 'cat_id=1'   # expect: 403
```

---

## #25 — Pre-compile templates as a deploy step

**Status:** Not started &nbsp;|&nbsp; **Size:** S

### Goal

Eliminate first-request compile latency by warming `_data/templates_c/` at deploy time instead of on first hit. Ship `tools/precompile_templates.php`, a CLI entrypoint that walks every active theme + admin context and compiles every template ahead of time. End state: the first request after a deploy serves cached PHP without invoking the template compiler. Depends on #23 — Latte is the primary target post-migration; legacy Smarty templates are covered for the duration of the compatibility shim.

### Current state

- Smarty 5 lazy-compiles `.tpl` → `_data/templates_c/<hash>_0.file_<name>.tpl.php` on first render of each template; the gallery + admin first-walk emits 100+ compiled files.
- Latte (post-#23) lazy-compiles `.latte` → `_data/templates_c/latte/` with the same first-hit penalty.
- `template_compile_check` is on by default — Smarty `stat`s every source on every render. `Piwigo\Core\Config::templateCompileCheck()` reads it from config; there is no CLI override and no production-time flip.
- No `tools/precompile_*` script exists today.

### Steps

1. **Add `tools/precompile_templates.php`.** Boot Piwigo (`include/common.inc.php`) in CLI mode without emitting output, then for each engine instance:
   - **Smarty path** (during the transition window): call `$engine->smarty->compileAllTemplates('.tpl', force: true)` per `template_dir` push (gallery context, admin context).
   - **Latte path** (primary post-#23): iterate every `.latte` under the active theme + admin dirs and call the engine's compile-only API (`Latte\Engine::warmupCache($name)` or equivalent — settle the call site against the Latte version pinned in #23 step 1).

   Report counts and any compile error on stderr; exit non-zero if any template fails to compile. This catches syntax regressions before they reach the gallery.

2. **Iterate per-theme.** Compiled cache keys are bound to the resolved `template_dir` stack. Cover every theme that may serve traffic — active gallery theme, admin theme, every theme listed under `themes_installed` — pushing/popping the dir stack between runs.

3. **Iterate per-plugin-set.** Plugins inject Smarty prefilters and Latte extensions at boot, both of which alter compiled output. Run against the production-active plugin set so cache keys match request-path lookups. Document in `CONTRIBUTING.md` that staging/test environments with different plugin sets need a separate warm.

4. **Wire into deploy and turn off `compile_check` in production.** Add a `make precompile-templates` target and a `php tools/precompile_templates.php` step in `INSTALL.md` after `composer install --no-dev`. The pay-off this enables is **`template_compile_check = 0`** — once compile-on-first-hit is gone, the per-render `stat()` is wasted work. Add a config example and an `INSTALL.md` callout.

5. **OPcache guidance.** `_data/templates_c/` holds plain PHP. Document that hosters should leave OPcache enabled with a generous `opcache.max_accelerated_files` (file count is high — ~150 today, similar post-Latte) and may use `opcache.preload` for the truly hot files.

6. **CI hook.** Add a job that runs the precompile against a representative theme + plugin set on every PR. Acts as a second syntax gate beyond #23's verification — catches Latte regressions in plugin templates that don't have unit-test coverage.

### Verification

```bash
rm -rf _data/templates_c/* _data/templates_c/latte/* 2>/dev/null
php tools/precompile_templates.php           # exits 0; reports N templates compiled
ls _data/templates_c/ | wc -l                # > 0, matches reported count

# After warming, the first request must not write to _data/templates_c/:
mtime_before=$(stat -c %Y _data/templates_c/)
curl -s http://localhost/ > /dev/null
mtime_after=$(stat -c %Y _data/templates_c/)
[ "$mtime_before" = "$mtime_after" ] && echo "no recompile on first hit"
```

---

## #26 — Plugin and theme system modernization

**Status:** Not started &nbsp;|&nbsp; **Size:** XL

### Goal

Replace the procedural plugin and theme APIs (`add_event_handler` / `trigger_change` over string event names; `themeconf.inc.php` arrays + arbitrary side-effect code) with a typed, DI-aware system: PSR-14 event dispatcher, typed event objects, lifecycle interfaces (`PluginInterface`, `ThemeInterface`), declarative manifests, and PSR-4-laid-out source. Keep compatibility layers so 3rd-party plugins and themes using the legacy APIs keep working through one major release of deprecation.

The work splits into two phases. Phase 1 lands the event-bus and plugin foundations; Phase 2 reuses those foundations to modernize themes (which hook through the same event mechanism today). Phase 2 cannot start until Phase 1 ships, but the bundled-asset migrations within each phase can run in parallel once the interfaces are in place.

---

### Phase 1 — Plugins

#### Current state

- **`include/functions_plugins.inc.php`** (12 free functions): `add_event_handler`, `remove_event_handler`, `trigger_change` (string event + variadic mixed args), `trigger_action`, `get_plugin_data`, `set_plugin_data`, plugin loader, etc.
- **5 bundled plugins** under `plugins/`: `LocalFilesEditor`, `nbc_ThemeChanger`, `piwigo-openstreetmap`, `piwigo-videojs`, `user_tags`. Each has a `main.inc.php` with `add_event_handler('event_name', 'callback_function')` calls and a `maintain.inc.php` extending `PluginMaintain`.
- **`src/Piwigo/Admin/PluginMaintain`** is the only typed part of the plugin API today.
- **3rd-party plugins** in the wild rely on the global event API; breaking it shatters the ecosystem.
- **No DI for plugin code** — plugins use `global $conf` etc.; covered for bundled plugins by item #6.

#### Steps

1. **Define `Piwigo\Plugin\PluginInterface`.**

   ```php
   interface PluginInterface {
       public function getId(): string;             // 'piwigo-openstreetmap'
       public function getVersion(): string;        // '1.4.0'
       public function getName(): string;           // human-readable
       public function boot(ContainerInterface $c): void;
       public function shutdown(): void;
       public function install(): void;
       public function activate(): void;
       public function deactivate(): void;
       public function uninstall(): void;
       public function subscribedEvents(): array;   // ['Piwigo\Event\PictureRendered' => 'onPictureRendered', …]
   }
   ```

2. **Adopt PSR-14 events.** `composer require psr/event-dispatcher symfony/event-dispatcher`. Replace string events with typed event objects under `src/Piwigo/Event/`:
   - `PictureRendered`, `CategoryRendered`, `UserAuthenticated`, `UserLoggedOut`, `CommentSubmitted`, `ImageUploaded`, `PluginActivated`, `ThemeActivated`, etc.
   - Event objects are `readonly` data classes (item #13). Listeners receive the typed object; can mutate `mixed $data` properties via `with*()` clone-and-modify methods for the `trigger_change` use case.
   - `Piwigo\Event\EventDispatcher` is registered in the DI container (item #12) as the `Psr\EventDispatcher\EventDispatcherInterface` implementation.

3. **Build the legacy compatibility layer.** `add_event_handler('user_login', $callback)` keeps working — both for plugins and for themes that register handlers from `themeconf.inc.php`. The legacy bridge maps the string event names to the new typed events; when a typed `UserAuthenticated` is dispatched, registered legacy listeners are also invoked with the bridged args. Document the deprecation in `src/Piwigo/Compat/LegacyEvents.php` with `trigger_error(E_USER_DEPRECATED, …)` on first call.

4. **Declarative plugin manifest.** Each plugin ships a `plugin.json` (or extends `composer.json`'s `extra` block):

   ```json
   {
     "id": "piwigo-openstreetmap",
     "version": "1.4.0",
     "name": "OpenStreetMap",
     "minPiwigo": "16.0",
     "main": "Piwigo\\Plugin\\OpenStreetMap\\Plugin",
     "autoload": { "psr-4": { "Piwigo\\Plugin\\OpenStreetMap\\": "src/" } }
   }
   ```

   `Piwigo\Plugin\PluginRegistry` reads the manifest, registers PSR-4 autoload, instantiates the main class, and calls `boot()`.

5. **DI for plugins.** Plugins receive the container in `boot()`. They register their own services via `$container->set(...)`. Their event listener methods get auto-resolved dependencies.

6. **Migrate the 5 bundled plugins.** One plugin per PR:
   - Move source to `plugins/<id>/src/` under PSR-4 namespace `Piwigo\Plugin\<Pascal>\`.
   - Convert `main.inc.php` event-handler registrations to a `Plugin` class with `subscribedEvents()`.
   - Convert `maintain.inc.php` to `Maintain` class implementing the lifecycle methods.
   - Add `plugin.json`.
   - Convert templates to Latte (item #23).

7. **Plugin admin UI.** The "Plugins" admin page reads `plugin.json` instead of parsing `main.inc.php` headers. Activation/deactivation calls the lifecycle methods.

8. **Document migration path** in `docs/PLUGIN-DEVELOPMENT.md`. Include a side-by-side: "old API" vs "new API" for each common pattern (event handler, admin tab, WS method, language strings, asset injection).

9. **Deprecation timeline.** Keep the legacy API working through the next minor release with `E_USER_DEPRECATED`. Plan removal one major release later.

#### Verification

```bash
# All bundled plugins use the new API:
for p in plugins/*/; do
  test -f "$p/plugin.json" || echo "MISSING: $p"
done

# Legacy bridge still works:
vendor/bin/phpunit --filter LegacyEventBridgeTest

# Event dispatcher conforms to PSR-14:
php -r 'echo (new Piwigo\Event\EventDispatcher) instanceof Psr\EventDispatcher\EventDispatcherInterface ? "ok" : "fail";'

# E2E with all plugins activated:
npx playwright test
```

---

### Phase 2 — Themes

Themes hook into the same event bus as plugins, so most of the foundation from Phase 1 (PSR-14 dispatcher, legacy bridge, DI container access, manifest pattern) is reused. Phase 2 specializes the contract for theme concerns: parent-theme inheritance, asset directories, template overrides, and the `themeconf.inc.php` side-effect block (which today runs arbitrary PHP at load time).

#### Current state

- **`themes/<id>/themeconf.inc.php`** is the only required file. It declares `$themeconf = ['name' => …, 'parent' => …, 'icon_dir' => …, 'img_dir' => …, 'load_parent_css' => …, 'local_head' => …]` and may also run arbitrary code (template assigns, event-handler registrations, config reads). Example: `themes/standard_pages/themeconf.inc.php` calls `$this->assign(...)` and `conf_get_param(...)` directly at file load.
- **2 frontend themes** bundled: `themes/default`, `themes/standard_pages` (the latter inherits from `default`).
- **3 admin themes** bundled: `admin/themes/default`, `admin/themes/clear`, `admin/themes/roma` (clear and roma inherit from default).
- **`src/Piwigo/Admin/ThemeMaintain`** is the only typed part of the theme API today.
- **3rd-party themes** rely on `themeconf.inc.php` being `include`'d at load time; breaking that shatters the ecosystem.
- **Inheritance** is resolved at load time by walking the `parent` chain and merging `$themeconf` arrays.
- **CSS skin variants** for admin themes (covered by [ROADMAP-CSS.md](ROADMAP-CSS.md) item #1) consume from `$themeconf` colors today.

#### Steps

1. **Define `Piwigo\Theme\ThemeInterface`.** Mirrors `PluginInterface` with theme-specific methods:

   ```php
   interface ThemeInterface {
       public function getId(): string;             // 'standard_pages'
       public function getVersion(): string;
       public function getName(): string;
       public function getParentId(): ?string;      // null for root themes
       public function loadParentCss(): bool;
       public function getAssetDir(string $kind): string;   // 'img', 'icon', 'mime_icon'
       public function getLocalHeadTemplate(): ?string;
       public function boot(ContainerInterface $c): void;
       public function install(): void;
       public function activate(): void;
       public function deactivate(): void;
       public function uninstall(): void;
       public function subscribedEvents(): array;
   }
   ```

2. **Declarative `theme.json` manifest.** Replaces the static array part of `themeconf.inc.php`:

   ```json
   {
     "id": "standard_pages",
     "version": "1.0.0",
     "name": "Standard Pages",
     "parent": "default",
     "loadParentCss": false,
     "assets": {
       "img": "images",
       "icon": "icon",
       "mimeIcon": "icon/mimetypes"
     },
     "localHead": "local_head.tpl",
     "main": "Piwigo\\Theme\\StandardPages\\Theme",
     "autoload": { "psr-4": { "Piwigo\\Theme\\StandardPages\\": "src/" } }
   }
   ```

3. **Move side-effect code to `Theme::boot()`.** Today, `themes/standard_pages/themeconf.inc.php` runs `$this->assign(...)` and `conf_get_param(...)` at load. That code moves into the `boot()` method, where it receives the container and can pull `Config`, the template registry, etc. via DI. Event handlers registered from `themeconf.inc.php` move into `subscribedEvents()`.

4. **`Piwigo\Theme\ThemeRegistry`.** Parallel to `PluginRegistry`. Reads `theme.json`, resolves the parent chain, registers PSR-4 autoload, instantiates `Theme`, calls `boot()`. Caches the resolved chain to avoid re-walking on every request.

5. **Inheritance via class hierarchy or composition.** Two viable approaches — pick one:
   - _Class inheritance:_ `class StandardPagesTheme extends DefaultTheme implements ThemeInterface` — overrides only what differs.
   - _Composition:_ `Theme` always has a `?ThemeInterface $parent` and methods walk up the chain (`getAssetDir()` falls back to parent if not declared). More flexible, but more boilerplate.

   Recommendation: composition. It mirrors how `themeconf.inc.php` currently works (array merge along the chain) and avoids forcing 3rd-party themes to extend a base class.

6. **Legacy `themeconf.inc.php` shim.** For themes that haven't migrated, the registry detects a missing `theme.json`, falls back to including `themeconf.inc.php`, and synthesizes a `LegacyTheme` instance from the resulting `$themeconf` array. The synthesized instance routes any registered legacy event handlers through the Phase 1 bridge.

7. **Admin theme support.** Admin themes (`admin/themes/<id>/`) follow the same contract. The "Themes" admin page reads `theme.json` and walks the registry instead of grepping `themeconf.inc.php` headers.

8. **Migrate the 5 bundled themes.** One theme per PR:
   - Add `theme.json`.
   - Add `Theme` class under `themes/<id>/src/` (or `admin/themes/<id>/src/`).
   - Move `themeconf.inc.php` side-effects into `boot()`.
   - Convert `ThemeMaintain` callers to the new lifecycle methods.
   - Convert templates to Latte (item #23).
   - Replace `themeconf.inc.php` with a one-liner that throws `E_USER_DEPRECATED` if any legacy code reaches for `$themeconf` directly.

9. **`Piwigo\Theme\ThemeChanged` event.** Theme switch fires a typed event so plugins (and other themes) can react (rebuild combined CSS, invalidate template cache, etc.). The `nbc_ThemeChanger` plugin migrates from procedural hooks to listening for this event.

10. **Document migration path** in `docs/THEME-DEVELOPMENT.md`. Side-by-side examples for each common pattern: parent inheritance, asset paths, local head template, runtime template assigns, event handler registration.

11. **Deprecation timeline.** Same cadence as Phase 1 — legacy `themeconf.inc.php` keeps working through the next minor release with `E_USER_DEPRECATED`; planned removal one major release later.

#### Verification

```bash
# All bundled themes have manifests:
for t in themes/*/ admin/themes/*/; do
  test -f "$t/theme.json" || echo "MISSING: $t"
done

# Inheritance chain resolves:
php -r '
  $r = new Piwigo\Theme\ThemeRegistry(...);
  $sp = $r->get("standard_pages");
  echo $sp->getParentId() === "default" ? "ok" : "fail";
'

# Legacy themeconf.inc.php shim still works:
vendor/bin/phpunit --filter LegacyThemeAdapterTest

# Theme switch fires the typed event:
vendor/bin/phpunit --filter ThemeChangedEventTest

# E2E across both frontend themes and all admin skins:
npx playwright test
```

---

## #27 — PHPStan level 10

**Status:** Not started (gated) &nbsp;|&nbsp; **Size:** M–L

### Goal

PHPStan analyse passes at level 10 with no baseline file. Level 10 enforces fully-typed `mixed` propagation — the strictest analysis level. Gated by the globals removal (#6) and `include/` migration (#17): without those, the bulk of level-10 errors trace back to untyped `mixed` flowing in from procedural code.

### Current state

- `phpstan.neon` set to `level: 9`, no baseline file. `vendor/bin/phpstan analyse` reports `[OK] No errors`. Custom rules registered: `NoDynamicNewRule`, `NoGlobalInSrcRule`, `TriggerChangeDynamicReturnType`, `PwgGetSessionVarDynamicReturnType`. The deprecation-rules pack is included; the strict-rules pack is required-dev but not yet wired in.
- Level 10 (`PHPSTAN_TABLE_ERROR_FORMATTER_FORCE_SHOW_ALL_ERRORS=1 phpstan analyse --level=10`) reports **1000+ errors** today — most from `mixed` returns of untyped helpers and the `global $conf, $page, $user, $lang, $template;` propagation.

### Steps

1. **Wait for the gating items.** #6 (globals) eliminates the largest single source of `mixed`. #17 (include/ migration) eliminates the second-largest. Estimate: re-measure level-10 error count after each gating PR; expect drop from 1000+ to <200.

2. **Switch `phpstan.neon` to `level: 10`.** Initial run will likely have <100 hits at this point.

3. **Sweep remaining errors module-by-module.** Most remaining hits will be:
   - Untyped event payloads (resolved by item #26's typed events).
   - `mixed` returns from PSR-3 logger context arrays — fine to suppress with `@phpstan-ignore-line`, since the API is intentionally generic.
   - Reflection / dynamic-property paths — case-by-case judgment.

4. **Toggle `treatPhpDocTypesAsCertain: true`.** Once errors are zero, enable this stricter setting to catch dishonest `@var` annotations.

5. **Drop the level-9 baseline file.** No baseline = no inherited debt.

6. **CI job already enforces level 9.** The `phpstan` job in `.github/workflows/ci.yml` (added during #1's CI build-out) runs `composer dump-autoload --strict-psr` and `vendor/bin/phpstan analyse` on every push, so it already enforces `StrictTypesRequiredRule` (#2), `NoGlobalInSrcRule`, `NoDynamicNewRule`, and PSR-4 layout (#3). When the level moves to 10 here, just bump `phpstan.neon` — the CI job picks it up automatically.

### Verification

```bash
vendor/bin/phpstan analyse --no-progress     # exits 0 with level: 10
test -z "$(cat phpstan-baseline.neon || echo '')"   # baseline empty / removed
composer dump-autoload --strict-psr          # zero warnings
```

---

## #28 — Mutation testing (Infection)

**Status:** Not started &nbsp;|&nbsp; **Size:** S

### Goal

Mutation testing runs in CI. Mutation Score Indicator (MSI) gated at ≥ 60% over `src/`; covered-MSI ≥ 75%. Mutation testing complements coverage % — high coverage with weak assertions still scores low MSI, surfacing tests that exercise but don't verify.

### Current state

- No mutation testing configured.
- Coverage % from PHPUnit is the only test-quality signal — easy to game with high call coverage but shallow assertions.

### Steps

1. **Install.**

   ```bash
   composer require --dev infection/infection
   ```

2. **Configure `infection.json5`** at the repo root:

   ```json5
   {
     $schema: 'vendor/infection/infection/resources/schema.json',
     source: { directories: ['src'] },
     logs: {
       text: 'build/infection/log.txt',
       summary: 'build/infection/summary.json',
       html: 'build/infection/report.html',
     },
     mutators: { '@default': true },
     phpUnit: { configDir: '.' },
     minMsi: 60,
     minCoveredMsi: 75,
   }
   ```

3. **Initial sweep.** Run `vendor/bin/infection --threads=4`. Triage the surviving mutants — most will be assertions that should be tightened (e.g., `assertNotEmpty($result)` → `assertSame($expected, $result)`).

4. **CI job.** Mutation testing is slower than unit tests — run on `main` push (after merge), not on every PR:

   ```yaml
   mutation:
     runs-on: ubuntu-latest
     if: github.event_name == 'push' && github.ref == 'refs/heads/main'
     steps:
       - uses: actions/checkout@v4
       - run: composer install --no-progress
       - run: vendor/bin/infection --threads=4 --min-msi=60 --min-covered-msi=75
   ```

5. **Iterate the threshold.** As tests improve, raise `minMsi` from 60 → 70 → 80. Track in `infection.json5` — bumps require a PR with rationale.

### Verification

```bash
vendor/bin/infection --threads=4 --min-msi=60 --min-covered-msi=75
# exits 0 on a clean run
open build/infection/report.html   # visualize surviving mutants per file
```

---

## #29 — Unit test coverage expansion (13% → ≥40%)

**Status:** Not started (continuous) &nbsp;|&nbsp; **Size:** L

### Goal

Raise PHPUnit unit-test coverage from the current level to ≥40% of `src/` statements. Runs in parallel with every other item — not gated. Do not add DB/HTTP-dependent tests to the Unit suite; those belong in Integration or E2E.

### Current state

- **218 test methods** across `tests/Unit/` (Auth, Cache, Core, Image, Menu, Search, Session, Template, Users, Ws). 28 test files.
- Largest untested areas in `src/`: `Admin/` (image backends, `plugins`, `themes`, `updates`), `Calendar/`. (The `Db/` namespace doesn't exist yet — gated by item #17.)

### Steps

1. **Establish a coverage baseline.** Run `vendor/bin/phpunit --testsuite Unit --coverage-html coverage/` and open `coverage/index.html`. Record which namespaces are below 20% — those drive priority.

2. **`src/Piwigo/Core/` — typed services.** `Config`, `PageState`, `Lang`, `CurrentUser`, `Kernel`, `ServiceLocator`. These are the highest-leverage tests: they underpin every other component. Target: 90%+ coverage on each.

3. **`src/Piwigo/Ws/` — encoders and server.** `PwgJsonEncoder`, `PwgRestEncoder`, `PwgXmlWriter`, `PwgServer::addMethod()` / `::verifyParams()`. Pure logic with no DB dependency. Target: 85%+.

4. **`src/Piwigo/Search/` and `src/Piwigo/Calendar/`.** Search Q-classes are already partially covered. Calendar classes require a DB stub — add `AbstractDbStub` to `tests/Unit/stubs/` returning canned result sets.

5. **`src/Piwigo/Template/` — ScriptLoader and manifest logic.** `ScriptLoader::add()` with and without `dist/manifest.json` present. Create a temp directory fixture in `setUp/tearDown`.

6. **`src/Piwigo/Admin/Image/` — GD backend only.** GD is always available in the test container. `ImageGd::resize()`, `::rotate()`, `::flip()` against `dev/fixtures/sample.jpg`. Imagick/ext_imagick backends are integration-only.

### Verification

```bash
vendor/bin/phpunit --testsuite Unit --coverage-text | grep "Lines:"
# Target: ≥ 40.00%
```

---

## #30 — Delete empty `*.class.php` stub shims

**Status:** ✅ Done; manual plugin spot-check + `class_alias` decision deferred &nbsp;|&nbsp; **Size:** XS

### Goal

Delete the 17 five-line `*.class.php` files in `include/` and `admin/include/` that exist only as `// Class moved to src/Piwigo/ — autoloaded by Composer.` placeholders. Composer PSR-4 autoload already resolves the namespaced classes (`Piwigo\Admin\plugins`, etc.), and every first-party caller has been updated to `use Piwigo\…\…;` — the stubs themselves include nothing and have no real callers.

### Current state

- All 17 stubs deleted (`git rm`). Autoload class count stayed at 2239, confirming the stubs contributed no symbols.
- One additional cleanup beyond the original plan: `src/Piwigo/Admin/Updates.php` had a `foreach ($this->types as $type) { include_once(PHPWG_ROOT_PATH.'admin/include/'.$type.'.class.php'); ... }` loop loading three of the placeholders (`plugins.class.php`, `themes.class.php`, `languages.class.php`) — the autoloader handles the real classes, so the `include_once` was dead and now removed.
- `tools/list-classes.php` glob list trimmed: `*.class.php` patterns dropped (those files no longer exist), `*.inc.php` patterns kept.
- `tools/triggers_list.php` doc strings updated for `BlockManager`, `Template`, and `FileCombiner` (7 entries pointing at `src/Piwigo/Menu/...` and `src/Piwigo/Template/...`). The earlier entries for `tabsheet`, `pwg_image`, `check_integrity` were already fixed during #3.
- `src/Piwigo/Compat/aliases.php` still does not exist; no first-party caller needs it.

### Steps

1. `git rm` the 17 stub files. ✅
2. Run `vendor/bin/phpstan analyse --no-progress` and `vendor/bin/phpunit`. ✅ Both green.
3. Update path strings in `tools/triggers_list.php`. ✅
4. **Manual** — spot-check that a representative bundled plugin still loads (e.g. activate `nbc_ThemeChanger` in a test gallery). Cannot be done from this session; pending.
5. Decision pending — introduce `src/Piwigo/Compat/aliases.php` only when a concrete 3rd-party plugin breaks on the missing unqualified names. Today no first-party caller needs it.

### Verification

```bash
git ls-files 'include/*.class.php' 'admin/include/*.class.php'   # empty
composer dump-autoload --strict-psr                              # clean
vendor/bin/phpstan analyse --no-progress                         # green
vendor/bin/phpunit                                               # green
```

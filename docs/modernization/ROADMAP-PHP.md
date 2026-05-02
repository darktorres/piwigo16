# Piwigo 16.x — Modernization Roadmap (PHP)

PHP-only modernization work. See [MODERNIZATION.md](MODERNIZATION.md) for architecture context and completed phase summaries; see [ROADMAP-TS.md](ROADMAP-TS.md) and [ROADMAP-CSS.md](ROADMAP-CSS.md) for the other tracks.

---

## #1 — PSR-12 + Pint CI gate

**Status:** Not started &nbsp;|&nbsp; **Size:** S

### Goal

Enforce PSR-12 across the entire PHP codebase via Laravel Pint as a hard CI gate. This is the foundation item — every subsequent rename, file move, and class extraction lands on top of a consistent style baseline.

### Current state

- `vendor/bin/pint` already installed (`composer.json` `require-dev`).
- No `.editorconfig`, no `.php-cs-fixer.php`, no `phpcs.xml`.
- No CI step runs Pint; style drift is unbounded.
- `pint.json` configuration is minimal or absent.

### Steps

1. **Configure `pint.json`.** Pick the `psr12` preset; add explicit rules for any project-specific exceptions (e.g., trailing comma in multiline arrays). Commit the config alone first so the diff is reviewable.

2. **Baseline-format all PHP.** Run `vendor/bin/pint` once across `admin/`, `include/`, `src/`, `tests/`, `tools/`. This produces a single large mechanical-format commit. Avoid mixing it with logic changes.

3. **Add the CI job.** Append a `style` job to `.github/workflows/ci.yml`:
   ```yaml
   style:
     runs-on: ubuntu-latest
     steps:
       - uses: actions/checkout@v4
       - uses: shivammathur/setup-php@v2
         with: { php-version: '8.5' }
       - run: composer install --no-progress
       - run: vendor/bin/pint --test
   ```
   `pint --test` exits non-zero on any style violation.

4. **Add `.editorconfig`** at repo root with PSR-12 rules (LF, UTF-8, 4-space PHP indent, 2-space JSON/YAML, trim trailing whitespace, final newline).

5. **Document.** Add a `Style` section to `CONTRIBUTING.md` (or `README.md`) pointing at `vendor/bin/pint` and the optional pre-commit hook (`pint --dirty`).

### Verification

```bash
vendor/bin/pint --test     # exits 0 on a clean tree
git ls-files | xargs grep -l $'\r$' || echo "no CRLF files"   # editorconfig honored
```

CI fails any PR introducing PSR-12 violations.

---

## #2 — `declare(strict_types=1)` sweep

**Status:** Not started &nbsp;|&nbsp; **Size:** S

### Goal

Every file under `src/` declares `strict_types=1`. A PHPStan rule fails CI if a new file is added without it. `include/` is deferred to item #11, where each migrated module lands directly in `src/` with the declaration.

### Current state

- `src/`: partial coverage — the bulk of `Core/`, `Ws/`, `Template/` already declare strict_types; `Admin/`, `Calendar/`, `Db/`, and a few stragglers do not.
- `include/`: zero coverage — none of the legacy free-function modules use strict_types.
- No PHPStan rule enforces the declaration.

### Steps

1. **Audit.** `grep -rL 'declare(strict_types=1);' src/ --include='*.php'` lists offenders. Expect ~20–30 files.

2. **Fix in one pass.** Insert `declare(strict_types=1);` as the first line after `<?php` (with one blank line before the `namespace` line). Run the unit suite after — strict_types changes coercion behavior at the boundary, so latent bugs may surface here.

3. **Add `Piwigo\Phpstan\Rules\StrictTypesRequiredRule`** under `tools/phpstan/Rules/`. The rule fires when a file inside `src/` is missing the declaration. Register it in `phpstan.neon` alongside `NoGlobalInSrcRule`.

4. **Run PHPStan.** New rule should report zero hits on a clean sweep.

### Verification

```bash
grep -rL 'declare(strict_types=1);' src/ --include='*.php'   # empty
vendor/bin/phpstan analyse --no-progress                     # green, including new rule
```

---

## #3 — PSR-4 strict layout + PascalCase normalization

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

Composer's `--strict-psr` mode passes clean. Every class in `src/` lives in a PascalCase file matching its class name. Lowercase class names are renamed to PascalCase. The 9 legacy `include/*.class.php` files are moved into `src/Piwigo/<domain>/` with PascalCase. `class_alias()` shims keep plugins and unmigrated callers working.

### Current state

**Snake_case files in `src/` (8):**
- `src/Piwigo/Admin/plugins.php` (class `plugins`)
- `src/Piwigo/Admin/themes.php` (class `themes`)
- `src/Piwigo/Admin/languages.php` (class `languages`)
- `src/Piwigo/Admin/tabsheet.php` (class `tabsheet`)
- `src/Piwigo/Admin/Image/image_gd.php` (class `image_gd`)
- `src/Piwigo/Admin/Image/image_imagick.php`
- `src/Piwigo/Admin/Image/image_ext_imagick.php`
- `src/Piwigo/Admin/Image/pwg_image.php`

**Mixed-case violations (3):**
- `src/Piwigo/Admin/Image/imageInterface.php` (interface should be `ImageInterface`)
- `src/Piwigo/Admin/Integrity/c13y_internal.php`
- `src/Piwigo/Admin/Integrity/check_integrity.php`

**Legacy `.class.php` files in `include/` (9):**
- `include/Logger.class.php` → `src/Piwigo/Log/Logger.php`
- `include/block.class.php` → `src/Piwigo/Menu/Block.php`
- `include/cache.class.php` → `src/Piwigo/Cache/Cache.php`
- `include/calendar_base.class.php` → `src/Piwigo/Calendar/CalendarBase.php`
- `include/calendar_monthly.class.php` → `src/Piwigo/Calendar/CalendarMonthly.php`
- `include/calendar_weekly.class.php` → `src/Piwigo/Calendar/CalendarWeekly.php`
- `include/pwgsession.class.php` → `src/Piwigo/Session/PwgSession.php`
- `include/template.class.php` → `src/Piwigo/Template/Template.php` (if not already moved)
- `include/totp.class.php` → `src/Piwigo/Auth/Totp.php`

### Steps

1. **Rename `src/` files to PascalCase.** One `git mv` per file. Update the `class` declaration to match. Keep namespace unchanged.

2. **Rename lowercase classes.** `plugins` → `Plugins`, `themes` → `Themes`, `tabsheet` → `Tabsheet`, `languages` → `Languages`, `image_gd` → `ImageGd`, `image_imagick` → `ImageImagick`, `image_ext_imagick` → `ImageExtImagick`, `pwg_image` → `PwgImage`, `c13y_internal` → `C13yInternal`, `check_integrity` → `CheckIntegrity`. Add `class_alias(Plugins::class, 'plugins');` etc. in `src/Piwigo/Compat/aliases.php` so plugins can keep using the old names.

3. **Move `include/*.class.php` to `src/`.** For each:
   a. `git mv include/Foo.class.php src/Piwigo/<Domain>/Foo.php`.
   b. Add `namespace Piwigo\<Domain>;` and PSR-12 header.
   c. Replace `include/Foo.class.php` with a one-line `class_alias()` shim or rely on `src/Piwigo/Compat/aliases.php`.
   d. Update the `include_once` callsites in `common.inc.php` and elsewhere — the file no longer needs to be explicitly loaded once Composer autoload resolves it.

4. **Run `composer dump-autoload --strict-psr`.** This must exit clean. Any remaining violation is a PSR-4 bug that needs surgical fix.

5. **PHPStan rule.** Add `Piwigo\Phpstan\Rules\Psr4StrictRule` to keep new violations out (or just rely on `--strict-psr` in CI).

### Verification

```bash
composer dump-autoload --strict-psr   # zero warnings
vendor/bin/phpstan analyse            # green
vendor/bin/phpunit                    # green
npx playwright test                   # green (plugin compat alias chain works)
```

---

## #4 — Eliminate procedural `global` declarations across the codebase

**Status:** In progress (`src/` done; `admin/` and `include/` remain) &nbsp;|&nbsp; **Size:** L

### Goal

Zero `global $conf`, `global $user`, `global $page`, `global $lang`, `global $template` declarations anywhere in the application. All code accesses these through the typed service layer:

| Global       | Replacement                                                                  |
| ------------ | ---------------------------------------------------------------------------- |
| `$conf`      | `Piwigo\Core\Config::get(…)` / typed accessors (`Config::galleryTitle()` etc.) |
| `$page`      | `Piwigo\Core\PageState::current()->errors[]` etc.                            |
| `$lang`      | `Piwigo\Core\Lang::current()` / `Lang::get($key)`                            |
| `$user`      | `Piwigo\Users\CurrentUser::get()`                                            |
| `$template`  | `Piwigo\Template\TemplateRegistry::current()` (new — see step 1)             |

The reference-bridge pattern in `Config::attachGlobals()` and `PageState::attachGlobals()` makes the migration incremental: old `$conf['x']` and new `Config::get('x')` read/write the same backing storage, so plugins and untouched files keep working.

### Current state

- `src/`: **done** — `grep -rn "^global \$" src/` returns 0; `NoGlobalInSrcRule` enforces this in CI.
- `admin/`: **138** `global` statements across 72 files (largest concentrations: `include/add_core_tabs.inc.php` 21, `include/functions.php` 17, `include/functions_notification_by_mail.inc.php` 10, `include/functions_upload.inc.php` 9).
- `include/`: **158** `global` statements across 40 files (largest: `functions.inc.php` 17, `functions_user.inc.php` 15, `dblayer/functions_mysqli.inc.php` 12, `ws_functions/pwg.images.php` 12, `ws_functions/pwg.users.php` 12).
- PHPStan level 10 is the proxy metric: 1000+ errors at level 10 today (truncated output), ~75% trace back to `mixed` types from these unannotated `global` declarations. Hot-spot files: `cat_modify.php` (101 errors), `picture_modify.php` (76), `include/functions_notification_by_mail.inc.php` (57), `include/add_core_tabs.inc.php` (54), `include/functions.php` (41).

### Steps

1. **Add a `Template` accessor.** Introduce `Piwigo\Template\TemplateRegistry::current(): Template` and `::set(Template $t): void`, mirroring the contract of `Config::attachGlobals()`/`PageState::current()`. Wire `set()` from the two `$template = new Template(…)` sites in `include/common.inc.php`. Keep `$GLOBALS['template']` referencing the same instance during the migration window so untouched files (and plugins) still work.

2. **Migrate `include/` first** (40 files, smaller surface, more reused). Order bottom-up by dependency: leaf files first (`functions_*.inc.php`), orchestrating files (`common.inc.php`) last. Per file: drop the `global $conf, $user, $page, $lang, $template;` line and replace `$conf['x']` with `Config::get('x')`, `$page['errors'][] = …` with `PageState::current()->errors[] = …`, `$user['id']` with `CurrentUser::get()->id()`, `$template->assign(...)` with `TemplateRegistry::current()->assign(...)`. Commit per file or per logical group.

3. **Migrate `admin/`** (72 files). Work top-down by error-count hot spots: `cat_modify.php`, `picture_modify.php`, `include/functions_notification_by_mail.inc.php`, `include/add_core_tabs.inc.php`, `include/functions.php` first — these five account for ~30% of the level-10 error report.

4. **Extend the PHPStan rule.** Rename `NoGlobalInSrcRule` → `NoGlobalRule` (or add a sibling) covering `admin/` and `include/`. Activate as the last step so new `global` declarations fail CI.

5. **Drop the bootstrap stubs.** Once no caller references the globals, the `/** @var … */` annotations in `tools/phpstan-bootstrap.php` for `$conf`, `$user`, `$page`, `$lang`, `$template` (plus the auxiliary `$logger`, `$service`, etc. once their accessors land) can be removed. The bootstrap stays for `PHPWG_ROOT_PATH` and the plugin function signatures.

### Out of scope (decide separately)

The following globals also appear in `global` statements but are deferred — they need their own accessors before they can join `NoGlobalRule`: `$persistent_cache`, `$logger`, `$mysqli`, `$service`, `$filter`, `$pwg_loaded_plugins`, `$pwg_event_handlers`, `$prefixeTable`. Recommend including `$logger` and `$service` in this work; defer `$mysqli` to the Db layer effort.

### Verification

```bash
# Both must return zero hits in scope:
grep -rn "^[[:space:]]*global \$" admin/ include/ src/
grep -rnE "\bglobal \$(conf|user|page|lang|template)\b" admin/ include/ src/

# PHPStan level 10 must pass clean:
PHPSTAN_TABLE_ERROR_FORMATTER_FORCE_SHOW_ALL_ERRORS=1 vendor/bin/phpstan analyse --no-progress

# E2E smoke (admin pages still load, plugins still work):
npx playwright test
```

---

## #5 — Overdue TODO cleanup

**Status:** Not started &nbsp;|&nbsp; **Size:** S

### Goal

Resolve or formally defer all `TODO`/`FIXME` markers in tracked PHP files. Current count: **34 markers** in `src/` and `include/`.

### Current state (selected markers)

| File | Line | Marker |
|------|------|--------|
| `include/common.inc.php` | 167 | `// TODO remove this data update as soon as 2025 arrives` — **past-due** |
| `include/functions.inc.php` | 1832 | `return $str; // TODO` — stub return, function body missing |
| `include/functions_category.inc.php` | 530 | `// TODO 2.7: add an upgrade script…` — pre-16 remnant |
| `include/config_default.inc.php` | 990 | `//TODO: Put this in admin…` — design note |
| `include/ws_functions/pwg.php` | 846 | `/*TODO - no need to get a huge number of rows…*/` — SQL optimization |
| `include/search_filters.inc.php` | 71 | `// TODO calling get_available_tags()… may cost time` — performance note |
| `src/Piwigo/Admin/updates.php` | 474 | `// TODO why redirect to a plugin page?` — logic question |

### Steps

1. **Triage each marker.** For each TODO: (a) fix it now, (b) open a dated tracking comment `// DEFERRED until X: reason`, or (c) delete the dead code it annotates.

2. **`common.inc.php:167` — act first.** The 2025 past-due item is a data migration shim. Check whether the condition it guards is still reachable in 16.x; if not, delete the block.

3. **`functions.inc.php:1832`** — identify what the function was supposed to do and either implement it or remove the function entirely if no call sites reference it.

4. **`functions_category.inc.php:530`** — the 2.7 upgrade script it references is long gone (pre-16 floor). Delete the comment and the dead branch.

5. **SQL optimization TODOs** — convert to `// PERF:` comments with a concrete ticket reference so they are not confused with unfinished code.

### Verification

```bash
grep -rn "TODO\|FIXME" src/ include/ --include="*.php" | grep -v "vendor\|install/db" | wc -l
# target: 0 actionable markers (DEFERRED/PERF markers are acceptable)
```

---

## #6 — Remove class duplication in `ws_core.inc.php`

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

`include/ws_core.inc.php` currently defines `PwgError`, `PwgNamedArray`, `PwgNamedStruct`, `PwgRequestHandler`, `PwgResponseEncoder`, and `PwgServer` — the same six classes that live under `src/Piwigo/Ws/` and are loaded by Composer autoload. The include file is the authoritative source today; `src/Piwigo/Ws/` holds the PSR-4 copies. The task is to invert this: make `src/` canonical and reduce `include/ws_core.inc.php` to just the `WS_TYPE_*` / `WS_PARAM_*` constants.

### Current state

- `include/ws_core.inc.php`: 681 lines — defines all 6 classes + 10 `define()` constants.
- `src/Piwigo/Ws/PwgServer.php`: 467 lines — PSR-4 copy already exists.
- All class aliases in `src/Piwigo/Compat/aliases.php` let unqualified `PwgError` etc. keep resolving.

### Steps

1. **Verify `src/Piwigo/Ws/` is feature-complete.** Diff each class in `include/ws_core.inc.php` against its `src/` counterpart. Confirm all methods, properties, and typed annotations are present in the `src/` version. If anything is missing, backport it.

2. **Convert `WS_TYPE_*` constants to an enum (bonus — ties into #12).** The 10 `define()` constants (`WS_TYPE_BOOL`, `WS_TYPE_INT`, `WS_TYPE_FLOAT`, `WS_TYPE_POSITIVE`, `WS_TYPE_NEGATIVE`, `WS_TYPE_NOTNULL`, `WS_PARAM_ACCEPT_ARRAY`, `WS_PARAM_FORCE_ARRAY`, `WS_PARAM_OPTIONAL`) are bitmask flags used in `addMethod()` call sites. Introduce `Piwigo\Ws\WsType` and `Piwigo\Ws\WsParam` backed integer enums (or flag constants on the class). Update `ws_functions.inc.php` registration sites.

3. **Strip the class bodies from `include/ws_core.inc.php`.** Replace each class definition with a `class_alias` call pointing at the `Piwigo\Ws\*` counterpart, or simply rely on the existing `src/Piwigo/Compat/aliases.php`. Keep only the `define()` constants (or forward them from the new enum) and the `global` declaration at the top. The file shrinks from 681 to ~30 lines.

4. **Confirm `ws.php` still boots.** `ws.php` includes `ws_core.inc.php` before using any WS types. With the class bodies removed, it must receive the PSR-4 autoloaded versions instead. Run `npx playwright test tests/e2e/` — the `WsApiTest` exercises the live WS layer.

5. **PHPStan.** Level-9 errors in the WS layer should drop significantly once `src/Piwigo/Ws/` is the single source of truth.

### Verification

```bash
grep -c "^class " include/ws_core.inc.php   # must be 0
vendor/bin/phpunit --testsuite Integration  # WsApiTest green
npx playwright test                         # e2e green
```

---

## #7 — `@` error-suppression cleanup

**Status:** Not started (audit complete) &nbsp;|&nbsp; **Size:** M

### Goal

Eliminate the 254 `@` error-suppression sites across 73 files inventoried in [`error-suppression-audit.md`](error-suppression-audit.md). Each `@` is a hidden contract: it implies the call can fail and the failure is intentionally ignored. Replace with explicit handling or, where appropriate, document the suppression as deliberate.

### Current state

- 254 `@` sites across 73 files (per the existing audit).
- Top hot spots: file ops (`@unlink`, `@mkdir`, `@chmod`), network calls (`@fsockopen`, `@file_get_contents` over HTTP), deprecated function shims, and pre-PHP-8 type-juggling defenses that are no longer needed under strict_types.

### Steps

1. **Categorize from the audit doc.** Group sites into tiers:
   - **File ops** (~80 sites) — `@unlink`, `@mkdir`, `@rename`, `@chmod`. Replace with `if (!@unlink(...))` → `if (!is_writable(...) || !unlink(...))` plus typed exception throw.
   - **Network ops** (~40 sites) — wrap in try/catch with explicit timeout + error-handler set/restore.
   - **Type-juggling defenses** (~60 sites) — already unnecessary under strict_types (item #2). Drop the `@`.
   - **Deprecated function calls** (~30 sites) — replace the underlying call with the modern equivalent.
   - **Justified suppressions** (~40 sites) — keep, but add an explanatory comment: `/** @suppress: reason */`.

2. **One commit per tier.** Each tier is bounded enough to review in isolation.

3. **Lint rule.** After cleanup, add a PHPStan rule (`Piwigo\Phpstan\Rules\NoErrorSuppressionRule`) that fails on any new `@` outside files explicitly listed in a small allowlist.

### Verification

```bash
grep -rn '@\(' src/ include/ admin/ --include='*.php' | wc -l
# target: ≤ 40 (the justified set, all commented)
vendor/bin/phpstan analyse           # NoErrorSuppressionRule green
```

---

## #8 — Exception hierarchy + eliminate `die()`

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

Define a typed exception hierarchy under `Piwigo\Exception\`. Replace the 27 `die()` calls in `include/` with thrown exceptions. Replace generic `\Exception` throws with appropriate domain exceptions. Wire a top-level catcher in the front controller (item #13) so unhandled exceptions render proper error pages.

### Current state

- 27 `die()` calls in `include/` (mostly `dblayer/`, `common.inc.php`, install/upgrade flows).
- Generic `throw new \Exception(...)` in `include/dblayer/functions_mysqli.inc.php` and similar.
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

5. **Top-level handler stub.** Add `Piwigo\Bootstrap\ExceptionHandler::handle(\Throwable $e): void` that logs (via PSR-3 — item #9) and renders an error response. Wire into `set_exception_handler()` in `common.inc.php` for now; the front controller (item #13) takes over later.

### Verification

```bash
grep -rn 'die(' include/ admin/ --include='*.php' | wc -l       # 0 (excluding tests/ and dev/)
grep -rnE 'throw new \\?Exception\(' src/ include/ admin/ \
  --include='*.php' | wc -l                                       # 0
vendor/bin/phpstan analyse                                        # green
```

---

## #9 — PSR-3 Logger

**Status:** Not started &nbsp;|&nbsp; **Size:** S

### Goal

The Piwigo logger implements `Psr\Log\LoggerInterface`. Callsites use the standard leveled API (`debug`, `info`, `warning`, `error`, `critical`). Third-party plugins/handlers can be plugged in via the standard interface.

### Current state

- `include/Logger.class.php` exists (moved to `src/Piwigo/Log/Logger.php` by item #3).
- Custom API: `$logger->add($message, $level)`.
- No PSR-3 dependency in `composer.json`.

### Steps

1. **Add `psr/log`** to `composer.json` (`require: { "psr/log": "^3.0" }`). `composer update`.

2. **Implement `LoggerInterface`.** `Piwigo\Log\Logger` extends `Psr\Log\AbstractLogger` (provides leveled methods routed to a single `log()`). Implement `log(string $level, string|\Stringable $message, array $context = []): void`.

3. **Adapt the storage layer.** Keep the existing log-to-file or log-to-DB backend; just normalize the level string against `Psr\Log\LogLevel` constants.

4. **Migrate callsites.** Replace `$logger->add('foo', 'error')` with `$logger->error('foo')`. Sweep `include/` and `admin/`. For sites that pass dynamic levels, use `$logger->log($level, $msg)`.

5. **Optionally swap in Monolog.** Once the interface is the contract, the implementation can be replaced with `monolog/monolog` in `src/Piwigo/Log/Logger.php` factory without touching callsites. Defer the actual swap unless there's demand.

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

## #10 — PSR-11 DI container

**Status:** Not started &nbsp;|&nbsp; **Size:** M

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

6. **Migrate to constructor injection.** New service classes from item #11 receive their dependencies via constructor parameters instead of pulling from the locator. Existing services migrate opportunistically when touched.

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

## #11 — Migrate `include/functions_*.inc.php` to typed service classes

**Status:** Not started &nbsp;|&nbsp; **Size:** XL

### Goal

Move all 366 free functions across the 19 `functions_*.inc.php` modules into typed, namespaced classes under `src/Piwigo/<domain>/`. Each migrated function becomes a static or instance method on a domain class. Free-function wrappers stay during the transition (one-line delegates) so call sites keep working without a sweep.

This item supersedes the previously-narrower "functions_user.inc.php split"; that module is now the first checklist entry below.

### Current state

- **19 `functions_*.inc.php` modules** in `include/`, ~366 free functions total.
- Three modules already mix one class with their free functions (`ws_core.inc.php` — covered by item #6; `functions_search.inc.php`; `functions_plugins.inc.php`).
- 9 legacy `.class.php` files are migrated to `src/` by item #3 — they're the home for the new domain classes here.

### Per-module checklist

| Module | Lines | Funcs | Target namespace |
|--------|-------|-------|------------------|
| `functions_user.inc.php` | 2,673 | 63 | `Piwigo\Users\`, `Piwigo\Auth\` |
| `functions.inc.php` | ? | 81 | spread by domain — split first |
| `functions_category.inc.php` | ? | 17 | `Piwigo\Category\` |
| `functions_search.inc.php` | ? | 17 | `Piwigo\Search\` |
| `functions_url.inc.php` | ? | ? | `Piwigo\Url\` |
| `functions_html.inc.php` | ? | ? | `Piwigo\Html\` |
| `functions_session.inc.php` | ? | ? | `Piwigo\Session\` |
| `functions_picture.inc.php` | ? | ? | `Piwigo\Picture\` |
| `functions_tag.inc.php` | ? | ? | `Piwigo\Tag\` |
| `functions_rate.inc.php` | ? | ? | `Piwigo\Rate\` |
| `functions_comment.inc.php` | ? | 8 | `Piwigo\Comment\` |
| `functions_metadata.inc.php` | ? | 5 | `Piwigo\Metadata\` |
| `functions_mail.inc.php` | ? | ? | `Piwigo\Mail\` |
| `functions_notification.inc.php` | ? | ? | `Piwigo\Notification\` |
| `functions_filter.inc.php` | ? | ? | `Piwigo\Filter\` |
| `functions_plugins.inc.php` | ? | ? | `Piwigo\Plugin\` |
| `functions_cookie.inc.php` | ? | ? | `Piwigo\Auth\` |
| `dblayer/functions_mysqli.inc.php` | ? | ? | `Piwigo\Db\` |
| `ws_functions/*.php` | ? | ? | `Piwigo\Ws\Method\` |

(Question marks filled in during step 1 of each module.)

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

5. **Wire DI.** New class is registered in `config/container.php` (item #10). Constructor takes its dependencies (Config, Logger, etc.).

6. **Remove wrappers** once all callers in `include/`, `admin/`, and `src/` have been migrated to the new class.

7. **PHPStan.** New classes are clean at level 9 from day one — write them typed, don't retrofit.

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

## #12 — PHP 8.1–8.5 features: readonly, enum, match

**Status:** Not started &nbsp;|&nbsp; **Size:** M

### Goal

Adopt PHP 8.1–8.5 language features where they tighten invariants without changing public API. Targets: `readonly` properties on value objects, `enum` for flag/constant sets, `match` in place of exhaustive `switch` value maps.

### Current state

- `src/` has 68 classes; `include/` free functions are out of scope (covered by item #11).
- No `readonly`, `enum`, or `match` usage found in `src/`.
- `include/ws_core.inc.php` has 10 `define()` bitmask constants — enum candidates (linked to #6).

### Steps

1. **Readonly properties.** Audit `src/` for classes whose constructor assigns properties that are never written again. Candidates: `Piwigo\Ws\PwgError` (`$_code`, `$_codeText`), `Piwigo\Cache\PersistentFileCache` (path properties), all search Q-token classes. Apply `readonly` to each confirmed write-once property.

2. **Backed enums.** Replace `define()` bitmask constants with `enum` backed by `int`:
   - `WsType: int` — `BOOL = 0x01`, `INT = 0x02`, `FLOAT = 0x04`, `POSITIVE = 0x10`, `NEGATIVE = 0x20`, `NOTNULL = 0x40` (linked to #6).
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

## #13 — Single front controller + PSR-7/15 routing

**Status:** Not started &nbsp;|&nbsp; **Size:** XL (capstone)

### Goal

All HTTP requests enter through a single `public/index.php` front controller. The controller adapts the request to PSR-7, runs it through a PSR-15 middleware pipeline (error handler → session → auth → routing → controller dispatch), and emits a PSR-7 response. The 26 root-level `.php` files and the ~20 admin entrypoints are replaced by controller classes registered in a route table. URL config switches (`question_mark_in_urls`, `php_extension_in_urls`) are dropped — URLs are always rewritten by the web server.

This is the capstone item. It depends on items #1–#10 landing first: PSR-12 style, strict_types, PSR-4 layout, exception hierarchy, PSR-3 logger, PSR-11 container.

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

5. **Controllers.** Move each root entrypoint into `app/Controller/<Name>Controller.php` as a class implementing `__invoke(ServerRequestInterface): ResponseInterface`. The body becomes the existing logic adapted to read from the request and return a response.

6. **Middleware pipeline.** `Piwigo\Http\MiddlewarePipeline` runs:
   1. `ExceptionHandlerMiddleware` (catches `PiwigoException`, renders error response — depends on item #8)
   2. `SessionMiddleware` (start session, attach to request attributes)
   3. `AuthMiddleware` (resolve `CurrentUser`, attach to request)
   4. `RoutingMiddleware` (FastRoute dispatch)
   5. `ControllerInvokerMiddleware` (calls `__invoke` with route args, returns response)

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

## #14 — Unit test coverage expansion (13% → ≥40%)

**Status:** Not started (continuous) &nbsp;|&nbsp; **Size:** L

### Goal

Raise PHPUnit unit-test coverage from the current level to ≥40% of `src/` statements. Runs in parallel with every other item — not gated. Do not add DB/HTTP-dependent tests to the Unit suite; those belong in Integration or E2E.

### Current state

- **229 test methods** across `tests/Unit/` (Auth, Cache, Core, Image, Menu, Search, Session, Template, Users, Ws).
- Largest untested areas in `src/`: `Admin/` (image backends, plugins, themes, updates), `Calendar/`, `Db/`.

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

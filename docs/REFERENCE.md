# Reference

Current-state reference for `17.x-rewrite`: architecture, configuration,
deployment, development setup, operations, and the standing architecture
decisions behind them. Consolidated 2026-07-26 from `ARCHITECTURE.md`,
`CONFIG.md`, `DEPLOYMENT.md`, `DEVELOPMENT.md`, `RUNBOOK.md`, and
`docs/adr/*.md` — each claim below was re-verified directly against the
live codebase during the merge, not copied from the source docs as-is
(several had drifted badly; see `docs/PLAN.md` for the phase-by-phase
history of how this codebase got here).

## Architecture

### Namespace tree and layers

Every first-party class lives under `src/Piwigo\*`, PSR-4 autoloaded. 49
top-level namespace directories today (confirmed via direct listing),
each explicitly assigned to one of 6 layers in `deptrac.yaml` (no
catch-all regex — a new namespace has to be deliberately placed). `L0Data`
additionally reserves 3 namespace names (`Common`/`Event`/`Exception`)
that don't exist as directories at all yet — real reservations, not a
miscount, but not equally inert: `Common` specifically was meant to hold a
real typed-primitives layer (path/id/email value objects, domain enums,
generic DTOs) per the original plan's own P19 scope, and its total absence
is a genuine, previously-undocumented gap — see `docs/PLAN.md`'s P19
section for the full finding, found this round. `Event`/`Exception` have no
equivalent finding attached (not separately investigated to the same
depth):

| Layer | Namespaces |
| --- | --- |
| **L4 Integration** | `Admin` (+ `Admin\Image`, `Admin\Integrity`), `Bootstrap`, `Command`, `Controller`, `Job`, `Ws` (+ `Ws\Encoder`, `Ws\Protocol`) |
| **L3 Presentation** | `Html`, `Http` (+ `Http\Middleware`), `Mail`, `Menu`, `Page`, `Picture`, `Routing`, `Template`; reserves `Asset`/`Listener` (not directories yet, same as L0Data's reserved names) |
| **L2b Extended Domain** | `Activity`, `Caddie`, `Calendar`, `Comment`, `Csrf`, `Feed`, `Filter`, `History`, `Metadata`, `Notification`, `Permalink`, `PluginConfig\PluginRepository`, `Rate`, `Search`, `Section`, `Site`, `Telemetry`, `Url` |
| **L2a Core Domain** | `Auth`, `Category`, `Group`, `Image`, `Permission`, `Tag`, `Users` |
| **L1 Infrastructure** | `Audit`, `Backup`, `Cache`, `Config`, `Core`, `Db`, `Lang`, `PluginConfig\EventDispatcher`, `Session`, `Storage`, `Validation` |
| **L0 Data** | `Common`, `Event`, `Exception` (reserved, no classes yet) |

Each layer may depend only on itself or a layer below it. **0 violations,
no `skip_violations` entries** — `vendor/bin/deptrac analyse` runs
unconditionally in CI (`deptrac` job), genuinely blocking.

`deptrac.yaml`'s own inline comments are the authoritative record of *why*
each namespace landed where it did (real dependency edges found via
`deptrac analyse` itself, not guessed placement) — read it directly for a
specific namespace's placement reasoning rather than this table alone.
Two structural notes worth surfacing here:

- **`PluginConfig` is split across two layers** — `EventDispatcher` sits at
  L1Infrastructure (a generic pub/sub bus reachable from every layer,
  injects nothing), `PluginRepository` stays at L2bExtendedDomain (its only
  real caller is L4Integration). Retargeting the legacy string-keyed event
  functions onto `EventDispatcher::get()` directly (rather than a free
  function) is what surfaced this split — a free-function call creates no
  deptrac dependency edge, a direct class reference does.
- **`MailService` implements `Core\MailerInterface`** (L1Infrastructure)
  specifically so `Users`/`Comment`/`Search`/`Section` (L2a/L2b) can
  constructor-inject it without an illegal upward dependency on
  `Mail` itself (L3Presentation, since `MailService` needs `Template` for
  themed HTML email). Same interface-inversion pattern used wherever an L2
  domain class needs an L3 capability.

### Kernel, DI container, and boot sequence

`public/index.php` (and every other root entry point, including `i.php`) →
`Piwigo\Bootstrap\RequestBootstrap::bootEntryPoint($paths)` — which itself
runs `configure()` (calls `Kernel::boot()` first thing, builds the DI
container), `connect()`, then `finalize()` — then
`Piwigo\Bootstrap\RequestPipeline::handle(RequestFactory::fromGlobals())`
runs the real request through the full PSR-15 middleware pipeline and
routes it to a controller — this is live on every real HTTP request, not a
built-but-unused code path. `CommonBootstrap` does not exist — an earlier
class of that name was retired and its steps absorbed into
`RequestBootstrap` directly; a separate `RequestBootstrap::bootConfigOnly()`
method exists (the lighter, config-only predecessor shape) but is
documented in its own docblock as unused by any real production route,
exercised only by a dedicated Unit test.

`Kernel` is the one intentional static class in the codebase — it exists
to bootstrap the container that makes everything else constructor-
injectable, idempotent (`self::$booted` guard). `Kernel::container()` (a
service-locator escape hatch) is restricted by an Arch test to
`src/Piwigo/Bootstrap/` **only** today — the test scans root `*.php`
files and `bin/*` too, but exempts neither; its own docblock notes the
`public/index.php` exemption this allowlist used to carry is gone,
since `index.php` has been pure bootstrap-and-dispatch through
`RequestBootstrap::bootEntryPoint()` since P22 and never called
`Kernel::container()` directly itself. Worth surfacing from the original
P6/P12-era architecture doc (recovered from git history for this check,
otherwise not carried into this consolidation): `Kernel::service()` — the
pre-rewrite procedural codebase's ~230-call-site service locator — was
never introduced at all; `Kernel::container()`'s Arch-test restriction
exists specifically so that broad, ambient pattern can't creep back in
through the new kernel.

`Piwigo\Core\Container::build()` wraps `DI\ContainerBuilder` loading
`config/container.php` — PHP-DI autowires by default (a constructor with
only class-typed params needs zero container entry); `config/container.php`
has 33 explicit entries today, for interface bindings
(`CacheItemPoolInterface`, `Psr\SimpleCache\CacheInterface`,
`Psr\Log\LoggerInterface`, `MailerInterface`, `ActivityLoggerInterface`,
`FilterUpdaterInterface`, `HtmlRenderingInterface`, `TemplateInterface`,
and others) and non-obvious construction — grows one entry at a time as a
concrete class needs one, never bulk-copied.

### HTTP middleware pipeline and routing

`Piwigo\Http\MiddlewarePipeline` (PSR-15, immutable recursive peel),
orchestrated by `Piwigo\Bootstrap\RequestPipeline`. Current roster, in
order: `ExceptionHandlerMiddleware` → `SecurityHeadersMiddleware` →
`SessionMiddleware` → `ServerTimingMiddleware` → `SentryMiddleware` →
`RoutingMiddleware` → `ControllerInvokerMiddleware`. `Piwigo\Routing\Router`
wraps `symfony/routing`; `config/routes.php` has 23 real routes today,
resolved to `Piwigo\Controller\*` classes (L4Integration).

### Cache

`Piwigo\Cache\CacheFactory` selects a `symfony/cache` PSR-6 adapter via
`PIWIGO_CACHE_ADAPTER` (`apcu` | `redis` | `filesystem`; auto-detects APCu
if loaded, else filesystem, when unset). Both `CacheItemPoolInterface` and
`Psr\SimpleCache\CacheInterface` bind to the same pool in
`config/container.php` (PSR-16 wraps it via `Symfony\Component\Cache\Psr16Cache`).
Coexists with two separate legacy mechanisms `bin/piwigo cache:clear`
deliberately does **not** touch: `Piwigo\Cache\PersistentCache`
(`_data/cache/*.cache` files, still real — used by
`MaintenanceActionDispatcher` via `CurrentPersistentCache::get()`) and the
legacy Smarty compiled-template files (`_data/templates_c/*.tpl.php`,
separate from the Latte compiled-template cache `cache:clear` does purge).
`cache:clear` only purges what the new infrastructure owns: the Latte
compiled-template cache dir and the `CacheFactory` PSR-6 pool.

**Session storage is separate from this pool** — `Piwigo\Session\PwgSession`
(`SessionHandlerInterface`) is registered via
`Piwigo\Bootstrap\SessionBootstrap::register()` on every real request, still
DB-backed, not routed through `CacheFactory`. To move sessions onto Redis
instead, set `session.save_handler`/`session.save_path` in `php.ini`
directly — a deployment config choice PHP's session extension handles
natively, no code change.

### Async jobs (Messenger)

`config/messenger.php` is real and wired — 5 `Piwigo\Job\*` message classes
(`SendNotificationEmailJob`, `GenerateDerivativeJob`, `BatchUploadJob`,
`ReindexImagesJob`, `RegenerateAllDerivativesJob`) with matching
`Piwigo\Job\Handler\*` handlers, transport table `messenger_messages`.

### CLI (`bin/piwigo`)

Delegates to `Piwigo\Bootstrap\CliBootstrap`, which boots the same
Kernel/DI container as the HTTP path and resolves each command in
`config/commands.php` (autowired). `Piwigo\Core\ShutdownHandler` wires
`SIGTERM` (`ext-pcntl`, a hard `composer.json` requirement) to run
registered cleanup callbacks — `Piwigo\Backup\BackupService` uses this so
an interrupted `backup:create`/`restore` doesn't leave temp files behind.

Current commands: `cache:clear`, `backup:create`, `backup:restore <file>
--force [--database=NAME]`, `user:list`, `maintenance:orphan-tags`,
`maintenance:purge-history`, `maintenance:purge-sessions`.

**Known gap**: `maintenance:repair-db` doesn't exist. The backing logic
(`Piwigo\Admin\Maintenance\DbMaintenanceRepository::repairOptimizeAllTables()`)
is real and works, but is only ever called from the admin web UI
(`MaintenanceActionDispatcher`) — the CLI wrapper was planned early
alongside the other 3 `maintenance:*` commands but never built. See
`docs/PLAN.md`'s gap-closure record for the full history.

### What's genuinely not built yet

- **FrankenPHP worker mode** — `docker/Caddyfile` runs plain `php_server`
  (classic per-request execution). The app boots FrankenPHP as its runtime,
  but not in worker mode (app kept booted in memory across requests) —
  `ADR-0013` decided to build this, it hasn't happened. See "Known gaps"
  below.
- **Doctrine Migrations** — replaced entirely by one static
  `install/piwigo_structure-mysql.sql` (a later decision reversed the
  original plan; see `ADR-0002`'s amendment below).
- **`bin/piwigo import:legacy`** — the one-way legacy-install migration
  tool `ADR-0002` describes doesn't exist yet.
- **opcache.preload** — `config/preload.php` exists and lists hot classes,
  but isn't enabled in any shipped `php.ini`; this is left as a
  deployment-time optimization, not something CI/local dev needs.
- **The image-derivative fast path (`bootMinimal`)** — the plan committed to
  a stripped request path for image derivatives specifically (the highest-
  volume request type on a photo gallery): skip the full DI container/
  middleware boot, check permissions with 1-2 cheap queries, then hand the
  file to the web server via `X-Sendfile`/`X-Accel-Redirect` for a
  zero-copy `sendfile()` response — never have PHP read the file itself.
  Confirmed directly: `public/i.php` boots through the exact same
  `RequestBootstrap::bootEntryPoint()` + `RequestPipeline::handle()` path
  as every other entry point (no branch, no reduced boot), and
  `ImageDerivativeController` reads the file into memory
  (`stream_get_contents()`) and returns it as a normal response body — no
  `X-Sendfile` anywhere in the codebase. The permission-check *logic* this
  design needed did get built and is real (`Piwigo\Permission\
  ImageVisibilityChecker`, `Piwigo\Session\SessionUserResolver` — both
  correctly avoid recomputing permissions live, reading precomputed data
  instead), but the fast-boot/zero-copy-serve half of the design was
  never implemented. A separate `RequestBootstrap::bootConfigOnly()`
  method exists and is explicitly documented as unused in production
  ("No real production route needs this method anymore"), confirming this
  isn't a case of me missing where the fast path lives — it genuinely
  isn't wired to anything.

## Configuration

`Piwigo\Config\CurrentConfig` is the single typed source of truth for every
runtime configuration property — one private static typed property per
key, with a named getter/setter (no generic string-keyed accessor).
`Piwigo\Config\ConfigService` (reached via `CurrentConfigService::get()`)
is the DB-backed persistence layer reading/writing the `config` table on
top of it. DB connection credentials and the sysadmin-lockable settings
live on separate classes (`Piwigo\Db\DbCredentials`, env-only;
`Piwigo\Config\DeploymentPolicy`, file-only).

There is no generated reference table for this anymore — `tools/build-config-docs.php`
and the doc it wrote (`docs/CONFIG.md`) were both removed: the generator
was never wired into CI or any hook, so the table it produced silently
drifted out of sync with the real properties (confirmed by running it
during this consolidation — it produced an immediate diff against the
committed table). `CurrentConfig.php`'s own per-property docblocks
(`#[Required]`/`#[Sensitive]` attributes included) are the real source of
truth — read the class directly.

## Deployment

Covers running the image built by `./Dockerfile` — standalone, via
Compose, or via Helm.

### The image

`docker build --target production .` — FrankenPHP (Caddy + PHP 8.5, per
`ADR-0013`). `docker build --target production-apache .` is the fallback
for hosts needing classic Apache/`mod_rewrite`. Both listen on `:80` as
non-root `www-data`; FrankenPHP/Caddy wants `CAP_NET_BIND_SERVICE` at
startup regardless of which port it ends up binding, so there's no
capability-reduction benefit to a high port — `cap_drop: [ALL]` + one
narrow `cap_add: [NET_BIND_SERVICE]` exception is the real hardening.

Writable at runtime, everything else read-only: `_data/` (cache),
`local/` (config + install sentinel), `galleries/` (photo storage),
`upload/` (incoming uploads). Mount all four as volumes.

### Image signing (SEC-54)

`.github/workflows/release-image.yml` builds the `production` target,
pushes to `ghcr.io/<repo>`, signs with keyless `cosign` (Fulcio/Rekor bind
the signature to that workflow run's GitHub Actions OIDC identity). Only
runs on a published GitHub Release, never every push. Verify before
deploying a pulled image:

```
cosign verify \
  --certificate-identity-regexp "https://github.com/<owner>/<repo>/.github/workflows/release-image.yml@.*" \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  ghcr.io/<owner>/<repo>:<tag>
```

### Standalone / Compose / Kubernetes

```
docker run -d --cap-drop=ALL --cap-add=NET_BIND_SERVICE --security-opt no-new-privileges \
  -p 8080:80 -e PIWIGO_DB_HOST=<host> -e PIWIGO_DB_USER=<user> \
  -e PIWIGO_DB_PASSWORD=<password> -e PIWIGO_DB_BASE=<database> \
  -v piwigo_data:/app/_data -v piwigo_local:/app/local \
  -v piwigo_galleries:/app/galleries -v piwigo_upload:/app/upload piwigo
```

`docker compose up` — app + `mysql:9.7` (`--container-aware=ON`) +
`redis:7-alpine` (provisioned, used by `CacheFactory`'s `redis` adapter
when selected; not used for sessions — see Cache above).
`docker compose --profile test run test` runs the Vitest suite in a
container — the PHP Integration/Contract/Browser/Visual suites stay on
bare-metal CI (they need a live DB + webserver, or a full Chromium stack).

```
helm install piwigo deploy/helm/piwigo \
  --set image.repository=<your-registry>/piwigo --set image.tag=<tag> \
  --set db.host=<mysql-service> --set db.existingSecret=<secret-name>
```

`db.existingSecret` (key `db-password`) is the only supported way to
supply the DB password — never `--set db.password=...`, which lands in
`helm history`/`helm get values` in plaintext. `values.yaml` defaults:
`ClusterIP` Service, Ingress disabled, 1 replica, the same hardening as
Compose (below), 4 PVCs matching the 4 writable directories. Set
`ingress.enabled=true` plus `ingress.host`/`ingress.className`/`ingress.tls`
for external access.

### Runtime hardening

Both Compose and Helm run: non-root (`www-data`, uid 33), `cap_drop: [ALL]`
+ `cap_add: [NET_BIND_SERVICE]`, `security_opt: no-new-privileges`,
`seccompProfile: RuntimeDefault`, `readOnlyRootFilesystem` (`/tmp` as
tmpfs/emptyDir, the four writable directories as the only other mounts).

### Web root (SEC-01, SEC-33, SEC-35, SEC-38, SEC-47)

Document root is `public/`, not the repo root — every PHP entry point
lives there (including `public/admin/popuphelp.php`, a real file, not a
themes symlink — `public/admin/` holds exactly that one entry point)
plus 3 symlinks for static assets real requests need: `public/dist ->
../dist`, `public/themes -> ../themes` (covers admin theme assets too,
since those live at `themes/admin/`, a subdirectory of the same symlinked
tree — no separate `admin/themes/` symlink exists or is needed), and the
nested `public/_data/combined -> ../../_data/combined` (`public/_data/`
itself is a real, otherwise-empty directory holding just that one
symlink). Everything else — `upload/`, `galleries/`, `local/`,
`language/`, `plugins/`, every other `_data/` subdirectory — is
structurally unreachable: no rule needed, requests 404 like any
nonexistent path. **Why this matters, not just what it does** (carried
forward from the original deployment doc, otherwise only in git history):
before document-root isolation, a private album's image derivatives could
be served to anyone who knew/guessed the URL, forever, without ever
re-triggering the permission check once a derivative was cached on disk —
found live during that work's own investigation, not a hypothetical risk.
`config/`, `tools/`, `dev/`,
`src/`, `tests/`, `install/` (the directory — `install.php` itself stays
reachable), `vendor/`, `node_modules/`, `docs/`, `deploy/`, `.git/`, plus
sensitive root-level tooling configs (`composer.json`, `phpstan.neon`,
`.env*`, any root `*.ts` config, etc.) must never be served directly, on
every supported front end:

- **Apache**: `public/.htaccess`.
- **FrankenPHP/Caddy**: `docker/Caddyfile` (baked into the production
  image).
- **nginx** (not a runtime this project ships an image for, but commonly
  fronting one, and not covered by CI's deny-rule jobs the way the other
  two are — docs-only guidance):

  ```nginx
  location ~ ^/(config|tools|dev|src|tests|install|vendor|node_modules|docs|deploy|\.git)/ {
      return 403;
  }

  location ~ ^/(composer\.(json|lock)|package\.json|bun\.lock|phpstan\.neon|psalm\.xml|rector\.php|ecs\.php|knip\.json|lefthook\.yml|tsconfig\.json|\.stylelintrc\.json|\.prettierrc\.json|lighthouserc\.json|\.size-limit\.json|renovate\.json|release-please-config\.json|\.release-please-manifest\.json|eslint-suppressions\.json|\.editorconfig|\.gitignore|\.dockerignore|Dockerfile|docker-compose\.ya?ml|justfile|\.env.*|[^/]+\.ts)$ {
      return 403;
  }

  location = /health { rewrite ^ /health.php last; }
  location = /ready { rewrite ^ /ready.php last; }

  location ~ \.[0-9a-f]{8}\.(js|css|woff2|avif|webp|png|jpg)$ {
      add_header Cache-Control "public, max-age=31536000, immutable";
  }

  location ~ ^/upload/.*\.(svg|html?)$ {
      add_header Content-Disposition attachment;
  }
  ```

All exercised by CI's `apache-deny-rules`/`container-deny-rules` jobs
against a real on-disk fixture file at each denied path (nginx excepted,
per above — this project doesn't ship an nginx image to test against).

[SEC-21] uploaded SVG/HTML is forced to download (`Content-Disposition:
attachment`) rather than render inline, same three-target split.

### Environment variables

See `.env.example` for the full reference (`PIWIGO_DB_HOST`/`USER`/
`PASSWORD`/`BASE`/`PREFIX`). In containers these are set directly
(compose `environment:`, Helm chart env) rather than via a file —
`Piwigo\Core\Env::loadEnvFile()` reads `getenv()` either way, so no `.env`
file needs to exist inside the image.

## Development

### Requirements

- PHP 8.5 (`ext-calendar`, `ext-ctype`, `ext-curl`, `ext-dom`,
  `ext-fileinfo`, `ext-filter`, `ext-gd`, `ext-iconv`, `ext-intl`,
  `ext-libxml`, `ext-mbstring`, `ext-mysqli`, `ext-openssl`, `ext-pcntl`,
  `ext-session`, `ext-simplexml`, `ext-zip`, `ext-zlib`; `pcov` for
  coverage)
- Composer 2.x
- Node 24, bun, [`just`](https://github.com/casey/just)
- MySQL 9.7 (or MariaDB 12.x / PostgreSQL 18 — see `ADR-0003`'s provider
  matrix) + a webserver serving this checkout, for anything beyond
  `composer test`

### Setup

```
composer install
bun install
node_modules/.bin/playwright install chromium
cp .env.example .env.test   # fill in the .env.test block; see Tests below
```

`just` runs recipes across both stacks (`just --list`).

### Tools

| Command | What it does |
| --- | --- |
| `composer test` | Pest `Unit`+`Arch` — fast, no DB/webserver |
| `composer analyse:phpstan` | PHPStan — the sole **blocking** static-analysis gate |
| `composer analyse:psalm` | Psalm — manual only, not gated (see `ADR-0026`) |
| `composer analyse` | Both of the above together |
| `composer lint:php` | ECS in check mode — **still not blocking** (see CI below) |
| `composer require-checker` | Composer-require-checker |
| `composer unused` | Composer-unused |
| `composer bench` | PHPBench (`tests/Bench/`, one real subject so far — `KernelBootBench::benchColdBoot()`, landed P11; no others yet) |
| `composer test:coverage` | Pest `Unit`+`Arch` with pcov coverage |
| `composer test:coverage:integration`/`:web`/`:all` | Wider pcov coverage variants — Integration alone, Browser/Contract ("web"), or the full combined measurement across all 5 suites |
| `composer sbom` | CycloneDX SBOM for Composer deps |
| `bun run build` | Vite build — 2 real entries today (`noop` placeholder, `vitals` — the web-vitals RUM beacon) |
| `bun run dev` | Vite dev server |
| `bun run test` | Vitest (TS unit — Pest can't execute these) |
| `bun run typecheck` | `tsc --noEmit` — TypeScript type checking, no build output |
| `bun run lint:js` (`:fix`) | ESLint against `eslint-suppressions.json` |
| `bun run lint:css` (`:fix`) | Stylelint |
| `bun run format` (`:fix`) | Prettier check / write |
| `bun run knip` | Unused files/exports/dependencies in JS/TS |
| `bun run size-limit` | Bundle size budget |

PHPStan has **no baseline file** (`phpstan-baseline.neon` was deleted once
the codebase reached a clean run — CI runs `phpstan analyse` with zero
suppressions). Psalm's `psalm-baseline.xml` is also gone from the repo,
even though `psalm.xml` and the `vimeo/psalm` dependency stay (per
`ADR-0026`'s "dormant, not deleted" intent for the tool itself — the
baseline specifically didn't survive).

### Tests

| Command | What it does |
| --- | --- |
| `composer test:integration` | Pest `Integration` — needs `.env.test` + `piwigo_test` DB |
| `composer test:contract` | Pest `Contract` — WS API contract tests against the committed fixture |
| `composer test:browser` | Pest `Browser` — E2E via `pest-plugin-browser` (Chromium) |
| `composer test:visual` | Visual regression only — **run in isolation**, see below |
| `composer test:install` | Install-flow E2E only (its own Browser group) |
| `composer test:fixture-regen` | Rebuilds `tests/Fixtures/piwigo-17.0.sql` from a fresh install + seed |

Tests run against a throw-away `piwigo_test` database, never production.
`PIWIGO_BASE_URL` must point at a running webserver for this checkout —
Integration/Contract/Browser all make real HTTP requests.

The env-switch mechanism (`Piwigo\Core\Env`, wired into request
bootstrap): an `X-Piwigo-Env: test` header, honored only from loopback,
switches the runtime to read `.env.test` and gate on
`local/.installed.test` instead of `.env`/`local/.installed`.
`tests/bootstrap.php` sets this for the whole Pest CLI process; Browser
tests set it per-context via Playwright's `extraHTTPHeaders`.

**Fixture**: `tests/Fixtures/piwigo-17.0.sql`, a committed dump
(`fixture_admin`/`fixture_admin`, seed content). `composer test:integration`/
`test:contract`/`test:browser`/`test:visual` **all self-provision a
pristine DB before running** — none depend on run order or on
`test:fixture-regen` having been run first.

**Contract tests**: `tests/Contract/ContractTestCase` drives the WS API
over curl, validating against JSON Schema files in `tests/Contract/schemas/`.
21 `Ws*Test` classes lock the legacy WS response shapes for as long as the
WS API exists — a later phase (not yet started) removes it in favor of a
REST `/api/v1` and retires these in favor of REST contract tests.

**Browser tests**: 40 files in `tests/Browser/` (38 E2E flows, plus the
two special-purpose files below) via `pestphp/pest-plugin-browser`.
`tests/Browser/Helpers/BrowserTestHelpers.php` centralizes the shared
patterns (`visitPwg()`/`loginAsAdmin()`, `navigateOk()`, `wsCall()`,
`uploadPhotoViaApi()`).

**Visual regression**: `tests/Browser/VisualRegressionTest.php` — 35
screenshot baselines (33 routes iterated in a data-driven loop + 2
standalone tests, `picture-1` and `admin-photo-editor`) via Pest's native
`assertScreenshotMatches()`
(`tests/.pest/snapshots/`). **Must run in isolation** —
`composer test:visual`, never bundled with CRUD-mutating Browser tests
(those drift the sidebar's live counts, producing false diffs).
Re-baseline after an intentional visual change:

```
vendor/bin/pest tests/Browser/VisualRegressionTest.php --update-snapshots
```

Several non-obvious determinism/reliability fixes are load-bearing here,
not optional cleanup:

- `BrowserTestHelpers::freezeImageHits()`/`truncateHistory()` pin
  hit-counters and history state before screenshots that would otherwise
  show live counts.
- A `PIWIGO_TEST_NOW` env var (read only in test mode) freezes "now" for
  every `new DateTime()`-based computation the dashboard/activity views
  render — zero behavior change in production (unset → real
  `new DateTime()`).
- `tests/Browser/RegenerateFixtureTest.php` disables the fixture's
  outbound `get_piwigo_news()`/update-check calls, since neither is
  screenshot-relevant but both make real live network calls otherwise.
- `BrowserTestHelpers::waitUntilHidden()` polls for an async loading
  spinner to actually disappear before a screenshot — `assertSee()`/
  `assertMissing()` are one-shot checks, not retrying.
- **Always use `tools/pest-cleanup.sh`**, never a bare `pest`/`vendor/bin/pest`
  invocation for Browser/Visual suites — `pest-plugin-browser`'s own
  `playwright run-server` subprocess isn't cleaned up on exit (unmerged
  upstream bug, `pestphp/pest-plugin-browser#169`); the wrapper force-kills
  any orphaned instance before and after a run. A bare invocation also
  skips the `fixture-regen`/`visual-regression` group exclusions the real
  `composer test:*` scripts apply.
- `IntegrationTestCase::settleDatabase()` polls a real table after fixture
  reimport before returning control — a cold InnoDB buffer pool on a
  freshly-recreated schema can otherwise cause a transient timeout on the
  very next test.

Always confirm a browser-test failure via an isolated re-run before
concluding it's real — never dismiss (or accept) a failure on sight.

### CI

`.github/workflows/ci.yml` runs on every push/PR (docs-only changes
excluded via `paths-ignore`). Current job list (29 jobs): `pest`, `ecs`,
`phpstan`, `rector`, `coverage`, `audit`, `deptrac`, `require-checker`,
`composer-unused`, `phpbench`, `vitest`, `eslint`, `stylelint`, `knip`,
`size-limit`, `k6-load` (no-op until a load-test track lands), `commitlint`,
`actionlint`, `test-file-inventory`, `integration`, `contract`, `browser`,
`install-flow`, `visual-regression`, `restore-drill`, `lighthouse`,
`sbom`, `apache-deny-rules`, `container-deny-rules`.

Separate workflow files, independent of `ci.yml`: `osv-scanner.yml`/
`scorecard.yml` (SEC-52/SEC-64, weekly + push/PR), `release-please.yml`
(targets `17.x-rewrite` explicitly — this repo's actual GitHub default
branch, `16.x-rewrite`, is an unrelated earlier rewrite lineage),
`release-image.yml` (image build + signing, on release only).

**Known gap, not just doc staleness**: `ecs` and `rector` are still
`continue-on-error: true` (non-blocking), each with an inline comment
saying so "until P5" — but the phase that comment refers to completed long
ago (fold-in note: verify P5's real completion status in `docs/PLAN.md`
before treating this as settled; the CI file itself hasn't been revisited
to make either job blocking since). Likewise, `ADR-0026` says Psalm gating
resumes "once P6 and P17-P23... have moved enough of the codebase" into
namespaced classes — both are done, but Psalm gating hasn't been
reconsidered since. Three separate instances of the same pattern: a
phase-conditioned deferral whose condition has since been met, never
revisited.

Every DB-backed job gets a real ephemeral `mysql:9.7` service container,
fixture imported fresh via `mysql < tests/Fixtures/piwigo-17.0.sql`.
`integration` runs directly in-process against that DB — no webserver
involved at all. `contract`/`browser`/`visual-regression`/`install-flow`/
`lighthouse` each start PHP's built-in server (`php -S`) themselves and
drive it over real HTTP, not Apache (`install-flow` is the one exception
serving from `public/` specifically, matching real document-root
behavior; the others serve from the repo root). Two additional jobs
(`apache-deny-rules`, `container-deny-rules`) serve the checkout via real
Apache and the built production image respectively, purely to prove the
SEC-01 deny rules on every server this project ships.

Three non-obvious, still-current facts about specific jobs (recovered from
the original per-phase dev doc this round, otherwise only in the workflow
file's own comments): **`test-file-inventory`** exists specifically to
catch a testsuite silently running 0 tests (Pest exits 0 on a `--filter`
that matches nothing in a non-empty suite, so file presence is the only
reliable signal) — its own job name cites this as "the 16.x-v2 lesson," a
real regression on a different, earlier rewrite branch that also lives in
this repo's git history. **`audit`** (`bun audit` + OSV-Scanner) ignores 3
specific GHSAs (`GHSA-52f5-9888-hmc6`, `GHSA-ph9p-34f9-6g65`,
`GHSA-w5hq-g745-h8pq`, confirmed still present in both `osv-scanner.toml`
and `ci.yml`) — transitive `tmp`/`uuid` pins inside `@lhci/cli` (dev-only,
never shipped), no fixed upstream release; re-check both places on every
`@lhci/cli` bump. **`sbom`**'s JS half runs a throwaway
`npm install --package-lock-only --ignore-scripts` first, confirmed still
real — `@cyclonedx/cyclonedx-npm` doesn't read `bun.lock` directly, so this
generates a disposable npm-format lockfile just for the SBOM tool; `bun.lock`
stays the real, authoritative lockfile.

## Runbook

### Bootstrap a clean checkout

```
composer install && composer dump-autoload && bun install
```

(`composer.json`'s `config` block does not set `classmap-authoritative` —
an earlier note said this would be added once PSR-4 namespaces existed;
that never happened. Not a blocker, just an open, small optimization.)

### Gates

`composer test`, `composer analyse`, `composer lint:php`,
`composer require-checker`, `composer unused`, `composer bench` — see
Development above for what each does and its current status.

### Test database

`composer test` (Unit+Arch) needs nothing beyond the bootstrap above.
Everything else needs a real MySQL instance and a webserver — see
Development → Tests above for the full setup.

### CI

Unlike local dev, CI provisions its own ephemeral `piwigo_test` per run
and serves the checkout via `php -S` rather than Apache for the main
suites — see Development → CI above for the full job list.

### Incident response

1. **Identify**: `/health` (liveness) and `/ready` (readiness — can it
   reach the DB) — plain-text `200`/`503`, no auth, safe to poll.
   Container/Helm-level: `docker logs <container>` or
   `kubectl logs deploy/<release>-piwigo`.
2. **Contain**: scale the affected deployment to 0 if the incident is
   active data corruption or an in-progress compromise — don't just
   restart, a restart can destroy forensic state.
3. **Diagnose**: pull the SBOM (`sbom-composer.cdx.json`) and OSV-Scanner/
   Scorecard history if the trigger might be a dependency CVE. Check
   `local/config/config.inc.php` and the `PIWIGO_DB_*`/`PIWIGO_*` env vars
   for unauthorized changes.
4. **Eradicate + recover**: redeploy from a known-good image tag/digest
   (never patch a running container in place); restore data per Restore
   below if the `_data`/`local`/`galleries`/`upload` volumes were affected.
5. **Post-incident**: rotate every secret the incident could have exposed,
   even if exposure is only suspected.

### Restore

`bin/piwigo backup:create` / `bin/piwigo backup:restore <file> --force`
are real — `mysqldump`/`mysql`/`tar` shelled out via `PIWIGO_DB_*` env
vars. `backup:create` writes a timestamped `.tar.gz` (db dump +
`galleries/` + `local/config/config.inc.php` if present) to
`_data/backups/`; `backup:restore` validates the archive's `manifest.json`,
restores the DB into `--database` (defaults to `PIWIGO_DB_BASE`), and
restores `galleries/`. `--force` is required (drops/recreates the target
database).

`tools/restore-drill.sh` stays intentionally PHP-dependency-free
(checkout + write `.env.test` + bash script, no `composer install`) rather
than rewired onto `bin/piwigo` — it restores the tracked
`tests/Fixtures/piwigo-17.0.sql` dump directly as its own lean, independent
proof. Real command-level (not just fixture-restore-level) proof of
`backup:create`/`backup:restore` themselves comes from
`tests/Integration/BackupServiceTest.php` — round-trips `BackupService::create()`/
`restore()` against a scratch database, plus a corrupt-archive rejection
test (confirmed current: both tests exist and exercise exactly this):

```
bash tools/restore-drill.sh
```

For an actual production restore: prefer
`bin/piwigo backup:restore <file> --force --database=<scratch>` into a
scratch database first, run the same smoke assertions
`restore-drill.sh` does against it, and only then point the real
deployment at it. Never restore directly onto a live production database
without a scratch-DB dry run first.

### Secret rotation

- **DB password** (`PIWIGO_DB_PASSWORD` / Helm `db.existingSecret`):
  rotate at the MySQL user level first, then update the Secret/env var and
  roll the deployment — rotating the Secret before the DB user locks the
  app out.
- **`secret_key`** (`piwigo_config`, session/CSRF token signing): rotating
  it invalidates all existing sessions and CSRF tokens — plan for a forced
  re-login.
- **Container registry / signing credentials** (cosign/sigstore keyless
  OIDC): no long-lived key to rotate by design — rotate the CI identity's
  OIDC trust if that trust itself is suspected compromised.

### Disaster recovery

The `_data`/`local`/`galleries`/`upload` volumes and the production
database are the only stateful assets (the image is rebuildable from this
repo at any commit) — run `bin/piwigo backup:create` on a schedule and
replicate `galleries/` (least replaceable — original photos) to offsite
storage at least daily. Full recovery: rebuild/pull the image at the last
known-good tag, restore the database, restore the volumes from offsite,
redeploy.

## Architecture Decision Records

New ADRs follow a standard template (status/context/decision/consequences)
— see any entry below for the shape. Six real decisions on record, all
`Accepted`; three have a real amendment against current state, noted
inline:

**ADR-0001 — Pest 4 replaces PHPUnit.** Sole PHP test framework, including
browser E2E via `pest-plugin-browser`. Vitest stays, separately, for
TypeScript unit tests. Still accurate.

**ADR-0002 — Clean fork, no in-place upgrade from upstream Piwigo.**
Existing installs adopt v17 via a one-time `bin/piwigo import:legacy`
migration (not built yet — see Architecture → "What's genuinely not built
yet" above), not a rolling upgrade. *Amendment*: the ADR's own text says
version-to-version upgrades within v17 "use Doctrine Migrations" — that
mechanism was later replaced entirely by one static
`install/piwigo_structure-mysql.sql` file. The no-in-place-upgrade-from-
upstream decision itself still holds; only the intra-fork migration
mechanism changed.

**ADR-0003 — Hard-required bleeding-edge stack, no capability gating.**
PHP 8.5, MySQL 9.7 (MariaDB 12.x / PostgreSQL 18 in the provider matrix),
Node 24 — hard requirements, no version-compatibility shims. Still
accurate; re-verify at any point this drifts, since it's an explicitly
fast-moving target by design.

**ADR-0013 — FrankenPHP worker-mode runtime.** Decided: FrankenPHP (Caddy
+ PHP 8.5) in worker mode as the primary production runtime, Apache/
`mod_rewrite` as a fallback stage. *Amendment*: only half-built. FrankenPHP
is the runtime in production images, but not in worker mode —
`docker/Caddyfile` still uses plain per-request `php_server`. The
Apache-fallback half of the decision is fully built and current.

**ADR-0021 — Native-platform-first library policy.** Prefer browser/
PHP-native features and the already-adopted Symfony/Doctrine layer over
vendored/third-party libraries. Historical decision record for a specific
library-swap batch — still accurate as a record of what happened, and the
policy itself is invoked again (not re-litigated) whenever a later phase
finds its own vendored/legacy surface to replace.

**ADR-0026 — Pause Psalm gating until after typed/namespaced
refactoring.** PHPStan is the sole blocking static-analysis gate; Psalm's
global-function-resolution scanner didn't hold up against a large,
non-namespaced procedural codebase. Decided to resume "once P6 (PSR-4
namespace migration) and the P17-P23 service-layer refactoring" land.
*Amendment*: both have landed, but Psalm gating hasn't been reconsidered
since — an open decision, not a doc staleness issue (see Development → CI
above). Also: `psalm-baseline.xml`, which this ADR says "stays in the repo
(dormant, not deleted)," is in fact gone — only `psalm.xml` and the
`vimeo/psalm` dependency itself survived.

**Referenced but never written**: three ADR numbers are cited but no such
file ever existed in `docs/adr/` — not a casualty of this consolidation,
confirmed by checking git history before this merge. `ADR-0025` (the
legacy-import tool's design) — re-verified this round: no current file
under `src/Piwigo/` cites it at all (an earlier pass of this doc wrongly
attributed the citation to `src/Piwigo/Auth/PasswordService.php`, which
actually cites the real `ADR-0002`, not `ADR-0025` — corrected here).
`ADR-0025` was only ever cited in two now-deleted planning files, recoverable
from git history — the original `PLAN-REPLAY.md` and, checked this round,
`ADR-0002`'s own original file (`docs/adr/0002-clean-fork-no-inplace-upgrade.md`,
"a one-way `bin/piwigo import:legacy` tool (see [ADR-0025]...)") — never in
live source.
`ADR-0007`/`ADR-0008` (the image-derivative fast-path decision,
"`bootMinimal`") — cited in `src/Piwigo/Permission/ImageVisibilityChecker.php`
as already "settled," but see "What's genuinely not built yet" above: the
decision these numbers supposedly recorded was only half-implemented, and
the record of the decision itself doesn't exist as a file. If ADR
numbering matters going forward, these three gaps are worth closing
directly rather than continuing to cite phantom files.

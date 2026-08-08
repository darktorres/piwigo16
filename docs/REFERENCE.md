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

Every first-party class lives under `src/Piwigo\*`, PSR-4 autoloaded. 52
top-level namespace directories today (confirmed via direct listing,
re-verified this pass — up from 49), each explicitly assigned to one of 6
layers in `deptrac.yaml`, with one live gap in that "no catch-all regex"
claim: **`Migrations`** (4 real Doctrine migration classes,
`src/Piwigo\Migrations\Version*`) matches no layer collector at all —
confirmed by reading `deptrac.yaml` directly, not just running the tool.
`L0Data`'s reserved names are **no longer an accurate description** —
`Common` and `Event` both shipped for real since this doc was last
verified: `Common` now holds the typed-primitives layer the original plan's
P19 scope called for (`Common\ValueObject` — 19 files, `UserId`/`Email`/
`TagId`/`CategoryId`/etc.; `Common\Dto` — `PaginatedResult`/
`UserGroupPair`; `Common\Enum` — `Section`/`SortOrder`), closing the gap
this doc previously flagged as "genuine, previously-undocumented" (see
`docs/PLAN.md`'s typed-primitives campaign entry — done as of 2026-08-07).
`Event` now holds 138 typed event classes across 13 sub-namespaces
(`Location`, `Picture`, `Admin`, `Search`, `BlockManager`, `Lifecycle`,
`Album`, `Tag`, `Ws`, `User`, `Mail`, `Site`, `Template`) — the shared home
for most of Track B's ~156 typed events; a handful of others (e.g.
`Ws\Event\WsAddMethods`) deliberately live under their own domain
namespace instead, where a first-party class they carry (like `PwgServer`)
would otherwise create an illegal upward dependency from L0Data — see
that class's own docblock. `Exception` is still genuinely empty, no
directory on disk.

| Layer | Namespaces |
| --- | --- |
| **L4 Integration** | `Admin` (+ `Admin\Image`, `Admin\Integrity`), `Bootstrap`, `Command`, `Controller`, `Job`, `Ws` (+ `Ws\Encoder`, `Ws\Protocol`) |
| **L3 Presentation** | `Html`, `Http` (+ `Http\Middleware`), `Mail`, `Menu`, `Page`, `Picture`, `Routing`, `Template`; reserves `Asset`/`Listener` (not directories yet, same as L0Data's `Exception` reservation) |
| **L2b Extended Domain** | `Activity`, `Caddie`, `Calendar`, `Comment`, `Csrf`, `Feed`, `Filter`, `History`, `Metadata`, `Notification`, `Permalink`, `PluginConfig\PluginRepository` (+ `PluginEntity`/`Projection\Plugin`), `Rate`, `Search`, `Section`, `Site`, `Telemetry`, `Url` |
| **L2a Core Domain** | `Auth`, `Category`, `Group`, `Image`, `Permission`, `Tag`, `Users` |
| **L1 Infrastructure** | `Audit`, `Backup`, `Cache`, `Config`, `Core`, `Db`, `Lang`, `PluginConfig\EventDispatcher` (+ `EventHandler`), `Session`, `Storage`, `Validation` |
| **L0 Data** | `Common` (real, 23 files — see above), `Event` (real, 138 files — see above), `Exception` (still reserved, no classes yet) |
| *(uncovered)* | `Migrations` — 4 real classes, matches no collector; not currently flagged as a violation only because nothing else in the layer graph depends on them |

Each layer may depend only on itself or a layer below it. **0 violations,
no `skip_violations` entries — re-confirmed live this pass**
(`vendor/bin/deptrac analyse --no-cache`: `Violations 0, Skipped
violations 0, Uncovered 1658, Allowed 7367`); `vendor/bin/deptrac analyse`
runs unconditionally in CI (`deptrac` job), genuinely blocking. The 1658
"Uncovered" figure includes the `Migrations` gap above among others —
deptrac's own terminology for a dependency edge it can't check because
one side has no assigned layer, distinct from a violation (a checked edge
that breaks the rules).

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
has 44 explicit entries today (re-counted this pass, up from 33), for interface bindings
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
`MaintenanceActionDispatcher`, constructor-injected directly as a plain
`?PersistentCache` — no `CurrentPersistentCache` class exists anymore;
this doc's prior reference to one is stale, likely predating the
singleton/DI elimination campaign converting it to plain constructor
injection like everything else that campaign touched) and the
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

Current commands, re-verified against `config/commands.php` and a live
`bin/piwigo list` run this pass — 4 more than this doc previously
tracked: `cache:clear`, `backup:create`, `backup:restore <file>
--force [--database=NAME]`, `user:list`, `maintenance:orphan-tags`,
`maintenance:purge-history`, `maintenance:purge-sessions`,
`maintenance:purge-failed-logins`, `maintenance:repair-db`,
`migrations:migrate`, `schema:dump`.

**Previously-tracked gap, now closed**: `maintenance:repair-db` was
missing as of this doc's last verification; it's real now
(`Piwigo\Command\MaintenanceRepairDbCommand`, backed by the same
`DbMaintenanceRepository::repairOptimizeAllTables()` the admin web UI
already used) — `config/commands.php`'s own comment confirms all 4
originally-planned `maintenance:*` commands are registered as of the
same pass that added `maintenance:purge-failed-logins`. See
`docs/PLAN.md`'s gap-closure record for the original finding.
`migrations:migrate` (Doctrine's own command) and `schema:dump`
(`Piwigo\Command\SchemaDumpCommand`) are both new since this doc's
Doctrine Migrations status was last checked too — see "What's genuinely
not built yet" below, which was flatly wrong on this point.

### What's genuinely not built yet

- **FrankenPHP worker mode** — `docker/Caddyfile` runs plain `php_server`
  (classic per-request execution). The app boots FrankenPHP as its runtime,
  but not in worker mode (app kept booted in memory across requests) — the
  "FrankenPHP worker-mode runtime, Apache as fallback" decision (see "Key
  design decisions" below) decided to build this, it hasn't happened. See
  "Known gaps" below.
- ~~Doctrine Migrations~~ — **stale, removed from this list.** This doc
  previously said Migrations were "replaced entirely by one static
  `install/piwigo_structure-mysql.sql`" — true at one point (a real
  reversal of the original plan), but since reinstated for real: 4
  live `Piwigo\Migrations\Version*` classes, a working
  `bin/piwigo migrations:migrate` command, and `install/
  piwigo_structure-{mysql,pgsql}.sql` regenerated *from* migrations by
  `schema:dump` rather than hand-maintained. This doc's own "Clean fork,
  no in-place upgrade from upstream Piwigo" section below already
  documented the reinstatement correctly — this bullet just never got
  updated to match, a real internal contradiction within this same file
  until this pass. See that section below for the full detail.
- **`bin/piwigo import:legacy`** — the one-way legacy-install migration
  tool described by the "Clean fork" decision below doesn't exist yet.
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
the "FrankenPHP worker-mode runtime, Apache as fallback" decision below).
`docker build --target production-apache .` is the fallback
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

- **Apache**: `public/.htaccess` for the deny rules; `docker/apache-vhost.conf`
  sets `DocumentRoot` to `public/` itself (baked into the
  `production-apache` image the same way `docker/Caddyfile` is baked into
  `production` below) — without it the base `php:8.5-apache` image's
  default `DocumentRoot` (`/var/www/html`) would serve the whole app
  directory the `COPY . .` step puts there, deny rules or not.
- **FrankenPHP/Caddy**: `docker/Caddyfile` (baked into the production
  image; its `root * /app/public` sets the document root itself, not just
  the deny rules).
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

- PHP 8.5 (`ext-calendar`, `ext-ctype`, `ext-curl`, `ext-dom`, `ext-exif`,
  `ext-fileinfo`, `ext-filter`, `ext-gd`, `ext-iconv`, `ext-imagick`,
  `ext-imap`, `ext-intl`, `ext-libxml`, `ext-mbstring`, `ext-mysqli`,
  `ext-openssl`, `ext-pcntl`, `ext-pgsql`, `ext-session`, `ext-simplexml`,
  `ext-zip`, `ext-zlib` — re-verified against `composer.json` this pass,
  which added `ext-exif`/`ext-imagick`/`ext-imap` since this doc was last
  checked; `pcov` for coverage)
- Composer 2.x
- Node 24, bun, [`just`](https://github.com/casey/just)
- MySQL 9.7 (or MariaDB 12.x / PostgreSQL 18 — see the "hard-required
  bleeding-edge stack" decision's provider matrix below, real and working
  via `PIWIGO_DB_DRIVER=pgsql`/`mysqli`, not just
  a target — the `db-multi-provider` CI job runs the full Integration/
  Contract/Browser suites against a real PostgreSQL 18 service container
  on every push) + a webserver serving this checkout, for anything beyond
  `composer test`

### Setup

```
composer install
bun install
node_modules/.bin/playwright install chromium
cp .env.example .env.test   # fill in the .env.test block; see Tests below
```

Set `PIWIGO_DB_DRIVER=mysqli` (default) or `PIWIGO_DB_DRIVER=pgsql` in
`.env`/`.env.test` to pick the provider — see `.env.example`'s own
commented pgsql block for the matching `PIWIGO_DB_PORT`/credential shape.
Schema for either provider comes from Doctrine Migrations
(`bin/piwigo migrations:migrate`), the real mechanism both a fresh
install and an existing install's own upgrade path run through — not a
one-time generator. `bin/piwigo schema:dump` regenerates the checked-in
`install/piwigo_structure-{mysql,pgsql}.sql` snapshots from the current
connection's live (post-migration) schema; CI's `db-multi-provider` job
fails on any drift between that snapshot and what a fresh
`migrations:migrate` run actually produces.

`just` runs recipes across both stacks (`just --list`).

### Tools

| Command | What it does |
| --- | --- |
| `composer test` | Pest `Unit`+`Arch` — fast, no DB/webserver |
| `composer analyse:phpstan` | PHPStan — the sole **blocking** static-analysis gate |
| `composer analyse` | Alias for `analyse:phpstan` — `analyse:psalm` doesn't exist anymore; Psalm was fully dropped as a dependency 2026-08-07, not just left non-gating (see "Psalm gating is moot, not just paused" below) |
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
suppressions). **Psalm is gone entirely, not just dormant — re-checked
this pass, a real change since this doc's last verification.**
`vimeo/psalm` was fully dropped from `composer.json`
(`c7a5b8366a`, 2026-08-07, "update composer packages, bump pest ecosystem
to v5"): the Pest 5 bump needed PHPUnit ^13.2 → `sebastian/diff` ^9.0,
which conflicts with Psalm 6.x's own cap of `sebastian/diff` ^8.0, and
Psalm has no stable v7 yet. That commit's own message: "Psalm was
already non-gating here (not wired into CI, superseded by
PHPStan/ECS/deptrac), so dropping it unblocks the bump." `vendor/bin/psalm`
no longer exists. Only `psalm.xml` (the config file) survives, now fully
orphaned — no installed tool reads it. See "Psalm gating is moot, not
just paused" below for the full detail.

### Tests

| Command | What it does |
| --- | --- |
| `composer test:integration` | Pest `Integration` — needs `.env.test` + `piwigo_test` DB |
| `composer test:contract` | Pest `Contract` — WS API contract tests against the committed fixture |
| `composer test:browser` | Pest `Browser` — E2E via `pest-plugin-browser` (Chromium) |
| `composer test:visual` | Visual regression only — **run in isolation**, see below |
| `composer test:install` | Install-flow E2E only (its own Browser group) |
| `composer test:fixture-regen` | Rebuilds `tests/Fixtures/piwigo-17.0.sql` from a fresh install + seed |
| `composer test:mutate` | Pest Mutate (`pestphp/pest-plugin-mutate`) against `Unit`, scoped to `src/Piwigo` via `phpunit.xml.dist`'s `<source>` block — local-only, not run in CI, no `--min` gate yet |

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
39 `Ws*Test` classes (re-counted this pass, up from 21 — the WS surface
kept growing test coverage: upload chunking/concurrency/gap-handling,
per-method mutation-vs-read splits, alternate/REST format tests, etc.)
lock the legacy WS response shapes for as long as the WS API exists — a
later phase (not yet started) removes it in favor of a REST `/api/v1`
and retires these in favor of REST contract tests.

**Browser tests**: 94 files in `tests/Browser/` (re-counted this pass, up
from 40 — 92 E2E flows, plus the two special-purpose files below) via
`pestphp/pest-plugin-browser`. `tests/Browser/Helpers/BrowserTestHelpers.php`
centralizes the shared patterns (`visitPwg()`/`loginAsAdmin()`,
`navigateOk()`, `wsCall()`, `uploadPhotoViaApi()`).

**Visual regression**: `tests/Browser/VisualRegressionTest.php` — 34
screenshot baselines (re-counted this pass, was 35: 32 routes iterated in
a data-driven loop + 2 standalone tests, `picture-1` and
`admin-photo-editor`) via Pest's native
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
- `pest-plugin-browser`'s own `playwright run-server` subprocess used to
  outlive `pest`'s own exit (root cause, confirmed live via `/proc`
  inspection: `PlaywrightNpmServer::start()` launched it via
  `SystemProcess::fromShellCommandline()`, a plain command *string* --
  `proc_open()` always routes a string command through `/bin/sh -c`
  unless Symfony's `Process` adds an `exec` prefix, which it only does
  when PHP was built `--enable-sigchild`; this project's PHP isn't, so
  `/bin/sh` forked rather than exec'd, and `stop()`'s `SIGTERM` killed the
  shell it tracked while the real server process, now an untracked child,
  survived as an orphan). Fixed at the source via
  `patches/pest-plugin-browser-4.3.1-stop-server-without-shell.patch`
  (launches via an argv array instead, so `proc_open()` `execve()`s the
  real binary directly -- no shell, nothing to orphan). A bare `pest`/
  `vendor/bin/pest` invocation for Browser/Visual suites no longer needs
  any wrapper as a result. It still needs the same `--exclude-group=
  fixture-regen`/`--exclude-group=visual-regression`/`--exclude-group=
  install-flow` flags the real `composer test:*` scripts pass when
  running the whole suite (a single-file/filtered invocation doesn't
  need them unless that file itself carries one of those groups).
- `IntegrationTestCase::settleDatabase()` polls a real table after fixture
  reimport before returning control — a cold InnoDB buffer pool on a
  freshly-recreated schema can otherwise cause a transient timeout on the
  very next test.

Always confirm a browser-test failure via an isolated re-run before
concluding it's real — never dismiss (or accept) a failure on sight.

### CI

`.github/workflows/ci.yml` runs on every push/PR (docs-only changes
excluded via `paths-ignore`). Current job list, re-parsed from the
workflow YAML directly this pass (30 jobs, not 29 — `db-multi-provider`
was missing from this enumeration even though this doc's own
Requirements section and "hard-required bleeding-edge stack" decision
already describe what it does): `pest`,
`ecs`, `phpstan`, `rector`, `coverage`, `audit`, `deptrac`,
`require-checker`, `composer-unused`, `phpbench`, `vitest`, `eslint`,
`stylelint`, `knip`, `size-limit`, `k6-load` (no-op until a load-test
track lands), `commitlint`, `actionlint`, `test-file-inventory`,
`db-multi-provider`, `integration`, `contract`, `browser`,
`install-flow`, `visual-regression`, `restore-drill`, `lighthouse`,
`sbom`, `apache-deny-rules`, `container-deny-rules`.

Separate workflow files, independent of `ci.yml`: `osv-scanner.yml`/
`scorecard.yml` (SEC-52/SEC-64, weekly + push/PR), `release-please.yml`
(targets `17.x-rewrite` explicitly, pinned rather than relying on the
default branch per that workflow's own comment — re-checked this pass:
the GitHub default branch is now `17.x-rewrite` itself, confirmed via
`gh repo view`/`git ls-remote --symref origin HEAD`, so this doc's prior
claim that the default branch was the unrelated `16.x-rewrite` lineage
is stale; the pin is still correct defensive practice regardless of
which branch is currently default),
`release-image.yml` (image build + signing, on release only).

**Known gap, not just doc staleness**: `ecs` and `rector` are still
`continue-on-error: true` (non-blocking), each with an inline comment
saying so "until P5" — but the phase that comment refers to completed long
ago (fold-in note: verify P5's real completion status in `docs/PLAN.md`
before treating this as settled; the CI file itself hasn't been revisited
to make either job blocking since). The "Psalm gating resumes once P6
and P17-P23 land" deferral (from the "Psalm gating is moot, not just
paused" decision below) is now moot rather than just unmet-but-still-open
— re-verified this pass: Psalm was dropped as a dependency entirely on
2026-08-07 (a real dependency conflict with the Pest 5 bump, not a
reconsideration of the gating decision itself — see Tools above and
"Key design decisions" below), so there's no tool left to gate. Two
separate instances of the same pattern remain live (`ecs`/`rector`): a
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

## Key design decisions

The numbered `ADR-XXXX`/`docs/adr/*.md` scheme this project used to
follow is retired — those files were folded into this doc during the
2026-07-26 consolidation and never re-created, so a numeric citation
no longer resolves to anything a reader can open. The decisions
themselves are still real; they're just described here directly, by
name, with no number attached. (Source comments elsewhere in the
codebase that still say "ADR-0021" or similar are stale references to
this retired scheme — cross-check the description here, not the
number.)

**Pest is the sole PHP test framework**, including browser E2E via
`pest-plugin-browser`. Vitest stays, separately, for TypeScript unit
tests. `composer.json` pins `pestphp/pest: ^5.0` (bumped from 4,
2026-08-07, `c7a5b8366a`, the same commit that dropped Psalm — see
below).

**Clean fork, no in-place upgrade from upstream Piwigo.** Existing
installs adopt v17 via a one-time `bin/piwigo import:legacy` migration
(not built yet — see Architecture → "What's genuinely not built yet"
above), not a rolling upgrade. Version-to-version upgrades *within* v17
run through Doctrine Migrations (`bin/piwigo migrations:migrate`) — the
real, live mechanism both a fresh install and an existing install's own
upgrade path use today (this mechanism was at one point replaced by a
single static `install/piwigo_structure-mysql.sql` file, then
reinstated for real during the pgsql-support pass — a real detour, not
a current divergence). `install/piwigo_structure-{mysql,pgsql}.sql`
still exist, now as generated, human-reviewable schema snapshots + a CI
drift guard (`bin/piwigo schema:dump`), not the install-time source of
truth.

**Hard-required bleeding-edge stack, no capability gating.** PHP 8.5,
MySQL 9.7 (MariaDB 12.x / PostgreSQL 18 in the provider matrix), Node
24 — hard requirements, no version-compatibility shims. The
MariaDB/PostgreSQL half of the provider matrix was originally just a
target (the install wizard was hardcoded to MySQL only); it's now real
and working — `PIWIGO_DB_DRIVER=pgsql`/`mysqli`, a driver field in the
install form, and the `db-multi-provider` CI job exercising all 3
providers on every push (see Development → Requirements above).
Re-verify at any point this drifts, since it's an explicitly
fast-moving target by design.

**FrankenPHP worker-mode runtime, Apache as fallback.** Decided:
FrankenPHP (Caddy + PHP 8.5) in worker mode as the primary production
runtime, Apache/`mod_rewrite` as a fallback stage. Only half-built:
FrankenPHP is the runtime in production images, but not in worker mode
— `docker/Caddyfile` still uses plain per-request `php_server`. The
Apache-fallback half of the decision is fully built and current.

**Native-platform-first library policy.** Prefer browser/PHP-native
features and the already-adopted Symfony/Doctrine layer over
vendored/third-party libraries. Record of a specific library-swap
batch — still accurate as a record of what happened, and the policy
itself is invoked again (not re-litigated) whenever a later phase finds
its own vendored/legacy surface to replace.

**Psalm gating is moot, not just paused.** Psalm was never wired into
CI as a blocking gate (superseded by PHPStan/ECS/deptrac); the original
plan was to revisit that once PSR-4 namespace migration and the
service-layer refactoring landed — both have, but gating was never
reconsidered, because `vimeo/psalm` was fully dropped from
`composer.json` entirely on 2026-08-07 (real dependency conflict with
the Pest 5 bump, not a reconsideration of the gating decision — see
Development → CI above), so there is no tool left to gate.
`psalm-baseline.xml` is gone, and so is the `vimeo/psalm` dependency
itself — only the orphaned `psalm.xml` config file survives, read by no
installed tool.

**Two decisions were cited by number in source but never had a written
record**, even back when the numbered scheme was still in use — not a
casualty of the 2026-07-26 consolidation, confirmed by checking git
history before that merge: the legacy-import tool's design (`bin/piwigo
import:legacy`, described under "Clean fork" above) and the
image-derivative fast-path decision (`ImageVisibilityChecker`/
`PermissionRepository::isImageOutsideForbiddenCategories()` forbidding
live permission recomputation on that path, described under Architecture
→ "What's genuinely not built yet" above). Both decisions are covered in
this doc's own prose already; nothing further to close now that the
numbering scheme itself is retired.

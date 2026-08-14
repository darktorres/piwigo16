# Reference

Current-state reference for `17.x-rewrite`: architecture, configuration,
deployment, development setup, operations, and the standing design
decisions behind them. See `docs/PLAN.md` for the phase-by-phase history
of how this codebase got here.

## Architecture

### Namespace tree and layers

Every first-party class lives under `src/Piwigo\*`, PSR-4 autoloaded. 53
top-level namespace directories, each assigned to one of 6 layers in
`deptrac.yaml`, with one exception: **`Migrations`** (4 Doctrine
migration classes, `src/Piwigo\Migrations\Version*`) matches no layer
collector — confirmed by reading `deptrac.yaml` directly.

`Common` holds this codebase's typed-primitives layer: `Common\ValueObject`
(19 files — `UserId`/`Email`/`TagId`/`CategoryId`/etc.), `Common\Dto`
(`PaginatedResult`/`UserGroupPair`), `Common\Enum` (`Section`/
`SortOrder`). `Event` holds 138 typed event classes across 13
sub-namespaces (`Location`, `Picture`, `Admin`, `Search`, `BlockManager`,
`Lifecycle`, `Album`, `Tag`, `Ws`, `User`, `Mail`, `Site`, `Template`) —
the shared home for most of the ~156 typed events; a handful of others
(e.g. `Ws\Event\WsAddMethods`) deliberately live under their own domain
namespace instead, where a first-party class they carry (like
`PwgServer`) would otherwise create an illegal upward dependency from
L0Data — see that class's own docblock. `Exception` is empty, no
directory on disk yet.

| Layer | Namespaces |
| --- | --- |
| **L4 Integration** | `Admin` (+ `Admin\Image`, `Admin\Integrity`), `Bootstrap`, `Command`, `Controller`, `Job`, `Ws` (+ `Ws\Encoder`, `Ws\Protocol`); `Listener\UploadFormatListener` is carved out of the general `Listener\*` L3 match (below) into this layer instead — see "Plugin/theme contract surface" below |
| **L3 Presentation** | `Html`, `Http` (+ `Http\Middleware`), `Mail`, `Menu`, `Page`, `Picture`, `Routing`, `Template`, `Listener\*` (except `UploadFormatListener`, above), `PluginConfig\{ExtensionInterface,ExtensionContext,ExtensionContextFactory,ExtensionSession,PluginManifest,ThemeManifest,PluginRegistry,ThemeRegistry,PluginValidationException,PluginDependencyException,ThemeValidationException,ThemeDependencyException,SettingsPageInterface,CurrentPluginRegistry}`, `PluginConfig\Facade\*`; reserves `Asset` (not a directory yet, same as L0Data's `Exception` reservation) |
| **L2b Extended Domain** | `Activity`, `Caddie`, `Calendar`, `Comment`, `Csrf`, `Feed`, `Filter`, `History`, `Metadata`, `Notification`, `Permalink`, `PluginConfig\{PluginRepository,PluginEntity,Projection\Plugin,PluginMigrationEntity,PluginMigrationRepository}`, `Rate`, `Search`, `Section`, `Site`, `Telemetry`, `Url` |
| **L2a Core Domain** | `Auth`, `Category`, `Group`, `Image`, `Permission`, `Tag`, `Users` |
| **L1 Infrastructure** | `Audit`, `Backup`, `Cache`, `Config`, `Core`, `Db`, `Lang`, `PluginConfig\EventDispatcher` (+ `EventHandler`), `Session`, `Storage`, `Validation` |
| **L0 Data** | `Common` (23 files — see above), `Event` (138 files — see above), `Exception` (reserved, no classes yet) |
| *(uncovered)* | `Migrations` — 4 classes, matches no collector; not currently flagged as a violation only because nothing else in the layer graph depends on them |

Each layer may depend only on itself or a layer below it. 0 violations,
no `skip_violations` entries (`vendor/bin/deptrac analyse --no-cache`:
`Violations 0, Skipped violations 0, Uncovered 1658, Allowed 7367`);
`vendor/bin/deptrac analyse` runs unconditionally in CI (`deptrac` job),
genuinely blocking. The 1658 "Uncovered" figure includes the
`Migrations` gap above among others — deptrac's own terminology for a
dependency edge it can't check because one side has no assigned layer,
distinct from a violation (a checked edge that breaks the rules).

`deptrac.yaml`'s own inline comments are the authoritative record of *why*
each namespace landed where it did (real dependency edges found via
`deptrac analyse` itself, not guessed placement) — read it directly for a
specific namespace's placement reasoning rather than this table alone.
Three structural notes worth surfacing here:

- **`PluginConfig` is split across three layers** — `EventDispatcher`
  (+ `EventHandler`) sits at L1Infrastructure (a generic pub/sub bus
  reachable from every layer, injects nothing); `PluginRepository`/
  `PluginEntity`/`Projection\Plugin`/`PluginMigrationEntity`/
  `PluginMigrationRepository` stay at L2bExtendedDomain (their only real
  caller is L4Integration); `ExtensionInterface`/`ExtensionContext`/
  `PluginRegistry`/`ThemeRegistry` and the rest of the plugin/theme
  contract (see "Plugin/theme contract" below) sit at L3Presentation,
  since `ExtensionContext` needs read access to `Config`/`Lang`/`Session`
  (L1), `CurrentUser` (L2a), and `CurrentTemplate` (L3) — L3 is the
  lowest layer covering all three. Retargeting the legacy string-keyed
  event functions onto `EventDispatcher::get()` directly (rather than a
  free function) is what originally surfaced the two-layer split — a
  free-function call creates no deptrac dependency edge, a direct class
  reference does; the third layer was added later by the plugin/theme
  contract itself (P27).
- **`MailService` implements `Core\MailerInterface`** (L1Infrastructure)
  specifically so `Users`/`Comment`/`Search`/`Section` (L2a/L2b) can
  constructor-inject it without an illegal upward dependency on
  `Mail` itself (L3Presentation, since `MailService` needs `Template` for
  themed HTML email). Same interface-inversion pattern used wherever an L2
  domain class needs an L3 capability.
- **`Listener\UploadFormatListener` is the one first-party-listener
  exception to "listeners live at L3Presentation"** — it exists purely
  to delegate to `Admin\Upload\UploadService`'s static per-format
  handlers, and `UploadService` itself is genuinely L4Integration (heavy
  `Kernel`/`WsContext`/DB dependencies) — relocating it just to satisfy
  this one caller wasn't worth it, so the listener follows its
  dependency to L4Integration instead of staying with its `Listener\*`
  siblings.

### Kernel, DI container, and boot sequence

`public/index.php` (and every other root entry point, including `i.php`) →
`Piwigo\Bootstrap\RequestBootstrap::bootEntryPoint($paths)` — which itself
runs `configure()` (calls `Kernel::boot()` first thing, builds the DI
container), `connect()`, then `finalize()` — then
`Piwigo\Bootstrap\RequestPipeline::handle(RequestFactory::fromGlobals())`
runs the real request through the full PSR-15 middleware pipeline and
routes it to a controller. `CommonBootstrap` does not exist; its steps
were absorbed into `RequestBootstrap` directly. A separate
`RequestBootstrap::bootConfigOnly()` method exists (a lighter, config-only
shape) but its own docblock documents it as unused by any real production
route, exercised only by a dedicated Unit test.

`Kernel` is the one intentional static class in the codebase — it exists
to bootstrap the container that makes everything else constructor-
injectable, idempotent (`self::$booted` guard). `Kernel::container()` (a
service-locator escape hatch) is restricted by an Arch test to
`src/Piwigo/Bootstrap/` only — the test scans root `*.php` files and
`bin/*` too, exempting neither. `Kernel::service()` — the pre-rewrite
procedural codebase's own ~230-call-site service locator — was never
introduced in this rewrite; `Kernel::container()`'s Arch-test restriction
exists specifically so that broad, ambient pattern can't creep back in
through the new kernel.

`Piwigo\Core\Container::build()` wraps `DI\ContainerBuilder` loading
`config/container.php` — PHP-DI autowires by default (a constructor with
only class-typed params needs zero container entry); `config/container.php`
has 44 explicit entries, for interface bindings
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
Coexists with a separate legacy mechanism `bin/piwigo cache:clear`
deliberately does not touch: `Piwigo\Cache\PersistentCache`
(`_data/cache/*.cache` files — used by `MaintenanceActionDispatcher`,
constructor-injected directly as a plain `?PersistentCache`).
`cache:clear` only purges what the new infrastructure owns: the Latte
compiled-template cache dir and the `CacheFactory` PSR-6 pool. The
template engine itself is Latte-only now (P31: Smarty fully removed,
no `.tpl` files or `smarty/smarty` dependency remain) —
`Template::deleteCompiledTemplates()` (the admin-UI "clear compiled
templates" action) purges the same Latte cache dir `cache:clear` does,
not a separate mechanism.

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
No code queries that table back — a failed job has no admin-facing
visibility, retry, or purge path today.

### CLI (`bin/piwigo`)

Delegates to `Piwigo\Bootstrap\CliBootstrap`, which boots the same
Kernel/DI container as the HTTP path and resolves each command in
`Piwigo\Bootstrap\CommandDefinitions` (autowired). `Piwigo\Core\ShutdownHandler` wires
`SIGTERM` (`ext-pcntl`, a hard `composer.json` requirement) to run
registered cleanup callbacks — `Piwigo\Backup\BackupService` uses this so
an interrupted `backup:create`/`restore` doesn't leave temp files behind.

Current commands (`Piwigo\Bootstrap\CommandDefinitions`): `cache:clear`, `backup:create`,
`backup:restore <file> --force [--database=NAME]`, `user:list`,
`maintenance:orphan-tags`, `maintenance:purge-history`,
`maintenance:purge-sessions`, `maintenance:purge-failed-logins`,
`maintenance:repair-db`, `migrations:migrate`, `schema:dump`,
`precompile:templates`, `phpstan-latte:generate-shims`,
`phpstan-latte:compile` (`lint:latte:inner`, `LintLatteCommand`'s own
registration, is deliberately hidden — `composer lint:latte` is the real
entry point, see `tools/latte-lint.php`).
`maintenance:repair-db` (`Piwigo\Command\MaintenanceRepairDbCommand`) is
backed by the same `DbMaintenanceRepository::repairOptimizeAllTables()`
the admin web UI uses. `migrations:migrate` is Doctrine's own command;
`schema:dump` (`Piwigo\Command\SchemaDumpCommand`) regenerates the
checked-in `install/piwigo_structure-{mysql,pgsql}.sql` snapshots from
the current, live (post-migration) schema.

### Plugin/theme contract (P27)

`PluginConfig\ExtensionInterface` is the one shared contract for both
plugins and themes — `boot(ExtensionContext $context)`, `install()`,
`activate()`, `deactivate()`, `uninstall()`, `update(string $oldVersion,
string $newVersion)`, and `subscribedEvents(): array<class-string,
Closure|list<Closure>>` (bound closures via first-class-callable syntax
on a literal method name — e.g. `[SomeEvent::class =>
$this->onFoo(...)]` — never a method-name string; this project's
PHPStan `level: 10` + `bleedingEdge.neon` bans variable method calls
outright, which a string-keyed shape would eventually need). A plugin's
`subscribedEvents()` runs on a bare `new $class()` with no
constructor-injected state and before `boot()` ever runs, so it can't
condition on runtime state the way a container-resolved
`Piwigo\Listener\*` class can — any runtime branching (e.g. admin vs.
public registration) has to live inside the handler methods themselves,
checking `ExtensionContext::isAdminContext()`.

`plugin.json`/`theme.json` (schema: `docs/schemas/plugin.schema.json`/
`theme.schema.json`, validated via `opis/json-schema`) replace the
legacy `main.inc.php`/`themeconf.inc.php` header-comment format
entirely — `Admin\Extensions\ExtensionScanner`/`ExtensionType::
markerFilenames()` recognize only the new manifest files, no legacy
fallback (P27.10; this fork breaks all pre-17.x extensions by design,
see "Key design decisions" in `docs/PLAN.md`). `PluginConfig\
PluginManifest`/`ThemeManifest` are the readonly DTOs; `require` (a
Composer-style version-constraint map keyed `'piwigo'` or
`'plugin/<id>'`) is a genuinely new capability legacy Piwigo never had —
`PluginRegistry` resolves it into a real, enforced dependency graph
(`composer/semver`), refusing `activate()` when a required plugin isn't
active and refusing `deactivate()`/`uninstall()` when another active
plugin still depends on it.

`PluginConfig\PluginRegistry`/`ThemeRegistry` own the manifest scan,
validation, dependency resolution, and request-time boot.
`PluginRegistry::bootActive()` is a two-pass process (register every
active plugin's `subscribedEvents()` first, *then* call `boot()` on the
same cached instances) so a plugin dispatching a custom event from its
own `boot()` reaches every other plugin's handlers regardless of load
order, and so registration/boot always share one instance per plugin.
`ThemeRegistry::bootCurrent()` walks the current theme's parent chain
furthest-ancestor-first for both registration and boot — the reverse of
the child-first order asset/CSS lookup uses, because `dispatchChange()`
is a pipeline where the *last* handler registered has final say. Both
registries retarget `Admin\Extensions\ExtensionLifecycle`'s admin
install/activate/deactivate/uninstall/update actions (P27.5) — the
business-rule layer (last-theme guard, default-theme reassignment on
deactivate) stays in `ExtensionLifecycle` itself, wrapping a call into
the registry rather than being replaced by it.

`PluginConfig\ExtensionContext` is the one object `boot()` and every
`subscribedEvents()` handler receive — never the raw container:
`template()`, `config()` (the same shared `CurrentConfig` instance core
itself mutates — full read/write, not a narrow facade),
`currentUser()`, `setLanguage()`/`syncLanguageForRequest()`, `lang()`/
`languages()`, `url()`, `redirect()`, `isAdminContext()`, a per-plugin
namespaced `session()` store (`PluginConfig\ExtensionSession`, backed by
`Session\SessionService::getSessionVar()`), `dispatchNotify()`/
`dispatchChange()`, `getSetting()`/`setSetting()`/`deleteSetting()`
(arbitrary-key config persistence via `ConfigService`), `mail()`
(wraps `Mail\MailService::mail()`, the same entry point every core
caller already funnels through), `images()`/`users()`/`themes()`
(`PluginConfig\Facade\{ImageReadFacade,UserReadFacade,ThemeReadFacade}`
— narrow, purpose-built read facades; no raw SQL access exists on
`ExtensionContext` at all), `checkCsrfOrFail()`/`checkCsrf()`/
`csrfToken()` (wraps `Csrf\CsrfService`), and `isWebmaster()` (wraps
`Auth\AccessControl::isWebmaster()`, the same service every other admin
controller's own webmaster gate already uses). `template()`/
`currentUser()` carry a real timing constraint: `bootActive()` runs
early in `RequestBootstrap::connect()`, before `UserBootstrap::
initialize()` resolves the real logged-in user and long before
`Template` is constructed in `finalize()` — calling `template()` from
`boot()` throws a guarded exception naming `boot()` as the cause;
user-dependent logic belongs in a `subscribedEvents()` handler for a
later lifecycle event instead.

**Settings pages (P27.15).** A plugin/theme whose manifest declares
`hasSettings` (`true` or `'webmaster'`) implements `PluginConfig\
SettingsPageInterface` (`handleSettingsRequest(ServerRequestInterface
$request): void`) alongside `ExtensionInterface` on the same `main`
class. `PluginRegistry::install()`/`activate()` and `ThemeRegistry`'s
equivalents validate this contract at manifest-declaration time — a
`hasSettings` manifest whose class doesn't implement the interface
throws `PluginValidationException`/`ThemeValidationException` there,
not confusingly deep inside the controller the first time an admin
opens that page. `Controller\Admin\PluginSubController`/
`ThemeSubController` (page slugs `plugin`/`theme`) dispatch to it
directly — no `include_once` of a plugin/theme file exists anywhere in
either controller. For plugins, the dispatched instance is the same
one `PluginRegistry::bootActive()` already booted this request
(`getBootedInstance()`, reached via `PluginConfig\
CurrentPluginRegistry`, a container-shared holder shaped like `Config\
CurrentConfigService` — needed because `PluginSubController` itself
resolves fresh via the DI container, a different construction path
than `RequestBootstrap`'s own manually-`Connection`-scoped
`PluginRegistry`). Themes have no equivalent "already active" boot to
reuse — an admin can open *any* installed theme's settings page, not
just the live site's current one — so `ThemeRegistry::
bootForSettingsPage()` does a page-scoped, throwaway `boot()` instead,
outside `bootCurrent()`'s own cache and without registering
`subscribedEvents()` against the live dispatcher. A settings page
renders through the same real mechanism `Controller\Admin\
ConfigurationSubController` already uses —
`ExtensionContext::template()->assignContext(...)`/
`assignVarFromTemplate('ADMIN_CONTENT', <absolute path>)` — no new
`Template` capability was needed.

The legacy PEM wire protocol (`piwigo.org/ext`'s `serialize()`-encoded,
3-endpoint API) isn't used by this fork's own extension catalog.
`Admin\Extensions\PemCatalog` instead fetches a sibling repo's plain
static `manifest.json` directly (`RequestBootstrap::pemUrl(?ExtensionType
$type)`, per-type overrides via `PIWIGO_ALT_PLUGINS_PEM_URL`/
`PIWIGO_ALT_THEMES_PEM_URL`) and filters/normalizes it in plain PHP —
every real caller's method signature and return shape is unchanged.
`AppInfo::VERSION` (`'17.0.0'`) is the real compatibility marker an
extension's own `piwigo_compat` array is checked against.

Bundled-extension status: `Admin\Extensions\ExtensionType::defaultIds()`
names the 7 extensions this fork ships and maintains itself
(`AdminTools`, `LocalFilesEditor`, `TakeATour`, `language_switch`,
`elegant`, `modus`, `smartpocket`) — these aren't duplicated into this
repo's own git history (`.gitignore` already excludes `plugins/*`/
`themes/*` except `index.php`/core's own `themes/default`); they ship
via the PEM mirror above and get auto-installed at install time. Porting
each one onto the new contract (source lives in the sibling
`../piwigo16-plugins`/`../piwigo16-themes` repos) is tracked as P27.6 —
in progress as of this writing, not yet complete for all 7.

### What's genuinely not built yet

- **FrankenPHP worker mode** — `docker/Caddyfile` runs plain `php_server`
  (classic per-request execution). The app boots FrankenPHP as its runtime,
  but not in worker mode (app kept booted in memory across requests).
- **`bin/piwigo import:legacy`** — the one-way legacy-install migration
  tool described under "Key design decisions" below doesn't exist yet.
- **opcache.preload** — two artifacts exist: `config/preload.php` (an
  early, ~19-class curated list) and `tools/opcache-preload.php` (a
  later, broader script that preloads every `Piwigo\`-namespaced class
  via Composer's classmap; measured admin.php ~30-45% faster, index.php
  ~40-50% faster, identification.php ~65-68% faster). The broader script
  is activated via a system-level `php.ini` kept outside this repo, so
  neither is enabled in any shipped `php.ini` here; this is left as a
  deployment-time optimization, not something CI/local dev needs.
- **Mobile/tablet device detection** — `Core\DeviceHelper::getDevice()`
  has a single writer, and it unconditionally sets `'desktop'` on every
  new session; no User-Agent parsing exists anywhere in this codebase.
  `'mobile'`/`'tablet'` are never produced automatically — the only path
  to the mobile theme is an explicit `?mobile=1` query param a visitor
  has to already know to use.
- **The image-derivative fast path (`bootMinimal`)** — the plan committed to
  a stripped request path for image derivatives specifically (the highest-
  volume request type on a photo gallery): skip the full DI container/
  middleware boot, check permissions with 1-2 cheap queries, then hand the
  file to the web server via `X-Sendfile`/`X-Accel-Redirect` for a
  zero-copy `sendfile()` response — never have PHP read the file itself.
  `public/i.php` boots through the exact same
  `RequestBootstrap::bootEntryPoint()` + `RequestPipeline::handle()` path
  as every other entry point (no branch, no reduced boot), and
  `ImageDerivativeController` reads the file into memory
  (`stream_get_contents()`) and returns it as a normal response body — no
  `X-Sendfile` anywhere in the codebase. The permission-check *logic* this
  design needed did get built (`Piwigo\Permission\ImageVisibilityChecker`,
  `Piwigo\Session\SessionUserResolver` — both avoid recomputing
  permissions live, reading precomputed data instead), but the
  fast-boot/zero-copy-serve half was never implemented. A separate
  `RequestBootstrap::bootConfigOnly()` method exists and is documented as
  unused in production ("No real production route needs this method
  anymore") — the fast path genuinely isn't wired to anything.

## Configuration

`Piwigo\Config\CurrentConfig` is the single typed source of truth for every
runtime configuration property — one private static typed property per
key, with a named getter/setter (no generic string-keyed accessor).
`Piwigo\Config\ConfigService` (reached via `CurrentConfigService::get()`)
is the DB-backed persistence layer reading/writing the `config` table on
top of it. DB connection credentials and the sysadmin-lockable settings
live on separate classes (`Piwigo\Db\DbCredentials`, env-only;
`Piwigo\Config\DeploymentPolicy`, file-only).

There is no generated reference table for this — `tools/build-config-docs.php`
and the doc it wrote (`docs/CONFIG.md`) were both removed: the generator
was never wired into CI or any hook, so the table it produced drifted out
of sync with the real properties. `CurrentConfig.php`'s own per-property
docblocks (`#[Required]`/`#[Sensitive]` attributes included) are the real
source of truth — read the class directly. Both attributes are markers
only today — `Required`/`Sensitive` are empty classes with no real
reflection-based consumer anywhere, so neither one currently drives any
enforcement or redaction; they document intent, nothing more.

## Deployment

Covers running the image built by `./Dockerfile` — standalone, via
Compose, or via Helm.

### The image

`docker build --target production .` — FrankenPHP (Caddy + PHP 8.5, per
the "FrankenPHP worker-mode runtime, Apache as fallback" decision below).
`docker build --target production-apache .` is the fallback for hosts
needing classic Apache/`mod_rewrite`. Both listen on `:80` as non-root
`www-data`; FrankenPHP/Caddy wants `CAP_NET_BIND_SERVICE` at startup
regardless of which port it ends up binding, so there's no
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
nonexistent path. **Why this matters**: before document-root isolation, a
private album's image derivatives could be served to anyone who
knew/guessed the URL, forever, without ever re-triggering the permission
check once a derivative was cached on disk. `config/`, `tools/`, `dev/`,
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
`PASSWORD`/`BASE`). In containers these are set directly
(compose `environment:`, Helm chart env) rather than via a file —
`Piwigo\Core\Env::loadEnvFile()` reads `getenv()` either way, so no `.env`
file needs to exist inside the image.

## Development

### Requirements

- PHP 8.5 (`ext-calendar`, `ext-ctype`, `ext-curl`, `ext-dom`, `ext-exif`,
  `ext-fileinfo`, `ext-filter`, `ext-gd`, `ext-iconv`, `ext-imagick`,
  `ext-imap`, `ext-intl`, `ext-libxml`, `ext-mbstring`, `ext-mysqli`,
  `ext-openssl`, `ext-pcntl`, `ext-pgsql`, `ext-session`, `ext-simplexml`,
  `ext-zip`, `ext-zlib`; `pcov` for coverage)
- Composer 2.x
- Node 24, bun, [`just`](https://github.com/casey/just)
- MySQL 9.7 (or MariaDB 12.x / PostgreSQL 18 — see the "hard-required
  bleeding-edge stack" decision's provider matrix below, real and working
  via `PIWIGO_DB_DRIVER=pgsql`/`mysqli` — the `db-multi-provider` CI job
  runs the full Integration/Contract/Browser suites against a real
  PostgreSQL 18 service container on every push) + a webserver serving
  this checkout, for anything beyond `composer test`

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
(`bin/piwigo migrations:migrate`), the mechanism both a fresh install and
an existing install's own upgrade path run through — not a one-time
generator. `bin/piwigo schema:dump` regenerates the checked-in
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
| `composer analyse` | Alias for `analyse:phpstan` — `analyse:psalm` doesn't exist; Psalm is installed again but has no `composer` script and isn't a CI gate (see "Key design decisions" below) |
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

PHPStan has no baseline file (`phpstan-baseline.neon` was deleted once the
codebase reached a clean run — CI runs `phpstan analyse` with zero
suppressions). Psalm is not gated, but is installed: `vimeo/psalm` was
dropped from `composer.json` entirely (`c7a5b8366a`, 2026-08-07 — the
Pest 5 bump needed PHPUnit ^13.2 → `sebastian/diff` ^9.0, which conflicted
with Psalm 6.x's own cap of `sebastian/diff` ^8.0, and Psalm had no stable
v7 yet), then reinstalled (`4118adbb85`, 2026-08-11) pinned to the
`7.x-dev` branch once that cap was dropped upstream. `vendor/bin/psalm`
and `psalm.xml` are both real again — see "Psalm gating is moot, not just
paused" below for the full history — just still non-gating (superseded by
PHPStan/ECS/deptrac, no CI job, no `composer` script).

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
`test:contract`/`test:browser`/`test:visual` all self-provision a
pristine DB before running — none depend on run order or on
`test:fixture-regen` having been run first.

**Contract tests**: `tests/Contract/ContractTestCase` drives the WS API
over curl, validating against JSON Schema files in `tests/Contract/schemas/`.
39 `Ws*Test` classes lock the legacy WS response shapes for as long as the
WS API exists — a later phase (not yet started) removes it in favor of a
REST `/api/v1` and retires these in favor of REST contract tests.
<!-- doc-drift-check: cmd='find tests/Contract -maxdepth 1 -iname "Ws*Test.php" | wc -l' expect="39" -->

**Browser tests**: 95 files in `tests/Browser/` (93 E2E flows, plus the
two special-purpose files below) via `pestphp/pest-plugin-browser`.
<!-- doc-drift-check: cmd='find tests/Browser -maxdepth 1 -iname "*.php" | wc -l' expect="95" -->
`tests/Browser/Helpers/BrowserTestHelpers.php` centralizes the shared
patterns (`visitPwg()`/`loginAsAdmin()`, `navigateOk()`, `wsCall()`,
`uploadPhotoViaApi()`).

**Visual regression**: `tests/Browser/VisualRegressionTest.php` — 34
screenshot baselines (32 routes iterated in a data-driven loop + 2
standalone tests, `picture-1` and `admin-photo-editor`) via Pest's native
`assertScreenshotMatches()` (`tests/.pest/snapshots/`). **Must run in
isolation** — `composer test:visual`, never bundled with CRUD-mutating
Browser tests (those drift the sidebar's live counts, producing false
diffs). Re-baseline after an intentional visual change:

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
  outlive `pest`'s own exit (root cause: `PlaywrightNpmServer::start()`
  launched it via `SystemProcess::fromShellCommandline()`, a plain
  command *string* — `proc_open()` always routes a string command
  through `/bin/sh -c` unless Symfony's `Process` adds an `exec` prefix,
  which it only does when PHP was built `--enable-sigchild`; this
  project's PHP isn't, so `/bin/sh` forked rather than exec'd, and
  `stop()`'s `SIGTERM` killed the shell it tracked while the real server
  process, now an untracked child, survived as an orphan). Fixed at the
  source via `patches/pest-plugin-browser-4.3.1-stop-server-without-shell.patch`
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
excluded via `paths-ignore`). 30 jobs: `pest`, `ecs`, `phpstan`, `rector`,
`coverage`, `audit`, `deptrac`, `require-checker`, `composer-unused`,
`phpbench`, `vitest`, `eslint`, `stylelint`, `knip`, `size-limit`,
`k6-load` (no-op until a load-test track lands), `commitlint`,
`actionlint`, `test-file-inventory`, `db-multi-provider`, `integration`,
`contract`, `browser`, `install-flow`, `visual-regression`,
`restore-drill`, `lighthouse`, `sbom`, `apache-deny-rules`,
`container-deny-rules`.

Separate workflow files, independent of `ci.yml`: `osv-scanner.yml`/
`scorecard.yml` (SEC-52/SEC-64, weekly + push/PR), `release-please.yml`
(targets `17.x-rewrite` explicitly, pinned rather than relying on the
default branch per that workflow's own comment — the GitHub default
branch is `17.x-rewrite` itself too, confirmed via `gh repo view`/
`git ls-remote --symref origin HEAD`, but the pin is correct defensive
practice regardless of which branch is currently default),
`release-image.yml` (image build + signing, on release only).

`ecs` and `rector` are still `continue-on-error: true` (non-blocking),
each with an inline comment saying so "until P5" — see `docs/PLAN.md` for
P5's completion status; the CI file itself hasn't been revisited to make
either job blocking since. Psalm has no CI job at all (gating was never
reconsidered, and would be moot regardless — see "Psalm gating is moot,
not just paused" under Key design decisions), even though the dependency
itself is installed again. Rector's rule set is real again too, not the
placeholder it once was: `rector.php` has `withPhpSets(php85: true)` and
`withPreparedSets(typeDeclarations: true, instanceOf: true)` both active
(`c49a00014d`/`0bfc324f59`, both applied tree-wide, not just enabled) —
still narrower than the reference implementation's set (no
`withComposerBased`, no explicit `SetList::TYPE_DECLARATION`), and the
`rector` job stays non-blocking regardless, but it checks real rules now,
not almost nothing. `phpstan/phpstan-deprecation-rules` is in
`composer.json` (`^2.0`) too, so PHPStan does flag calls to
`@deprecated`-tagged methods project-wide.

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

Three non-obvious facts about specific jobs, otherwise only in the
workflow file's own comments: **`test-file-inventory`** exists
specifically to catch a testsuite silently running 0 tests (Pest exits 0
on a `--filter` that matches nothing in a non-empty suite, so file
presence is the only reliable signal) — its own job name cites this as
"the 16.x-v2 lesson," a real regression on a different, earlier rewrite
branch that also lives in this repo's git history. **`audit`** (`bun
audit` + OSV-Scanner) ignores 3 specific GHSAs (`GHSA-52f5-9888-hmc6`,
`GHSA-ph9p-34f9-6g65`, `GHSA-w5hq-g745-h8pq`) — transitive `tmp`/`uuid`
pins inside `@lhci/cli` (dev-only, never shipped), no fixed upstream
release; re-check both `osv-scanner.toml` and `ci.yml` on every
`@lhci/cli` bump. **`sbom`**'s JS half runs a throwaway `npm install
--package-lock-only --ignore-scripts` first — `@cyclonedx/cyclonedx-npm`
doesn't read `bun.lock` directly, so this generates a disposable
npm-format lockfile just for the SBOM tool; `bun.lock` stays the real,
authoritative lockfile.

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
test:

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
- **`secret_key`** (`config`, session/CSRF token signing): rotating
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
2026-07-26 consolidation and never re-created, so a numeric citation no
longer resolves to anything. The decisions themselves are still real;
they're described here directly, by name, with no number attached.

**Pest is the sole PHP test framework**, including browser E2E via
`pest-plugin-browser`. Vitest stays, separately, for TypeScript unit
tests. `composer.json` pins `pestphp/pest: ^5.0`.

**Clean fork, no in-place upgrade from upstream Piwigo.** Existing
installs adopt v17 via a one-time `bin/piwigo import:legacy` migration
(not built yet — see Architecture → "What's genuinely not built yet"
above), not a rolling upgrade. Version-to-version upgrades *within* v17
run through Doctrine Migrations (`bin/piwigo migrations:migrate`) — the
mechanism both a fresh install and an existing install's own upgrade
path use today. `install/piwigo_structure-{mysql,pgsql}.sql` are
generated, human-reviewable schema snapshots + a CI drift guard
(`bin/piwigo schema:dump`), not the install-time source of truth.

**Hard-required bleeding-edge stack, no capability gating.** PHP 8.5,
MySQL 9.7 (MariaDB 12.x / PostgreSQL 18 in the provider matrix), Node
24 — hard requirements, no version-compatibility shims.
`PIWIGO_DB_DRIVER=pgsql`/`mysqli` picks the provider (a driver field in
the install form too); the `db-multi-provider` CI job exercises all 3
providers on every push (see Development → Requirements above).

**FrankenPHP worker-mode runtime, Apache as fallback.** FrankenPHP
(Caddy + PHP 8.5) in worker mode is the primary production runtime,
Apache/`mod_rewrite` a fallback stage. Only the fallback is fully built:
FrankenPHP is the runtime in production images, but not in worker mode —
`docker/Caddyfile` still uses plain per-request `php_server`.

**Native-platform-first library policy.** Prefer browser/PHP-native
features and the already-adopted Symfony/Doctrine layer over
vendored/third-party libraries, invoked whenever a phase finds its own
vendored/legacy surface to replace.

**Psalm gating is moot, not just paused — but Psalm itself is back.**
Psalm was never wired into CI as a blocking gate — PHPStan is the sole
blocking static-analysis gate; Psalm's global-function-resolution scanner
didn't hold up against this codebase's large, non-namespaced procedural
legacy tree, so gating stays moot regardless of whether the dependency is
installed. `vimeo/psalm` was dropped from `composer.json` entirely on
2026-08-07 (real dependency conflict with the Pest 5 bump — see
Development → CI above), then reinstalled on 2026-08-11 (`4118adbb85`),
pinned to the `7.x-dev` branch once that branch dropped the
`sebastian/diff` cap that caused the original conflict. `psalm.xml`'s
drifted path references were fixed in the same pass, and a real Psalm
7.x-dev crash (undeclared `StatementsAnalyzer` properties — no upstream
fix exists) is patched via `composer-patches`. `vendor/bin/psalm` and a
rebuilt `psalm.xml` are both real again today; `psalm-baseline.xml`
stays gone (a 25+-error "Batch 5/6/7" cleanup ran clean instead of
re-baselining). Still no dedicated CI job and no `composer` script wired
to it — reinstalled for real, local, best-effort use, not as a
resurrected gate.

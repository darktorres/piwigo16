# Architecture

> Created in P6 (PSR-4 namespace migration). Extended in P7–P23, P27, P32 as
> later phases add the kernel, DI container, HTTP layer, config/DB/language
> subsystems, service layer, frontend build, and the final dependency-cycle
> cleanup. P7–P12 (Epoch C, "Kernel & HTTP foundation") landed the kernel,
> DI container, PSR-15 middleware pipeline, routing, observability, cache,
> and the `bin/piwigo` CLI — this document now covers that ground too, not
> just P6's original namespace-extraction snapshot.

## Scope of P6

P6 extracted every first-party `class`/`interface` declaration that used to
live inside `include/` and `admin/include/` procedural files into a
PSR-4-autoloaded `src/Piwigo/` tree, under the `Piwigo\` namespace prefix
(`composer.json`'s `autoload.psr-4`). **This was extraction and namespacing
only, not a rewrite** — class names, method signatures, and behavior are
unchanged from the pre-P6 procedural codebase; only file location and
namespace changed. Renaming classes to modern PSR-1 `StudlyCaps` (several
still use the legacy lowercase/snake_case style: `pwg_image`, `tabsheet`,
`plugins`, `check_integrity`, …), redesigning APIs, and introducing
dependency injection are P17–P23's job.

66 classes/interfaces across 33 origin files were moved. The procedural
files that contained them either got fully deleted (when they held nothing
but class declarations) or trimmed down to their remaining top-level
`function`/`define()` statements (when they mixed classes with procedural
code — e.g. `include/functions_plugins.inc.php` keeps its ~10
`add_event_handler()`-family functions after `PluginMaintain`/`ThemeMaintain`
moved out).

## Namespace tree

```
src/Piwigo/
├── Admin/                    admin-side domain classes (plugin/theme/language
│   │                         management, integrity checks, image processing,
│   │                         tab navigation) — still legacy-cased names
│   ├── Image/                image processing backends (GD, Imagick, external
│   │                         ImageMagick) behind a common imageInterface
│   └── Integrity/            installation self-check (check_integrity,
│                             c13y_internal)
├── Auth/                     TOTP two-factor auth (PwgBase32, PwgTOTP)
├── Backup/                   BackupService (P12) — real DB+galleries/ backup/
│                             restore via mysqldump/mysql/tar shell-out
├── Bootstrap/                CliBootstrap (P12), CommonBootstrap (P7, HTTP
│                             boot orchestrator), RequestPipeline (P9),
│                             SentryBootstrap (P10)
├── Cache/                    PersistentCache abstract base + file-backed impl
│                             (P6, legacy); CacheFactory (P11, symfony/cache
│                             PSR-6 adapter selection — new infra, coexists
│                             with the legacy classes, doesn't replace them)
├── Calendar/                 monthly/weekly calendar navigation widgets
├── Command/                  bin/piwigo's Symfony Console commands (P12):
│                             CacheClearCommand, BackupCreateCommand,
│                             BackupRestoreCommand, UserListCommand
├── Core/                     Kernel (P7, DI container bootstrapper),
│                             Container (P8), ServerTiming (P10),
│                             FeatureFlag (P11), ShutdownHandler (P12,
│                             SIGTERM cleanup registry), Logger (P6, legacy)
├── Db/                       DbCredentials (P12) — env-var DB credentials
│                             for CLI code that can't reach the legacy
│                             include/common.inc.php bootstrap chain
├── Http/                     RequestFactory/ResponseEmitter/ResponseFactory
│                             (P7), MiddlewarePipeline + BaselineSecurityHeaders
│                             + SecurityHeaderContributor (P9)
│   └── Middleware/            8-stage PSR-15 pipeline (P9-P11) — see
│                             "HTTP middleware pipeline" below
├── Image/                    derivative-image computation: SrcImage,
│                             DerivativeImage, DerivativeParams, ImageRect,
│                             ImageStdParams, SizingParams, WatermarkParams
├── Menu/                     admin/theme menu block registration
├── Routing/                  Router (P9, wraps symfony/routing),
│                             RouteResult, RouteMatchStatus
├── Search/                   advanced-search query model (Q*Scope/Q*Token
│                             classes) plus per-language search-term
│                             inflectors
├── Session/                  PwgSession (P6, SessionHandlerInterface,
│                             legacy, still the only one actually registered
│                             on real requests); Session (P11, new empty VO
│                             — not yet reachable from real traffic)
├── Site/                     local filesystem site-synchronization reader
├── Template/                 Smarty wrapper + JS/CSS combination/minification
│                             pipeline (Template, CssLoader, ScriptLoader,
│                             FileCombiner, Combinable/Script/Css)
└── Ws/                       web services (REST/XML-RPC) protocol layer
    ├── Encoder/               response-encoder abstract base
    └── Protocol/              per-format encoders/request handlers (JSON,
                                Serial-PHP, REST, XML-RPC)
```

Every namespace segment above is deliberately enumerated in `deptrac.yaml`'s
collectors (not matched by a catch-all regex) — a future phase adding a new
top-level namespace has to explicitly decide which architectural layer it
belongs to rather than silently falling through to a default.

## Layer model (`deptrac.yaml`)

P6 also introduced a 6-layer dependency-direction model, enforced by
[Deptrac](https://github.com/qossmic/deptrac) in CI (`.github/workflows/ci.yml`'s
`deptrac` job). Each layer may only depend on layers at or below its own
level:

| Layer | P6 namespaces | Landed P7-P12 | Still later-phase (per `docs/PLAN-REPLAY.md`) |
| --- | --- | --- | --- |
| **L4 Integration** | `Admin` (incl. `Admin\Image`, `Admin\Integrity`), `Ws` (incl. `Ws\Encoder`, `Ws\Protocol`) | `Bootstrap` (P7-P12), `Command` (P12) | `Controller`, `Job` |
| **L3 Presentation** | `Menu`, `Template` | `Http` (incl. `Http\Middleware`, P7-P11), `Routing` (P9) | `Html`, `Page`, `Asset`, `Listener` |
| **L2b Extended Domain** | `Search`, `Calendar`, `Site` | *(none)* | every other first-party domain namespace |
| **L2a Core Domain** | `Auth`, `Image` | *(none)* | `Users`, `Permission`, `Category`, `Tag` |
| **L1 Infrastructure** | `Cache`, `Session`, `Core` | `Db` (P12), `Backup` (P12) | `Config` |
| **L0 Data** | *(none yet)* | *(none)* | `Common`, `Event`, `Exception` |

A layer may depend on itself and any layer below it, never a layer above it
(e.g. L4 can depend on L3/L2b/L2a/L1/L0, but L1 can only depend on L0).

### Baseline

**No violations, no `skip_violations` entries.** P6 originally recorded a
10-entry baseline here (Calendar/Template code reaching into `Image`), but
P8 found this was a false positive: deptrac 4.6.2's Symfony Config layer
parser silently breaks ruleset resolution when a layer name contains a
hyphen. `deptrac.yaml`'s original layer names (`L0-Data`, `L1-Infrastructure`,
etc.) all had one, so every cross-layer dependency the ruleset explicitly
allowed — including the Calendar/Template → `Image` calls above, which the
6-layer model always intended to permit (L2b/L3 depending on L2a is exactly
what the ruleset's own allow-lists state) — was being misreported as a
violation. Confirmed via an isolated minimal reproduction (a bare
`LayerA: [LayerB]` ruleset resolves correctly; renaming to
`Layer-A: [Layer-B]` makes the identical dependency register as a
violation). Fixed by dropping the hyphen from every layer name (`L0Data`,
`L1Infrastructure`, `L2aCoreDomain`, `L2bExtendedDomain`, `L3Presentation`,
`L4Integration`) — re-running `deptrac analyse` confirms 0 violations
project-wide. There was never a real architecture problem to baseline.

Still **0 violations, no `skip_violations` entries** as of P12 (33 allowed
edges, up from P6's original handful) — `Command`/`Backup` were added to
existing layer collectors, no ruleset changes needed.

## Kernel boot sequence and DI container (P7-P8)

`index.php` includes legacy `include/common.inc.php` first (unchanged
procedural bootstrap — DB connection, `$conf`, superglobal sanitization,
session, template), then calls `Piwigo\Bootstrap\CommonBootstrap::run()`,
which calls `Piwigo\Core\Kernel::boot()`. `Kernel` is deliberately the one
intentional static class in this codebase: it exists to bootstrap the DI
container that makes everything else constructor-injectable, and is
idempotent (`self::$booted` guard) so nested `run()` calls can't corrupt
state. `Kernel::container()` — a service-locator escape hatch — is
restricted by an arch test (`tests/Arch/StructuralTest.php`) to
`Bootstrap/`, root `index.php`, and `bin/*` (P12): no other production code
may call it, and `Kernel::service()` (the pre-rewrite codebase's 230-call
service locator) is never introduced at all.

`Piwigo\Core\Container::build()` wraps `DI\ContainerBuilder`, loading
`config/container.php` — PHP-DI autowires by default (any constructor with
only class-typed params needs zero container entry); explicit entries exist
only for interface bindings (`CacheItemPoolInterface`,
`Psr\SimpleCache\CacheInterface`, `Psr\Log\LoggerInterface`) and
non-obvious construction (Monolog channel config, the PSR-16 cache-pool
wrapper). `config/container.php` grows one entry at a time as each phase
needs one — never copied in bulk from the reference.

## HTTP middleware pipeline and routing (P9-P11)

`Piwigo\Http\MiddlewarePipeline` (PSR-15, immutable recursive peel) is
orchestrated by `Piwigo\Bootstrap\RequestPipeline` — kept out of `Kernel`
itself after a real deptrac violation surfaced in P9 (`Kernel::handle()`
originally lived directly on `Kernel`/L1Infrastructure but depended on
Http/Routing classes at L3Presentation, disallowed by the layer model).
Current roster, in order: `ExceptionHandlerMiddleware` →
`SecurityHeadersMiddleware` → `SessionMiddleware` → `ServerTimingMiddleware`
→ `SentryMiddleware` → `RoutingMiddleware` → `ControllerInvokerMiddleware`.
**Not yet reachable from real traffic** — built and tested since P9, but
nothing routes an actual request through it yet; that's a later-phase
cutover (P22+).

`Piwigo\Routing\Router` wraps `symfony/routing`'s `UrlMatcher`; routes live
in `config/routes.php`, empty since P9 pending real controllers.

## Cache architecture (P11)

`Piwigo\Cache\CacheFactory` selects a `symfony/cache` PSR-6 adapter
(`PIWIGO_CACHE_ADAPTER` env var: `apcu` | `redis` | `filesystem`; auto-detects
APCu-if-available else filesystem when unset). `config/container.php` binds
both `Psr\Cache\CacheItemPoolInterface` and `Psr\SimpleCache\CacheInterface`
to the same underlying pool — the PSR-16 binding wraps the pool via
`Symfony\Component\Cache\Psr16Cache` (symfony/cache adapters implement
Symfony's own `Contracts\Cache\CacheInterface`, not PSR-16 directly). This
coexists with, and does not replace, the legacy `Piwigo\Cache\PersistentCache`
family (P6) still used by `admin/maintenance_actions.php` and the legacy
template-compilation path — see `docs/DEVELOPMENT.md`'s CLI section for
exactly which cache layer `bin/piwigo cache:clear` purges.

## CLI commands (P12)

`bin/piwigo` → `Piwigo\Bootstrap\CliBootstrap` → boots the same `Kernel`/DI
container as the HTTP path, builds a `Symfony\Component\Console\Application`,
and resolves each command in `config/commands.php` from the container
(autowired). Commands live in `Piwigo\Command\*` (L4Integration, alongside
`Bootstrap`/`Controller`/`Job`). `Piwigo\Core\ShutdownHandler` wires `SIGTERM`
(via `ext-pcntl`) to run registered cleanup callbacks — used by
`Piwigo\Backup\BackupService` so an interrupted `backup:create`/`restore`
doesn't leave temp files behind. Full command list, what's built vs.
deliberately deferred, and why: `docs/DEVELOPMENT.md`'s CLI section.

## What P6 did not do

- No renaming to modern casing (`pwg_image`, `tabsheet`, `plugins`, etc. keep
  their legacy names — P17–P23).
- No dependency injection or service-layer introduction — classes still read
  globals (`global $conf;`, `global $template;`) exactly as before.
- No behavior changes beyond what was strictly required to survive the
  namespace move itself: qualifying built-in PHP class references
  (`\Exception`, `\DateTime`, …) that used to resolve from the global
  namespace implicitly, fixing dynamic-reference patterns that don't respect
  `use` imports (`new $classString()`, `['ClassName', 'method']` callable
  arrays — changed to `[self::class, 'method']` where self-referential), and
  one genuine bug fix forced by the move: `admin/include/updates.class.php`'s
  `new $type()` dynamic instantiation of `plugins`/`themes`/`languages` by
  bare string, replaced with an explicit `match()`.

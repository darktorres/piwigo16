# Piwigo PHP Rewrite — Ground-Up Architecture

> A clean-room PHP 8.5 rewrite inspired by Piwigo, designed for worker-mode and test-first from day one.  
> No legacy baggage. No `exit()`. No globals. No Smarty. No backward compatibility — greenfield schema, greenfield API, modern tools and concepts throughout.

---

## Table of Contents

1. [Why PHP Rewrite](#1-why-php-rewrite)
2. [Runtime: FrankenPHP](#2-runtime-frankenphp)
3. [Framework: PSR-15 Middleware Stack](#3-framework-psr-15-middleware-stack)
4. [Database Layer](#4-database-layer)
5. [Image Pipeline](#5-image-pipeline)
6. [Template Engine](#6-template-engine)
7. [Plugin System](#7-plugin-system)
8. [Sessions and Auth](#8-sessions-and-auth)
9. [Routing](#9-routing)
10. [Testing and Quality Gates](#10-testing-and-quality-gates)
11. [What Goes Away](#11-what-goes-away)
12. [What Carries Over (Conceptually)](#12-what-carries-over-conceptually)
13. [Repository Structure](#13-repository-structure)
14. [Installation and Rollout](#14-installation-and-rollout)

---

## 1. Why PHP Rewrite

Three paths exist for getting Piwigo to a clean, modern state:

| Approach | Upside | Downside |
|---|---|---|
| **Incremental migration** | No big-bang risk, ships continuously | Worker mode blocked by 263 `exit()` calls and `define()` pollution — architectural debt never resolves |
| **Rust rewrite** | Maximum performance, compile-time SQL safety, single binary | Months of ramp-up, template system requires ~40-50% custom Rust infrastructure, no PHP Composer ecosystem |
| **PHP rewrite** | Worker-native from day one, PHP 8.5 features, full Composer ecosystem, fastest path to production | Still PHP — ceiling is lower than Rust |

The PHP rewrite targets **clean worker-mode architecture and test-first discipline** from the first line of code:

- No `exit()` or `die()` anywhere in application code
- No `define()` inside request scope
- No superglobal mutation in business logic
- No procedural globals (`$conf`, `$user`, `$page`, `$template`)
- PSR-7/PSR-15 throughout
- PHP 8.5: pipe operator (`|>`), clone-with, `#[\NoDiscard]`, `array_first()`/`array_last()`, asymmetric visibility, closures in constant expressions, plus 8.4's `readonly` classes, property hooks, enums, fibers where useful
- Every feature ships with tests — unit, integration, and (where applicable) browser. Code without tests is not merged. See [Testing and Quality Gates](#10-testing-and-quality-gates).

### Not a Piwigo drop-in

This is **not** a Piwigo-compatible replacement. It borrows the domain (photo gallery with albums, permissions, derivatives, plugins) but does not preserve Piwigo's database schema, web-service API, URL layout, theme format, or plugin contracts. Existing Piwigo installations cannot be "upgraded" to it. The goal is a from-scratch modernization, not a migration target.

---

## 2. Runtime: FrankenPHP

**Decision:** FrankenPHP over RoadRunner for the full application.

### Why FrankenPHP wins for the full app

| Feature | RoadRunner | FrankenPHP |
|---|---|---|
| `exit()`/`die()` handling | Must throw `WorkerExitException` manually | Intercepted transparently at the C level — application code never needs to know |
| Superglobals | Must populate `$_GET`, `$_POST`, `$_SERVER` manually from PSR-7 | Auto-populated per request — write normal PHP |
| `$_FILES` | Complex reconstruction from PSR-7 | Native, works as expected |
| Server | External (Apache, Nginx) still needed | Embeds Caddy — HTTP/2, HTTP/3, TLS, static serving, reverse proxy included |
| Worker script complexity | Full PSR-7Worker loop required | `frankenphp_handle_request(fn() => require 'index.php')` |

### Worker entry point

```php
<?php
// franken-worker.php — the entire worker bootstrap

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php'; // builds the DI container + middleware stack

frankenphp_handle_request(function () use ($app): void {
    $app->handle();
});
```

That's it. FrankenPHP resets superglobals, session state, and output buffers between requests automatically.

### Caddyfile

```caddyfile
{
    frankenphp
}

yourdomain.com {
    root * /var/www/piwigo/public
    encode zstd br gzip

    # Static assets served directly by Caddy
    @static path /assets/* /node_modules/* /_data/i/*
    handle @static {
        file_server
    }

    # Everything else to FrankenPHP
    php_server
}
```

Apache goes away entirely.

### Derivative worker

The existing `worker-i.php` (RoadRunner) is replaced with a FrankenPHP equivalent. Same libvips logic, no PSR-7Worker boilerplate:

```php
// franken-worker-i.php
frankenphp_handle_request(function (): void {
    require __DIR__ . '/derivative.php';
});
```

---

## 3. Framework: PSR-15 Middleware Stack

**Decision:** No Laravel, no Symfony full-stack. Compose from focused PSR packages.

### Rationale

Piwigo's domain (permission trees, derivative params, tag/category graphs, plugin hooks, filesystem sync) doesn't align with any framework's conventions. Laravel imposes Eloquent, Blade, its own event system, its own DI container — fighting all of these while porting Piwigo's logic adds friction without removing complexity. Symfony components à la carte is reasonable but the full stack is heavy.

A PSR-15 middleware stack gives a proper request lifecycle with zero opinion about domain logic.

### Package selection

| Layer | Package | Notes |
|---|---|---|
| HTTP routing | `nikic/fast-route` | Used internally by Slim; ~500 LOC; zero dependencies |
| Middleware pipeline | `relay/relay` | Minimal PSR-15 dispatcher |
| Request/Response | `nyholm/psr7` | Already in `composer.json` |
| DI container | `php-di/php-di` | Autowiring, attribute-based injection, PHP 8 native |
| Config | Typed PHP classes + `vlucas/phpdotenv` for env vars | No YAML/XML config files |
| Event/hook system | `league/event` (PSR-14) | See [Plugin System](#7-plugin-system) |
| Logging | `monolog/monolog` | Already in `composer.json` |
| CLI (sync, migrations) | `symfony/console` | Just the Console component, not the full framework |
| Testing | `pestphp/pest` | Built on PHPUnit; terser syntax; parallel + architecture + mutation plugins |
| Static analysis | `phpstan/phpstan` (level `max`) | Gated in CI |
| Code style | `laravel/pint` | Opinionated PHP-CS-Fixer wrapper |
| Browser tests | `symfony/panther` | Headless Chrome driver for end-to-end flows |

The full DI-wired application — including the test harness — is operational once these are installed.

### Middleware stack (inner to outer)

```
Request
  → CorsMiddleware
  → SecurityHeadersMiddleware
  → SessionMiddleware
  → AuthMiddleware         ← populates User from session, checks access level
  → CsrfMiddleware         ← validates token on POST/PUT/DELETE
  → LocaleMiddleware
  → Router                 ← dispatches to controller
Response
```

Each middleware is a `Psr\Http\Server\MiddlewareInterface` implementation, injected with services via PHP-DI.

---

## 4. Database Layer

**Decision:** PDO directly + a thin internal QueryBuilder. No ORM.

### Rationale

523 dynamic SQL queries in the current codebase. An ORM (Eloquent, Doctrine) would require translating every query into a DSL — equal or greater effort, with an abstraction layer that fights the dynamic WHERE/ORDER patterns used by search, batch manager, and filter system.

### Design

```php
// Connection service (singleton in DI container)
final readonly class Database
{
    public function __construct(private \PDO $pdo) {}

    public function query(string $sql, array $params = []): \PDOStatement { ... }
    public function fetchAll(string $sql, array $params = []): array { ... }
    public function fetchOne(string $sql, array $params = []): array|false { ... }
    public function execute(string $sql, array $params = []): int { ... }
    public function lastInsertId(): string { ... }
    public function transaction(\Closure $fn): mixed { ... }
}

// QueryBuilder for dynamic construction (search, filters, batch)
$qb = new QueryBuilder('images i');
$qb->select('i.id, i.path, i.width, i.height')
   ->leftJoin('image_tag it', 'it.image_id = i.id')
   ->where('i.level <= :level', ['level' => $user->level]);

if ($search->tags) {
    $qb->whereIn('it.tag_id', $search->tags);
}

$qb->orderBy($sort->column, $sort->direction)
   ->limit($page->size)
   ->offset($page->offset);

$rows = $db->fetchAll($qb->toSql(), $qb->getParams());
```

No raw string interpolation anywhere. Named parameters everywhere.

### Multi-database

Two PDO adapters implement a `DatabaseAdapterInterface`:

```php
interface DatabaseAdapterInterface
{
    public function connect(DatabaseConfig $config): \PDO;
    public function upsert(string $table, array $data, array $conflictKeys): string;
    public function bulkInsert(string $table, array $rows): void;
    public function fullTextSearch(string $table, array $columns, string $term): string;
    // ... other dialect-specific helpers
}
```

MySQL and PostgreSQL adapters implement this. Business logic never branches on `$conf->dblayer`.

### Migrations

SQL migration files in `database/migrations/`, run by a CLI command:

```
php piwigo migrate
php piwigo migrate:rollback
php piwigo migrate:status
```

---

## 5. Image Pipeline

**Decision:** `jcupitt/vips` as the sole backend. GD and Imagick are dropped.

The fork already requires `jcupitt/vips ^2.5.0` in `composer.json`. The rewrite formalizes this — no more 4-way backend (`pwg_image` class with GD/Imagick/ext_imagick/VIPS branches).

### Capabilities (all via libvips)

| Operation | Implementation |
|---|---|
| Resize | Lanczos (`VIPS_KERNEL_LANCZOS3`) |
| Crop | COI-aware center-of-interest cropping |
| Rotate | EXIF auto-rotation |
| Sharpen | Convolution mask |
| Watermark | Alpha composite |
| Output formats | JPEG (progressive), WebP, AVIF, PNG |
| Metadata stripping | `image.autorot().strip()` |
| Animated WebP/GIF | libvips native support |

### DerivativeService

```php
final readonly class DerivativeService
{
    public function __construct(
        private Database $db,
        private DerivativeConfig $config,
        private StorageService $storage,
    ) {}

    public function serve(DerivativeRequest $req): DerivativeResponse
    {
        $derivative = $this->findOrGenerate($req);
        return new DerivativeResponse($derivative);
    }

    private function generate(SourceImage $src, DerivativeParams $params): string { ... }
}
```

---

## 6. Template Engine

### Comparison

| Engine | Syntax | Context-aware escaping | Runtime extension | Plugin hook support | Verdict |
|---|---|---|---|---|---|
| **Latte** | `{foreach}` `{if}` `{block}` — Smarty-like | ✅ Yes — automatic per context | ✅ Yes — filters, macros, functions at runtime | ✅ Excellent | **Best fit** |
| **Twig** | `{% %}` `{{ }}` — Jinja2-like | ❌ Manual (`\|e('html_attr')`) | ✅ Yes — TwigExtension | ✅ Good | Safe conservative choice |
| **Plates** | Pure PHP | ❌ Manual | ⚠️ Via helper functions | ❌ No block/filter system | Not suitable |
| **Blade** | `@foreach` `@if` `@section` | ❌ Manual | ❌ No runtime registration | ❌ Ruled out | Not suitable |
| **Mustache** | `{{var}}` | N/A | ❌ No logic | ❌ Ruled out | Not suitable |

### Decision: Latte

**Context-aware escaping** is the decisive factor. Latte tracks where in the HTML document a variable is rendered and applies the correct escaping automatically:

```latte
{* Latte automatically uses htmlspecialchars() here *}
<p>{$title}</p>

{* Automatically uses addslashes() for JS context *}
<script>var title = {$title};</script>

{* Automatically uses rawurlencode() for URL context *}
<a href="/search?q={$query}">Search</a>

{* Automatically uses attribute-safe escaping *}
<input title="{$title}">
```

This eliminates an entire class of XSS vulnerabilities with no developer discipline required. Twig requires explicit `|e('html_attr')` — every missed call is a potential XSS.

**Runtime macro registration** maps directly to the plugin hook system:

```php
// Plugin registers a Latte macro at boot time
$latte->addExtension(new MyPluginLatteExtension());

// MyPluginLatteExtension adds a {my_plugin_block} tag that plugins can inject
```

**Syntax familiarity**: Smarty uses `{foreach}`, `{if}`, `{block}`, `{extends}`. Latte uses the same tokens. Template migration is mechanical.

**Sandboxed mode**: Latte can restrict what templates are allowed to call — useful for user-contributed themes or plugins injecting template fragments.

### Template example

```latte
{* themes/darkroom/templates/index.latte *}
{extends 'layout.latte'}

{block content}
<div id="thumbnails" class="thumbnails-grid">
    {foreach $thumbnails as $thumb}
    <div class="thumbnail-cell">
        <a href="{$thumb->url}" data-pswp-src="{$thumb->srcUrl}"
           data-pswp-width="{$thumb->width}" data-pswp-height="{$thumb->height}">
            <img src="{$thumb->thumbUrl}" width="{$thumb->thumbWidth}"
                 height="{$thumb->thumbHeight}" loading="lazy"
                 alt="{$thumb->alt}">
        </a>
    </div>
    {/foreach}
</div>
{/block}
```

No `|escape:'html'` sprinkled everywhere. No risk of forgetting it.

### Theme override system

Child themes work via Latte's native `{extends}`:

```latte
{* themes/my-child-theme/templates/index.latte *}
{extends '../darkroom/templates/index.latte'}

{block content}
{* Override just this block, inherit everything else *}
<div class="my-custom-grid">
    {include parent}
</div>
{/block}
```

---

## 7. Plugin System

**Decision:** PSR-14 event dispatcher (`league/event`) as the hook backbone.

### Current vs rewrite

```php
// Current Piwigo — string-keyed hooks, array passing
$element_info = trigger_change('render_element_content', $element_info, $page);

// Rewrite — typed events, no string matching
$event = new RenderElementContentEvent($elementInfo, $page);
$dispatcher->dispatch($event);
$elementInfo = $event->getElementInfo();
```

### Plugin structure

Plugins are Composer packages with a single bootstrap class:

```php
// plugins/my-plugin/src/MyPlugin.php
final class MyPlugin implements PluginInterface
{
    public static function register(ContainerInterface $container, EventDispatcher $dispatcher): void
    {
        $dispatcher->subscribeTo(RenderElementContentEvent::class, MyContentListener::class);
        $dispatcher->subscribeTo(BeforeImageSaveEvent::class, MyImageListener::class);

        // Register Latte macros
        $container->get(Engine::class)->addExtension(new MyPluginLatteExtension());
    }
}
```

Declared in `composer.json` extras:

```json
{
    "extra": {
        "piwigo-plugin": {
            "bootstrap": "MyVendor\\MyPlugin\\MyPlugin"
        }
    }
}
```

Plugin discovery iterates installed Composer packages looking for the `piwigo-plugin` extra key. No `functions_plugins.php`, no string matching, no `include` magic.

### Hook catalog

Current `trigger_notify`/`trigger_change` hooks become typed event classes in `Piwigo\Events\`. The complete hook catalog from `RUST_REWRITE_PLAN.md` Appendix D applies here verbatim — same events, typed PHP classes instead of Lua callbacks.

---

## 8. Sessions and Auth

FrankenPHP resets `$_SESSION` between requests automatically. The custom `PwgSessionHandler` (currently `inc/PwgSessionHandler.php`) carries over with minimal changes — it's already a clean `SessionHandlerInterface` implementation.

### Auth middleware

```php
final readonly class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private UserRepository $users,
        private SessionService $session,
    ) {}

    public function process(ServerRequestInterface $req, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->session->getUser() ?? User::guest();
        $request = $req->withAttribute('user', $user);
        return $handler->handle($request);
    }
}
```

Access level checks move from scattered `check_status()` calls into route-level middleware or controller constructors:

```php
#[RequiresLevel(AccessLevel::Admin)]
final class AdminController { ... }
```

### Enums for access levels

```php
enum AccessLevel: int
{
    case Guest       = 0;
    case Registered  = 1;
    case Normal      = 2;
    case Private     = 4;
    case Confidential = 8;
    case Webmaster   = 255;

    public function allows(self $required): bool
    {
        return $this->value >= $required->value;
    }
}
```

---

## 9. Routing

All entry points (`index.php`, `picture.php`, `admin.php`, `ws.php`, `i.php`, etc.) collapse into a single `public/index.php` front controller.

### Route map

```
GET  /                              → GalleryController::index
GET  /category/{id:\d+}[-{slug}]   → CategoryController::show
GET  /picture/{id:\d+}[-{slug}]    → PictureController::show
GET  /tags                          → TagController::index
GET  /search                        → SearchController::index
GET  /comments                      → CommentController::index
GET  /calendar[/{view}[/{date}]]    → CalendarController::show
GET  /random                        → RandomController::redirect
GET  /feed                          → FeedController::rss

POST /identification                → AuthController::login
POST /logout                        → AuthController::logout
GET  /register                      → RegisterController::form
POST /register                      → RegisterController::submit

GET  /admin                         → Admin\DashboardController::index
GET  /admin/albums                  → Admin\AlbumController::index
GET  /admin/photos                  → Admin\PhotoController::index
GET  /admin/users                   → Admin\UserController::index
GET  /admin/plugins                 → Admin\PluginController::index
GET  /admin/sync                    → Admin\SyncController::index
... (all current admin pages)

POST /api                           → ApiController::handle
GET  /api                           → ApiController::handle

GET  /i/{params:.+}                 → DerivativeController::serve
```

Admin routes are wrapped in `AdminAuthMiddleware`. API routes are wrapped in `ApiAuthMiddleware` (session or token).

The URL layout is a **fresh design**, not a mirror of Piwigo's legacy entry points. There is no commitment to preserving `picture.php?image_id=…`, `index.php?/category/…`, or the `ws.php` query shape — old Piwigo URLs will not resolve.

---

## 10. Testing and Quality Gates

**Decision:** The rewrite is **test-first from commit one**. No feature is considered complete without tests. CI enforces this — not as a soft goal, but as a gate.

### Test pyramid

| Layer | Framework | Scope | Speed target |
|---|---|---|---|
| Unit | Pest | Pure domain logic (permission resolution, derivative params, search query building, tag graph traversal) — no I/O | < 1 ms each |
| Integration | Pest | Services wired to a real database + real libvips. Migrations run against a SQLite or test-Postgres DB per suite | < 100 ms each |
| HTTP | Pest + `nyholm/psr7` | Boot the full middleware stack, hand in a PSR-7 request, assert on the response — no browser | < 50 ms each |
| Browser | Pest + Symfony Panther | End-to-end flows (login, upload, view album, edit photo, admin actions) against a FrankenPHP dev server | seconds |
| Architecture | Pest Arch | Rule enforcement: no `exit()`/`die()` in `src/`, no `echo`/`print`, controllers only depend on `Domain\*`, no PDO use outside `Database\*`, no superglobals outside middleware | ms |
| Mutation | Infection | Runs nightly; any PR that drops mutation score below threshold blocks | minutes |

### Architecture tests (Pest Arch)

These replace code-review vigilance with compiler-grade enforcement:

```php
arch('no forbidden functions in application code')
    ->expect(['exit', 'die', 'echo', 'print_r', 'var_dump'])
    ->not->toBeUsedIn('Piwigo\\');

arch('controllers stay thin')
    ->expect('Piwigo\\Controller')
    ->toOnlyUse(['Piwigo\\Domain', 'Psr\\Http', 'Piwigo\\Template']);

arch('no raw PDO outside Database layer')
    ->expect('PDO')
    ->toOnlyBeUsedIn('Piwigo\\Database');

arch('no superglobals outside middleware')
    ->expect(['$_GET', '$_POST', '$_SERVER', '$_SESSION', '$_COOKIE', '$_FILES'])
    ->not->toBeUsedIn('Piwigo\\Domain');
```

If someone adds an `exit()` to a controller, CI fails before the PR can be merged. The rules in the "No legacy baggage" banner are enforced mechanically, not by discipline.

### Coverage and static analysis gates

- **Line coverage:** ≥ 85% on `src/Domain/**`, ≥ 70% overall. Enforced in CI.
- **Mutation score:** ≥ 70% on domain code. Nightly job posts trend.
- **PHPStan:** `level: max` — no baselines, no ignores without a `// phpstan-ignore-line` with a justification comment.
- **Pint:** runs on every commit; style violations fail CI.
- **Pest parallel runner:** full unit + integration suite under 30 s on a laptop.

### Test fixtures, not shared state

- Every test gets a fresh DB (SQLite in-memory for most, Postgres via `testcontainers` when a dialect-specific behavior is under test).
- No `setUp` chains with 20 factory calls — each test builds the precise state it needs via tiny domain factories (`ImageFactory::make()->inAlbum($album)->withTags(['vacation'])->create()`).
- The sample image fixtures live in `tests/fixtures/images/` — a small set covering EXIF orientations, HEIC, animated WebP, and the known-pathological JPEGs that have broken derivative generation in the past.

### CI workflow

Every PR must pass, in order:

1. `pint --test` — style
2. `phpstan analyse` — static analysis, level max
3. `pest --parallel --coverage --min=70` — unit + integration + HTTP + arch tests with coverage threshold
4. `pest tests/Browser` — browser tests against a FrankenPHP dev server booted in the job

Nightly: `infection --min-msi=70` on the main branch.

A failing test is never skipped with `->skip()` to get a merge through. Either fix it, delete it (with justification in the PR), or don't merge.

---

## 11. What Goes Away

| Current | Replacement |
|---|---|
| Apache + PHP-FPM | FrankenPHP (embeds Caddy) |
| Smarty 5 | Latte |
| `functions_*.php` procedural files | Service classes, injected via PHP-DI |
| `$conf`, `$user`, `$page`, `$template` globals | Constructor-injected services |
| `pwg_query()` + `addslashes()` | PDO named parameters |
| `trigger_change()` / `trigger_notify()` string hooks | PSR-14 typed events (`league/event`) |
| `exit()` / `die()` for flow control | Exceptions caught by PSR-15 error middleware |
| `define()` at request time | `readonly` config classes, constructor injection |
| 4-way image backend (GD / Imagick / ext_imagick / VIPS) | libvips only (`jcupitt/vips`) |
| GDThumb plugin | Core derivative system |
| Multiple entry points (7 PHP files) | Single front controller (`public/index.php`) |
| `.htaccess` rewrite rules | Caddy native routing |
| Custom script/CSS combiner (`FileCombiner`) | Vite build pipeline (already in place) |
| `PwgTemplateAdapter` (Smarty wrapper) | Latte `Engine` directly |

---

## 12. What Carries Over (Conceptually)

Nothing is preserved for compatibility. What carries over is the **domain model and feature surface** — what a photo gallery *does* — reimplemented from scratch on a clean schema and a new API.

| Concept | Carried over | Not carried over |
|---|---|---|
| Database engines | MySQL + PostgreSQL support | Table names, column names, foreign-key layout, charset choices, legacy indexes, `piwigo_` prefix |
| Permission model | Album-level ACL + per-user access levels (guest/normal/admin/webmaster) | Exact representation; `user_access` / `group_access` tables get redesigned |
| Derivative system | On-demand generation with deterministic URLs + disk cache | Legacy `/i/…` URL grammar, existing cached-derivative path layout |
| Web-service API | JSON HTTP API for third-party clients exists | `ws.php` method names, request/response shape, error codes — **the new API is its own surface**, versioned and documented fresh |
| Themes | Child-theme override mechanism (via Latte `{extends}`) | Smarty `.tpl` files, `{combine_*}`, `{footer_script}` — existing themes do not load |
| Plugins | Event/hook extension points | `trigger_change`/`trigger_notify` string keys, `functions_plugins.php`, existing plugin packages |
| Image pipeline | libvips-backed resize/crop/rotate/sharpen/watermark | `pwg_image` class, GD/Imagick backends, existing derivative params format |

**Existing Piwigo databases are not importable.** Existing clients of `ws.php` will not work. Existing themes and plugins will not load. This is intentional — dragging the schema and API forward would re-import the constraints the rewrite exists to escape.

---

## 13. Repository Structure

```
piwigo-rewrite/
├── public/
│   └── index.php               # Front controller — FrankenPHP entry point
├── bootstrap/
│   └── app.php                 # Builds DI container + middleware stack
├── src/
│   ├── Controller/             # HTTP handlers
│   │   ├── GalleryController.php
│   │   ├── PictureController.php
│   │   ├── Admin/
│   │   └── Api/
│   ├── Domain/                 # Core domain logic (no HTTP dependencies)
│   │   ├── Image/
│   │   ├── Album/
│   │   ├── User/
│   │   ├── Tag/
│   │   ├── Search/
│   │   └── Permission/
│   ├── Event/                  # PSR-14 event classes (hook catalog)
│   ├── Middleware/             # PSR-15 middleware
│   ├── Database/               # PDO wrapper, QueryBuilder, adapters
│   ├── Template/               # Latte Engine setup, extensions
│   ├── Image/                  # DerivativeService, libvips wrapper
│   └── Plugin/                 # Plugin loader, PluginInterface
├── templates/
│   └── default/                # Default theme Latte templates
├── themes/
│   ├── darkroom/
│   └── modus/
├── plugins/                    # Built-in plugins as Composer packages
├── database/
│   └── migrations/             # SQL migration files
├── config/
│   ├── container.php           # PHP-DI definitions
│   └── routes.php              # FastRoute definitions
├── tests/
│   ├── Unit/                   # Pest unit tests — mirror src/ structure
│   ├── Integration/            # Pest integration tests (real DB, real libvips)
│   ├── Http/                   # PSR-7 in, PSR-7 out — full middleware stack
│   ├── Browser/                # Symfony Panther end-to-end flows
│   ├── Arch/                   # Pest Arch rules
│   ├── Factories/              # Domain object factories for tests
│   └── fixtures/               # Sample images, SQL seed data
├── franken-worker.php          # FrankenPHP worker entry point
├── franken-worker-i.php        # Derivative worker entry point
├── Caddyfile
├── phpstan.neon
├── pint.json
├── infection.json5
└── composer.json
```

---

## 14. Installation and Rollout

This is a **fresh install**, not a migration. There is no import tool, no legacy adapter, no backward-compatible shim. An existing Piwigo installation and the rewrite are two separate applications that happen to share a problem domain.

### Fresh install

```
git clone … piwigo-rewrite
cd piwigo-rewrite
composer install
cp .env.example .env         # configure DB creds, storage paths
php piwigo migrate           # create schema from scratch
php piwigo admin:create      # create the webmaster account
frankenphp run --config Caddyfile
```

No `install.php` wizard. No legacy `config/database.inc.php`. All configuration is `.env` + typed config classes.

### For users coming from Piwigo

There is no supported upgrade path. Users who want to move their library will use the same tools anyone else uses to move between gallery systems: bulk re-upload via the API/CLI, plus a user-supplied script if they want to preserve tags and album structure. The project may publish an *unsupported* example script that reads a Piwigo database and emits CLI calls against the new system, but it is not a product feature and is not on the roadmap gate.

### Build order

The rewrite is built vertically feature by feature, each with full test coverage, each shippable:

1. **Foundations** — DI container, middleware stack, PDO + QueryBuilder, Latte wiring, CLI skeleton, test harness, CI pipeline. Arch tests guard the "no legacy baggage" rules from day one.
2. **Auth + users** — login, registration, sessions, access levels. Full HTTP + browser test coverage.
3. **Albums + images + upload** — storage service, album CRUD, image upload with libvips-generated derivatives.
4. **Gallery rendering** — public-facing album/picture pages, themes, PhotoSwipe integration.
5. **Search + tags + comments + calendar** — remaining public features.
6. **Admin UI** — batch manager, user/group admin, permission editor.
7. **Plugin system** — PSR-14 dispatcher, plugin loader, example plugin.
8. **JSON API** — v1 of the new API, OpenAPI spec, versioning policy.

Each step lands behind passing tests. "Ship when the tests pass" is the whole release criterion.

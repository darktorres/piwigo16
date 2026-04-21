# Piwigo PHP Rewrite — Ground-Up Architecture

> A clean-room PHP 8.5 rewrite of Piwigo, designed for worker-mode from day one.  
> No legacy baggage. No `exit()`. No globals. No Smarty.

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
10. [What Goes Away](#10-what-goes-away)
11. [What Is Preserved](#11-what-is-preserved)
12. [Repository Structure](#12-repository-structure)
13. [Migration Strategy](#13-migration-strategy)

---

## 1. Why PHP Rewrite

Three paths exist for getting Piwigo to a clean, modern state:

| Approach | Upside | Downside |
|---|---|---|
| **Incremental migration** | No big-bang risk, ships continuously | Worker mode blocked by 263 `exit()` calls and `define()` pollution — architectural debt never resolves |
| **Rust rewrite** | Maximum performance, compile-time SQL safety, single binary | Months of ramp-up, template system requires ~40-50% custom Rust infrastructure, no PHP Composer ecosystem |
| **PHP rewrite** | Worker-native from day one, PHP 8.5 features, full Composer ecosystem, fastest path to production | Still PHP — ceiling is lower than Rust |

The PHP rewrite targets **clean worker-mode architecture** from the first line of code:

- No `exit()` or `die()` anywhere in application code
- No `define()` inside request scope
- No superglobal mutation in business logic
- No procedural globals (`$conf`, `$user`, `$page`, `$template`)
- PSR-7/PSR-15 throughout
- PHP 8.5: pipe operator (`|>`), clone-with, `#[\NoDiscard]`, `array_first()`/`array_last()`, asymmetric visibility, closures in constant expressions, plus 8.4's `readonly` classes, property hooks, enums, fibers where useful

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

5 new packages total. The full DI-wired application is operational.

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

POST /api                           → ApiController::handle    (ws.php equivalent)
GET  /api                           → ApiController::handle

GET  /i/{params:.+}                 → DerivativeController::serve
```

Admin routes are wrapped in `AdminAuthMiddleware`. API routes are wrapped in `ApiAuthMiddleware` (session or token).

---

## 10. What Goes Away

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

## 11. What Is Preserved

- **MySQL + PostgreSQL** — PDO adapters replace `functions_mysqli.php` / `functions_pgsql.php`
- **Derivative URL scheme** (`/i/...`) — same URL structure, `DerivativeController` handles it
- **`ws.php` API surface** — same method names, same JSON response structure — external clients (apps, scripts) don't break
- **Album/image/tag/user/permission data model** — schema migration not redesign; existing databases importable
- **Theme override mechanism** — Latte `{extends}` replaces Smarty `{extends}`
- **Plugin event catalog** — same hook points, typed classes instead of string keys

---

## 12. Repository Structure

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
├── franken-worker.php          # FrankenPHP worker entry point
├── franken-worker-i.php        # Derivative worker entry point
├── Caddyfile
└── composer.json
```

---

## 13. Migration Strategy

The rewrite does not carry forward any PHP source files. However, the **database schema is preserved** — an existing Piwigo installation can be migrated:

1. Run `php piwigo import:legacy --from=/path/to/old/piwigo` — reads the old `config.php`, connects to the database, migrates any schema differences, verifies image paths
2. Point Caddy at the new codebase
3. Existing URLs continue to work (same routing patterns)
4. Existing API clients continue to work (same `ws.php` method surface)
5. Themes need to be ported from Smarty `.tpl` to Latte `.latte` — largely mechanical (same block names, same variable names, different delimiters)
6. Plugins need to be rewritten against the PSR-14 event API — not compatible with the old hook system

### Template migration (Smarty → Latte)

| Smarty | Latte |
|---|---|
| `{$var}` | `{$var}` (identical) |
| `{$var\|escape:'html'}` | `{$var}` (automatic) |
| `{foreach $items as $item}` | `{foreach $items as $item}` (identical) |
| `{if $cond}` | `{if $cond}` (identical) |
| `{extends 'base.tpl'}` | `{extends 'base.latte'}` |
| `{block name='content'}` | `{block content}` |
| `{include file='partial.tpl'}` | `{include 'partial.latte'}` |
| `{assign var=x value=y}` | `{var $x = y}` |
| `{footer_script}...{/footer_script}` | Custom Latte tag registered by asset system |
| `{combine_css path=...}` | Custom Latte tag registered by Vite helper |

The mechanical differences are small. A Rector-style script could automate 80% of the migration.

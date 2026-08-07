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
11. [Security Model](#11-security-model)
12. [Observability and Operations](#12-observability-and-operations)
13. [Background Jobs and Scheduling](#13-background-jobs-and-scheduling)
14. [Caching Architecture](#14-caching-architecture)
15. [Storage and Backup](#15-storage-and-backup)
16. [Data Model](#16-data-model)
17. [Frontend Architecture](#17-frontend-architecture)
18. [Internationalization](#18-internationalization)
19. [Developer Experience](#19-developer-experience)
20. [Release Engineering and Governance](#20-release-engineering-and-governance)
21. [Search Architecture](#21-search-architecture)
22. [JSON API v1 Design](#22-json-api-v1-design)
23. [Plugin Event Catalog](#23-plugin-event-catalog)
24. [Upload and Filesystem Sync](#24-upload-and-filesystem-sync)
25. [Admin UI and UX](#25-admin-ui-and-ux)
26. [Performance and Scaling](#26-performance-and-scaling)
27. [Data Privacy and Compliance](#27-data-privacy-and-compliance)
28. [Media Types Beyond Photos](#28-media-types-beyond-photos)
29. [End-to-End Workflow Walkthroughs](#29-end-to-end-workflow-walkthroughs)
30. [What Goes Away](#30-what-goes-away)
31. [What Carries Over (Conceptually)](#31-what-carries-over-conceptually)
32. [Repository Structure](#32-repository-structure)
33. [Installation and Rollout](#33-installation-and-rollout)

---

## 1. Why PHP Rewrite

### The state we're leaving behind

Piwigo 14 is a working photo gallery and millions of photos sit happily in installations around the world — this plan is not a dismissal of what it does well. It is, however, an honest look at the architectural debt accumulated over two decades, and a judgment that the cleanest way to resolve it is a clean-room rebuild rather than another refactor.

The technical-debt audit that motivates this rewrite:

| Pain point | Scale in current codebase | Why it blocks progress |
|---|---|---|
| `exit()` / `die()` for flow control | 263 call sites across `include/`, `admin/`, entry points | Worker runtimes (FrankenPHP, RoadRunner) cannot reliably recycle the interpreter when the application terminates the process mid-request |
| `define()` at request scope | ~180 constants set during bootstrap | Constants persist for the worker lifetime, poisoning subsequent requests with stale config |
| Procedural globals | `$conf`, `$user`, `$page`, `$template`, `$lang`, `$header_notes`, `$header_items` | Mutation at a distance — any included file can rewrite request state invisibly |
| `pwg_query()` + manual `addslashes()` | ~523 dynamic SQL sites | String-concatenated queries resist static analysis and complicate parameter handling |
| Smarty 5 with PHP-style escape filters | ~180 `.tpl` files | Escaping requires discipline (`\|escape:'html'`) at every output — any miss is an XSS |
| 4-way image backend selection | GD / Imagick / `ext/imagick` / VIPS branches in `pwg_image` | Every new operation must be implemented four times; edge cases multiply |
| Seven entry points | `index.php`, `picture.php`, `admin.php`, `ws.php`, `i.php`, `action.php`, `identification.php` | Each has its own bootstrap path, its own auth flow, its own error handling |
| `functions_plugins.php` string hooks | 400+ `trigger_change` / `trigger_notify` call sites | Hook names are string-matched at runtime; no type checking, no IDE support, no refactor safety |

None of these can be removed by an incremental refactor without breaking every existing theme and plugin simultaneously — at which point the project has incurred the cost of a rewrite without gaining the benefits.

### Paths considered

| Approach | Upside | Downside | Verdict |
|---|---|---|---|
| **Incremental migration** | No big-bang risk, ships continuously, preserves users | Worker mode blocked by the `exit()` and `define()` pollution listed above — the architectural ceiling never rises. Each ostensibly safe change must preserve the exact semantics of every global, which compounds over years. | Rejected: already the approach of this fork, and returns diminishing value |
| **Rust rewrite** | Maximum performance, compile-time SQL safety (via `sqlx`), single-binary deploy, memory-safe | Months of ramp-up for contributors, template system requires ~40-50% custom Rust infrastructure, no PHP Composer ecosystem to lean on (`jcupitt/vips` has no equivalent stability on the Rust side), and the bulk of Piwigo's code is glue rather than hot paths where Rust's edge matters | Rejected: power-to-weight ratio is wrong for a gallery app |
| **Go rewrite** | Fast, statically typed, good concurrency, solid standard library | Same ecosystem loss as Rust; Go's type system is weak compared to modern PHP (no enums, weak generics until 1.18, no readonly); no equivalent to Latte's context-aware escaping | Rejected |
| **Node/TypeScript rewrite** | Familiar to many contributors, rich ecosystem, excellent tooling | Image-processing bindings less mature than libvips in PHP; runtime fragmentation (Node / Bun / Deno); callers still need a front web server | Rejected |
| **PHP rewrite (this plan)** | Worker-native from day one, PHP 8.5's type system and property hooks, full Composer ecosystem including mature libvips bindings, the broadest contributor pool for a PHP project | Still PHP — raw throughput ceiling is below Rust/Go, but the gap is closed substantially by FrankenPHP + worker mode + libvips offloading the hot paths to C | **Accepted** |

### Design mandates

The rewrite targets **clean worker-mode architecture and test-first discipline** from the first line of code:

- **No `exit()` or `die()`** anywhere in application code. Early returns, exceptions, or explicit response objects only.
- **No `define()`** inside request scope. Constants live on config classes or enums, evaluated at container build time.
- **No superglobal reads in domain code.** `$_GET` / `$_POST` / `$_SERVER` are consumed only by the outermost middleware that builds the PSR-7 request.
- **No procedural globals.** Every service is constructor-injected via the DI container; nothing is reached via `global` or singletons.
- **PSR-7 / PSR-15 / PSR-14 throughout.** Request, response, middleware, and event types all conform to published interfaces — no bespoke reinventions.
- **PHP 8.5 idioms by default.** Pipe operator (`|>`) for data transformations, clone-with for immutable updates, `#[\NoDiscard]` on query builders and result objects, `array_first()` / `array_last()` instead of tricks, asymmetric visibility for DTOs, closures in constant expressions, plus 8.4's `readonly` classes, property hooks, enums, and fibers where they clarify rather than complicate.
- **Every feature ships with tests.** Unit, integration, and (where applicable) browser. Code without tests does not merge. Architecture tests enforce the mandates above mechanically — see [Testing and Quality Gates](#10-testing-and-quality-gates).

### Success criteria

The rewrite is considered successful when:

1. **Worker mode is the default.** A production install boots FrankenPHP in worker mode and stays up across thousands of requests without memory growth, state leaks, or degraded latency.
2. **Cold-start latency is sub-50ms.** Request → response for a cached gallery page completes in under 50 ms at the p95 on commodity hardware, without a CDN in front.
3. **All domain logic is covered by tests.** `tests/Unit` and `tests/Integration` exercise the domain layer end-to-end; CI fails PRs that drop coverage below threshold.
4. **A new contributor can add a feature in under a day.** The architecture is discoverable enough that reading the relevant namespace reveals the full shape of a feature — no hidden `functions_*.php`, no string-hook indirection.
5. **No architectural escape hatches exist.** There is no `src/Legacy/`, no `adaptOldX()` method, no compatibility shim that preserves Piwigo 14 behavior at the cost of clean design.

### Explicit non-goals

To keep the scope honest, the rewrite will **not**:

- Run Piwigo 14 plugins or themes. They were written against a different system and should not be expected to work; attempting compatibility would dictate architecture.
- Serve Piwigo 14 URLs. `index.php?/category/N-slug`, `picture.php?image_id=N`, and `ws.php` are legacy artifacts; the new URL scheme is designed fresh.
- Provide a one-click migration wizard from an existing Piwigo install. See [Installation and Rollout](#33-installation-and-rollout) for the no-supported-migration stance.
- Match feature parity with Piwigo 14 on day one. Features are prioritized by value and built in order; some seldom-used corners (LDAP sync, specific RSS variants, obscure plugin hooks) may never return.
- Ship a WYSIWYG web installer. Installation is CLI-first; a first-run web flow may come later but is not a v1 requirement.

### Not a Piwigo drop-in

This is **not** a Piwigo-compatible replacement. It borrows the domain (photo gallery with albums, permissions, derivatives, plugins) but does not preserve Piwigo's database schema, web-service API, URL layout, theme format, or plugin contracts. Existing Piwigo installations cannot be "upgraded" to it. The goal is a from-scratch modernization, not a migration target. Framing it any other way would smuggle the very constraints the rewrite exists to escape back in through the front door.

---

## 2. Runtime: FrankenPHP

**Decision:** FrankenPHP over RoadRunner, traditional PHP-FPM, or Swoole for the full application. FrankenPHP is PHP's own official app server, maintained by Kévin Dunglas, and embeds Caddy as an HTTP front end.

### Why FrankenPHP wins for the full app

| Feature | PHP-FPM | RoadRunner | Swoole | FrankenPHP |
|---|---|---|---|---|
| `exit()` / `die()` handling | Process death per request (fine) | Must throw `WorkerExitException` — every call site changes | Fiber state gets corrupted; needs wrappers | Intercepted transparently at the C level — application code never knows |
| Superglobals | Native, per-request | Must populate `$_GET`, `$_POST`, `$_SERVER` from PSR-7 in user code | Coroutine-scoped, non-standard | Auto-populated per request — write normal PHP |
| `$_FILES` | Native | Complex reconstruction from PSR-7 | Custom | Native, works as expected |
| Session management | Native | Requires custom handler wiring | Coroutine-unsafe by default | Native, resets between requests |
| HTTP front end | External (Apache, Nginx) still needed | External | External | Embeds Caddy — HTTP/2, HTTP/3, TLS, static serving, reverse proxy all included |
| Worker script complexity | N/A (no worker concept) | Full `PSR-7Worker` loop with Goridge RPC | `Swoole\Http\Server` event hooks | `frankenphp_handle_request(fn() => require 'index.php')` |
| Deployment artifact | PHP binary + Apache/Nginx config + PHP-FPM pool | Roadrunner binary + Caddy/Nginx + PHP-FPM or embedded PHP | Swoole ext + Nginx + supervisord | Single static binary (Go) with PHP embedded, optional `.phar`-equivalent app packaging |
| Mercure (SSE) | No | No | Yes, custom | Built in — real-time notifications without extra infrastructure |

No other runtime matches FrankenPHP's combination of "write normal PHP" and "no external web server". RoadRunner is technically excellent but imposes PSR-7Worker discipline on every entry point; Swoole imposes coroutine semantics on every I/O call. FrankenPHP lets the application code look identical to traditional PHP-FPM code while delivering worker-mode performance.

### Worker lifecycle

When FrankenPHP starts in worker mode, it forks N worker processes (typically one per CPU core). Each worker:

1. Loads `vendor/autoload.php` and builds the DI container once.
2. Enters a loop calling `frankenphp_handle_request()`.
3. On each request: superglobals are populated, the callback runs, output is flushed, superglobals and `$_SESSION` are reset.
4. After K requests (configurable, default 500), the worker voluntarily exits; FrankenPHP forks a replacement. This is the release valve for memory leaks and stale caches.
5. On `SIGUSR2`, the worker completes its current request and exits — this is how graceful deploys roll new code in without dropping connections.

The application code sees none of this. It runs as if it were a traditional PHP-FPM request, except that every service in the DI container was already instantiated when the request arrived.

### Worker entry point

```php
<?php
// franken-worker.php — the entire worker bootstrap

declare(strict_types=1);

ignore_user_abort(true);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php'; // builds the DI container + middleware stack

// Warm up caches that should outlive individual requests:
$app->warmup();

$maxRequests = (int) ($_SERVER['FRANKENPHP_MAX_REQUESTS'] ?? 500);
$handled = 0;

while (frankenphp_handle_request(static function () use ($app): void {
    $app->handle();
})) {
    ++$handled;
    gc_collect_cycles(); // proactively clean cycles before the next request
    if ($handled >= $maxRequests) {
        break; // FrankenPHP will respawn the worker
    }
}
```

FrankenPHP resets superglobals, session state, and output buffers between requests automatically. The application's responsibility is confined to running the middleware stack.

### Memory model in worker mode

Worker mode trades per-request isolation for per-worker persistence. Three consequences shape the design:

1. **Container singletons persist for the life of the worker.** DI container entries marked as singletons (the default for repositories, services, the Latte `Engine`, the PDO connection) live across requests. They must be request-agnostic — no stashing of the current user, no per-request config mutations.
2. **Request-scoped state must be explicit.** Services that genuinely need per-request data (the current `User`, the active `Locale`, the CSRF token) are pulled from PSR-7 request attributes, not from container-level state.
3. **No static mutable state.** Static properties on classes are shared across requests in the same worker. The rule enforced by architecture tests: no non-constant static properties in `src/` outside of dedicated caches with explicit invalidation.

### Caddyfile

```caddyfile
{
    frankenphp
    order php_server before file_server

    # Admin endpoint for metrics and on-the-fly reloads
    admin localhost:2019
}

yourdomain.com {
    root * /var/www/piwigo/public
    encode zstd br gzip

    # Static assets served directly by Caddy — never touches PHP
    @assets path /assets/* /themes/*/assets/* /plugins/*/assets/*
    handle @assets {
        header Cache-Control "public, max-age=31536000, immutable"
        file_server
    }

    # Derivatives — served by PHP the first time (generates on miss),
    # then by Caddy directly once written to the cache directory
    @derivatives path /media/*
    handle @derivatives {
        try_files {path} @php
    }

    # FrankenPHP handles everything else in worker mode
    php_server {
        num_threads auto
        worker /var/www/piwigo/franken-worker.php 4
    }
}
```

Apache goes away entirely. Nginx goes away entirely. No `.htaccess`, no `php-fpm.conf`, no `sites-available`. One binary, one config file.

### Deployment topologies

- **Single-server install.** FrankenPHP binary + Caddyfile + PHP app code. Systemd unit:

  ```ini
  [Unit]
  Description=Piwigo (FrankenPHP)
  After=network-online.target

  [Service]
  ExecStart=/usr/local/bin/frankenphp run --config /etc/piwigo/Caddyfile
  Restart=on-failure
  User=piwigo
  WorkingDirectory=/var/www/piwigo

  [Install]
  WantedBy=multi-user.target
  ```

- **Docker.** Official `dunglas/frankenphp` base image, copy the app, done:

  ```dockerfile
  FROM dunglas/frankenphp:1-php8.5

  COPY . /app
  WORKDIR /app
  RUN composer install --no-dev --optimize-autoloader

  ENV FRANKENPHP_CONFIG="worker /app/franken-worker.php 4"
  EXPOSE 80 443 443/udp
  ```

- **Behind a load balancer.** FrankenPHP's embedded Caddy can terminate TLS, or it can speak plain HTTP behind an external LB. For horizontal scaling, sessions move to Redis or DB-backed storage (see [Sessions and Auth](#8-sessions-and-auth)).

### Graceful reload

Deploys issue `caddy reload --config /etc/piwigo/Caddyfile` or send `SIGUSR2` to the FrankenPHP process. Workers drain (complete in-flight requests), exit, and are replaced by workers running the new code. No dropped connections; no window where half the traffic hits the old code and half hits the new.

### Known gotchas

Worker mode exposes bugs that per-request PHP hides. The ones most likely to bite:

- **PDO connections silently drop** when MySQL's `wait_timeout` elapses between requests. Mitigation: the `Database` service issues a `SELECT 1` health probe on checkout, reconnecting on failure, *or* relies on PDO's `ATTR_PERSISTENT` behavior explicitly.
- **Opcache + file-watch.** Opcache is enabled with `revalidate_freq=0` in dev, `validate_timestamps=0` in prod. In prod, deploys must restart the worker to pick up new PHP files — `SIGUSR2` handles this.
- **`error_reporting()` and `ini_set()` calls leak.** They persist for the worker lifetime. The app must never call them at request scope; prod settings are set via `php.ini` or `FRANKENPHP_CONFIG`.
- **Open file descriptors.** Temporary files, cURL handles, and unbuffered PDOStatements must be closed explicitly — PHP's end-of-request cleanup doesn't fire in worker mode.
- **Monolog handlers.** The `StreamHandler` holds an open file descriptor; that's fine. Handlers that buffer (`BufferHandler`, `FingersCrossedHandler`) need explicit flushing at end-of-request.

Architecture tests enforce the safe patterns. A checklist in `CONTRIBUTING.md` captures the rest.

### Derivative worker

Derivative generation benefits from worker mode too: libvips has a sizeable warm-up cost (loading color profiles, spinning up thread pools) that is paid once per worker instead of once per request.

```php
// franken-worker-media.php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$derivativeApp = require __DIR__ . '/bootstrap/derivative-app.php';

while (frankenphp_handle_request(static function () use ($derivativeApp): void {
    $derivativeApp->handle();
})) {
    // libvips leaves refs in its own cache; trim them periodically
    if (function_exists('vips_cache_set_max_mem')) {
        vips_cache_set_max_mem(256 * 1024 * 1024);
    }
}
```

A separate worker pool for derivative generation keeps slow image operations from starving gallery page requests. Caddy routes `/media/*` to this pool.

### Benchmarks (informal, for scale)

Based on FrankenPHP's published numbers and the Piwigo fork's observed request characteristics, expected throughput for a gallery index page:

| Runtime | Requests/sec (single core, no DB cache) | p95 latency |
|---|---|---|
| Apache + PHP-FPM (current) | ~80 | ~110 ms |
| FrankenPHP worker mode | ~450 | ~18 ms |

These are order-of-magnitude figures. Real numbers will land in the [Testing and Quality Gates](#10-testing-and-quality-gates) benchmark suite once the rewrite exists to measure.

---

## 3. Framework: PSR-15 Middleware Stack

**Decision:** No Laravel, no Symfony full-stack. Compose from focused PSR packages.

### Rationale

Piwigo's domain (permission trees, derivative params, tag/category graphs, plugin hooks, filesystem sync) doesn't align with any framework's conventions. Laravel imposes Eloquent, Blade, its own event system, its own DI container — fighting all of these while porting Piwigo's logic adds friction without removing complexity. Symfony components à la carte is reasonable but the full stack is heavy and drags in opinions about directory layout and bundle wiring.

The alternative is a PSR-15 middleware stack composed from focused packages, each replaceable, each understood end-to-end by a contributor in an afternoon. A PSR-15 middleware stack gives a proper request lifecycle with zero opinion about domain logic, which matters because Piwigo's domain has idiosyncrasies (derivative URL grammar, permission inheritance through album trees, filesystem sync) that no framework was designed for.

### Package selection

| Layer | Package | Notes |
|---|---|---|
| HTTP routing | `nikic/fast-route` | Used internally by Slim; ~500 LOC; zero dependencies; compiled routing table |
| Middleware pipeline | `relay/relay` | Minimal PSR-15 dispatcher (~200 LOC) |
| Request/Response | `nyholm/psr7` + `nyholm/psr7-server` | Already in `composer.json`; fastest PSR-7 implementation in benchmarks |
| DI container | `php-di/php-di` | Autowiring, attribute-based injection, PHP 8 native, compilable to a single file for prod |
| Config | Typed PHP classes + `vlucas/phpdotenv` for env vars | No YAML/XML config files — config is code |
| Event/hook system | `league/event` (PSR-14) | See [Plugin System](#7-plugin-system); typed event classes instead of string keys |
| Logging | `monolog/monolog` | Already in `composer.json`; worker-mode-safe handlers |
| CLI (sync, migrations) | `symfony/console` | Just the Console component, not the full framework |
| Validation | `respect/validation` or custom value objects | Domain-level validation; HTTP-level via form objects |
| Testing | `pestphp/pest` | Built on PHPUnit; terser syntax; parallel + architecture + mutation plugins |
| Static analysis | `phpstan/phpstan` (level `max`) | Gated in CI; no baselines allowed |
| Code style | `laravel/pint` | Opinionated PHP-CS-Fixer wrapper |
| Browser tests | `symfony/panther` | Headless Chrome driver for end-to-end flows |

A deliberate non-choice: no framework-specific router, no framework-specific DI container, no framework-specific template engine. The only "ecosystem" the rewrite is tied to is the PSR ecosystem itself, which is by design vendor-neutral.

### DI container philosophy

PHP-DI is configured in two modes:

- **Development:** autowiring enabled, reflection at runtime, no compiled container. Trades startup speed for zero-configuration — adding a new service means writing the class; the container figures out how to construct it.
- **Production:** the container is compiled to a single PHP file (`var/cache/container.php`). Zero reflection at runtime, all wiring resolved at build time. Loaded in ~1 ms on worker boot.

Wiring uses constructor injection with PHP-DI's `#[Inject]` attribute only where autowiring can't guess — which is rare, because all services declare their dependencies as typed constructor parameters.

```php
final readonly class PictureController
{
    public function __construct(
        private ImageRepository $images,
        private PermissionService $permissions,
        private TemplateEngine $view,
        private Logger $log,
    ) {}

    public function show(ServerRequestInterface $req, int $id): ResponseInterface
    {
        $user = $req->getAttribute('user');
        $image = $this->images->findByIdOrFail($id);
        $this->permissions->assertCanView($user, $image);

        return $this->view->render('picture.latte', [
            'image' => $image,
            'user' => $user,
        ]);
    }
}
```

No `extends`, no `$this->get('service')`, no service-locator pattern. Every dependency is visible on the class signature, which makes testing trivial (instantiate with mocks) and refactoring safe (rename follows all usages).

### Config as code

All configuration lives in typed, immutable PHP classes:

```php
final readonly class DerivativeConfig
{
    public function __construct(
        public string $cacheDirectory,
        public int $jpegQuality,
        public int $webpQuality,
        public int $avifQuality,
        public int $maxWidth,
        public int $maxHeight,
        public bool $preferAvif,
        public array $presets,
    ) {}

    public static function fromEnv(EnvReader $env): self
    {
        return new self(
            cacheDirectory: $env->required('DERIVATIVE_CACHE_DIR'),
            jpegQuality:    $env->int('DERIVATIVE_JPEG_QUALITY', 82),
            webpQuality:    $env->int('DERIVATIVE_WEBP_QUALITY', 80),
            avifQuality:    $env->int('DERIVATIVE_AVIF_QUALITY', 65),
            maxWidth:       $env->int('DERIVATIVE_MAX_WIDTH', 7680),
            maxHeight:      $env->int('DERIVATIVE_MAX_HEIGHT', 7680),
            preferAvif:     $env->bool('DERIVATIVE_PREFER_AVIF', true),
            presets:        require __DIR__ . '/derivative-presets.php',
        );
    }
}
```

No arrays of magic strings. Values are typed, defaults are explicit, and `PHPStan` flags any access to a non-existent property. `.env` is the only place env-specific values live; everything else is versioned.

### Middleware stack (outer to inner)

```
Request arrives
  │
  ├─ ErrorHandlerMiddleware       ← catches exceptions, produces 4xx/5xx JSON or HTML
  ├─ RequestLoggerMiddleware      ← structured log per request (method, path, status, ms)
  ├─ CorsMiddleware               ← CORS headers for API routes
  ├─ SecurityHeadersMiddleware    ← CSP, X-Frame-Options, Referrer-Policy, HSTS
  ├─ TrustedProxyMiddleware       ← unwraps X-Forwarded-* from trusted LBs
  ├─ SessionMiddleware            ← starts / resumes session, writes Set-Cookie
  ├─ AuthMiddleware               ← populates User from session (Guest if none)
  ├─ LocaleMiddleware             ← picks locale from user pref / Accept-Language / URL
  ├─ CsrfMiddleware               ← validates token on POST/PUT/DELETE
  ├─ MaintenanceModeMiddleware    ← 503s all non-admin traffic when flag set
  └─ Router                       ← dispatches to controller, controller produces Response
  ↑
Response flows back up through each middleware
```

Each middleware is a `Psr\Http\Server\MiddlewareInterface` implementation, injected with services via PHP-DI. A middleware can:

- Inspect and modify the request before passing to the next layer (via `$request = $request->with*()`).
- Short-circuit by returning a response without calling `$handler->handle()`.
- Modify the response on the way out.

Example — the CSRF middleware:

```php
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CsrfTokenService $tokens,
        private ResponseFactoryInterface $responses,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        $token = $request->getHeaderLine('X-CSRF-Token')
            ?: ($request->getParsedBody()['_csrf'] ?? '');

        if (! $this->tokens->isValid($request->getAttribute('session'), $token)) {
            return $this->responses->createResponse(419)
                ->withHeader('Content-Type', 'application/json')
                ->withBody(Stream::create(json_encode(['error' => 'csrf_invalid'])));
        }

        return $handler->handle($request);
    }
}
```

### Error handling

The outermost `ErrorHandlerMiddleware` catches every exception the stack can throw and maps it to a response. The mapping is explicit — no "if exception contains 'not found' return 404" heuristics:

```php
final readonly class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Logger $log,
        private ResponseFactoryInterface $responses,
        private ProblemDetailsFactory $problems,
    ) {}

    public function process($request, $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (NotFoundException $e) {
            return $this->problems->make(404, 'not_found', $e->getMessage());
        } catch (ForbiddenException $e) {
            return $this->problems->make(403, 'forbidden', $e->getMessage());
        } catch (ValidationException $e) {
            return $this->problems->withErrors(422, 'validation_failed', $e->errors());
        } catch (\Throwable $e) {
            $this->log->error('unhandled exception', ['exception' => $e]);
            return $this->problems->make(500, 'server_error', 'An error occurred');
        }
    }
}
```

Responses conform to RFC 7807 *Problem Details for HTTP APIs* for JSON routes, and to HTML error pages for gallery routes.

### Controller pattern

Controllers are thin. Any controller longer than 30 lines has business logic that should be in a domain service. The typical controller method:

1. Pull the authenticated `User` from the request attributes.
2. Build a request DTO from the parsed body (via `FormObject::fromRequest($request)`).
3. Call one domain-service method.
4. Return a response — rendered template for HTML, JSON payload for API.

```php
public function store(ServerRequestInterface $req): ResponseInterface
{
    $user   = $req->getAttribute('user');
    $input  = CreateAlbumInput::fromRequest($req);
    $album  = $this->albums->create($user, $input);

    return $this->responses->redirect("/admin/albums/{$album->id}")
        ->withFlash('success', "Album '{$album->name}' created");
}
```

Single-action controllers are used when a route has no natural grouping — each class has one `__invoke()` method.

### Why not Symfony HttpKernel?

Symfony's HttpKernel is excellent but brings opinions about directory layout (`src/Controller/`, `src/Entity/`, etc.) and binds the app to Symfony's event system for its own internal events. The PSR-15 stack here is ~200 LOC of dispatcher plus small, focused middleware — every part can be read in one sitting and replaced if needed. That transparency is worth the DIY wiring cost.

---

## 4. Database Layer

**Decision:** PDO directly + a thin internal QueryBuilder + Repository pattern per aggregate. No ORM.

### Rationale

Piwigo's current codebase has ~523 dynamic SQL query sites. An ORM (Eloquent, Doctrine) would require translating every query into a DSL — equal or greater effort than writing focused SQL, with an abstraction layer that fights the dynamic `WHERE`/`ORDER` patterns used by search, batch manager, and the filter system. The specific pain points ORMs impose on a gallery domain:

- **N+1 is the default failure mode.** Getting it right requires `with()` / eager-load annotations on every query; getting it wrong silently balloons query counts.
- **Schema-first is misaligned with Piwigo's data.** Permission resolution joins `albums`, `user_access`, `group_access`, and `user_group` in patterns that don't map cleanly to relationships — it's a tree traversal problem dressed as SQL.
- **Dynamic search** (tag combinators, date ranges, permission filters) has no clean ORM expression; it always lands back in raw `DB::raw()` escape hatches.
- **Migrations and seeding** get tangled with the ORM's state, making greenfield setup and test isolation harder.

Writing focused SQL in repositories, with a small QueryBuilder for dynamic cases, keeps the query shape visible and the performance predictable. For the 20% of sites that legitimately need dynamic WHERE clauses, the internal QueryBuilder handles it without introducing an entire abstraction layer.

### Layers

```
Controller / Domain Service
    │
    ▼
Repository         ← Domain-friendly API: $albums->findChildrenOf($parent)
    │
    ▼
QueryBuilder       ← Dynamic SQL construction, parameter binding
    │
    ▼
Database           ← Connection, transactions, statement caching, reconnect on timeout
    │
    ▼
PDO                ← MySQL or PostgreSQL driver
```

Controllers never see PDO. Domain services never see PDO. Only repositories talk to the `Database` service. Architecture tests enforce this.

### Database service

```php
final class Database
{
    private \PDO $pdo;
    private array $statementCache = [];

    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        private readonly DatabaseConfig $config,
        private readonly Logger $log,
    ) {
        $this->pdo = $adapter->connect($config);
    }

    /**
     * Health-check + reconnect. Called from a worker-mode
     * "between requests" hook to recover from stale connections.
     */
    public function ensureConnected(): void
    {
        try {
            $this->pdo->query('SELECT 1');
        } catch (\PDOException) {
            $this->log->warning('db reconnect');
            $this->statementCache = [];
            $this->pdo = $this->adapter->connect($this->config);
        }
    }

    #[\NoDiscard('caller must consume the row set')]
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->prepare($sql)->tap(fn ($s) => $s->execute($params))->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->prepare($sql)->tap(fn ($s) => $s->execute($params))->fetch();
        return $row === false ? null : $row;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function transaction(\Closure $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function prepare(string $sql): \PDOStatement
    {
        return $this->statementCache[$sql] ??= $this->pdo->prepare($sql);
    }
}
```

The statement cache is bounded — an LRU eviction at ~500 entries prevents unbounded growth in long-running workers.

### QueryBuilder for dynamic cases

```php
$qb = new QueryBuilder('images i');
$qb->select('i.id, i.path, i.width, i.height, i.created_at')
   ->leftJoin('image_tag it', 'it.image_id = i.id')
   ->leftJoin('images_albums ia', 'ia.image_id = i.id')
   ->where('i.level <= :level', ['level' => $user->level->value]);

if ($search->tags !== []) {
    $qb->whereIn('it.tag_id', $search->tags);
}

if ($search->albumIds !== []) {
    $qb->whereIn('ia.album_id', $search->albumIds);
}

if ($search->dateRange !== null) {
    $qb->where('i.created_at BETWEEN :from AND :to', [
        'from' => $search->dateRange->from->format('Y-m-d'),
        'to'   => $search->dateRange->to->format('Y-m-d'),
    ]);
}

$qb->groupBy('i.id')
   ->orderBy($sort->column, $sort->direction)
   ->limit($page->size)
   ->offset($page->offset);

$rows = $db->fetchAll($qb->toSql(), $qb->getParams());
```

Rules enforced by the QueryBuilder:

- Only named parameters (`:name`). Positional binding rejected.
- Table and column names validated against an allowlist built at startup from the schema. Random string interpolation into `orderBy()` is not possible.
- `WHERE` expressions can be given parameters inline — the builder composes the final param bag.
- `toSql()` returns a fully-bound, parameter-safe statement ready for `prepare()`.

No raw string interpolation for values. Named parameters everywhere. No `${dynamicColumn}` sneaking into the query.

### Repository pattern

Each aggregate has a repository class with a domain-friendly API:

```php
final readonly class AlbumRepository
{
    public function __construct(private Database $db) {}

    public function findById(int $id): ?Album
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM albums WHERE id = :id',
            ['id' => $id],
        );
        return $row ? Album::fromRow($row) : null;
    }

    public function findByIdOrFail(int $id): Album
    {
        return $this->findById($id)
            ?? throw new AlbumNotFoundException($id);
    }

    public function findChildrenOf(Album $parent, User $viewer): array
    {
        $rows = $this->db->fetchAll(
            'SELECT a.* FROM albums a
             WHERE a.parent_id = :parent
               AND a.min_access_level <= :level
             ORDER BY a.rank',
            ['parent' => $parent->id, 'level' => $viewer->level->value],
        );
        return array_map(Album::fromRow(...), $rows);
    }

    public function save(Album $album): Album { ... }
    public function delete(Album $album): void { ... }
}
```

Domain objects (`Album`, `Image`, `User`) are PHP `readonly` classes with a static `fromRow()` constructor. They carry domain behavior (permission checks, derived properties) but never load their own data — they're passive DTOs populated by repositories.

### Connection lifecycle in worker mode

The PDO connection lives for the life of the worker. Between requests:

1. MySQL's `wait_timeout` (default 28800s) can drop the connection if the worker is idle.
2. Transaction leaks — an uncommitted transaction from a previous request would poison the next.

Mitigations:

- `SessionMiddleware` runs an `ensureConnected()` probe on each request. Tiny cost (~0.1 ms), prevents `"MySQL server has gone away"`.
- `ErrorHandlerMiddleware` rolls back any active transaction before propagating an exception — a `finally` block guarantees this even if the controller throws.
- Architecture test: no code outside repositories may call `Database::transaction()`, so the number of transaction sites stays small and auditable.

### Multi-database adapters

MySQL 9.7 LTS, MariaDB 11.8 LTS, and PostgreSQL 18 are all supported. A `DatabaseAdapterInterface` abstracts the dialect-specific operations:

```php
interface DatabaseAdapterInterface
{
    public function connect(DatabaseConfig $config): \PDO;

    /** Returns the SQL fragment for an UPSERT on the given conflict keys. */
    public function upsertSql(string $table, array $columns, array $conflictKeys): string;

    /** Inserts many rows in a single statement, dialect-appropriate. */
    public function bulkInsert(\PDO $pdo, string $table, array $rows): int;

    /** Returns a WHERE fragment for full-text search. */
    public function fullTextWhere(array $columns, string $placeholder): string;

    /** Applies LIMIT/OFFSET in dialect-native form. */
    public function paginate(string $sql, int $limit, int $offset): string;

    /** Quotes an identifier — `col` for MySQL, "col" for Postgres. */
    public function quoteIdentifier(string $name): string;

    /** Returns the DATE_TRUNC equivalent for a given granularity. */
    public function dateTrunc(string $granularity, string $column): string;
}
```

`MySqlAdapter`, `MariaDbAdapter`, and `PostgresAdapter` implement this. Everything dialect-specific lives in these three files — repositories call `$adapter->upsertSql(...)` without branching. `MariaDbAdapter` extends `MySqlAdapter` and overrides only the places MariaDB diverges: the `JSON` type is a `LONGTEXT`-with-check-constraint alias rather than a native type, so functional JSON indexes go through `JSON_VALUE(... RETURNING ...)`; collations use `utf8mb4_uca1400_ai_ci` (MariaDB 10.10+) instead of `utf8mb4_0900_ai_ci`; and `UUID` is a native type (MariaDB 10.7+) so `BINARY(16)` is not needed.

### Schema design principles

The new schema is **not** a port of the Piwigo 14 schema. Key principles:

- **Snake_case table and column names.** No `piwigo_` prefix.
- **Surrogate primary keys** (`bigint id`) on every table. Natural keys get unique indexes instead.
- **Timestamps everywhere.** `created_at`, `updated_at` on every row; `deleted_at` on soft-deletable entities (users, comments — not images or albums, which are hard-deleted).
- **Foreign keys with `ON DELETE CASCADE`** for owned relationships (image tags cascade on image delete); `ON DELETE SET NULL` for non-owning (album parent_id).
- **No polymorphic columns.** `taggable_type` / `taggable_id` anti-patterns stay out; separate join tables instead.
- **UTF-8 / utf8mb4 everywhere.** No Latin-1 fallbacks. PostgreSQL uses `UTF8` encoding with `C.UTF-8` collation; MySQL uses `utf8mb4_0900_ai_ci`; MariaDB uses `utf8mb4_uca1400_ai_ci`.
- **Binary UUIDs** for externally-exposed identifiers (API resources), stored as `BINARY(16)` on MySQL, native `UUID` on Postgres and on MariaDB 10.7+. Sequential `bigint id`s stay internal.
- **Explicit indexes on every foreign key and every WHERE column.** No implicit assumptions about MySQL's behavior.

The full ERD lives in `database/SCHEMA.md` once the rewrite starts.

### Migration framework

SQL-first migrations stored in `database/migrations/YYYYMMDDHHMMSS_description.sql`:

```sql
-- database/migrations/20260501120000_create_albums.sql

-- +migrate up
CREATE TABLE albums (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    parent_id     BIGINT NULL,
    name          VARCHAR(255) NOT NULL,
    slug          VARCHAR(255) NOT NULL,
    description   TEXT,
    rank          INT NOT NULL DEFAULT 0,
    min_level     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES albums(id) ON DELETE SET NULL,
    UNIQUE KEY albums_parent_slug (parent_id, slug)
);

-- +migrate down
DROP TABLE albums;
```

Runner:

```
php piwigo migrate            # apply all pending
php piwigo migrate:status     # list applied/pending
php piwigo migrate:rollback   # roll back the last batch
php piwigo migrate:fresh      # drop everything and reapply (dev + CI only)
php piwigo migrate:make name  # scaffold a new timestamped file
```

Applied migrations are recorded in a `schema_migrations` table with a checksum. A migration whose file contents changed after being applied fails the runner loudly — accidentally editing an applied migration is a bug the framework catches, not a silent divergence.

### Seed data

`database/seeds/` holds the handful of rows the schema requires to be functional (the `guest` user row, default permission levels, the root album). Seeds are idempotent — safe to rerun. The `php piwigo db:seed` command applies them; `migrate:fresh` runs them automatically.

### Read replicas — not yet

Read replicas are not in scope for v1. Gallery reads are dominated by derivative serving (handled by Caddy directly after the first generation) and cached template output, so the DB is not the first bottleneck. If the workload later calls for it, `Database` gains a `readonly()` accessor returning a replica connection, and repositories opt-in per query. The `DatabaseAdapterInterface` already accommodates this shape.

### Logging

Slow queries (> 100 ms) and query exceptions are logged to a dedicated Monolog channel with the SQL (parameterized, not interpolated — parameters go to the `context` field separately so logs can be redacted). Query counts per request are exposed in `X-Query-Count` header in dev mode — a built-in N+1 alarm.

---

## 5. Image Pipeline

**Decision:** `jcupitt/vips` as the sole backend. GD, Imagick, and the `ext/imagick` extension are all dropped.

The fork already requires `jcupitt/vips ^2.5.0` in `composer.json`. The rewrite formalizes this — no more 4-way backend (`pwg_image` class with GD/Imagick/ext_imagick/VIPS branches each implementing resize, crop, watermark independently, often with subtle rendering differences). libvips is consistently faster, uses less memory (streams tiles rather than loading full bitmaps), and produces sharper output than GD/Imagick defaults.

### Why libvips only

| Dimension | GD | Imagick | libvips |
|---|---|---|---|
| Memory for a 10000×7000 JPEG resize | ~800 MB | ~600 MB | ~80 MB (tile streaming) |
| Resize quality (Lanczos) | Lacks kernel choice | Available | `VIPS_KERNEL_LANCZOS3` default |
| AVIF support | No | Via ImageMagick delegates, flaky | Native, fast |
| HEIC support | No | Flaky | Native |
| Multi-threading | No | Global lock | Per-operation parallel |
| Orientation handling | Manual EXIF parsing | `auto-orient` option | `image.autorot()` |
| Color profile handling | No | Yes | Yes, with narrow colorspace conversions |

Keeping multiple backends has a real cost: every new operation must be implemented multiple times, tested multiple times, and its subtle rendering differences documented. Dropping to one backend trades the optionality for consistency and maintainability.

### Capabilities (all via libvips)

| Operation | Implementation |
|---|---|
| Resize | Lanczos (`VIPS_KERNEL_LANCZOS3`), with `shrink-on-load` for JPEG to read at reduced resolution |
| Crop | COI-aware center-of-interest cropping (libvips `smartcrop`) |
| Rotate | EXIF auto-rotation via `autorot()` |
| Sharpen | `sharpen()` with configurable radius/threshold/scale |
| Watermark | Alpha composite with position presets (corners, center, tiled) |
| Output formats | JPEG (progressive), WebP, AVIF, PNG, animated WebP/GIF |
| Metadata stripping | `image.autorot().strip()` — EXIF/XMP/IPTC removed |
| Color profile | sRGB normalization on intake; keep or strip per config |
| Animated content | libvips native — animated WebP and GIF preserved as animated derivatives |
| Multi-page TIFFs | Flatten to first page for derivatives; keep original as source |

### Upload pipeline

When a new image arrives (via web upload, API, or CLI sync), the pipeline runs:

```
Uploaded file
    │
    ▼
Validate extension + magic bytes (no trusting Content-Type)
    │
    ▼
Probe with libvips: dimensions, has_alpha, n_pages (animated?)
    │
    ▼
Extract EXIF/XMP: date taken, camera, lens, GPS, rating
    │
    ▼
Compute perceptual hash (pHash) for duplicate detection
    │
    ▼
Move to final storage (originals/YYYY/MM/{uuid}.{ext})
    │
    ▼
Write row to `images` table with computed metadata
    │
    ▼
Enqueue derivative generation for standard sizes (async)
    │
    ▼
Dispatch AfterImageUploadEvent (for plugins to react)
```

Each step is a small service with its own tests. Failures are caught and returned as structured errors — an unreadable EXIF doesn't abort the upload, it just logs and leaves the field null.

### DerivativeService

The hot path: serving a derivative URL.

```php
final readonly class DerivativeService
{
    public function __construct(
        private ImageRepository $images,
        private DerivativeConfig $config,
        private DerivativeStorage $storage,
        private VipsProcessor $vips,
        private Logger $log,
    ) {}

    public function serve(DerivativeRequest $req): DerivativeResponse
    {
        // 1. Validate & normalize the params. Unknown preset → 404.
        $params = $this->config->resolvePreset($req->preset)
            ?? throw new NotFoundException("unknown preset: {$req->preset}");

        // 2. Resolve source image — 404 if not found, 403 if not permitted.
        $source = $this->images->findByIdOrFail($req->imageId);

        // 3. Compute the cache key (content-addressed — same params on same source = same key).
        $cacheKey = $this->storage->keyFor($source, $params, $req->format);

        // 4. Cache hit → stream the bytes and be done.
        if ($this->storage->exists($cacheKey)) {
            return DerivativeResponse::fromCache($this->storage, $cacheKey);
        }

        // 5. Cache miss → generate under an exclusive lock (prevents thundering herd).
        return $this->storage->withLock($cacheKey, function () use ($source, $params, $req, $cacheKey) {
            if ($this->storage->exists($cacheKey)) {
                return DerivativeResponse::fromCache($this->storage, $cacheKey);
            }

            $bytes = $this->vips->generate($source->sourcePath, $params, $req->format);
            $this->storage->put($cacheKey, $bytes);

            return DerivativeResponse::freshlyGenerated($bytes, $req->format);
        });
    }
}
```

### DerivativeParams

Immutable, hashable, fully typed — this is the single source of truth for what a derivative looks like:

```php
final readonly class DerivativeParams
{
    public function __construct(
        public int $maxWidth,
        public int $maxHeight,
        public CropMode $crop,          // enum: None, CenterOfInterest, FixedAspect
        public ?Aspect $aspect,
        public Sharpen $sharpen,        // small value object
        public ?Watermark $watermark,
        public int $quality,
        public bool $stripMetadata,
    ) {}

    /** Stable, deterministic hash — identical params → identical key. */
    public function hash(): string
    {
        return hash('xxh128', serialize([
            $this->maxWidth, $this->maxHeight, $this->crop->value,
            $this->aspect, $this->sharpen, $this->watermark,
            $this->quality, $this->stripMetadata,
        ]));
    }
}
```

Presets (`thumbnail`, `medium`, `large`, `xlarge`) are defined in config as `DerivativeParams` instances. Custom params (used rarely, for admin tools) are encoded in the URL.

### URL grammar

The new derivative URL is its own design — not a port of Piwigo's `/i/...` grammar. We use `/media/` to keep the path open for future video derivatives without a breaking URL split:

```
/media/{preset}/{image_uuid}.{format}
```

Examples:

```
/media/thumbnail/0198f3c5-7e5a-7c2d-9b1a-b37a2f0c8100.webp
/media/large/0198f3c5-7e5a-7c2d-9b1a-b37a2f0c8100.avif
```

Signed URLs (for custom params, or for private images with time-limited access) add a signature query:

```
/media/custom/{image_uuid}.{format}?w=1024&h=768&crop=coi&s={signature}&exp={unix_ts}
```

Signature is HMAC-SHA256 over the URL components using a per-install secret. Unsigned custom URLs are rejected with 403.

### Format negotiation

The `/media/{preset}/{uuid}` URL (with no extension) returns the best format the client accepts:

- `Accept: image/avif` → AVIF, falls back to WebP then JPEG
- `Accept: image/webp` → WebP, falls back to JPEG
- Otherwise → JPEG

Caddy handles the negotiation via `Vary: Accept`, so a CDN can cache each variant. Explicit-extension URLs (`.webp`, `.avif`, `.jpg`) bypass negotiation.

### Storage backends

`DerivativeStorage` abstracts over:

- **Local disk** — `var/derivatives/{first-2-of-uuid}/{uuid}/{hash}.{ext}`. Caddy serves these directly after the first generation.
- **S3-compatible** — via `league/flysystem-aws-s3-v3`, for deployments that want CDN-backed storage.

The interface:

```php
interface DerivativeStorage
{
    public function exists(string $key): bool;
    public function get(string $key): string|resource;
    public function put(string $key, string $bytes): void;
    public function delete(string $key): void;
    public function withLock(string $key, \Closure $fn): mixed;
    public function keyFor(Image $source, DerivativeParams $params, ImageFormat $format): string;
}
```

Business logic is storage-agnostic. A config flag switches between backends.

### Concurrency and locking

Cache miss for the same URL from two simultaneous requests would otherwise run libvips twice for the same output. `withLock()` uses:

- **Local disk:** `flock()` on a lockfile next to the target path.
- **S3:** SETNX pattern on Redis, with a 30 s TTL.

The lock is held only during generation; reads don't lock.

### Memory and concurrency controls

libvips is per-op parallel; without bounds it will happily saturate all cores for one request. Config limits:

```
VIPS_CONCURRENCY=2          # threads per op (2 = balance of speed + fairness)
VIPS_CACHE_MAX_MEM=256MB    # libvips operation cache per worker
VIPS_DISC_THRESHOLD=1GB     # spill to disk beyond this size
```

Per-request memory is capped in PHP too (`memory_limit=512M` in the derivative worker) as a safety net.

### Streaming response

The `DerivativeResponse` streams bytes rather than buffering:

- Freshly generated bytes are written to disk and streamed from disk in parallel — no double-buffering in PHP memory.
- Cached bytes are streamed with `X-Accel-Redirect` (Caddy `file_server` equivalent) so PHP releases the request early and the bytes flow from Caddy directly.

Result: PHP memory during a derivative serve is < 10 MB even for 50 MB source files.

### Regeneration and cache invalidation

- When a source image is re-uploaded or its metadata changes, its derivative hash changes → new cache keys → old cached files age out (a daily `php piwigo derivatives:prune` CLI).
- When a preset is reconfigured (quality bumped, dimensions changed), its hash changes → same effect.
- Manual invalidation: `php piwigo derivatives:flush {image_id}` removes every derivative of one source; `php piwigo derivatives:flush --all` nukes the whole cache.

### Watermark system

Watermarks are configured as an overlay image + positioning rules:

```php
$watermark = new Watermark(
    imagePath: '/var/www/piwigo/storage/watermark.png',
    position: WatermarkPosition::BottomRight,
    opacity: 0.6,
    minSize: 640, // don't watermark thumbnails
);
```

Applied only to derivatives matching the rules (e.g., never on images the user owns, never on thumbnails). Configurable per-album.

### Testing the pipeline

Golden-image tests: a small fixture set lives in `tests/fixtures/images/` covering:

- EXIF orientations 1–8
- CMYK source JPEGs (must convert to sRGB)
- Very wide panoramas (aspect ratio edge cases)
- HEIC and AVIF sources
- Animated WebP and GIF
- A handful of known-pathological JPEGs that have previously broken derivative generation

Each test runs the pipeline and compares the output hash to a checked-in expected hash. Changes to libvips or to pipeline code that alter output are detected immediately. Human review accepts or rejects the new hash in the PR.

---

## 6. Template Engine

**Decision:** Latte, from Nette. Twig is the runner-up.

### Comparison

| Engine | Syntax | Context-aware escaping | Runtime extension | Plugin hook support | Verdict |
|---|---|---|---|---|---|
| **Latte** | `{foreach}` `{if}` `{block}` — Smarty-like | ✅ Yes — automatic per context | ✅ Yes — filters, macros, tags, functions at runtime | ✅ Excellent — sandboxed mode | **Chosen** |
| **Twig** | `{% %}` `{{ }}` — Jinja2-like | ❌ Manual (`\|e('html_attr')`) | ✅ Yes — TwigExtension | ✅ Good | Safe conservative runner-up |
| **Plates** | Pure PHP | ❌ Manual | ⚠️ Via helper functions | ❌ No block/filter system | Not suitable |
| **Blade** | `@foreach` `@if` `@section` | ❌ Manual | ❌ Laravel-coupled | ❌ Ruled out | Not suitable |
| **Mustache** | `{{var}}` | N/A (logic-less) | ❌ No logic | ❌ Ruled out | Not suitable |

### Why Latte wins

#### Context-aware escaping

**The decisive factor.** Latte tracks where in the HTML document a variable is rendered and applies the correct escaping automatically:

```latte
{* Latte automatically uses htmlspecialchars() here *}
<p>{$title}</p>

{* Automatically uses addslashes() for JS context *}
<script>var title = {$title};</script>

{* Automatically uses rawurlencode() for URL context *}
<a href="/search?q={$query}">Search</a>

{* Automatically uses attribute-safe escaping *}
<input title="{$title}">

{* CSS context *}
<style>.banner { background: url({$bgUrl}); }</style>
```

This eliminates an entire class of XSS vulnerabilities with no developer discipline required. Twig requires explicit `|e('html_attr')`, `|e('js')`, or `|e('url')` — every missed call is a potential XSS. In a codebase with hundreds of templates and many contributors, "don't forget to escape" is a doomed policy.

Latte's parser literally knows it's inside an attribute vs. inside a `<script>` vs. inside a `<style>`, and picks the right encoder. If a variable appears in a place Latte can't safely encode (e.g., as an HTML attribute name, or as a tag name), it refuses to compile.

#### Runtime extension maps to plugins

A plugin registers a Latte extension at boot time, adding custom tags, filters, and functions:

```php
final class GalleryLatteExtension extends Latte\Extension
{
    public function getTags(): array
    {
        return [
            'image'       => ImageTagParser::class,  // {image $img size=thumbnail}
            'album_url'   => AlbumUrlTagParser::class,
            'csrf'        => CsrfTagParser::class,
            'asset'       => AssetTagParser::class,
        ];
    }

    public function getFilters(): array
    {
        return [
            'filesize' => fn (int $bytes) => human_filesize($bytes),
            'duration' => fn (int $seconds) => human_duration($seconds),
        ];
    }
}
```

#### Compiled templates, cached once per worker

Latte compiles `.latte` to `.php` at first render and caches the compiled file. In worker mode, the compiled files sit in opcache — zero parsing cost on subsequent renders. Deploys invalidate the cache via a compile-stamp file check.

#### Sandbox mode for untrusted templates

Latte's sandbox mode restricts what templates can call — useful for user-submitted theme fragments or plugin-injected snippets that should not be allowed to invoke arbitrary PHP. The core themes run unsandboxed; user templates run sandboxed by policy.

#### Syntax familiarity

Smarty (and Smarty-compatible Piwigo themes in the wild) uses `{foreach}`, `{if}`, `{block}`, `{extends}`. Latte uses the same tokens with minor differences. For people coming from Smarty, the learning curve is almost flat — but since this rewrite abandons Piwigo theme compatibility, this matters mainly for the *project's* familiarity, not end users.

### Template example

```latte
{* themes/darkroom/templates/index.latte *}
{extends 'layout.latte'}

{block title}{$album->name} — {$site->title}{/block}

{block content}
    <header class="album-header">
        <h1>{$album->name}</h1>
        {if $album->description}
            <p class="album-description">{$album->description|stripHtml}</p>
        {/if}
    </header>

    <div id="thumbnails" class="thumbnails-grid">
        {foreach $thumbnails as $thumb}
            <div class="thumbnail-cell">
                <a href="{link 'picture', id: $thumb->id}"
                   data-pswp-src="{$thumb->largeUrl}"
                   data-pswp-width="{$thumb->width}"
                   data-pswp-height="{$thumb->height}">
                    {image $thumb size: 'thumbnail', loading: 'lazy'}
                </a>
            </div>
        {/foreach}
    </div>

    {include 'partials/pagination.latte', page: $page}
{/block}
```

No `|escape:'html'` sprinkled everywhere. No risk of forgetting it. The `{image}`, `{link}`, and `{include}` tags are provided by the core `GalleryLatteExtension`.

### Layout strategy

A base layout (`layout.latte`) defines the outer shell (head, nav, footer); page templates `{extends}` it and fill named blocks:

```
themes/default/templates/
├── layout.latte              # Shell: html, head, body, nav, footer
├── index.latte               # Gallery index; {extends layout}
├── album.latte               # Single album view; {extends layout}
├── picture.latte             # Single picture view; {extends layout}
├── search.latte
├── error.latte               # 404/403/500 pages
└── partials/
    ├── nav.latte
    ├── pagination.latte
    ├── thumbnail.latte
    └── footer.latte
```

Partials are always included with explicit params — no inherited `$var` magic. This keeps templates auditable and makes IDE support practical.

### Theme override system

Themes live in `themes/{name}/templates/`. A child theme extends a parent by declaring it in `theme.json`:

```json
{
    "name": "my-custom-theme",
    "parent": "darkroom",
    "version": "1.0.0"
}
```

The `TemplateLoader` then searches in order: `my-custom-theme/templates/` → `darkroom/templates/` → `default/templates/`. Any template the child provides overrides the parent; missing templates fall through.

Inside a template, `{extends}` picks up the parent theme's version of the same file:

```latte
{* themes/my-custom-theme/templates/index.latte *}
{extends 'index.latte'}  {* resolves to the parent theme's index *}

{block content}
    <div class="my-custom-grid">
        {include parent}  {* inherit parent's content *}
    </div>
{/block}
```

### Asset pipeline integration

Themes ship with Vite-built CSS/JS. The `{asset 'main.css'}` tag resolves to the fingerprinted output path via Vite's manifest:

```latte
<link rel="stylesheet" href="{asset 'main.css'}">
<script type="module" src="{asset 'main.js'}"></script>
```

In dev, `{asset}` points at `http://localhost:5173/...` for HMR; in prod, at the fingerprinted built file. Plugin assets work the same way, scoped under `/plugins/{name}/assets/`.

### Translations

Latte templates call `{_'Search'}` for translatable strings; the filter resolves against the current locale's message catalog. Pluralization uses ICU message syntax:

```latte
<p>{_'{count, plural, one {# photo} other {# photos}}', count: $count}</p>
```

The `_()` function is registered on the Latte engine once, reads from the user's locale, falls back to the default locale if missing, and returns the key itself (not a blank) if wholly untranslated — so missing translations are visible in the UI rather than silent.

### Caching strategy

- **Compiled templates:** written to `var/cache/templates/`. Touched only when the source `.latte` changes (checksum-based).
- **Rendered output:** not cached at the template level. Page-level caching (full HTML responses for gallery pages) is a separate concern handled by an HTTP-cache middleware, not by the template engine.

### Template testing

Templates get two test layers:

- **Snapshot tests.** Render each page template with fixture data; compare output to a checked-in snapshot. Deliberate changes accept the new snapshot via `--update-snapshots`; accidental changes fail CI.
- **Accessibility tests.** Rendered output is run through `axe-core` headless in the browser test suite — no WCAG regressions.

### Not planned

- No server-side-rendered React / Vue for v1. The stack is Latte + light JS enhancement (PhotoSwipe, TomSelect, HTMX for specific admin interactions). An SPA-shaped UI is a different project.
- No in-browser template editing / "visual theme builder". Theme authoring is a developer workflow.

---

## 7. Plugin System

**Decision:** PSR-14 event dispatcher (`league/event`) as the hook backbone, plus a Composer-package plugin format with explicit lifecycle hooks. No `functions_plugins.php`, no string matching, no `include` magic.

### Current vs rewrite

```php
// Current Piwigo — string-keyed hooks, array passing
$element_info = trigger_change('render_element_content', $element_info, $page);
trigger_notify('loc_end_index');

// Rewrite — typed events, no string matching, no guesswork about parameters
$event = new RenderElementContentEvent($elementInfo, $page);
$dispatcher->dispatch($event);
$elementInfo = $event->getElementInfo();

$dispatcher->dispatch(new GalleryIndexRenderedEvent($page));
```

Advantages of the typed approach:

- **Static analysis catches misuse.** `$event->getImageInfu()` is a compile error via PHPStan; `trigger_change('redner_element_content', ...)` is a silent no-op.
- **IDE autocompletion** for event classes and their methods.
- **Rename-safe.** Renaming an event class updates every subscriber; renaming a string key updates nothing.
- **Self-documenting payloads.** The event class is the contract; there's no ambiguity about what's passed or what a listener should return.

### PluginInterface

Every plugin implements a single interface:

```php
interface PluginInterface
{
    /**
     * Called once per worker during boot. Register services,
     * subscribe to events, add Latte extensions, etc.
     *
     * Keep it fast — this runs on every worker start.
     */
    public function register(
        ContainerBuilder $container,
        EventDispatcher $dispatcher,
    ): void;

    /**
     * Called once, at install time, before any request.
     * Apply plugin-specific DB migrations here.
     */
    public function install(InstallContext $context): void;

    /**
     * Called once, when the plugin is being removed.
     * Roll back migrations, clean up data the user consents to deleting.
     */
    public function uninstall(UninstallContext $context): void;

    /** Called when the user toggles the plugin on in admin. */
    public function enable(Context $context): void;

    /** Called when the user toggles the plugin off. */
    public function disable(Context $context): void;
}
```

Most plugins implement only `register()`; the lifecycle methods have no-op defaults via a trait.

### Plugin structure

A plugin is a Composer package living in `plugins/{vendor}/{name}/`:

```
plugins/example/gps-map/
├── src/
│   ├── GpsMapPlugin.php
│   ├── Events/
│   │   └── MapRenderedListener.php
│   └── Latte/
│       └── GpsMapExtension.php
├── templates/
│   └── map.latte
├── assets/
│   ├── src/
│   │   ├── map.js
│   │   └── map.css
│   └── dist/                 # Vite output, fingerprinted
├── migrations/
│   └── 20260601000000_gps_cache.sql
├── composer.json
├── vite.config.js
└── README.md
```

Declared in `composer.json`:

```json
{
    "name": "example/gallery-gps-map",
    "type": "gallery-plugin",
    "require": {
        "gallery/gallery": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "Example\\GpsMap\\": "src/"
        }
    },
    "extra": {
        "gallery-plugin": {
            "name": "GPS Map",
            "bootstrap": "Example\\GpsMap\\GpsMapPlugin",
            "min-core-version": "1.0.0",
            "templates": "templates/",
            "assets": "assets/dist/"
        }
    }
}
```

Plugin discovery at boot:

1. Scan `plugins/*/*/composer.json` and any globally-installed Composer packages with `type: gallery-plugin`.
2. Build a dependency graph (plugins may depend on other plugins).
3. Topologically sort — dependencies initialize first.
4. Check each plugin's enabled status (stored in DB).
5. Call `register()` on each enabled plugin, in order.

### Example plugin

A minimal plugin that adds a copyright notice to every picture page:

```php
<?php
namespace Example\Copyright;

use Piwigo\Events\RenderPictureEvent;
use Piwigo\Plugin\PluginInterface;
use Piwigo\Plugin\BasicPlugin;

final class CopyrightPlugin extends BasicPlugin implements PluginInterface
{
    public function register(ContainerBuilder $c, EventDispatcher $d): void
    {
        $d->subscribe(RenderPictureEvent::class, function (RenderPictureEvent $e): void {
            $e->addBottomOfPageHtml(
                '<p class="copyright">© ' . $e->picture->author . '</p>'
            );
        });
    }
}
```

### Event catalog (partial)

Events live in `src/Event/` and are grouped by concern. A partial list:

| Event | When fired | Listener may |
|---|---|---|
| `UserAuthenticatingEvent` | Username/password submitted, before verification | Veto login (e.g., rate limit), add second-factor check |
| `UserAuthenticatedEvent` | After successful login | Log, send notification, set extra session data |
| `UserCreatedEvent` | Row inserted into `users` | Send welcome email, add to mailing list |
| `BeforeImageUploadEvent` | New image about to be saved | Validate, reject, add tags |
| `AfterImageUploadEvent` | Image fully processed + derivatives queued | Notify, index for search, post to activity feed |
| `ImageDeletedEvent` | Image removed (after derivative cleanup) | Log, update external systems |
| `AlbumCreatedEvent` / `AlbumUpdatedEvent` / `AlbumDeletedEvent` | Album lifecycle | Mirror to external service |
| `DerivativeGeneratedEvent` | New derivative file written | Mirror to CDN, log bandwidth stats |
| `BeforeSearchEvent` | Search query about to execute | Inject extra filters, rewrite query |
| `AfterSearchEvent` | Results assembled | Re-rank, filter, decorate |
| `RenderGalleryIndexEvent` / `RenderAlbumEvent` / `RenderPictureEvent` | Public page about to render | Add HTML, add CSS/JS, replace template data |
| `AdminMenuBuildingEvent` | Admin sidebar being constructed | Add menu items |
| `CronEvent` (daily / hourly / every-5-min) | Scheduler tick | Run background work |

The full catalog is documented in `docs/events.md` once the rewrite starts. Events are designed to be **additive** — a new event can be introduced without breaking subscribers of old ones; fields on an event can be added without breaking listeners that don't read them.

### Event design rules

- **Past tense for facts** (`UserCreatedEvent`) — listeners react, can't mutate the fact.
- **Present participle for "before"** (`UserAuthenticating`) — listeners may veto or mutate.
- **Imperative for hook points** (`RenderPictureEvent` — "render this") — listeners contribute content.
- Events carry domain objects, not arrays. `$event->user` is a `User`, not `$event['user']`.
- Mutable events expose typed setters; immutable events expose only getters.

### Subscribing via attributes

Beyond manual `$dispatcher->subscribe()` calls, listeners can self-register via attributes:

```php
final class LogSuccessfulLogins
{
    public function __construct(private Logger $log) {}

    #[Subscribe]
    public function __invoke(UserAuthenticatedEvent $event): void
    {
        $this->log->info('user login', ['user_id' => $event->user->id]);
    }
}
```

At boot, the plugin loader scans its classes for `#[Subscribe]` attributes and auto-subscribes them. Saves boilerplate; the event type is still checked at compile time via the method signature.

### Plugin-specific config

A plugin that needs config declares a `Config` class and registers it:

```php
final readonly class GpsMapConfig
{
    public function __construct(
        public string $tileServer,
        public bool $clustering,
    ) {}
}

// In register():
$container->set(GpsMapConfig::class, fn () => new GpsMapConfig(
    tileServer: $_ENV['GPS_MAP_TILE_SERVER'] ?? 'https://tile.openstreetmap.org',
    clustering: ($_ENV['GPS_MAP_CLUSTERING'] ?? 'true') === 'true',
));
```

Plugin settings stored in DB are managed via a `PluginSettings` service with a simple key/value API.

### Plugin assets

Plugin JS/CSS is built with Vite like the core — the plugin has its own `vite.config.js`, producing a fingerprinted bundle under `assets/dist/`. The core's `{asset}` Latte tag resolves plugin-scoped assets via the plugin's manifest:

```latte
<link rel="stylesheet" href="{plugin_asset 'gps-map', 'map.css'}">
```

Resolves to `/plugins/example/gps-map/assets/dist/map-a7b3c2.css`.

### Plugin DB migrations

Plugins may ship SQL migrations in their `migrations/` directory. Applied as part of `install()`:

```php
public function install(InstallContext $ctx): void
{
    $ctx->runMigrations(__DIR__ . '/../migrations/');
}

public function uninstall(UninstallContext $ctx): void
{
    if ($ctx->shouldDropData()) {
        $ctx->runMigrations(__DIR__ . '/../migrations/', direction: 'down');
    }
}
```

Migrations are tracked alongside core migrations but namespaced (`plugin:example/gps-map:20260601000000`) so an uninstall knows which rows to roll back.

### Plugin testing

Each plugin has its own `tests/` directory using the core's test harness:

```php
it('adds copyright notice to picture page', function () {
    $app = bootApp(withPlugin: CopyrightPlugin::class);
    $response = $app->handle(get('/picture/42'));
    expect($response->getBody())->toContain('© ');
});
```

Plugins are installable into the test harness as mini-packages via Composer path repositories.

### Security model

Plugins run with **full application privilege** — there's no sandboxing of plugin PHP code. Users install plugins by trusting their source. The project's plugin directory (if any) will require code review before publication.

Template fragments injected by plugins *can* be rendered in sandbox mode if the core chooses — a defense for "what if this plugin's author was compromised" at the expense of some feature limits. The sandbox toggle lives on the plugin-loader config; default is full-trust.

---

## 8. Sessions and Auth

FrankenPHP resets `$_SESSION` between requests automatically, so the session-handling code stays close to vanilla PHP. Everything beyond the raw session goes through a `SessionService` that hides the superglobal from domain code.

### Session storage backend

Sessions are stored in the database by default. The `DatabaseSessionHandler` implements `SessionHandlerInterface` against a `sessions` table:

```sql
CREATE TABLE sessions (
    id           VARCHAR(128) PRIMARY KEY,
    user_id      BIGINT NULL REFERENCES users(id) ON DELETE CASCADE,
    payload      MEDIUMBLOB NOT NULL,
    last_active  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at   TIMESTAMP NOT NULL,
    ip_hash      BINARY(32),
    user_agent   VARCHAR(255),
    INDEX (user_id),
    INDEX (expires_at)
);
```

Alternate backends:

- **Redis** (`predis/predis`) for multi-server deployments — the `RedisSessionHandler` swaps in via a config flag.
- **File** for tiny single-server installs (the PHP default, suboptimal for worker mode).

The backend is configured once, in the container; application code never cares.

### SessionService

Domain code never touches `$_SESSION` directly:

```php
final class SessionService
{
    private ?array $data = null;
    private bool $dirty = false;

    public function __construct(private readonly SessionHandlerInterface $handler) {}

    public function start(string $id): void { /* hydrate $this->data */ }
    public function id(): string { ... }
    public function regenerateId(): void { ... }
    public function get(string $key, mixed $default = null): mixed { ... }
    public function put(string $key, mixed $value): void { $this->dirty = true; /* ... */ }
    public function forget(string $key): void { $this->dirty = true; /* ... */ }
    public function flush(): void { ... }
    public function save(): void { if ($this->dirty) { $this->handler->write(...); } }
}
```

`SessionMiddleware` wraps the service around the request — `start()` on request in, `save()` on response out. Architecture tests forbid any access to `$_SESSION` outside `src/Session/`.

### Authentication flow

```
POST /login { username, password }
    │
    ▼
LoginController::submit
    │
    ├─ AuthService::attempt($username, $password)
    │     │
    │     ├─ UserRepository::findByUsername
    │     ├─ PasswordHasher::verify($password, $user->passwordHash)
    │     ├─ RateLimiter::recordAttempt (5 failures / 10 min / IP+username)
    │     └─ Dispatch UserAuthenticatedEvent or UserAuthenticationFailedEvent
    │
    ├─ SessionService::regenerateId()    ← prevents session fixation
    ├─ SessionService::put('user_id', $user->id)
    └─ Redirect home
```

Every step is a small service with its own tests. The controller has ~15 lines of code.

### Password hashing

`argon2id` via PHP's native `password_hash()`:

```php
final class PasswordHasher
{
    private const OPTIONS = [
        'memory_cost' => 65536,   // 64 MB
        'time_cost'   => 4,
        'threads'     => 2,
    ];

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    public function verify(
        #[\SensitiveParameter] string $password,
        string $hash,
    ): bool {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::OPTIONS);
    }
}
```

`PASSWORD_ARGON2ID` is PHP's built-in — no third-party hashing dep. The `needsRehash()` check runs on every successful login; out-of-date hashes are transparently upgraded.

No bcrypt support for new passwords. Existing bcrypt hashes (from legacy imports, if any) are accepted once and upgraded on first successful login.

### Auth middleware

```php
final readonly class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private UserRepository $users,
        private SessionService $session,
    ) {}

    public function process(
        ServerRequestInterface $req,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $userId = $this->session->get('user_id');
        $user = $userId
            ? $this->users->findById($userId)
            : null;

        return $handler->handle($req->withAttribute('user', $user ?? User::guest()));
    }
}
```

Every downstream middleware and controller pulls `$user = $req->getAttribute('user')` — a value always present, never null.

### Access levels as an enum

```php
enum AccessLevel: int
{
    case Guest        = 0;
    case Registered   = 1;
    case Normal       = 2;
    case Contributor  = 4;
    case Moderator    = 8;
    case Admin        = 64;
    case Webmaster    = 255;

    public function allows(self $required): bool
    {
        return $this->value >= $required->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Guest       => 'Guest',
            self::Registered  => 'Registered',
            self::Normal      => 'Normal',
            self::Contributor => 'Contributor',
            self::Moderator   => 'Moderator',
            self::Admin       => 'Administrator',
            self::Webmaster   => 'Webmaster',
        };
    }
}
```

Controllers mark access requirements declaratively:

```php
#[RequiresLevel(AccessLevel::Admin)]
final class AdminPanelController { ... }

#[RequiresLevel(AccessLevel::Registered)]
public function uploadAction(ServerRequestInterface $req): ResponseInterface { ... }
```

An `AuthorizationMiddleware` reads the attribute before dispatching to the controller and returns 403 if the user is below the required level.

### CSRF protection

Every state-changing request carries a CSRF token, validated by `CsrfMiddleware`:

- Tokens are per-session, rotated on login.
- Sent via `X-CSRF-Token` header or `_csrf` body field.
- Double-submit cookie pattern for SPAs / API clients that prefer it.
- SameSite=Lax cookies everywhere; SameSite=Strict for admin-scope cookies.

```latte
<form method="POST" action="/admin/users">
    {csrf}  {* expands to a hidden <input name="_csrf"> *}
    ...
</form>
```

### Rate limiting

Brute-force protection on login, registration, and password-reset:

- Per (IP, username) tuple, 5 failures allowed per 10 minutes, then locked for 30 minutes.
- Per-IP global cap (100 requests/min to auth endpoints) to slow spray attacks.
- Locked accounts do not reveal themselves — responses are indistinguishable from "wrong password" to avoid account enumeration.

Implemented via a simple leaky-bucket counter in Redis or DB. Not a full-blown rate limiter (no distributed consensus), just enough to make automated attacks unprofitable.

### Remember-me cookies

Optional "remember me" checkbox issues a long-lived token (90 days default) stored in a dedicated `remember_tokens` table:

```sql
CREATE TABLE remember_tokens (
    selector   CHAR(32) PRIMARY KEY,     -- random, non-secret
    hash       BINARY(32) NOT NULL,      -- HMAC of the secret, not reversible
    user_id    BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

The cookie carries `selector:token`; the DB stores `selector` and a hash of `token`. Verification compares hashes; stolen cookies can't be directly replayed against the DB. Tokens are single-use — after a remember-me login, the old token is invalidated and a new one issued.

### API tokens

Third-party clients (mobile apps, CLI tools) authenticate with personal access tokens:

```sql
CREATE TABLE api_tokens (
    id          BIGINT PRIMARY KEY,
    user_id     BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name        VARCHAR(100) NOT NULL,
    token_hash  BINARY(32) NOT NULL,    -- stored only as hash
    scopes      JSON NOT NULL,          -- ['read', 'write', 'admin']
    expires_at  TIMESTAMP NULL,
    last_used   TIMESTAMP NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

The token itself is shown to the user exactly once at creation; the server stores only a hash. Token auth is a separate middleware (`ApiTokenMiddleware`) that runs instead of `AuthMiddleware` on `/api/*` routes.

### Two-factor authentication

Not in v1, but the design accommodates it:

- TOTP (authenticator apps) first — standard RFC 6238, `spomky-labs/otphp` library.
- Recovery codes generated at enrollment.
- Webauthn / passkey support as a v2 goal.

When 2FA is enabled on an account, `UserAuthenticatedEvent` fires only after the second factor clears; the first-factor stage sets a short-lived "pending 2FA" session.

### Audit log

Every security-relevant action writes a row to `audit_log`:

- User login / logout / failed login
- Password change / reset
- Permission change (user promoted/demoted, album ACL modified)
- Admin actions (plugin enable/disable, user create/delete, migration run)

The log is append-only from the application's perspective — there is no delete API. Retention policy is configurable (default 365 days).

### Logout

- Clears the session (server-side).
- Rotates the session ID.
- Invalidates any "remember me" token associated with this session.
- Dispatches `UserLoggedOutEvent`.

No "log out from all devices" UI in v1, but the data model supports it — deleting all `remember_tokens` and `sessions` rows for a user does it.

---

## 9. Routing

All entry points (`index.php`, `picture.php`, `admin.php`, `ws.php`, `i.php`, `action.php`, `identification.php`) collapse into a single `public/index.php` front controller. FastRoute compiles the route table to a lookup array at startup; dispatching a request is a hash lookup and a method call.

### URL design principles

The URL layout is a **fresh design**, not a mirror of Piwigo's legacy entry points. There is no commitment to preserving `picture.php?image_id=…`, `index.php?/category/…`, or the `ws.php` query shape — old Piwigo URLs will not resolve.

Guiding rules:

- **No file extensions in URLs.** `/album/vacation-2024`, not `/album.php?name=vacation`.
- **Slugs for humans, IDs for stability.** `/album/{id}-{slug}` — the ID drives the lookup, the slug is cosmetic and can change without breaking links.
- **Plural nouns for collections.** `/albums`, `/photos`, `/tags`, `/users`.
- **Verbs as HTTP methods.** `POST /photos` creates; `PATCH /photos/42` edits; `DELETE /photos/42` deletes. No `/photos/42/edit` HTML action URLs (except for the form page itself, for browser clients).
- **Query strings for filters and pagination**, not for identity. `/albums/42?sort=date&page=2`.
- **No redundant prefixes.** `/admin/albums`, not `/admin/admin_albums`.
- **Stable.** Once a route is public, it doesn't move. New alternatives get new paths; old paths get 301'd if they must change.

### Route map

```
# Public gallery
GET    /                              → GalleryController::index
GET    /albums                        → AlbumController::index
GET    /albums/{id:\d+}[-{slug}]      → AlbumController::show
GET    /photos/{id:\d+}[-{slug}]      → PhotoController::show
GET    /tags                          → TagController::index
GET    /tags/{slug}                   → TagController::show
GET    /search                        → SearchController::index
GET    /comments                      → CommentController::index
GET    /calendar[/{view}[/{date}]]    → CalendarController::show
GET    /random                        → RandomController::redirect
GET    /feed.rss                      → FeedController::rss
GET    /feed.atom                     → FeedController::atom

# Auth / account
GET    /login                         → AuthController::form
POST   /login                         → AuthController::submit
POST   /logout                        → AuthController::logout
GET    /register                      → RegisterController::form
POST   /register                      → RegisterController::submit
GET    /password/reset                → PasswordResetController::form
POST   /password/reset                → PasswordResetController::sendEmail
GET    /password/reset/{token}        → PasswordResetController::confirmForm
POST   /password/reset/{token}        → PasswordResetController::commit
GET    /account                       → AccountController::index
PATCH  /account                       → AccountController::update
GET    /account/tokens                → AccountController::tokens
POST   /account/tokens                → AccountController::createToken
DELETE /account/tokens/{id}           → AccountController::revokeToken

# User-facing album management (uploaders)
GET    /upload                        → UploadController::form
POST   /upload                        → UploadController::submit

# Admin
GET    /admin                         → Admin\DashboardController::index
GET    /admin/albums                  → Admin\AlbumController::index
GET    /admin/albums/{id}             → Admin\AlbumController::edit
PATCH  /admin/albums/{id}             → Admin\AlbumController::update
DELETE /admin/albums/{id}             → Admin\AlbumController::delete
GET    /admin/photos                  → Admin\PhotoController::index
GET    /admin/photos/batch            → Admin\PhotoController::batch
POST   /admin/photos/batch            → Admin\PhotoController::batchSubmit
GET    /admin/users                   → Admin\UserController::index
GET    /admin/users/{id}              → Admin\UserController::edit
PATCH  /admin/users/{id}              → Admin\UserController::update
DELETE /admin/users/{id}              → Admin\UserController::delete
GET    /admin/groups                  → Admin\GroupController::index
GET    /admin/tags                    → Admin\TagController::index
GET    /admin/comments                → Admin\CommentController::index
GET    /admin/plugins                 → Admin\PluginController::index
POST   /admin/plugins/{name}/enable   → Admin\PluginController::enable
POST   /admin/plugins/{name}/disable  → Admin\PluginController::disable
GET    /admin/sync                    → Admin\SyncController::index
POST   /admin/sync                    → Admin\SyncController::run
GET    /admin/maintenance             → Admin\MaintenanceController::index
POST   /admin/maintenance/flush-cache → Admin\MaintenanceController::flushCache

# JSON API (versioned; v1 is the first stable release)
POST   /api/v1/login                  → Api\V1\AuthController::login
POST   /api/v1/logout                 → Api\V1\AuthController::logout
GET    /api/v1/me                     → Api\V1\MeController::show
GET    /api/v1/albums                 → Api\V1\AlbumController::index
POST   /api/v1/albums                 → Api\V1\AlbumController::create
GET    /api/v1/albums/{id}            → Api\V1\AlbumController::show
PATCH  /api/v1/albums/{id}            → Api\V1\AlbumController::update
DELETE /api/v1/albums/{id}            → Api\V1\AlbumController::delete
GET    /api/v1/photos                 → Api\V1\PhotoController::index
POST   /api/v1/photos                 → Api\V1\PhotoController::upload
GET    /api/v1/photos/{id}            → Api\V1\PhotoController::show
PATCH  /api/v1/photos/{id}            → Api\V1\PhotoController::update
DELETE /api/v1/photos/{id}            → Api\V1\PhotoController::delete
GET    /api/v1/search                 → Api\V1\SearchController::index
GET    /api/v1/tags                   → Api\V1\TagController::index

# Derivatives
GET    /media/{preset}/{uuid}.{ext}       → DerivativeController::serve
GET    /media/{preset}/{uuid}             → DerivativeController::serveNegotiated
GET    /media/custom/{uuid}           → DerivativeController::serveCustom   (signed)

# Misc
GET    /sitemap.xml                   → SitemapController::index
GET    /robots.txt                    → RobotsController::index
GET    /manifest.webmanifest          → ManifestController::index
GET    /healthz                       → HealthController::index
```

### Route groups and middleware

Routes are organized into groups by prefix; each group can attach middleware:

```php
// config/routes.php
return function (RouteCollector $r) {
    // Public routes — auth middleware populates $user but doesn't require login
    $r->group([/* no extra middleware */], function (RouteCollector $r) {
        $r->get('/', [GalleryController::class, 'index']);
        $r->get('/albums/{id:\d+}[-{slug}]', [AlbumController::class, 'show']);
        // ...
    });

    // Admin routes — require Admin access level
    $r->group([AdminAuthMiddleware::class], function (RouteCollector $r) {
        $r->addRoute('GET', '/admin', [DashboardController::class, 'index']);
        // ...
    });

    // API routes — support both session and token auth
    $r->group([ApiAuthMiddleware::class, ApiThrottleMiddleware::class, JsonResponseMiddleware::class],
        function (RouteCollector $r) {
            $r->addRoute('GET', '/api/v1/albums', [Api\V1\AlbumController::class, 'index']);
            // ...
        },
    );
};
```

### Attribute-based routing (alternative)

For contributors who prefer colocated routing, controllers can declare routes via attributes:

```php
final class AlbumController
{
    #[Route('GET', '/albums/{id:\d+}[-{slug}]')]
    public function show(int $id, ?string $slug = null): Response { ... }

    #[Route('PATCH', '/admin/albums/{id}', middleware: [AdminAuthMiddleware::class])]
    public function update(int $id): Response { ... }
}
```

At boot, a scanner reads `#[Route]` attributes and adds them to the FastRoute collector. Both styles coexist — large/cohesive admin areas typically use the `config/routes.php` style; small/focused controllers prefer attributes.

### URL generation (reverse routing)

Building URLs in templates and redirects goes through a `UrlGenerator`:

```php
$url = $this->urls->to('album.show', ['id' => $album->id, 'slug' => $album->slug]);
// => /albums/42-vacation-2024

$url = $this->urls->to('photo.show', ['id' => $photo->id, 'slug' => $photo->slug]);
// => /photos/4200-beach-sunset
```

In templates:

```latte
<a href="{link 'album.show', id: $album->id, slug: $album->slug}">
    {$album->name}
</a>
```

Route names are assigned once in the route table; any reference to an undefined name is a compile-time error in Latte (via a custom tag compiler) and a runtime exception in PHP. Rename-safe: rename a route name and grep finds every caller.

### i18n and URL prefixes

Locale handling does *not* prefix URLs by default (`/en/albums/42` is uglier than `/albums/42` and adds SEO complications). Locale is picked from:

1. Authenticated user's preference (stored on `users.locale`).
2. `?lang=fr` query param (sticky — sets a cookie when used).
3. `Accept-Language` header.
4. Fallback to the install's default locale.

If a deployment prefers URL-prefixed locales (multilingual public gallery), a config flag enables `LocalePrefixMiddleware` that adds `/{locale}/...` prefixes and rewrites. Off by default.

### Maintenance mode

`MaintenanceModeMiddleware` checks a file flag (or config value) and returns 503 with a `Retry-After` header for all non-admin requests when set. Admin routes remain accessible so the admin can fix whatever prompted the shutdown. Flag is toggled via:

```
php piwigo down "Deploying v1.2 — back in 5 minutes"
php piwigo up
```

### Error routes

404, 403, 419 (CSRF), 422 (validation), 500 are handled by `ErrorHandlerMiddleware` (see [PSR-15 Middleware Stack](#3-framework-psr-15-middleware-stack)) rather than being routes per se. HTML responses render `templates/error.latte`; JSON responses return RFC 7807 problem details.

### Content negotiation

Controllers that serve both HTML and JSON (rare — the `/api/v1/*` split handles most of this) use an `AcceptNegotiator` helper:

```php
return $this->negotiate($request)
    ->html(fn () => $this->view->render('album.latte', $data))
    ->json(fn () => new JsonResponse($data))
    ->respond();
```

### URL forwarding from legacy paths — not supported

Deliberate non-feature: the app will not ship redirects for legacy Piwigo URLs. A site owner migrating (via whatever tooling they build themselves) who cares about URL preservation is responsible for their own mod_rewrite / Caddy rewrites. Shipping those shims in-app would pollute the design.

---

## 10. Testing and Quality Gates

**Decision:** The rewrite is **test-first from commit one**. No feature is considered complete without tests. CI enforces this — not as a soft goal, but as a gate. There is no "tests will come in a follow-up PR" exception.

### Philosophy

- **Tests describe the feature.** The test suite is the spec; reading the tests for a feature tells you what it does, what its edge cases are, and what its contracts with callers are.
- **Tests are not an afterthought.** They're written alongside (and often before) the code. Pest's expressive syntax makes that cheap enough that "write the test first" stops being aspirational.
- **Architecture rules are tests.** Rules like "no `exit()` in application code" are Pest Arch tests — if someone breaks them, CI fails, not a reviewer's memory.
- **Fast feedback trumps coverage theater.** 100% coverage on trivial getters proves nothing. 70% coverage that exercises every meaningful branch is worth more.

### Test pyramid

| Layer | Framework | Scope | Speed target | Count |
|---|---|---|---|---|
| Unit | Pest | Pure domain logic (permission resolution, derivative params, search query building, tag graph traversal, slug generation, hash calculation) — no I/O at all | < 1 ms each | The bulk — expected thousands |
| Integration | Pest | Services wired to a real database + real libvips. Migrations run against a SQLite or test-Postgres DB per suite | < 100 ms each | Hundreds |
| HTTP | Pest + `nyholm/psr7` | Boot the full middleware stack, hand in a PSR-7 request, assert on the response — no browser | < 50 ms each | One per route |
| Browser | Pest + Symfony Panther | End-to-end flows (login, upload, view album, edit photo, admin actions) against a FrankenPHP dev server | seconds | ~30–50 critical flows |
| Architecture | Pest Arch | Rule enforcement | ms | ~20 rules |
| Mutation | Infection | Verifies tests kill real bugs | minutes | Nightly only |
| Contract | Pest | API contract tests (v1 JSON responses don't drift) | < 50 ms each | One per API endpoint |
| Performance | Pest + k6 or custom benchmark harness | p50/p95/p99 latency for hot paths | varies | Small, nightly |

### Pest, not PHPUnit directly

Pest is built on PHPUnit but reads more like a spec. Compare:

```php
// PHPUnit
public function testGuestCanViewPublicAlbum(): void
{
    $album = AlbumFactory::new()->public()->create();
    $user = User::guest();

    $permissions = new PermissionService($this->db);

    $this->assertTrue($permissions->canView($user, $album));
}
```

```php
// Pest
it('allows a guest to view a public album', function () {
    $album = AlbumFactory::new()->public()->create();

    expect(new PermissionService($this->db)->canView(User::guest(), $album))
        ->toBeTrue();
});
```

The lower ceremony matters when there are thousands of tests. Pest also ships native parallelization (`--parallel`), architecture tests, and a mutation-testing plugin.

### Architecture tests (Pest Arch)

These replace code-review vigilance with compiler-grade enforcement:

```php
// tests/Arch/NoLegacyBaggage.php

arch('no process-killing functions in application code')
    ->expect(['exit', 'die', 'exit_nested', 'pcntl_exec'])
    ->not->toBeUsedIn('Piwigo\\');

arch('no direct output')
    ->expect(['echo', 'print_r', 'var_dump', 'print', 'printf'])
    ->not->toBeUsedIn('Piwigo\\');

arch('controllers stay thin — depend only on services + PSR-7 + templates')
    ->expect('Piwigo\\Controller')
    ->toOnlyUse([
        'Piwigo\\Domain',
        'Piwigo\\Template',
        'Piwigo\\Http',
        'Psr\\Http',
        'Psr\\Log',
    ]);

arch('no raw PDO outside Database layer')
    ->expect('PDO')
    ->toOnlyBeUsedIn('Piwigo\\Database');

arch('no superglobals outside the outer middleware ring')
    ->expect(['$_GET', '$_POST', '$_SERVER', '$_SESSION', '$_COOKIE', '$_FILES'])
    ->not->toBeUsedIn('Piwigo\\Domain')
    ->and->not->toBeUsedIn('Piwigo\\Controller')
    ->and->not->toBeUsedIn('Piwigo\\Template');

arch('domain objects are final and readonly')
    ->expect('Piwigo\\Domain')
    ->classes()
    ->toBeFinal()
    ->and->toBeReadonly();

arch('no framework coupling in domain')
    ->expect('Piwigo\\Domain')
    ->not->toUse(['Symfony', 'Laravel', 'Slim']);

arch('migrations never reference live PHP classes')
    ->expect('database\\migrations')
    ->not->toUse('Piwigo\\');

arch('no global state')
    ->expect('Piwigo\\')
    ->not->toUse(['global', '$GLOBALS']);
```

If someone adds an `exit()` to a controller, CI fails before the PR can be merged. The "No legacy baggage" banner on this document is a set of rules enforced mechanically.

### Coverage and static analysis gates

- **Line coverage:** ≥ 85% on `src/Domain/**`, ≥ 70% overall. Enforced in CI via `--min=`.
- **Branch coverage:** tracked (Xdebug in coverage mode), not gated — branch coverage gaming is more effort than it's worth.
- **Mutation score:** ≥ 70% on domain code via Infection. Nightly job; any PR dropping the trend posts a CI comment.
- **PHPStan:** `level: max`. No baselines allowed — each ignore must be a `// phpstan-ignore-line` with a justification comment, and `phpstan-baseline.neon` is git-ignored by policy.
- **Psalm** is an open consideration — may run alongside PHPStan if there's a signal PHPStan misses.
- **Pint:** runs on every commit; style violations fail CI. `pint --test` in CI, `pint` locally to autofix.
- **Pest parallel runner:** full unit + integration + HTTP + arch suite under 30 s on a developer laptop.

### Test data: factories, not fixtures

Every test builds exactly the data it needs:

```php
// tests/Factories/ImageFactory.php
final class ImageFactory
{
    public static function new(): ImageBuilder
    {
        return new ImageBuilder();
    }
}

final class ImageBuilder
{
    private ?Album $album = null;
    private array $tags = [];
    private AccessLevel $level = AccessLevel::Guest;

    public function inAlbum(Album $album): self    { $this->album = $album; return $this; }
    public function withTags(array $tags): self    { $this->tags = $tags; return $this; }
    public function private(): self                { $this->level = AccessLevel::Private; return $this; }

    public function create(): Image
    {
        $image = new Image(...);
        $this->db->save($image);
        foreach ($this->tags as $tag) { $this->db->tag($image, $tag); }
        return $image;
    }
}

// Test usage
$image = ImageFactory::new()
    ->inAlbum($album)
    ->withTags(['vacation', 'beach'])
    ->private()
    ->create();
```

No shared `tests/fixtures/sample_data.sql`. No `setUp()` chains with 20 factory calls. Each test declares its own setup, which also documents what the test is about.

### Fresh DB per test

- **SQLite in-memory** for most integration tests — zero setup, zero cleanup, faster than a real DB.
- **PostgreSQL via `testcontainers`** when a test exercises Postgres-specific behavior (JSONB, array types, full-text). Spun up once per test suite, shared within it.
- **MySQL 9.7 LTS via `testcontainers`** for MySQL-specific behavior. A second job runs **MariaDB 11.8 LTS** through the same adapter as a compatibility gate.
- **No "golden test DB"** that all tests share. The next test shouldn't care what the previous one did.

Migrations run at the start of each test DB creation; transactions wrap each test so changes roll back. For tests that themselves commit (rare — transaction-boundary tests), a `RefreshDatabase` trait drops-and-recreates.

### Image fixtures

`tests/fixtures/images/` holds a small, curated set covering pipeline edge cases:

```
tests/fixtures/images/
├── orientations/           # EXIF 1-8
│   ├── orient-1.jpg
│   ├── orient-2.jpg
│   ├── ...
│   └── orient-8.jpg
├── colorspaces/
│   ├── cmyk.jpg
│   ├── adobe-rgb.jpg
│   └── prophoto.jpg
├── formats/
│   ├── animated.webp
│   ├── animated.gif
│   ├── photo.heic
│   ├── photo.avif
│   └── multi-page.tif
├── edge-cases/
│   ├── very-wide-panorama.jpg
│   ├── very-tall-portrait.jpg
│   ├── corrupted-exif.jpg
│   └── truncated.jpg
└── pathological/           # Files that have historically broken the pipeline
    ├── issue-123.jpg
    └── issue-456.jpg
```

Each goes through a golden-image test — pipeline output is hashed and compared to a checked-in expected hash. Intentional output changes update the hash via `--update-snapshots`; accidental ones fail CI.

### Snapshot testing for templates

Rendered templates are snapshot-tested: given fixture data, the HTML output is compared to a stored snapshot. Deliberate template changes update the snapshot; accidental changes fail.

```php
it('renders the album page with default theme', function () {
    $album = AlbumFactory::new()->withPhotos(5)->create();

    $html = $this->view->render('album.latte', ['album' => $album]);

    expect($html)->toMatchSnapshot();
});
```

Snapshots live beside tests (`tests/Snapshots/album.html`). PR reviewers see the diff of the snapshot, which is the diff of the rendered HTML — more reviewable than the Latte source change alone.

### Property-based testing

For algorithms with invariants (permission resolution, slug generation, URL signing), use property-based tests with `eris/eris`:

```php
it('slug is always URL-safe regardless of input', function () {
    forAll(strings())->then(function (string $input) {
        $slug = Slug::from($input);
        expect($slug->value)->toMatch('/^[a-z0-9-]*$/');
    });
});
```

Property tests find edge cases a human wouldn't think to write (empty strings, unicode combining marks, very long inputs).

### Performance regression tests

A small benchmark suite runs nightly:

- Gallery index page (100 photos, 5 tags, not logged in): p95 target < 20 ms
- Album page with 50 thumbnails: p95 < 30 ms
- Picture page: p95 < 15 ms
- Derivative serve (cache hit): p95 < 5 ms
- Derivative serve (cache miss, small image): p95 < 200 ms

A regression of > 20% on any hot-path benchmark fails the nightly run and posts to the project's dashboard. Flaky benchmarks are excluded, not tolerated — a benchmark that measures nondeterminism is wrong.

### Contract tests for the v1 API

Every API response has a contract test:

```php
it('GET /api/v1/albums/{id} matches the v1 contract', function () {
    $album = AlbumFactory::new()->create();
    $response = $this->api->get("/api/v1/albums/{$album->id}");

    expect($response)
        ->toHaveStatus(200)
        ->toMatchJsonSchema('schemas/album.v1.json');
});
```

Schemas live in `api/schemas/v1/`; breaking-change detection compares committed schemas against proposed diffs. A breaking v1 change is rejected; v2 requires a new namespace.

### CI matrix

Each PR runs tests across:

- PHP: 8.5 (primary), 8.4 (compat verification)
- DB: MySQL 9.7 LTS (primary), MariaDB 11.8 LTS (compat), PostgreSQL 18
- OS: Ubuntu 22.04 (primary), macOS 14 (smoke test)

The full matrix runs on main-branch pushes; per-PR jobs run only the primary cell to keep feedback fast.

### CI workflow (per-PR)

```
1. Checkout → composer install
2. pint --test                                           (style, ~10s)
3. phpstan analyse --memory-limit=2G                     (static, ~60s)
4. pest --parallel --coverage --min=70                   (unit/integ/HTTP/arch, ~30s)
5. pest tests/Contract                                   (API contracts, ~10s)
6. pest tests/Browser                                    (FrankenPHP + Panther, ~2-3 min)
```

Total wall time: ~5 min.

Nightly (on main):

```
1. infection --min-msi=70                                (mutation, ~15 min)
2. pest tests/Performance --repeat=10                    (benchmarks, ~10 min)
3. security check (composer audit + pip-audit on CI)    (~30s)
```

### Flaky test policy

Any test that fails intermittently is **quarantined** (moved to `tests/Quarantine/`) within one working day of the flake being observed. The quarantined test must be fixed or deleted within a week. Flakes are not tolerated — they erode trust in the suite and get ignored; ignored tests are worse than no tests.

### No `->skip()` as a merge escape hatch

A failing test is never skipped to get a merge through. Either fix it, delete it (with explicit justification in the PR body), or don't merge. The `->skip()` modifier is legitimate only for environment-dependent tests (e.g., "skip if libvips not installed"), not for regressions.

### Reviewability

PR descriptions include a "tests" section that answers:

1. What does this change? (1 sentence)
2. What tests cover it? (links)
3. How would you break this and have a test catch it?

If question 3 can't be answered, the change is under-tested.

---

## 11. Security Model

**Principle:** defense in depth. Every layer assumes the layer in front of it has failed. No single mitigation is load-bearing.

### Input validation philosophy

Input comes in at three boundaries — HTTP, CLI, and filesystem sync. At each boundary, input is coerced into typed value objects before it reaches domain code. Domain code never sees a raw `$_POST` array; it sees `CreateAlbumInput` with a typed `name` string, a validated `parentId` integer, a bounded `description` length.

Typed form objects do the enforcement:

```php
final readonly class CreateAlbumInput
{
    private function __construct(
        public string $name,
        public ?int $parentId,
        public string $description,
    ) {}

    public static function fromRequest(ServerRequestInterface $req): self
    {
        $data = (array) $req->getParsedBody();

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new ValidationException(['name' => 'required, 1-255 chars']);
        }

        $parentId = null;
        if (isset($data['parent_id']) && $data['parent_id'] !== '') {
            $parentId = filter_var($data['parent_id'], FILTER_VALIDATE_INT);
            if ($parentId === false) {
                throw new ValidationException(['parent_id' => 'must be int']);
            }
        }

        $description = (string) ($data['description'] ?? '');
        if (mb_strlen($description) > 10_000) {
            throw new ValidationException(['description' => 'max 10000 chars']);
        }

        return new self($name, $parentId, $description);
    }
}
```

Validation throws `ValidationException`; the error middleware maps it to 422 with field-level details.

### Output encoding

Latte handles it. (See section 6.) The security argument for Latte over Twig is precisely that output encoding is automatic and context-correct. Architecture tests forbid `echo` / `print` in application code — all output goes through Latte or JSON responses.

For JSON responses, `json_encode` with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE` is the only codepath; a `JsonResponse` wrapper enforces it.

### File upload security

Uploads are the highest-risk input surface. The pipeline:

```
Multipart file received
    ↓
1. Reject if content-length > configured max (default 100 MB)
    ↓
2. Reject if declared MIME isn't in the allowed list
    ↓
3. Stream to quarantine dir (not web-accessible) with randomized name
    ↓
4. Probe magic bytes via libvips — file MUST be what it claims
    ↓
5. libvips loads headers — corrupt or malicious files fail here
    ↓
6. Extract EXIF in try/catch — failures log but don't abort upload
    ↓
7. Scan with ClamAV (optional, via clamav-client)
    ↓
8. If all pass → move to originals/ with UUIDv7 filename
    ↓
9. Dispatch BeforeImageSaveEvent — plugins can veto
    ↓
10. Write DB row, enqueue derivative generation
```

Non-negotiables:

- **Files are never served from the upload directory.** Originals get UUID filenames; the mapping from user filename → UUID is a one-way door.
- **Magic-byte check via libvips.** Trusting `Content-Type` or file extension is never sufficient.
- **EXIF stripping** is the default for derivatives. Originals keep EXIF; derivatives don't.
- **Streaming via libvips** keeps memory bounded — a 100 MB malicious JPEG doesn't load 100 MB into RAM.
- **Filename sanitization on display.** User-provided original filenames are stored for UI but never used on disk.
- **No SVG uploads** without sandboxing. SVG is executable XML; by default rejected. Opt-in per-install re-enables with a strict sanitizer.

### CSRF deep dive

Introduced in section 8. Key details:

- **SameSite=Lax** for session cookies. Blocks most cross-origin POSTs.
- **SameSite=Strict** for admin-scope cookies. Admin actions never succeed cross-origin.
- **Double-submit cookie** pattern is the default for API clients.
- **Token rotated on login.** An unauthenticated session gets a fresh token when elevated.
- **Per-form tokens not required.** A single session-scoped token suffices; per-form would be defense-in-depth we don't need here.

### Content Security Policy

Strict CSP by default, per-response nonce for inline `<script>` / `<style>` (the Latte engine injects the nonce automatically):

```
Content-Security-Policy:
  default-src 'self';
  script-src 'self' 'nonce-{generated}';
  style-src 'self' 'nonce-{generated}';
  img-src 'self' data:;
  font-src 'self';
  connect-src 'self';
  frame-ancestors 'none';
  form-action 'self';
  base-uri 'self';
  upgrade-insecure-requests
```

Deployments with an external image CDN extend `img-src`; plugins declaring third-party script origins add them via a manifest. No `unsafe-inline`, no `unsafe-eval`.

CSP violations are reported to `/api/csp-report` and counted as metrics — a spike in violations is an alert.

### Other security headers

- `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload` when `APP_URL` is HTTPS.
- `X-Frame-Options: DENY` (belt-and-braces with `frame-ancestors 'none'`).
- `Referrer-Policy: strict-origin-when-cross-origin`.
- `Permissions-Policy: camera=(), microphone=(), geolocation=(self)` — default-deny, per-feature opt-in.
- `X-Content-Type-Options: nosniff`.
- `Cross-Origin-Opener-Policy: same-origin`, `Cross-Origin-Embedder-Policy: require-corp` where compatible.

All set by `SecurityHeadersMiddleware`.

### SSRF protection

Any feature fetching a user-supplied URL (import from URL, OAuth redirect, webhook dispatch) goes through an `SsrfGuard`:

- **Pinned IP resolution.** DNS resolved once; the HTTP request is made to the resolved IP with the original Host header — DNS-rebinding attacks lose their target.
- **Private-range rejection.** Resolved IPs in RFC 1918 (10/8, 172.16/12, 192.168/16), loopback (127/8), link-local (169.254/16), IPv6 equivalents — all refused.
- **Scheme allowlist.** Only HTTP/HTTPS. No `file://`, `gopher://`, `dict://`, `ldap://`.
- **Redirect follow-through.** Each hop re-runs the guard.
- **Bounded response size + timeouts.** Response capped at configurable max (default 10 MB); 5 s connect, 30 s read.

### XML / XXE prevention

`libxml_disable_entity_loader()` is no longer needed on PHP 8.0+, but the app never passes `LIBXML_NOENT` or `LIBXML_DTDLOAD`. Sitemaps and RSS are **generated**, not parsed. Any incoming XML (rare — the API is JSON) is rejected unless explicitly enabled via config, in which case entity substitution is disabled at parse time.

### Open redirect

`RedirectResponse::to($url)` validates the target against an allowlist: relative URLs only, or absolute URLs matching `APP_URL`. A login `?next=/albums/42` param validates the same way; external redirects return 400.

### Timing attacks

- Password verification uses `password_verify()` (constant-time).
- Session IDs and tokens compared with `hash_equals()`.
- Login response time for valid-username-wrong-password vs. unknown-username normalized — both trigger a bcrypt/argon2 verify against a dummy hash so the response time doesn't leak account existence.

### Secrets management

- `APP_SECRET` is a 32-byte random hex. Used for CSRF tokens, signed URLs, remember-me HMACs. Rotating it logs out every session and invalidates every remember-me — acceptable trade-off, documented.
- `.env` is never committed. `.env.example` is committed as a template.
- Prod secrets: read from environment (Docker / systemd `EnvironmentFile=`); the `.env` in prod is root-owned, mode `600`.
- Backup procedures explicitly exclude `.env`.
- Optional: `APP_KEY_PROVIDER=vault|aws-secrets-manager|gcp-secret-manager` reads secrets at boot from an external store; the secret never lives on disk.

### Rate limiting catalog

Per-endpoint limits (configurable, defaults sized for small-to-medium sites):

| Endpoint | Key | Limit | Action over limit |
|---|---|---|---|
| `POST /login` | IP + username | 5 fails / 10 min, then 30 min lock | 429 with `Retry-After` |
| `POST /register` | IP | 3 / hour | 429 |
| `POST /password/reset` | IP + email | 3 / hour | 429 |
| Any `POST /api/v1/*` | token or IP | 60 / min | 429 |
| `POST /api/v1/photos` (upload) | token | 100 / hour, 10 GB / day | 429 |
| `POST /comments` | IP | 10 / hour | 429 |
| Any endpoint | IP (global) | 300 / min | 429 |

Implemented via leaky-bucket counters in Redis (or DB fallback). Not a full-blown rate limiter (no distributed consensus), just enough to make automated attacks unprofitable.

### Dependency auditing

- `composer audit` runs in CI on every PR; any CVE on a direct or transitive dep fails the build unless explicitly waived in `composer.json conflict` with a justification comment and a time-bounded removal issue.
- `bun audit` in CI for JS.
- **Renovate** (or Dependabot) manages version bumps: patch bumps merge automatically after CI; minor bumps need human review; major bumps create tracking issues.
- A weekly CI job audits all release branches, even dormant ones — stale branches don't drift unnoticed.

### `security.txt`

Served by the app at `/.well-known/security.txt`:

```
Contact: mailto:security@example.org
Contact: https://example.org/security/report
Expires: 2027-01-01T00:00:00Z
Preferred-Languages: en
Canonical: https://example.org/.well-known/security.txt
Encryption: https://example.org/pgp.txt
Policy: https://example.org/security/policy
```

Generated dynamically so the `Expires` can refresh automatically.

### Responsible disclosure

A public `SECURITY.md` in the repo describes:

- How to report (email + GPG public key, or GHSA private vulnerability report).
- Response SLA (acknowledge within 72 h, patch ETA within 14 days for Critical / High).
- Safe-harbor language for good-faith researchers.
- A hall-of-fame / credit page for accepted reports.

### Threat model document

Living document at `docs/security/threat-model.md`. Enumerates:

- **Assets:** user credentials, session tokens, admin credentials, user photos (some potentially private), server filesystem, derivative cache, audit log.
- **Actors:** unauthenticated visitor, registered user, authenticated admin, attacker with a stolen session token, compromised plugin, malicious upload, malicious link handler.
- **Vectors** for each asset × actor pair.
- **Mitigations** in place, planned, and explicitly accepted risks.

Reviewed at each major release and whenever a security advisory is filed.

---

## 12. Observability and Operations

Operating the app without visibility is operating blind. Observability is a first-class concern — built in from the start, not bolted on after a production incident teaches the value of it.

### Three pillars

- **Structured logging** — what happened, with enough context to investigate.
- **Metrics** — aggregate trends (request rate, latency, error rate, queue depth).
- **Traces** — per-request causal paths through the system.

Error tracking (Sentry) sits on top of logs; it is not a substitute for them.

### Structured logging

All logs are JSON, one event per line. Shape:

```json
{
    "ts": "2026-05-01T14:23:01.123Z",
    "level": "info",
    "msg": "user.login",
    "request_id": "01J9X2H7B0Q4Z5VXGYQ4ARBTVM",
    "user_id": 42,
    "ip": "203.0.113.5",
    "duration_ms": 18,
    "channel": "auth"
}
```

- `ts` — ISO 8601 millisecond precision, UTC.
- `level` — PSR-3 severity: `debug|info|notice|warning|error|critical|alert|emergency`.
- `msg` — short, template-like. Not a full sentence; not an error dump.
- `request_id` — ULID, generated by `RequestLoggerMiddleware`, on every log line within a request.
- `user_id` — if authenticated.
- `ip` — client IP after `TrustedProxyMiddleware` unwrapping.
- `duration_ms` — set on request-end events.
- `channel` — `auth`, `upload`, `derivative`, `api`, `admin`, `sync`, `db`, `mail`, etc.

Per-event context fields add to this base. PII-bearing fields (email, full username, passwords, tokens, IPs in some deployments) are redacted by Monolog processors before the event leaves PHP.

### Log levels — when to use each

| Level | Use for | Volume |
|---|---|---|
| `debug` | Dev-only. Disabled in prod. | High |
| `info` | Expected events: login success, album created, derivative generated. | High, bounded |
| `notice` | Expected anomalies: login failure, unknown route, DB reconnect. | Moderate |
| `warning` | Unexpected non-fatal: slow query, retryable failure, deprecated API use. | Low |
| `error` | Request-level failure a user experienced: 500, upload rejected, job failed. | Low |
| `critical` | Broken: DB unreachable, libvips crashed, disk full. | Rare |
| `alert` / `emergency` | Paging-level. | Should be zero |

### Request correlation

`RequestLoggerMiddleware` sets a ULID request ID at the top of the stack and injects it into:

- Every Monolog event (via a processor).
- The `X-Request-Id` response header — so bug reports can cite it.
- OpenTelemetry span attributes.
- `$_SERVER['REQUEST_ID']` for any code that needs a fallback.

A report with an `X-Request-Id` lets a maintainer grep related log lines in seconds across any log backend.

### OpenTelemetry tracing

OTLP traces emitted via `open-telemetry/sdk`. Spans cover:

- Request lifecycle (root span per request).
- DB queries (child spans, with hashed SQL as an attribute).
- libvips operations (child spans with preset + duration).
- External HTTP calls.
- Cache hits and misses.
- Plugin event listeners (child span per listener invocation, if tracing enabled for plugins).

Sampling is head-based: 100% of 5xx requests; 100% of requests > 1 s; 1% of healthy requests. Configurable.

Exporter is OTLP/HTTP — Jaeger, Tempo, Honeycomb, Datadog, Grafana Cloud all speak it.

### Metrics (Prometheus format)

Exposed at `/metrics` (gated by IP allowlist or basic auth — never public):

```
# HELP http_requests_total HTTP request count
# TYPE http_requests_total counter
http_requests_total{method="GET",route="album.show",status="200"} 12345

# HELP http_request_duration_seconds Request latency (seconds)
# TYPE http_request_duration_seconds histogram
http_request_duration_seconds_bucket{route="album.show",le="0.01"} 11000
http_request_duration_seconds_bucket{route="album.show",le="0.05"} 12200
...

# Other metrics
db_query_duration_seconds{kind="select"} ...
derivative_cache_hits_total ...
derivative_cache_misses_total ...
upload_bytes_total ...
jobs_in_queue{queue="images"} ...
jobs_processed_total{queue="images",status="success"} ...
worker_requests_handled_total ...
worker_memory_bytes ...
db_connections_active ...
cache_hits_total{layer="response"} ...
cache_misses_total{layer="response"} ...
```

`MetricsCollector` is a singleton; middleware and services record into it. `/metrics` renders the current snapshot.

### Error tracking (Sentry, opt-in)

If `SENTRY_DSN` is set, unhandled exceptions report to Sentry. Fields:

- Request ID.
- User ID (pseudonymized — never email or username).
- Breadcrumbs: last 50 log events + last 20 DB queries.
- Release: git tag or SHA.
- Environment: `production` / `staging`.

404s and validation errors are excluded by default — they're expected, not bugs.

### Health checks

- **`/healthz`** — *liveness* probe. Returns 200 if the PHP worker can reach the dispatcher. Does not touch dependencies. Kubernetes uses this to decide whether to restart the pod.
- **`/readyz`** — *readiness* probe. Checks DB connectivity, storage write access, Redis if configured. Returns 200 only when ready to serve traffic. Kubernetes uses this to decide whether to route traffic.
- **`/version`** — returns git SHA + semver. No auth. Useful for deploy verification.

CLI equivalents:

```
php bin/gallery healthcheck                 # exits 0/1 — suitable for monit / systemd
php bin/gallery healthcheck --verbose       # dumps each check's result
```

### Monitoring dashboards

`docs/monitoring/grafana-dashboard.json` ships with panels:

- Request rate + error rate, broken down by route.
- p50 / p95 / p99 latency.
- DB query count per request (surfaces N+1 regressions).
- Derivative cache hit ratio.
- Queue depth over time.
- Worker memory trend — flat is healthy; growing is a leak.
- Uploads per hour + upload error rate.
- Login success / failure rate.

### Alerting policies

Example Prometheus alert rules ship in `docs/monitoring/alerts.yaml`:

- **Error rate > 1% for 5 min** → page.
- **p99 latency > 5 s for 10 min** → warn.
- **Queue depth growing for 15 min** → warn.
- **Worker memory > 500 MB for 10 min** → investigate (likely a leak).
- **DB connection failures** → page.
- **Disk usage > 85%** → warn; > 95% → page.
- **Derivative write failure rate > 1%** → warn.
- **5xx on `/healthz` or `/readyz`** → page.

### Debug mode in production

The `?_debug=1` URL parameter is a trap. Even in dev, it requires an env flag to enable. In prod it is a hard no-op. Stack traces never reach end-users; they go to logs and the error tracker. Prod's `error.latte` shows a friendly message with the request ID — the user can send that ID to the admin.

### Audit log vs operational log

Two distinct stores, answering different questions:

- **Operational log** — Monolog → stdout (FrankenPHP) or file → log aggregator. High volume, short retention (7–30 days typical).
- **Audit log** — DB table, append-only. Security-relevant events (login, permission change, admin action). Long retention (1 year+). Queryable via admin UI.

"What was the system doing at 02:13 last Tuesday?" → operational.
"Who deleted that album last month?" → audit.

### Log retention

- Structured logs: the app doesn't store them. Log aggregation (Loki, Elasticsearch, Datadog, Grafana Cloud, etc.) is the deployment's responsibility.
- Audit log: configurable retention (default 365 days); `php bin/gallery audit:prune` removes rows older than the window.

### Operational runbooks

`docs/operations/runbooks/` holds procedural docs for common alerts:

- `disk-full.md`
- `db-unreachable.md`
- `queue-backlog.md`
- `memory-leak.md`
- `derivative-cache-corruption.md`
- `security-incident.md`

Each describes: what the alert means, first diagnostic steps, common root causes, mitigation, and post-incident checklist.

---

## 13. Background Jobs and Scheduling

**Decision:** `symfony/messenger` with a Doctrine DBAL transport as the default. Redis and RabbitMQ as alternatives.

### Why not run things inline

Some work doesn't belong on the request path:

- **Email sending.** Synchronous SMTP can block a request for seconds.
- **Derivative generation beyond the first preset.** The first thumbnail is generated on demand; the full preset set (small / medium / large / xlarge / AVIF variants) is queued.
- **Sync / reindex.** Walking a filesystem tree is minutes of work.
- **Cleanup.** Orphan files, expired sessions, old audit rows.
- **Webhook delivery.** Retryable outbound HTTP.
- **Plugin work.** Plugins may enqueue their own arbitrary async work.

A request that blocks on any of these is doing the wrong thing.

### Why `symfony/messenger`

- **Transport-agnostic.** Dev uses a sync transport; prod picks DB/Redis/RabbitMQ via config.
- **Typed messages.** Each job is a PHP class; dispatch is `$bus->dispatch(new SendEmail(...))`.
- **Retry + dead-letter built-in.** Configurable per message type.
- **Middleware support.** Adds logging, tracing, auth-scoping to every dispatch without touching handlers.
- **Scheduler bridge.** `symfony/scheduler` handles recurring jobs from the same infrastructure.

### Message shape

```php
final readonly class GenerateDerivativesMessage
{
    public function __construct(
        public int $imageId,
        public array $presetNames,
    ) {}
}

final readonly class SendEmailMessage
{
    public function __construct(
        public string $recipientEmail,
        public string $templateKey,
        public array $context,
    ) {}
}
```

Messages are PHP `readonly` DTOs. State needed to run the job is encoded in the payload; handlers never assume prior state beyond the database.

### Handlers

```php
#[AsMessageHandler]
final readonly class GenerateDerivativesHandler
{
    public function __construct(
        private ImageRepository $images,
        private DerivativeService $derivatives,
    ) {}

    public function __invoke(GenerateDerivativesMessage $msg): void
    {
        $image = $this->images->findByIdOrFail($msg->imageId);
        foreach ($msg->presetNames as $preset) {
            $this->derivatives->ensureGenerated($image, $preset);
        }
    }
}
```

A handler is a plain class with a single `__invoke`; Symfony's dispatcher discovers it via the attribute.

### Transports

Configured via `.env`:

```
# DB-backed (default — one less moving part)
MESSENGER_TRANSPORT_DSN=doctrine://default?queue_name=default

# Redis
MESSENGER_TRANSPORT_DSN=redis://redis:6379/messages

# RabbitMQ
MESSENGER_TRANSPORT_DSN=amqp://rabbitmq:5672/%2f/messages
```

### Queues and routing

```php
// config/messenger.php
return [
    'routing' => [
        GenerateDerivativesMessage::class => 'images',
        SendEmailMessage::class           => 'mail',
        ReindexSearchMessage::class       => 'search',
        WebhookDeliveryMessage::class     => 'webhooks',
        // unrouted messages → default queue
    ],
];
```

Four named queues with distinct concurrency profiles:

- `images` — CPU-bound; 2–4 workers with libvips concurrency throttled.
- `mail` — I/O-bound, low volume; 1 worker.
- `search` — I/O-bound, short; 1–2 workers.
- `webhooks` — I/O-bound with retries; 2–4 workers.

### Running workers

Each queue is a long-lived process:

```
php bin/gallery messenger:consume images \
    --limit=1000 \
    --time-limit=3600 \
    --memory-limit=256M
```

Systemd unit (template `piwigo-worker@.service`, instantiated per queue):

```ini
[Unit]
Description=Piwigo worker (%i queue)
After=network-online.target

[Service]
ExecStart=/usr/bin/php /app/bin/gallery messenger:consume %i --limit=1000 --time-limit=3600
Restart=always
User=piwigo
WorkingDirectory=/app

[Install]
WantedBy=multi-user.target
```

Instantiated: `systemctl enable --now piwigo-worker@images piwigo-worker@mail …`.

`--limit` and `--time-limit` cycle the worker (same release-valve as FrankenPHP workers), so leaks don't accumulate indefinitely.

### Retry semantics

```php
// per-message retry policy
RetryStrategy::exponential(
    delaysMs: [1_000, 5_000, 30_000, 300_000],  // 1s, 5s, 30s, 5min
    multiplier: 1,
)
```

After all retries are exhausted, the message moves to the **dead-letter queue** (`failed_messages` table). The admin UI exposes DLQ contents with "retry" and "drop" actions. DLQ growth rate is monitored — a spike is an alert.

### Transactional outbox

Messages dispatched inside a DB transaction are queued to an outbox table, not sent to the transport immediately:

- Transaction commits → outbox flushed to transport.
- Transaction rolls back → messages discarded.

Guarantees: you never enqueue work for state that then rolls back. Implemented via a middleware wrapping the bus.

### Scheduler

`symfony/scheduler` handles recurring work:

```php
#[AsSchedule('default')]
final readonly class DefaultSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            RecurringMessage::cron('0 3 * * *', new PurgeExpiredSessionsMessage()),
            RecurringMessage::cron('0 * * * *', new DeliverPendingWebhooksMessage()),
            RecurringMessage::every('5 minutes', new CheckFeedsMessage()),
            RecurringMessage::cron('0 4 * * 0', new AuditLogPruneMessage()),
            RecurringMessage::cron('30 2 * * *', new DerivativesPruneMessage()),
        );
    }
}
```

Runs as its own process:

```
php bin/gallery messenger:consume scheduler_default
```

No crontab entries on the host — scheduler is PHP, versioned with the app.

### Jobs shipped with core

- `GenerateDerivativesMessage` — generate a named preset (or list) for an image.
- `SendEmailMessage` — dispatched via Symfony Mailer.
- `PurgeExpiredSessionsMessage` — daily session GC.
- `PruneExpiredApiTokensMessage` — remove tokens past `expires_at`.
- `PruneOrphanedDerivativesMessage` — derivatives whose source image no longer exists.
- `AuditLogPruneMessage` — rows older than retention window.
- `ReindexSearchMessage` — rebuild full-text search index for an image or album.
- `DeliverWebhookMessage` — outbound webhook with retry.
- `SyncAlbumMessage` — filesystem sync for one album.
- `UpdateImageCountersMessage` — refresh denormalized `image_count` on albums and tags (consistency belt).

Plugins add their own by providing `AsMessageHandler`-attributed handlers and dispatching matching messages.

### Monitoring queue depth

`jobs_in_queue{queue="images"}` gauge → Prometheus. Dashboard alert if any queue grows unbounded.

Per-queue panel shows:

- Current depth.
- Processed / minute.
- Failure rate.
- Average processing time.
- Oldest-job-age.

### Testing

The sync transport runs jobs inline, so tests can:

```php
it('enqueues derivative generation after upload', function () {
    $this->app->handle(postUpload(sampleImage()));

    expect(messengerTransport('images'))
        ->toHaveMessageOfType(GenerateDerivativesMessage::class);
});

it('generates all configured derivatives', function () {
    $image = ImageFactory::new()->create();

    $this->app->dispatch(new GenerateDerivativesMessage(
        imageId: $image->id,
        presetNames: ['thumbnail', 'medium', 'large'],
    ));

    expect($this->derivatives->count($image))->toBe(3);
});
```

Handlers are unit-testable in isolation — no queue involved.

### Graceful worker shutdown

On `SIGTERM`:

- Worker finishes the current message.
- Refuses new messages.
- Exits.

Systemd's default `KillSignal=SIGTERM` + `TimeoutStopSec=60s` gives a clean draining window. Deploys can `systemctl reload` worker units without losing in-flight work.

---

## 14. Caching Architecture

Caching is layered. Each layer has a distinct purpose and invalidation story. The goal is *right*, not *fast* — caches that lie are worse than no caches.

### The layers

```
Client
   │
   ▼
┌──────────────────────────────────┐
│ Browser cache (HTTP headers)     │  Cache-Control / ETag on response
├──────────────────────────────────┤
│ CDN (optional)                   │  Caches by URL + Vary
├──────────────────────────────────┤
│ Caddy reverse cache (optional)   │  Static assets + derivatives
├──────────────────────────────────┤
│ HTTP response cache              │  App-level page cache for anon gallery
├──────────────────────────────────┤
│ Query/object cache (PSR-16)      │  Repo-level (permission resolves, etc.)
├──────────────────────────────────┤
│ Latte compile cache              │  Parse .latte once per content change
├──────────────────────────────────┤
│ Opcache (JIT)                    │  Compiled PHP bytecode, kernel-level
├──────────────────────────────────┤
│ PDO statement cache              │  Prepared statements held across requests
└──────────────────────────────────┘
```

### PSR-16 everywhere

App-level caching goes through `symfony/cache` behind PSR-16 (`Psr\SimpleCache\CacheInterface`):

```php
final class PermissionService
{
    public function __construct(
        private AlbumRepository $albums,
        private CacheInterface $cache,
    ) {}

    public function allowedAlbumIdsFor(User $user): array
    {
        return $this->cache->get(
            key: "user.{$user->id}.allowed_albums",
            callback: fn () => $this->resolveFromDb($user),
            ttl: 300,
        );
    }
}
```

Backends:

- **APCu** — per-worker, zero-latency; used for small, hot, immutable caches (enum decodes, route table, locale catalog).
- **Redis** — cross-worker, cross-server; user-scoped and shared caches.
- **Filesystem** — fallback; acceptable for single-server installs without Redis.

The container wires the configured backend; services never know which one they're hitting.

### HTTP caching

Responses declare `Cache-Control` based on content:

| Content | Cache-Control |
|---|---|
| Static assets (Vite-fingerprinted) | `public, max-age=31536000, immutable` |
| Derivative images | `public, max-age=31536000, immutable` (URL is content-addressed) |
| Anonymous gallery pages | `public, max-age=60, stale-while-revalidate=300` |
| Authenticated gallery pages | `private, no-cache, must-revalidate` |
| API responses | `no-store` by default; specific endpoints opt-in to `max-age` |
| Auth / admin pages | `no-store` |

`ETag` is set on gallery pages; `If-None-Match` handled → 304 response.

`Vary: Accept, Accept-Language, Cookie` set appropriately. A gallery page with a session cookie isn't served from cache to an anonymous user.

### Application response cache

Unauthenticated gallery pages get a response-cache middleware storing rendered HTML keyed by URL + locale + theme + content version:

```
key = hash("{url}|{locale}|{theme_version}|{content_version}")
```

`content_version` is bumped by events that invalidate content (album updated, image uploaded, permission changed). Incrementing is a Redis `INCR`; invalidation is effectively free.

Miss → render → store with short TTL (60 s).

Authenticated pages bypass this cache — permission-sensitive content never lives in a shared cache.

### Latte compile cache

Latte compiles `.latte` → PHP; compiled files live in `var/cache/templates/`. Latte tracks source mtime; a changed template triggers recompilation. In prod, the compiled files sit in opcache; render is near-instant.

Deploys invalidate the cache via a compile-stamp file; Latte refuses to use compiled files older than the stamp.

### Opcache

Enabled in all environments. Prod settings:

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0        ; deploys trigger restart, not timestamp check
opcache.jit=tracing
opcache.jit_buffer_size=128M
opcache.revalidate_freq=0
```

Deployment runs `opcache_reset()` (via a CLI command or a restart signal) when new code rolls out.

### PDO statement cache

Prepared statements held in the `Database` service for the worker lifetime (LRU at 500 entries). Subsequent requests with the same SQL skip the `prepare()` step. See section 4.

### Cache invalidation via events

Events drive invalidation; no scattered `$cache->clear()` calls:

```php
#[AsEventListener]
final readonly class InvalidateAlbumCaches
{
    public function __construct(
        private CacheInterface $cache,
        private VersionBumper $version,
    ) {}

    public function __invoke(AlbumUpdatedEvent $event): void
    {
        $this->cache->delete("album.{$event->album->id}");
        $this->cache->delete("album.{$event->album->id}.children");
        $this->version->bump('content_version');
    }
}
```

Listeners register per-event; invalidation lives beside the event definition, not scattered across call sites.

### Cache tags

`symfony/cache`'s tagged adapter supports group invalidation:

```php
$cache->get('user.42.feed', fn () => ..., tags: ['user.42', 'album.17']);

// Later — invalidate anything touching album 17
$cache->invalidateTags(['album.17']);
```

### Cache warm-up

CLI command for post-deploy warm-up:

```
php bin/gallery cache:warm --routes --permissions --top-albums=10
```

Pre-computes the route table, common permission resolves, and the first page of the N most-viewed albums. Optional — for installs that can't afford the first post-deploy request being slow.

### Cache anti-patterns avoided

- **No cache-aside in domain code.** Domain code doesn't know there's a cache. Only repositories read/write the cache.
- **No "cache everything with a long TTL".** Each TTL is chosen against how quickly the data goes stale; long TTL without invalidation is a bug.
- **No cache-stampede risk.** `symfony/cache`'s `get()` uses lock semantics; simultaneous misses don't all recompute.
- **No shared cache for private content.** Per-user or per-session caches, never key-collision via "oh it's the same URL".

### Performance budgets

For each page, a performance budget (as tests):

- Anonymous gallery index: p95 < 20 ms server time, ≤ 50 KB HTML compressed.
- Album page (50 thumbnails): p95 < 30 ms server time, ≤ 100 KB HTML compressed.
- Picture page: p95 < 15 ms server time, ≤ 30 KB HTML compressed.
- Derivative serve (cache hit): p95 < 5 ms.

A regression of > 20% fails the nightly benchmark run.

---

## 15. Storage and Backup

The app has several categories of persistent data. Each has its own retention, backup, and replication story.

### Data categories

| Category | What it is | Where it lives | Recovery priority |
|---|---|---|---|
| **Originals** | User-uploaded source files, never modified after write | `storage/originals/` or S3 bucket | Non-negotiable — losing this is data loss |
| **Derivatives** | On-demand-regeneratable thumbnails / medium / large / AVIF | `var/derivatives/` or S3 bucket | Regenerable — acceptable to lose |
| **Database** | All metadata: users, permissions, albums, tags, comments, audit log | MySQL / PostgreSQL | Non-negotiable |
| **Session store** | Sessions, CSRF tokens, remember-me tokens | DB or Redis | Disposable — users re-login |
| **Temporary uploads** | In-flight multipart uploads before validation | `var/uploads/tmp/` | Disposable — ephemeral |
| **Logs (operational)** | Application stdout/file logs | Log aggregator | Operational — short retention |
| **Logs (audit)** | Security-relevant events | DB table | Long retention (365+ days) |

### Originals layout

Content-addressed with a date-prefixed tree:

```
storage/originals/
├── 2026/
│   ├── 04/
│   │   ├── 0198f3c5-7e5a-7c2d-9b1a-b37a2f0c8100.jpg
│   │   ├── 0198f3c6-4d8b-7a12-bc04-e8f1223aa001.heic
│   │   └── ...
│   └── 05/
│       └── ...
```

- **Year/month prefix** keeps any single directory under ~10k entries.
- **UUIDv7 filenames.** Time-ordered, globally unique, no collision possible, sort nicely.
- **Extension preserved** from the user's file (`.jpg`, `.heic`, `.webp`).
- **No user-supplied paths.** The user sees "beach-sunset.jpg"; the disk sees `0198f3c6....jpg`.

The DB column `images.storage_path` records the relative path. Migrating between storage backends updates the column; files move, rows follow.

### Derivatives layout

```
var/derivatives/
├── 01/                                     # first 2 chars of UUID
│   └── 98/                                 # next 2 chars
│       └── 0198f3c5.../
│           ├── thumbnail.a7b3c2.jpg
│           ├── thumbnail.a7b3c2.webp
│           ├── thumbnail.a7b3c2.avif
│           ├── medium.d4e5f6.jpg
│           └── ...
```

- Sharded by UUID prefix — no single directory balloons.
- Filename includes preset + params-hash + format — immutable, content-addressed.
- Regeneration uses `withLock()` (see section 5) to avoid duplicate work.

### S3 / object-storage backend

For deployments that prefer object storage, `StorageBackend` abstracts over local disk and S3-compatible APIs:

```php
interface StorageBackend
{
    public function exists(string $key): bool;
    public function put(string $key, StreamInterface $body, string $contentType): void;
    public function get(string $key): StreamInterface;
    public function delete(string $key): void;
    public function presignedUrl(string $key, \DateInterval $expires): string;
    public function stat(string $key): StorageMetadata;
}
```

Configuration:

```
# S3-compatible
STORAGE_ORIGINALS_DSN=s3://originals-bucket?region=us-east-1
STORAGE_DERIVATIVES_DSN=s3://derivatives-bucket?region=us-east-1

# Local
STORAGE_ORIGINALS_DSN=file:///srv/piwigo/originals
STORAGE_DERIVATIVES_DSN=file:///srv/piwigo/derivatives
```

The S3 implementation uses the AWS SDK's streaming upload — originals never buffer in PHP memory. For private originals, the app issues presigned URLs with short TTLs instead of proxying bytes.

Hybrid deployments (originals on S3, derivative cache on local disk) work — the two DSNs are independent.

### Uploads / temp directory

Uploads stream to `var/uploads/tmp/` before validation. A scheduled job removes stale files (> 24 h) not attached to an in-progress multipart upload.

Resumable uploads (tus protocol) use this directory with an ID; the client can reconnect and resume an interrupted upload.

### Backup strategy

Two-tier:

**Tier 1: Database**

- Daily `mysqldump --single-transaction --routines --triggers --events` (MySQL) or `pg_dump --clean --create` (Postgres) to a dedicated backup volume.
- Streamed to cold storage (S3 Glacier, B2, Wasabi, etc.).
- Retention: 7 daily + 4 weekly + 12 monthly (configurable).
- **Restore tested monthly** — a scheduled CI job restores the latest backup into an empty DB and runs a smoke test. An untested backup is a "maybe".

**Tier 2: Originals**

- **Filesystem-level snapshots** (ZFS, Btrfs, LVM) if the OS supports them. Cheapest, fastest.
- **rsync** to a secondary location nightly. Delta-only.
- **S3 cross-region replication** if using S3.
- Never store backups on the same disk as live data.

Derivatives are **not** backed up — they regenerate from originals.

### Restore procedure

Documented step-by-step in `docs/operations/restore.md`:

1. Provision a new host with the same PHP / MySQL / libvips versions.
2. `composer install --no-dev`.
3. Restore DB from the most recent known-good backup.
4. Restore originals (from backup or sync from S3).
5. `php bin/gallery migrate` (no-op if backup includes current schema).
6. `php bin/gallery cache:clear`.
7. Start FrankenPHP.
8. Verify `/healthz`, `/readyz`, and a handful of known URLs.
9. Derivative cache warms up on access — first page loads are slower; users notice nothing.

RTO target: 1 hour for single-server. RPO: 24 hours (daily backups).

### Disk usage monitoring

Metrics + alerts:

- `storage_originals_bytes`
- `storage_derivatives_bytes`
- `storage_free_bytes`

Warn at > 85% used; page at > 95%.

### Orphan detection

Files on disk not referenced in the DB (crashes during upload, manual file moves, bugs):

```
php bin/gallery storage:orphans            # dry-run report
php bin/gallery storage:orphans --delete   # after human review
```

Symmetric: DB rows referencing non-existent files:

```
php bin/gallery storage:broken-links
```

Run weekly via the scheduler; findings logged to audit.

### Quotas

Per-user upload quotas (bytes, file count) enforced at upload time:

```sql
CREATE TABLE user_quotas (
    user_id        BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    bytes_limit    BIGINT,         -- NULL = unlimited
    bytes_used     BIGINT NOT NULL DEFAULT 0,
    files_limit    INT,            -- NULL = unlimited
    files_used     INT NOT NULL DEFAULT 0
);
```

Quota updates happen inside the upload's DB transaction. Exceeded quota → 413 Payload Too Large with a quota-specific error code and current-usage breakdown.

### Multi-tenancy — not planned

One install serves one gallery. Multi-tenancy (multiple isolated galleries behind one install) is out of scope for v1. The design doesn't exclude it — row-level scoping via `site_id` would be the path — but the complexity cost isn't justified without a concrete audience.

---

## 16. Data Model

This is the concrete schema the rewrite starts from. Subject to refinement during milestone 1, but the shape is committed.

### Core tables

```sql
-- ─────────────────────────────────────────────────────────────
-- Users and auth
-- ─────────────────────────────────────────────────────────────
CREATE TABLE users (
    id                 BIGINT AUTO_INCREMENT PRIMARY KEY,
    uuid               BINARY(16) NOT NULL UNIQUE,
    username           VARCHAR(100) NOT NULL UNIQUE,
    email              VARCHAR(255) NOT NULL UNIQUE,
    email_verified_at  TIMESTAMP NULL,
    password_hash      VARCHAR(255) NOT NULL,
    access_level       TINYINT UNSIGNED NOT NULL DEFAULT 2,   -- AccessLevel enum
    locale             VARCHAR(10) NOT NULL DEFAULT 'en',
    timezone           VARCHAR(64) NOT NULL DEFAULT 'UTC',
    theme              VARCHAR(50) NOT NULL DEFAULT 'default',
    last_login_at      TIMESTAMP NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,
    deleted_at         TIMESTAMP NULL,
    INDEX (access_level),
    INDEX (deleted_at)
);

CREATE TABLE groups (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    is_default  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE user_groups (
    user_id   BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    group_id  BIGINT NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, group_id),
    INDEX (group_id)
);

-- ─────────────────────────────────────────────────────────────
-- Sessions, tokens
-- ─────────────────────────────────────────────────────────────
CREATE TABLE sessions (
    id           VARCHAR(128) PRIMARY KEY,
    user_id      BIGINT NULL REFERENCES users(id) ON DELETE CASCADE,
    payload      MEDIUMBLOB NOT NULL,
    ip_hash      BINARY(32),
    user_agent   VARCHAR(255),
    last_active  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at   TIMESTAMP NOT NULL,
    INDEX (user_id),
    INDEX (expires_at)
);

CREATE TABLE remember_tokens (
    selector     CHAR(32) PRIMARY KEY,
    hash         BINARY(32) NOT NULL,
    user_id      BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires_at   TIMESTAMP NOT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX (expires_at)
);

CREATE TABLE api_tokens (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id       BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name          VARCHAR(100) NOT NULL,
    token_hash    BINARY(32) NOT NULL UNIQUE,
    scopes        JSON NOT NULL,
    expires_at    TIMESTAMP NULL,
    last_used_at  TIMESTAMP NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id)
);

-- ─────────────────────────────────────────────────────────────
-- Albums (Piwigo called them "categories"; we call them albums)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE albums (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    uuid            BINARY(16) NOT NULL UNIQUE,
    parent_id       BIGINT NULL REFERENCES albums(id) ON DELETE SET NULL,
    name            VARCHAR(255) NOT NULL,
    slug            VARCHAR(255) NOT NULL,
    description     TEXT,
    cover_image_id  BIGINT NULL,                     -- FK added after images
    rank            INT NOT NULL DEFAULT 0,
    min_level       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_public       BOOLEAN NOT NULL DEFAULT TRUE,
    image_count     INT NOT NULL DEFAULT 0,          -- denormalized
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY albums_parent_slug (parent_id, slug),
    INDEX (parent_id, rank),
    INDEX (is_public, min_level)
);

-- ─────────────────────────────────────────────────────────────
-- Images
-- ─────────────────────────────────────────────────────────────
CREATE TABLE images (
    id                 BIGINT AUTO_INCREMENT PRIMARY KEY,
    uuid               BINARY(16) NOT NULL UNIQUE,
    storage_path       VARCHAR(500) NOT NULL,          -- e.g. 2026/04/0198...jpg
    original_name      VARCHAR(255) NOT NULL,          -- user-supplied, display only
    mime_type          VARCHAR(100) NOT NULL,
    width              INT NOT NULL,
    height             INT NOT NULL,
    filesize           BIGINT NOT NULL,
    sha256             BINARY(32) NOT NULL,
    perceptual_hash    BINARY(8),                      -- pHash for duplicate detection
    author_id          BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    title              VARCHAR(255),
    description        TEXT,
    rating             TINYINT UNSIGNED,               -- 0-5
    exif               JSON,                           -- typed EXIF; queryable
    taken_at           TIMESTAMP NULL,                 -- EXIF DateTimeOriginal
    taken_at_offset    SMALLINT NULL,                  -- preserved tz offset minutes
    gps_lat            DECIMAL(10, 7) NULL,
    gps_lng            DECIMAL(10, 7) NULL,
    min_level          TINYINT UNSIGNED NOT NULL DEFAULT 0,
    view_count         BIGINT NOT NULL DEFAULT 0,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,
    INDEX (author_id),
    INDEX (taken_at),
    INDEX (min_level, created_at),
    INDEX (sha256),
    INDEX (perceptual_hash)
);

ALTER TABLE albums
    ADD FOREIGN KEY (cover_image_id) REFERENCES images(id) ON DELETE SET NULL;

CREATE TABLE image_albums (
    image_id   BIGINT NOT NULL REFERENCES images(id) ON DELETE CASCADE,
    album_id   BIGINT NOT NULL REFERENCES albums(id) ON DELETE CASCADE,
    rank       INT NOT NULL DEFAULT 0,
    added_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (image_id, album_id),
    INDEX (album_id, rank)
);

-- ─────────────────────────────────────────────────────────────
-- Tags
-- ─────────────────────────────────────────────────────────────
CREATE TABLE tags (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL UNIQUE,
    slug         VARCHAR(100) NOT NULL UNIQUE,
    image_count  INT NOT NULL DEFAULT 0,              -- denormalized
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE image_tags (
    image_id   BIGINT NOT NULL REFERENCES images(id) ON DELETE CASCADE,
    tag_id     BIGINT NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    added_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (image_id, tag_id),
    INDEX (tag_id)
);

-- ─────────────────────────────────────────────────────────────
-- Permissions (album ACL)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE album_user_access (
    album_id     BIGINT NOT NULL REFERENCES albums(id) ON DELETE CASCADE,
    user_id      BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    can_view     BOOLEAN NOT NULL DEFAULT TRUE,
    can_upload   BOOLEAN NOT NULL DEFAULT FALSE,
    can_manage   BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (album_id, user_id),
    INDEX (user_id)
);

CREATE TABLE album_group_access (
    album_id     BIGINT NOT NULL REFERENCES albums(id) ON DELETE CASCADE,
    group_id     BIGINT NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    can_view     BOOLEAN NOT NULL DEFAULT TRUE,
    can_upload   BOOLEAN NOT NULL DEFAULT FALSE,
    can_manage   BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (album_id, group_id),
    INDEX (group_id)
);

-- ─────────────────────────────────────────────────────────────
-- Comments
-- ─────────────────────────────────────────────────────────────
CREATE TABLE comments (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    image_id      BIGINT NOT NULL REFERENCES images(id) ON DELETE CASCADE,
    user_id       BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    author_name   VARCHAR(100),                        -- anonymous
    author_email  VARCHAR(255),
    body          TEXT NOT NULL,
    status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    ip_hash       BINARY(32),
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at   TIMESTAMP NULL,
    INDEX (image_id, status),
    INDEX (status, created_at)
);

-- ─────────────────────────────────────────────────────────────
-- Audit log (append-only)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE audit_log (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    actor_id     BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    actor_ip     BINARY(16),
    event        VARCHAR(100) NOT NULL,               -- 'user.login', 'album.permission_changed', ...
    target_type  VARCHAR(100),
    target_id    BIGINT,
    details      JSON,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (actor_id, created_at),
    INDEX (event, created_at),
    INDEX (target_type, target_id)
);

-- ─────────────────────────────────────────────────────────────
-- Messenger failed messages (DLQ)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE failed_messages (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    body         LONGBLOB NOT NULL,
    headers      JSON NOT NULL,
    queue_name   VARCHAR(100) NOT NULL,
    error_class  VARCHAR(255) NOT NULL,
    error_msg    TEXT NOT NULL,
    stack_trace  TEXT NOT NULL,
    failed_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (queue_name, failed_at)
);

-- ─────────────────────────────────────────────────────────────
-- Schema version
-- ─────────────────────────────────────────────────────────────
CREATE TABLE schema_migrations (
    version     VARCHAR(255) PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    checksum    CHAR(64) NOT NULL,
    applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ─────────────────────────────────────────────────────────────
-- Settings (key/value, typed via JSON)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE settings (
    key_name    VARCHAR(100) PRIMARY KEY,
    value       JSON NOT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP
);
```

### Key design decisions

- **`BIGINT` surrogate PKs** on every table for internal relations. `AUTO_INCREMENT` on MySQL, `GENERATED ALWAYS AS IDENTITY` on Postgres.
- **`BINARY(16)` UUIDv7** on user / album / image for external exposure. Time-ordered, index-friendly. On Postgres, native `UUID`.
- **Soft delete on users only.** Audit log retention depends on historical user IDs being resolvable. Images and albums hard-delete (with audit entries); their referenced rows cascade.
- **JSON for EXIF and settings.** MySQL's `JSON` type + `JSON_EXTRACT` functional indexes for querying specific fields. Postgres uses native `JSONB`.
- **Denormalized counts** (`image_count` on albums and tags) maintained via event listeners. Refreshed nightly as a belt-and-suspenders consistency check.
- **No `is_deleted` flag.** Where soft-delete applies, a nullable `deleted_at` timestamp is both the flag and the "when".
- **IP addresses as hashes.** `ip_hash` is a salted hash (salt rotates weekly). Preserves utility for rate-limit and audit; avoids making the DB a surveillance goldmine.
- **Email uniqueness is case-insensitive.** Generated column on MySQL; `CITEXT` on Postgres.

### Permission resolution algorithm

"Can user U view album A?" resolves in order:

1. If `U.access_level >= Webmaster` → **allow**.
2. If `U.access_level < A.min_level` → **deny**.
3. If `A.is_public` and no ancestor restricts access → **allow**.
4. Otherwise, walk from A up to root:
   - At each level, check for an explicit `album_user_access(can_view=1)` row for U.
   - Or a matching row in `album_group_access(can_view=1)` for any group U belongs to.
   - If any level has an explicit permit → **allow**.
   - If any level has an explicit deny (can_view=0) → **deny**, short-circuit.
5. Default → **deny**.

Resolution is memoized per-user per-request; changes to permissions dispatch `PermissionChangedEvent`, invalidating the per-user cache.

### ERD summary

```
users ──┬──── user_groups ────── groups
        │            │               │
        ├─ api_tokens│               ├── album_group_access ──┐
        ├─ remember_tokens           │                        │
        ├─ album_user_access ────────┤                        ├── albums ──┬── image_albums ──┐
        │                            │                        │            │                  │
        └─ comments ──── images ─────┘            tags ◄────┐ │            ├── image_tags ──┤
                          │                                 │ │                              │
                          ├── exif (JSON)                   └─┴──────────────────────────────┘
                          └── (storage_path → filesystem/S3)

audit_log ──── sessions ──── settings ──── schema_migrations ──── failed_messages
```

### Migrations strategy

SQL-first migrations in `database/migrations/`. The initial migration creates everything above as one file (`20260501120000_initial_schema.sql`). Subsequent migrations are additive — column adds, new tables, new indexes. Destructive migrations (drop columns, drop tables) happen only in major-version bumps and are explicit in release notes.

Every migration is checksummed; modifying an applied migration's file is caught by the runner. Backfills that require code (e.g., recomputing `perceptual_hash` for existing rows) go in a separate "data migration" CLI command, not in schema migrations.

### Naming conventions (recap)

- Tables: plural snake_case (`albums`, `image_tags`).
- Columns: snake_case.
- Booleans: `is_*` / `can_*` / `has_*` prefix.
- Timestamps: `*_at` (no `*_date`).
- Foreign keys: `{referenced_table_singular}_id` (`user_id`, `album_id`).
- Explicit indexes on every FK and every column used in WHERE.

---

## 17. Frontend Architecture

**Decision:** server-rendered HTML (Latte) enhanced with vanilla JavaScript ESM modules and a small set of focused libraries. No SPA framework.

### Why not an SPA

A photo gallery is a document-oriented app, not a stateful one. SPAs add:

- **Bundle weight.** Every user pays for React / Vue on every page, even the ones doing nothing interactive.
- **Two sources of truth.** Server state + client state, plus a hydration layer between them. Bugs hide in the seams.
- **SEO friction.** SSR-for-SPAs is its own stack (Next, Nuxt), doubling the runtime complexity.
- **Accessibility regressions.** Custom routing breaks browser back, focus management, skip-links.

The gallery renders HTML; JavaScript enhances it. The few genuinely interactive surfaces (upload, batch manager, tag editor) use small focused components that don't require a framework.

### Build pipeline: Vite

Per-theme asset bundles via Vite:

```
themes/default/
├── vite.config.js
├── assets/
│   ├── src/
│   │   ├── main.js
│   │   ├── main.css
│   │   ├── upload.js
│   │   └── photoswipe-init.js
│   └── dist/                     # Vite output (gitignored; built on deploy)
│       ├── assets/
│       │   ├── main-a7b3c2.js
│       │   └── main-a7b3c2.css
│       └── manifest.json
```

Dev:

```
bun run dev      # vite dev server on :5173 with HMR
```

Prod:

```
bun run build    # writes dist/ + manifest.json
```

Latte reads the manifest at render time (cached); `{asset 'main.js'}` resolves to the fingerprinted filename.

### JavaScript architecture

- **ESM only.** No CommonJS fallback, no UMD wrappers.
- **No jQuery.** Modern DOM APIs are sufficient.
- **No Webpack.** Vite is the only bundler.
- **One module per feature.** `upload.js`, `photoswipe-init.js`, `batch-manager.js` — loaded only where needed via `<script type="module">`.
- **Progressive enhancement.** Every page works with JS disabled (with some degraded interactivity).

### Libraries (budgeted small)

| Library | Purpose | Size (gzip) |
|---|---|---|
| PhotoSwipe | Lightbox for picture viewer | ~40 KB |
| TomSelect | Tag input, user picker | ~20 KB |
| HTMX | Partial updates in admin (batch manager, live filters) | ~15 KB |
| Alpine.js | Small interactive components (dropdowns, modals, tooltips) — optional | ~15 KB |
| Leaflet | Map display — loaded only when the map plugin is active | ~50 KB |

No library ≥ 60 KB. Each library is evaluated against "could we do this in 20 lines of vanilla?" — sometimes yes.

### CSS methodology

- **Native CSS.** No preprocessor (Sass / Less / Stylus). Modern CSS has variables, nesting, `:has()`, container queries, layers.
- **Cascade layers via `@layer`.** `@layer reset, base, components, utilities, overrides;` — specificity is predictable without `!important` wars.
- **Design tokens as custom properties.**

```css
:root {
    --color-bg: #0a0a0a;
    --color-fg: #f0f0f0;
    --color-accent: #ff6b35;
    --color-surface: #141414;
    --color-border: #2a2a2a;

    --space-xs: 0.25rem;
    --space-sm: 0.5rem;
    --space-md: 1rem;
    --space-lg: 2rem;
    --space-xl: 4rem;

    --font-body: system-ui, -apple-system, sans-serif;
    --font-mono: ui-monospace, "SF Mono", Consolas, monospace;

    --radius-sm: 4px;
    --radius-md: 8px;
    --radius-lg: 16px;

    --shadow-sm: 0 1px 2px rgba(0,0,0,0.1);
    --shadow-md: 0 4px 12px rgba(0,0,0,0.15);

    --transition-fast: 120ms;
    --transition-normal: 200ms;
}

[data-theme="light"] {
    --color-bg: #f0f0f0;
    --color-fg: #0a0a0a;
    --color-surface: #ffffff;
    --color-border: #e0e0e0;
}
```

- **Container queries** for components. Thumbnail grids reflow based on their own width, not viewport.
- **No utility-class framework** (no Tailwind). A handful of utility classes for one-off layout (`.u-center`, `.u-visually-hidden`) exist, but not at Tailwind scale.
- **No CSS-in-JS.** CSS is a file.

### Progressive enhancement patterns

- Upload form submits multipart/form-data without JS. JS-enhanced → per-file progress, drag-drop, chunked resumable uploads.
- Search input submits a GET form without JS. JS-enhanced → live results via HTMX.
- PhotoSwipe lightbox: no-JS → click navigates to a dedicated picture page; JS → opens in-place.
- Pagination: no-JS → links to numbered pages; JS → infinite scroll option (off by default — clicks are more accessible).

### Accessibility — WCAG 2.1 AA target

- Semantic HTML: `<nav>`, `<main>`, `<article>`, `<section>`, `<figure>` / `<figcaption>`.
- Every image carries `alt`. If the user didn't provide text, alt falls back to the filename-derived title; `alt=""` only on purely decorative imagery.
- Form labels associated via `for` / `id`.
- Keyboard nav: every interactive element reachable with Tab; custom widgets expose `role` / `aria-*`.
- Focus ring: `:focus-visible` with high-contrast outline; never `outline: none` without a replacement.
- Skip-link to main content on every page.
- Color contrast verified per WCAG ratio; `prefers-color-scheme` respected; `prefers-reduced-motion` disables transitions and animations.

Automated check: `axe-core` runs in browser tests against every rendered page. Failures are blockers.

### Responsive design

- Mobile-first CSS — base styles target small screens; `@media (min-width: …)` layers on larger.
- Container queries for internal component responsiveness.
- Images served in appropriate sizes via `<picture>` + `srcset` + `sizes`. Derivative formats negotiated per request.
- No horizontal scroll at any viewport width ≥ 320 px.

### Dark mode

First-class:

- `prefers-color-scheme` detected on first visit.
- User can override in account settings (stored on `users.theme`).
- All themes ship light + dark variants; `[data-theme="light"]` / `[data-theme="dark"]` on `<html>`.
- Images render natively; UI chrome flips.

### Print stylesheet

`@media print` rules on every theme:

- Hide navigation, footer, admin controls.
- Render images at reasonable print size (one per page for picture view; grid for albums).
- Add footer with page title and canonical URL.

Tested via Chrome headless PDF generation in browser tests.

### No service worker in v1

PWA / offline-first is a later concern. v1 is web-first, web-only. The service-worker API is in the backlog.

### Asset CSP

Vite-generated bundles are fingerprinted → `Cache-Control: immutable`. Served by Caddy directly; PHP never touches them.

---

## 18. Internationalization

**Goal:** every user-facing string is translatable; translators work in standard tools; new locales ship without code changes.

### Message catalog format: gettext `.po` / `.mo`

Gettext is the least-interesting choice, which makes it the right one. Mature tooling (Poedit, Weblate, Transifex, Crowdin all speak it); stable format; decades of translator familiarity; robust plural support.

```
locales/
├── en.po            # source of truth, written by developers
├── fr.po
├── de.po
├── ja.po
├── zh-CN.po
└── ...
```

Compiled `.mo` files are built at deploy time:

```
bun run build:locales   # or: php bin/gallery translations:compile
```

PHP reads them via the native gettext extension or `symfony/translation`'s gettext loader (fallback if the extension isn't compiled into the PHP build).

### Extraction workflow

```
php bin/gallery translations:extract     # scans src/, templates/, plugins/, writes .pot
```

Extracts strings from:

- PHP: `_('string')` and `$this->translator->trans('string')` calls.
- Latte: `{_'string'}` tags.
- JS: `t('string')` calls (Vite plugin).

Output is a `.pot` template; translators produce `.po` files per locale. Transifex / Weblate syncs them back into the repo as PRs.

### Using translations in code

```php
// In a service
$this->translator->trans('album.created', ['name' => $album->name]);
```

```latte
{* In a template *}
<h1>{_'Welcome, %name%', name: $user->name}</h1>

{* Pluralization via ICU MessageFormat *}
<p>{_'{count, plural, one {# photo} other {# photos}}', count: $count}</p>
```

```javascript
// In JS
t('upload.in_progress')
```

### Pluralization via ICU MessageFormat

Gettext's native plural forms handle simple cases; ICU handles complex ones:

```
{count, plural,
    =0 {no photos}
    one {# photo}
    few {# photos}
    many {# photos}
    other {# photos}
}
```

`symfony/translation` supports ICU. Each locale declares its plural rule family in metadata; translators don't have to know CLDR rules — the tooling handles it.

### Locale negotiation

Precedence in order:

1. Authenticated user's stored preference (`users.locale`).
2. `?lang=fr` query parameter (sticky via cookie once used).
3. `Accept-Language` header, parsed against available locales.
4. `DEFAULT_LOCALE` env var (typically `en`).

Resolved by `LocaleMiddleware` and written to request attributes; downstream code reads `$request->getAttribute('locale')`.

### Date / number formatting

All formatting through `IntlDateFormatter` and `NumberFormatter`:

```php
$formatter = new IntlDateFormatter(
    $locale,
    IntlDateFormatter::MEDIUM,
    IntlDateFormatter::SHORT,
    timezone: $user->timezone,
);
$formatter->format($image->takenAt);
```

In templates:

```latte
<time datetime="{$image->takenAt|atom}">{$image->takenAt|date}</time>
<span>{$image->filesize|filesize}</span>    {* "4.2 MB" *}
<span>{$duration|duration}</span>            {* "2 min 14 s" *}
```

The `|date`, `|filesize`, `|duration`, `|number`, `|currency` filters dispatch on locale.

### RTL language support

- `dir="rtl"` on `<html>` for RTL locales (ar, he, fa, ur).
- Theme CSS uses logical properties (`margin-inline-start`, not `margin-left`) everywhere.
- Directional icons (back/forward arrows) flip via `transform: scaleX(-1)` inside `[dir="rtl"]`.
- Themes tested in both directions — snapshot tests include an RTL locale.

### Translator tooling

- `.po` files in the repo. Translators can PR directly (small changes) or sync via Weblate (larger installs).
- Missing translations fall back to the source string — visible in the UI, not blank. Translators see them in context and prioritize.
- `msgfmt --check` runs on every `.po` in CI; malformed translations fail the build.

### Adding a new locale

1. Add `ja.po` by copying `en.po` and translating.
2. `bun run build:locales`.
3. Add `'ja'` to `AVAILABLE_LOCALES` in config.
4. Ship.

No code change to add a locale beyond the config list.

### Untranslated string audit

```
php bin/gallery translations:audit --locale=ja
```

Reports missing strings and percent-complete. Useful for PRs that touch UI strings — did we add new strings that need translation?

### Timezone handling

- All timestamps stored as UTC.
- Users have a `timezone` preference (default `UTC`); times are formatted in their tz on display.
- `taken_at` (from EXIF) stored as UTC with `taken_at_offset` preserving the original offset; displayed in the original tz unless the user prefers otherwise.

### Character set

UTF-8 / utf8mb4 everywhere. No Latin-1. `mb_*` string functions throughout — `strlen` / `substr` used on user-supplied text is a bug.

### CLDR data

Locale-specific data (first day of week, number grouping, currency symbols) comes from ICU, which ships CLDR data. No hand-maintained tables of "this is how French formats numbers."

---

## 19. Developer Experience

First-day contributors should be running the app locally within 30 minutes. First PR should land within a week.

### Local dev stack

Docker Compose for dependencies (`docker-compose.dev.yml`) — the PHP app runs on the host for fast iteration:

```yaml
services:
  db:
    image: mysql:9.7
    environment:
      MYSQL_ROOT_PASSWORD: dev
      MYSQL_DATABASE: piwigo_dev
    ports: ["3306:3306"]
    volumes: ["db-data:/var/lib/mysql"]

  redis:
    image: redis:7-alpine
    ports: ["6379:6379"]

  mailpit:                                # SMTP sink with web UI
    image: axllent/mailpit
    ports: ["8025:8025", "1025:1025"]

  minio:                                  # S3-compatible for storage-backend testing
    image: minio/minio
    command: server /data
    ports: ["9000:9000", "9001:9001"]
    environment:
      MINIO_ROOT_USER: dev
      MINIO_ROOT_PASSWORD: devsecret
```

Setup:

```
docker compose -f docker-compose.dev.yml up -d
cp .env.example .env.dev
php bin/gallery migrate
php bin/gallery db:seed --with-demo-data   # creates ~500 sample photos
frankenphp run --config Caddyfile.dev
bun run dev                                 # Vite dev server on :5173
```

`Caddyfile.dev` wires `/` to FrankenPHP in watch mode and proxies `/assets/*` to Vite's dev server at 5173 for HMR.

### Hot reload

- **Templates:** Latte recompiles on source change (opcache timestamp validation in dev).
- **PHP:** FrankenPHP watch mode reloads workers when `src/` changes — 1–2 s feedback loop.
- **CSS/JS:** Vite HMR — instant (CSS hot-swap) or sub-second (JS module replacement).

### Xdebug in worker mode

Xdebug + long-running workers is workable:

- `xdebug.mode=debug,coverage` in `php.ini.dev`.
- `xdebug.start_with_request=trigger` — only starts debugging when the request carries `XDEBUG_TRIGGER` (cookie, query param, header). Zero overhead on non-debug requests.
- Worker must be restarted when breakpoints at module-load time are added — most breakpoints are inside request scope, no restart needed.
- PHPStorm / VS Code config files ship in `.idea/` and `.vscode/`.

### Debug toolbar

Dev-only middleware injects a footer toolbar with:

- Request timing (total + per-middleware + per-query).
- All DB queries (SQL, params, duration).
- Cache hits / misses.
- Log messages accumulated during the request.
- Current user, locale, theme.
- Request ID.
- Dispatched events and which listeners fired.

Toggleable; zero impact in prod (middleware not wired).

### Demo data

`php bin/gallery db:seed --with-demo-data` creates:

- 20 users at various access levels.
- 5 groups.
- 30 nested albums with realistic permissions.
- ~500 sample photos (CC0-licensed fixtures from a curated mirror, small files).
- Random tags, comments, ratings.

Reproducible via fixed random seed; useful for reproducing bugs.

### Adding a feature: the happy path

Documented in `docs/CONTRIBUTING.md`, but the gist:

1. Write a failing HTTP test for the feature.
2. Add the route to `config/routes.php`.
3. Implement the controller with an unimplemented method.
4. Write a unit test for each domain service the controller calls.
5. Implement the domain services.
6. Implement the controller method.
7. Add / update the Latte template (with snapshot test).
8. `bun run dev` to iterate on frontend.
9. `pest --parallel` — green.
10. `pint && phpstan` — green.
11. Push PR.

A scaffolding command generates file stubs:

```
php bin/gallery make:feature "album export"
```

Creates: route entry, controller class, test file, template, empty migration. Delete what's not needed.

### Pre-commit hooks

`.githooks/pre-commit` runs:

- `pint --test` on changed PHP files.
- `phpstan analyse` on changed PHP files.
- Arch tests (fast, always all).
- `bun run lint` on changed JS / CSS files.

Failing hook blocks commit. `--no-verify` is possible but CI catches it.

### CI parity locally

```
php bin/gallery ci
```

Runs the same checks CI runs, in the same order. "Green locally → green in CI" is the goal; flake-free CI is the discipline.

### First-contribution tutorial

`docs/tutorial/first-change.md` walks a newcomer through:

1. Setting up the dev stack.
2. Finding a "good first issue" on the issue tracker.
3. Writing the test first.
4. Implementing the change.
5. Running checks.
6. Opening the PR.

Aimed at a developer new to this codebase but comfortable with PHP.

### IDE configuration

- `.idea/` (PHPStorm) and `.vscode/` (VS Code) shipped with recommended settings + extensions.
- Code-style config auto-applied via Pint's rules file.
- PHPStan config detected automatically.
- Latte plugin recommended for syntax highlighting.
- Xdebug adapter configured for the dev stack ports.

### Useful local commands

```
php bin/gallery about                        # environment summary
php bin/gallery route:list                   # all routes + their middleware
php bin/gallery container:dump               # DI wiring inspection
php bin/gallery tinker                       # REPL via psy/psysh
php bin/gallery db:dump > dump.sql           # DB dump including data
php bin/gallery migrate:fresh --seed=demo    # nuke + rebuild with demo data
php bin/gallery translations:audit           # missing-translation report
php bin/gallery events:list                  # all registered event listeners
```

### Docs as code

- Architecture decisions live in `docs/adr/` as numbered Markdown files (`0001-use-frankenphp.md`, `0002-no-orm.md`, …).
- Significant decisions require a PR adding an ADR.
- Existing ADRs are not rewritten; when a decision is superseded, a new ADR notes the change and links back.

### Communication

- Public chat (Matrix / Discord / IRC) for live discussion.
- Async via GitHub Issues and Discussions.
- Optional weekly office-hours call, time-zone-friendly rotation.

### Gotchas documented up-front

A `docs/gotchas.md` page lists things that trip up newcomers:

- Worker-mode pitfalls (static state, persistent connections, `ini_set` leaks).
- FrankenPHP-specific behaviors (Caddy routing, module loading order).
- Latte context-aware escape quirks.
- libvips memory-cap settings.
- Pest parallel execution + DB isolation.

Read this page during onboarding; reference it when an odd behavior shows up.

---

## 20. Release Engineering and Governance

### Versioning

**Semantic Versioning 2.0.** Breaking changes to any of the following bump the major version:

- Plugin API (`PluginInterface`, event classes, hook-point signatures).
- CLI flags and command shapes.
- JSON API (each version under `/api/vN/`).
- Theme contract (`theme.json`, Latte extension API).
- `.env` key names and semantics.

Additive changes bump minor. Fixes bump patch.

`v1.0.0` is the first stable release. Pre-1.0 (`v0.x.y`) is beta — backward compatibility is *not* guaranteed between 0.x versions.

### Release cadence

- **Patches** released as needed — typically weekly.
- **Minors** released monthly.
- **Majors** released every 18–24 months, with a 6-month deprecation runway.

### Branching model

- `main` — always green, always deployable.
- `release/1.x` — backport branch for the current stable line.
- Feature branches merge into `main` via PR.
- Releases are tagged on `main` or `release/1.x`.

No long-lived feature branches — features land behind flags or in small PRs. Flags for unfinished features live on `users.feature_flags` or a runtime config.

### Release artifacts

For each tag:

- **Composer package** pushed to Packagist.
- **Docker image** pushed to GHCR and Docker Hub: `ghcr.io/org/piwigo:1.0.0`, `:1.0`, `:1`, `:latest`.
- **Source tarball** attached to the GitHub release with SHA256 checksum.
- **SBOM** (Software Bill of Materials) in CycloneDX format attached.
- **Release notes** in `CHANGELOG.md` (keep-a-changelog format) + GitHub release body.
- **Signatures:**
  - Docker images signed with cosign.
  - Source tarball signed with the maintainer's GPG key.
  - Public keys published on the project website and via `keys.openpgp.org`.

### Changelog management

`CHANGELOG.md` follows keep-a-changelog:

```
## [Unreleased]

### Added
- Plugin API: new `AlbumDeletedEvent` (#128)

### Changed
- Default derivative JPEG quality lowered from 85 to 82 (#132)

### Deprecated
- `DerivativeConfig::$oldField` — use `newField`; removed in 2.0 (#134)

### Fixed
- Memory leak in libvips operation cache (#142)

### Security
- Upload validation rejects malformed JPEGs that could trigger DoS (GHSA-…)
```

Every PR touching user-visible behavior updates the `Unreleased` section. Release cuts promote `Unreleased` → a dated version.

### Security advisories

GitHub Security Advisories (GHSA) with CVSS scores. Workflow:

1. Private report — `security@` email or GHSA form.
2. Triage within 72 h.
3. Fix on a private fork; coordinate with the reporter.
4. Publish fix + advisory simultaneously.
5. Credit the reporter (unless they decline).
6. Issue a patch release; the advisory links to the patch commit.

### License

**GPL-2.0-or-later** — matches Piwigo's license (allowing contributors familiar with the existing Piwigo-world to transition comfortably even though technical compatibility is deliberately broken).

All contributors sign a Developer Certificate of Origin (DCO) via `Signed-off-by` in the commit. No CLA.

### Governance

- **Maintainers:** named individuals with commit access. Initial set is small (2–3). Becoming a maintainer requires sustained contribution plus agreement from existing maintainers.
- **Decisions:** proposed via GitHub Discussions or RFC-style PRs in `docs/rfc/`. Maintainers approve via comment. Contentious decisions escalate to an async vote.
- **Code review:** every PR requires approval from one maintainer who did not author it. Significant architectural changes require two.
- **Issue triage:** weekly rotation among maintainers. Stale bot closes issues inactive for 90 days after a grace-period ping.

### Contributing

`CONTRIBUTING.md` covers:

- Dev environment setup (pointer to section 19).
- PR checklist (tests, changelog entry, docs if user-facing, ADR if architectural).
- PR template (auto-populates via GitHub).
- Review expectations — response within 3 business days.
- Code of conduct (Contributor Covenant).

### Roadmap

Public on GitHub Milestones. Each milestone has:

- Target date (always estimate; communicate when it slips).
- List of issues.
- Acceptance criteria.

Shipping early is fine. Slipping is announced with a root-cause note.

This document is the **architecture** roadmap. The **feature** roadmap is separate — what gets built when, based on user feedback and maintainer bandwidth.

### Deprecation communication

Deprecations surface in three places:

- `@deprecated` PHPDoc on the affected code (visible in IDEs).
- Release notes with the removal version.
- A `/admin/deprecations` page in-app showing any deprecated API or config the current install is using (sourced from `trigger_deprecation()` events logged during operation).

### Support channels

- **Bugs:** GitHub Issues.
- **Feature requests:** GitHub Discussions.
- **Security:** `security@` email or GHSA.
- **Dev chat:** Matrix / Discord.
- **Commercial support:** not offered by the project. Third parties may offer it.

### Metrics and public health

`docs/health.md` auto-updates weekly via CI:

- Latest release.
- Open / closed issue counts.
- Median PR time-to-merge.
- CI success rate.
- Test coverage trend.
- Active contributors in the last 90 days.

Maintained automatically — a stalled project is visible, not hidden.

### Sunset plan

If the project ever stops being maintained:

- Public notice in the README and on the website.
- Six-month archival notice with last-known-good release.
- Repo and release artifacts remain available indefinitely.
- Existing installs continue to work (no required phone-home, no remote kill switch).

---

## 21. Search Architecture

**Decision:** start with native DB full-text (MySQL `FULLTEXT` / Postgres `tsvector`) behind a `SearchEngine` interface; make Meilisearch a first-class alternative for large installs.

### What's searchable

| Surface | Fields | Weight |
|---|---|---|
| Images | `title`, `description`, `original_name`, `author`, camera/lens from EXIF | title 3× > description 2× > filename 1× > EXIF 0.5× |
| Tags | `name`, `slug` | tag match on image = 2× of a word-match |
| Albums | `name`, `description` | same weight as image title |
| Comments | `body` | opt-in; off by default |

Searchable surfaces are configurable; the default is images + tags + albums.

### Engine choice

Three realistic backends, picked by deployment size:

| Backend | When | Upsides | Downsides |
|---|---|---|---|
| **MySQL FULLTEXT** | Default for MySQL installs < 100k images | Zero extra infra, native `MATCH AGAINST`, inverted index kept in-engine | No good multilingual stemming, ranking is `WITH QUERY EXPANSION`-based and mediocre, no typo tolerance |
| **Postgres `tsvector` + `GIN`** | Default for Postgres installs < 1M images | Mature, good `to_tsvector` stemming per language, ranking via `ts_rank_cd()`, generated-column index | Per-language config matters, no typo tolerance without `pg_trgm` extension |
| **Meilisearch** | Any install that wants typo tolerance, instant search, or > 1M images | Sub-50 ms p95 even at scale, typo-tolerant, facets/filters built in, minimal ops | One more service to run, index lives outside the DB transaction boundary |

Opinionated defaults plus a clean abstraction. A site can migrate backends later without domain-code changes.

### `SearchEngine` interface

```php
interface SearchEngine
{
    public function index(Indexable $doc): void;
    public function bulkIndex(iterable $docs, int $batchSize = 500): void;
    public function remove(string $type, int $id): void;
    public function search(SearchQuery $query): SearchResult;
    public function suggest(string $prefix, int $limit = 8): array;
    public function rebuildIndex(string $type, \Closure $progress = null): void;
}

final readonly class SearchQuery
{
    public function __construct(
        public string $term,
        public array $tags = [],              // tag IDs, AND semantics
        public array $excludedTags = [],      // NOT
        public ?DateRange $dateTaken = null,
        public ?DateRange $dateUploaded = null,
        public ?int $authorId = null,
        public ?AccessLevel $maxLevel = null, // filter by viewer's access
        public SortMode $sort = SortMode::Relevance,
        public int $page = 1,
        public int $pageSize = 30,
    ) {}
}

final readonly class SearchResult
{
    public function __construct(
        public array $hits,                    // Image | Album | Tag DTOs
        public int $totalHits,
        public int $elapsedMs,
        public array $facets = [],             // { 'tag' => [['id'=>1,'name'=>'beach','count'=>42], ...], ... }
    ) {}
}
```

Three implementations: `MySqlFullTextSearchEngine`, `PostgresTsvectorSearchEngine`, `MeilisearchEngine`. Selected by config.

### Query grammar

The public search UI accepts a natural-feeling syntax, parsed into a `SearchQuery`:

```
sunset beach                     → term "sunset beach"
"golden hour"                    → phrase match
tag:beach                        → must have tag
-tag:portrait                    → must NOT have tag
tag:(beach OR ocean)             → tag combinator
author:alice                     → images by alice
taken:2024-06..2024-09           → date-taken range
uploaded:>2026-01-01             → date-uploaded cutoff
camera:"X-T5"                    → EXIF camera match
sort:newest                      → sort override
```

Parser is `SearchQueryParser`, unit-tested thoroughly. Invalid syntax doesn't throw — unknown tokens fall back to free-text terms. The UI shows a hint bar with the parsed interpretation.

### Indexing strategy

Two paths: direct-write (small installs) and event-driven (everyone else).

**Direct-write** — when the search engine is the same DB (MySQL / Postgres), the `FULLTEXT` / `tsvector` index is maintained automatically by the engine on `INSERT`/`UPDATE`/`DELETE`. No app code needed beyond correct `CREATE INDEX`.

**Event-driven** — when the engine is external (Meilisearch), the app dispatches `ReindexSearchMessage` on every domain event that changes searchable content:

```php
#[AsEventListener]
final class QueueReindexOnImageChange
{
    public function __invoke(
        ImageCreatedEvent|ImageUpdatedEvent|ImageDeletedEvent $event,
    ): void {
        $this->bus->dispatch(new ReindexSearchMessage(
            type: 'image',
            id: $event->image->id,
            action: $event instanceof ImageDeletedEvent ? 'delete' : 'upsert',
        ));
    }
}
```

The handler invokes `$searchEngine->index()` or `->remove()`. Queued, retryable, batched.

### Postgres-specific design

```sql
ALTER TABLE images ADD COLUMN search_doc tsvector
    GENERATED ALWAYS AS (
        setweight(to_tsvector('simple', coalesce(title, '')),       'A') ||
        setweight(to_tsvector('simple', coalesce(description, '')), 'B') ||
        setweight(to_tsvector('simple', coalesce(original_name, '')), 'C')
    ) STORED;

CREATE INDEX images_search_idx ON images USING GIN (search_doc);
```

Multi-language deployments parameterize `'simple'` with the current locale's config (`'english'`, `'french'`, etc.); cross-language searches fall back to `'simple'`.

### MySQL-specific design

```sql
ALTER TABLE images ADD FULLTEXT INDEX images_search_idx (title, description, original_name);
```

Queried with `MATCH(...) AGAINST(:term IN BOOLEAN MODE)` for explicit-operator support (`+required`, `-excluded`, `"phrase"`).

### Meilisearch-specific design

One index per type (`images`, `albums`, `tags`). Settings configured at init:

```json
{
    "searchableAttributes": ["title", "description", "original_name", "tags_names"],
    "filterableAttributes": ["tag_ids", "album_ids", "author_id", "min_level", "taken_at", "created_at"],
    "sortableAttributes": ["taken_at", "created_at", "view_count"],
    "rankingRules": ["words", "typo", "proximity", "attribute", "sort", "exactness"],
    "stopWords": ["the", "a", "an", "of", "in", "on", ...],
    "synonyms": { "photo": ["picture", "image"], "beach": ["shore", "coast"] }
}
```

### Result ranking

- **MySQL:** `MATCH AGAINST` score + boost for tag-equal matches.
- **Postgres:** `ts_rank_cd()` with the weighted `tsvector`.
- **Meilisearch:** default ranking rules with optional per-install tuning.

Fallback sort when relevance is tied or `sort:newest` is explicit: `taken_at DESC` then `created_at DESC` then `id DESC` (deterministic).

### Permission filtering

Hits are filtered post-retrieval by the viewer's access — the search index doesn't store ACLs. Filter logic:

```php
$hits = $this->engine->search($query);
return $hits->filter(fn ($h) => $this->permissions->canView($viewer, $h));
```

This is O(n) over a page of ~30 hits — negligible. For very private installs, a pre-filter on `min_level` narrows the initial query.

### Suggestions / autocomplete

- **Postgres:** `pg_trgm` + a GIST index on `tags.name` for prefix + similarity suggestions.
- **MySQL:** `LIKE 'prefix%'` on `tags.name` with a secondary index.
- **Meilisearch:** the engine does this natively.

Shown in a dropdown as the user types; debounced 150 ms.

### Saved searches

A user can save a query with a name; saved searches are rows in `saved_searches(user_id, name, query_json)`. The UI shows them as one-click links; they're also subscribable (a saved search becomes an RSS/Atom feed URL).

### Incremental vs full reindex

- **Incremental:** driven by events; the normal path.
- **Full reindex:** CLI command for disaster recovery or initial import:

```
php bin/gallery search:reindex           # all types
php bin/gallery search:reindex images    # just one type
php bin/gallery search:reindex --since=2026-01-01
```

Batched (500 docs/batch); progress bar; resumable via a checkpoint row.

### Search analytics

Optional (off by default). When enabled, records anonymized `(query, hit_count, ip_hash, user_id)` for popularity metrics; retained per the global retention config. Feeds the "trending searches" widget if a theme opts in.

### Testing

- **Unit:** parser tests covering every grammar construct.
- **Integration:** each engine tested against fixture data — identical queries must produce overlapping top-10 hits across backends (exact ranking can differ; the set shouldn't).
- **Property-based:** random query strings should never throw, never return invalid results, always respect permissions.
- **Performance:** p95 < 100 ms for a 10k-image corpus across all backends; benchmark nightly.

### Not in scope for v1

- Visual similarity search (image-to-image via embeddings). Separate engine class, could be a plugin later.
- AI-powered "find photos of my dog" semantic search. Plugin territory.
- Cross-install federated search. Out of scope.

---

## 22. JSON API v1 Design

The JSON API is a **first-class surface**, not an afterthought. Mobile apps, CLI tools, third-party integrations, and the admin UI itself all consume it. Getting this right once beats retrofitting it later.

### Design principles

1. **RESTish, not dogmatic REST.** Paths are resource-oriented; verbs are HTTP methods; but pragmatic deviations (batch endpoints, RPC-style `/actions`) are fine where REST would be tortured.
2. **Nouns in URLs, verbs in methods.** `POST /api/v1/photos` not `POST /api/v1/uploadPhoto`.
3. **Stable is better than elegant.** A v1 is a contract; breakage moves to v2.
4. **Predictable.** Pagination, errors, filtering, sorting all look the same across every endpoint.
5. **Discoverable.** OpenAPI spec is generated from the code; endpoints that aren't in the spec don't exist.

### URL structure

```
/api/v1/{resource}                          collection
/api/v1/{resource}/{id}                     single item
/api/v1/{resource}/{id}/{subresource}       related collection
/api/v1/{resource}/{id}/actions/{verb}      RPC-style action
```

Always `/api/vN/`. Never unversioned `/api/`.

### Authentication

Two mechanisms:

| Mechanism | Used by | Header |
|---|---|---|
| Session cookie | Browser (admin UI) | `Cookie: session=...` |
| Personal access token | CLI, mobile apps, integrations | `Authorization: Bearer {token}` |

Tokens carry scopes: `read`, `write`, `admin`. A request attempting an out-of-scope operation returns 403.

### Response envelopes

No envelope for single resources:

```http
GET /api/v1/photos/42

{
  "id": "0198f3c5-7e5a-7c2d-9b1a-b37a2f0c8100",
  "title": "Beach sunset",
  "description": "...",
  "created_at": "2026-04-20T12:00:00Z",
  ...
}
```

Collections return an envelope with pagination + facets:

```http
GET /api/v1/photos?album=17&sort=-taken_at&page_size=30

{
  "data": [ {...}, {...}, ... ],
  "page": {
    "size": 30,
    "next_cursor": "eyJ0YWtlbl9hdCI6I...",
    "prev_cursor": null,
    "has_more": true
  },
  "meta": {
    "total_estimate": 12400
  }
}
```

### Pagination: cursor-based

Offset pagination is deliberately **not** supported — it breaks at scale (`OFFSET 100000` scans 100k rows), produces duplicates/skips when data changes mid-scroll, and is a subtle footgun.

Cursor shape: opaque base64-encoded tuple of the sort key + tiebreaker. Example for `sort=-taken_at`:

```
cursor = base64({ "taken_at": "2026-04-20T12:00:00Z", "id": 42 })
```

Next page: `WHERE (taken_at, id) < (:cursor_taken_at, :cursor_id)`. Stable under concurrent writes; O(1) page fetch.

`total_estimate` is approximate (from index stats, not `COUNT(*)`) — exact counts would defeat the purpose.

### Filtering

Query-parameter filters, with an operator suffix convention borrowed from JSON:API / Django:

```
GET /api/v1/photos?author=42
GET /api/v1/photos?taken_at[gte]=2024-01-01&taken_at[lt]=2025-01-01
GET /api/v1/photos?tags[in]=beach,sunset
GET /api/v1/photos?min_width[gte]=1920
GET /api/v1/photos?q=sunset                   # free-text search
```

Operators: `eq` (default), `ne`, `lt`, `lte`, `gt`, `gte`, `in`, `nin`, `like`. Invalid filters return 400 with field details, never 500.

### Sorting

```
GET /api/v1/photos?sort=-taken_at,id       # multi-key, `-` prefix = desc
```

Allowed sort keys are per-resource and documented. Unknown keys → 400.

### Sparse fieldsets

```
GET /api/v1/photos/42?fields=id,title,taken_at
```

Omitting `fields` returns the full resource. Clients that know they only need a few fields save bandwidth and render time.

### Relationships

Side-loaded via `include`:

```
GET /api/v1/photos/42?include=author,tags,albums

{
  "id": "...",
  "title": "...",
  "author": { "id": "...", "username": "alice" },
  "tags":   [ { "id": "...", "name": "beach" } ],
  "albums": [ { "id": "...", "name": "Vacation 2024" } ]
}
```

Without `include`, relationships are returned as IDs (or omitted), not full objects — avoid accidental over-fetching.

### DTOs

Every endpoint's request and response shape is a PHP class (a DTO), validated and serialized the same way:

```php
final readonly class PhotoResponse
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public int $width,
        public int $height,
        public int $filesize,
        public \DateTimeImmutable $created_at,
        public ?\DateTimeImmutable $taken_at,
        public DerivativeUrls $derivatives,
        public ?AuthorSummary $author = null,
        /** @var TagSummary[] */
        public array $tags = [],
    ) {}

    public static function from(Image $image, ?IncludeList $include = null): self { ... }
}
```

DTOs are where OpenAPI schemas come from (via attribute-introspection). They're the single source of truth for the API shape.

### Request DTOs

```php
final readonly class CreatePhotoRequest
{
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public array $tagNames = [],
        public array $albumIds = [],
    ) {}

    public static function fromRequest(ServerRequestInterface $req): self
    {
        $body = json_decode(
            (string) $req->getBody(),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        return (new self(
            title:       trim((string) ($body['title'] ?? '')) ?: null,
            description: trim((string) ($body['description'] ?? '')) ?: null,
            tagNames:    is_array($body['tag_names'] ?? null) ? $body['tag_names'] : [],
            albumIds:    is_array($body['album_ids'] ?? null) ? $body['album_ids'] : [],
        ))->validate();
    }
}
```

Validation throws `ValidationException`; middleware maps to 422.

### Error format — RFC 7807

```http
HTTP/1.1 422 Unprocessable Entity
Content-Type: application/problem+json

{
  "type": "https://example.org/problems/validation-failed",
  "title": "Validation failed",
  "status": 422,
  "detail": "One or more fields are invalid",
  "instance": "/api/v1/photos",
  "errors": {
    "title": ["required"],
    "album_ids": ["at least one is invalid: 9999 not found"]
  },
  "request_id": "01J9X2H7B0Q4Z5VXGYQ4ARBTVM"
}
```

Canonical `type` URIs resolve to documentation pages describing the error class.

### Rate limiting (recap from §11)

Every response includes:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1714651200
```

Exceeded → 429 with `Retry-After`.

### OpenAPI spec

Generated from PHP via attributes + DTO introspection:

```php
#[OpenApi\Operation(
    summary: 'List photos',
    tags: ['photos'],
    responses: [200 => PhotoListResponse::class, 401, 403],
)]
#[OpenApi\Filter('tags[in]', array: true, description: 'Tag ID allowlist')]
#[OpenApi\Sort(['taken_at', 'created_at', 'view_count'])]
public function index(ServerRequestInterface $req): ResponseInterface { ... }
```

`php bin/gallery openapi:dump > docs/api/v1/openapi.yaml` produces the spec on demand; CI regenerates it and fails if it diverges from the committed copy. Drift between code and spec is a bug.

Served at `/api/v1/openapi.yaml` + interactive docs via Scalar or Swagger UI at `/api/v1/docs`.

### Long-running operations

Some operations (full sync, bulk reindex, large batch edits) take minutes. Pattern:

```http
POST /api/v1/albums/17/actions/sync
{ "directory": "/photos/vacation-2024" }

HTTP/1.1 202 Accepted
Location: /api/v1/operations/op-a7b3c2

{
  "id": "op-a7b3c2",
  "status": "queued",
  "progress": 0,
  "created_at": "..."
}
```

```http
GET /api/v1/operations/op-a7b3c2

{
  "id": "op-a7b3c2",
  "status": "running",
  "progress": 0.42,
  "message": "indexed 420 of 1000 images",
  "result": null
}
```

When `status` is `succeeded` or `failed`, `result` contains the outcome or a problem-details object. The operation record is retained for 24 hours, then purged.

### Batch endpoints

When the natural shape is "do N things at once":

```http
POST /api/v1/photos/actions/batch-tag
{
  "photo_ids": ["...", "...", "..."],
  "add_tags": ["summer", "beach"],
  "remove_tags": ["draft"]
}
```

Batch results report per-item success/failure:

```json
{
  "succeeded": 98,
  "failed": 2,
  "items": [
    { "id": "...", "status": "ok" },
    { "id": "...", "status": "error", "error": { "type": "...", "detail": "..." } }
  ]
}
```

Never silently skip failures in a batch — the caller is told exactly which items failed and why.

### Webhooks out

Users configure webhooks in admin; events they subscribe to trigger `DeliverWebhookMessage`:

```json
POST https://example.com/piwigo-webhook
X-Piwigo-Event: image.uploaded
X-Piwigo-Delivery: dlv-a7b3c2
X-Piwigo-Signature: sha256=a7b3c2...

{
  "event": "image.uploaded",
  "delivered_at": "...",
  "data": {
    "image": { ... full PhotoResponse ... }
  }
}
```

Signature: `HMAC_SHA256(webhook_secret, payload)`. Retry: exponential backoff up to 24 h; then dead-letter.

### Webhooks in

Not in v1. Users needing inbound webhooks write a plugin that exposes an auth-gated endpoint.

### Client SDKs

Not shipped with core in v1. The OpenAPI spec makes generated clients straightforward (`openapi-generator`, `swagger-codegen`). A community-contributed PHP client may live under `contrib/`.

### Deprecation

API v1 is frozen for the life of the v1.x release line. Breaking changes → v2 at `/api/v2/`, with v1 running alongside. v1 marked deprecated when v2 is stable; retired no earlier than 12 months after v2 release.

Non-breaking additions are allowed within v1: new optional fields in responses (clients must tolerate unknown fields), new endpoints, new filters. Removals and shape changes are never allowed.

### CORS

`Access-Control-Allow-Origin: *` for public read endpoints (e.g., public album content). Credentialed endpoints (cookie/token) use a configured `ALLOWED_ORIGINS` allowlist.

### Caching

- Read endpoints on public resources: `Cache-Control: public, max-age=60`.
- Authenticated reads: `Cache-Control: private, must-revalidate`, `ETag` on the response.
- Conditional requests (`If-None-Match`) → 304.
- Writes: `Cache-Control: no-store`.

### Contract tests

Every endpoint has a contract test that:

1. Builds a fixture state.
2. Hits the endpoint.
3. Asserts response status, headers, body matches the DTO's expected JSON shape.
4. Asserts the OpenAPI schema validates the response.

Breaking a contract test fails CI; a PR that changes a response shape must include both the code change and the schema update.

---

## 23. Plugin Event Catalog

Full catalog of events the rewrite ships in v1. Partial list was given in §7; this is the authoritative reference plugins code against.

### Naming conventions (recap)

- **Past tense for facts** (`UserCreated`, `AlbumDeleted`) — listeners react; event is immutable.
- **Present participle for "before" hooks** (`UserAuthenticating`, `ImageUploading`) — listeners may veto or mutate.
- **Imperative for render hooks** (`RenderPicturePage`, `BuildAdminMenu`) — listeners contribute content/data.
- **Noun only for scheduled** (`CronTick`, `HourlyTick`) — listeners run periodic work.

Namespaces: `Piwigo\Event\{Area}\{EventName}`.

Cancellation: mutable "before" events implement `CancellableEventInterface`; calling `$event->cancel('reason')` aborts the operation and returns a meaningful error to the caller.

### Auth events (`Piwigo\Event\Auth\`)

| Event | Fired when | Payload | Mutable |
|---|---|---|---|
| `UserAuthenticating` | Credentials submitted, before password verify | `$username`, `$ipAddress`, cancellable with reason | Yes |
| `UserAuthenticated` | Successful login (before session regen) | `User $user`, `AuthMethod $method` | No |
| `UserAuthenticationFailed` | Bad password / unknown user / locked | `$username`, `FailureReason $reason`, `$ipAddress` | No |
| `SessionStarted` | Session initialized for a request | `SessionService $session` | No |
| `SessionRegenerated` | Session ID rotated (on login, privilege change) | `$oldId`, `$newId` | No |
| `UserLoggedOut` | Logout endpoint invoked | `User $user` | No |
| `TokenCreated` | Personal access token issued | `ApiToken $token` (value shown once) | No |
| `TokenRevoked` | Token deleted by user or admin | `ApiToken $token`, `User $revokedBy` | No |
| `RememberMeConsumed` | Valid remember-me cookie auto-loggged in user | `User $user`, `RememberToken $token` | No |
| `PasswordResetRequested` | User requested reset email | `User $user`, `$resetToken` | No |
| `PasswordResetCompleted` | Password actually changed via reset | `User $user` | No |
| `TwoFactorChallengeRequired` | 2FA enabled on account, awaiting code | `User $user` | No |

### User lifecycle events (`Piwigo\Event\User\`)

| Event | Fired when | Payload | Mutable |
|---|---|---|---|
| `UserRegistering` | Registration submitted, before row insert | `RegistrationInput $input`, cancellable | Yes |
| `UserCreated` | Row inserted into `users` | `User $user`, `?Request $request` | No |
| `UserUpdating` | Profile update, before commit | `User $user`, `UserUpdate $changes` | Yes |
| `UserUpdated` | Profile update committed | `User $before`, `User $after` | No |
| `UserDeleting` | Account deletion, before commit | `User $user`, cancellable | Yes |
| `UserDeleted` | Account deleted | `User $user` (read-only snapshot) | No |
| `UserAccessLevelChanged` | Role promoted/demoted | `User $user`, `AccessLevel $old`, `AccessLevel $new` | No |

### Album lifecycle events (`Piwigo\Event\Album\`)

| Event | Fired when | Payload | Mutable |
|---|---|---|---|
| `AlbumCreating` | Before row insert | `CreateAlbumInput $input`, cancellable | Yes |
| `AlbumCreated` | After commit | `Album $album` | No |
| `AlbumUpdating` | Before commit | `Album $album`, `AlbumUpdate $changes` | Yes |
| `AlbumUpdated` | After commit | `Album $before`, `Album $after` | No |
| `AlbumDeleting` | Before delete | `Album $album`, cancellable | Yes |
| `AlbumDeleted` | After delete | `Album $snapshot` | No |
| `AlbumMoved` | Parent changed (tree move) | `Album $album`, `?Album $oldParent`, `?Album $newParent` | No |
| `AlbumPermissionsChanged` | User/group ACL or `min_level` changed | `Album $album`, `PermissionDiff $diff` | No |
| `AlbumCoverImageChanged` | Cover image reassigned | `Album $album`, `?Image $old`, `?Image $new` | No |

### Image lifecycle events (`Piwigo\Event\Image\`)

| Event | Fired when | Payload | Mutable |
|---|---|---|---|
| `ImageUploading` | File received, before validation | `UploadedFile $file`, `User $uploader`, cancellable | Yes |
| `ImageValidating` | After magic-byte check, before save | `ProbedImage $probe`, cancellable | Yes |
| `ImageSaving` | Before DB row insert | `Image $image`, mutable metadata | Yes |
| `ImageCreated` | After commit, before derivative queue | `Image $image` | No |
| `ImageUpdating` | Metadata edit, before commit | `Image $image`, `ImageUpdate $changes` | Yes |
| `ImageUpdated` | Metadata edit committed | `Image $before`, `Image $after` | No |
| `ImageDeleting` | Before delete | `Image $image`, cancellable | Yes |
| `ImageDeleted` | After delete (derivatives cleaned, row removed) | `Image $snapshot` | No |
| `ImageMovedBetweenAlbums` | Membership change | `Image $image`, `Album[] $added`, `Album[] $removed` | No |
| `ImageTagsChanged` | Tag membership change | `Image $image`, `Tag[] $added`, `Tag[] $removed` | No |
| `ImageRatingChanged` | Rating updated | `Image $image`, `?int $old`, `?int $new` | No |
| `ImageViewed` | Public view recorded (throttled to one per session per image) | `Image $image`, `?User $viewer` | No |

### Derivative events (`Piwigo\Event\Derivative\`)

| Event | Fired when | Payload | Mutable |
|---|---|---|---|
| `DerivativeRequested` | Cache miss on `/media/...` | `DerivativeRequest $req`, `Image $source` | No |
| `DerivativeGenerating` | Before libvips runs | `Image $source`, `DerivativeParams $params`, cancellable | Yes |
| `DerivativeGenerated` | File written to cache | `Image $source`, `DerivativeParams $params`, `$path`, `$bytes` | No |
| `DerivativeFlushed` | Manual or scheduled purge | `Image $source` | No |
| `DerivativeBatchPrunedOrphans` | Scheduled prune ran | `int $removedCount`, `int $freedBytes` | No |

### Tag events (`Piwigo\Event\Tag\`)

| Event | Fired when | Payload | Mutable |
|---|---|---|---|
| `TagCreated` | New tag inserted | `Tag $tag` | No |
| `TagRenamed` | Tag name/slug changed | `Tag $tag`, `$oldName`, `$newName` | No |
| `TagMerged` | Two tags merged | `Tag $survivor`, `Tag $absorbed`, `int $imagesUpdated` | No |
| `TagDeleted` | Tag removed (with cascade) | `Tag $snapshot`, `int $imagesDetached` | No |

### Comment events (`Piwigo\Event\Comment\`)

| Event | Fired when | Payload | Mutable |
|---|---|---|---|
| `CommentSubmitting` | Before validation (spam check hook) | `CommentInput $input`, cancellable | Yes |
| `CommentCreated` | After commit (may be `pending`) | `Comment $comment` | No |
| `CommentApproved` | Moderator approves | `Comment $comment`, `User $moderator` | No |
| `CommentRejected` | Moderator rejects | `Comment $comment`, `User $moderator`, `$reason` | No |
| `CommentDeleted` | Comment removed | `Comment $snapshot` | No |

### Search events (`Piwigo\Event\Search\`)

| Event | Fired when | Payload | Mutable |
|---|---|---|---|
| `SearchBuilding` | Query about to be parsed | `$rawQuery`, `User $viewer`, mutable | Yes |
| `SearchQueryBuilt` | After parse, before execute | `SearchQuery $query` | Yes |
| `SearchCompleted` | Results assembled | `SearchQuery $query`, `SearchResult $result`, `int $elapsedMs` | No |
| `SuggestionsRequested` | Autocomplete hit | `$prefix`, array of suggestions | Yes |

### API events (`Piwigo\Event\Api\`)

| Event | Fired when | Payload | Mutable |
|---|---|---|---|
| `ApiRequestReceived` | Top of any `/api/v*` request | `ServerRequestInterface $req` | No |
| `ApiResponseSending` | Just before response write | `ResponseInterface $res`, `$request` | Yes |
| `ApiRateLimitExceeded` | 429 about to be returned | `$key`, `$limit`, `$resetAt` | No |
| `WebhookDelivering` | Before outbound HTTP call | `WebhookDelivery $delivery`, cancellable | Yes |
| `WebhookDelivered` | 2xx response from endpoint | `WebhookDelivery $delivery`, `$responseTimeMs` | No |
| `WebhookFailed` | Non-2xx or network error (per attempt) | `WebhookDelivery $delivery`, `int $attemptNumber`, `\Throwable $error` | No |
| `WebhookDeadLettered` | All retries exhausted | `WebhookDelivery $delivery` | No |

### Render events (`Piwigo\Event\Render\`)

Hook-point events plugins use to contribute HTML/data:

| Event | Fired when | Payload | Mutable |
|---|---|---|---|
| `RenderHead` | `<head>` building | `HeadBuilder $head` — `->addScript()`, `->addStyle()`, `->addMeta()`, `->addLink()` | Yes |
| `RenderGalleryIndex` | Index page template data being built | `GalleryIndexData $data` | Yes |
| `RenderAlbum` | Album page template data | `AlbumViewData $data` | Yes |
| `RenderPicture` | Picture page template data | `PictureViewData $data` | Yes |
| `RenderSearchResults` | Search results template data | `SearchViewData $data` | Yes |
| `RenderFooter` | Before `</body>` | `FooterBuilder $footer` | Yes |
| `RenderPictureMetadata` | Metadata panel (EXIF, GPS) rendering | `Image $image`, `MetadataPanel $panel` | Yes |
| `RenderThumbnailCell` | Each thumbnail cell in a grid | `Image $image`, `ThumbnailCell $cell` | Yes |

### Admin events (`Piwigo\Event\Admin\`)

| Event | Fired when | Payload | Mutable |
|---|---|---|---|
| `BuildAdminMenu` | Building sidebar | `AdminMenu $menu` — `->add('Settings', '/admin/settings', icon: …, order: 50)` | Yes |
| `BuildAdminDashboard` | Dashboard widgets | `DashboardBuilder $builder` | Yes |
| `AdminActionExecuted` | Any admin action (audit trail) | `$action`, `User $admin`, `$target`, `$details` | No |
| `PluginEnabled` | Plugin toggled on | `Plugin $plugin`, `User $admin` | No |
| `PluginDisabled` | Plugin toggled off | `Plugin $plugin`, `User $admin` | No |
| `PluginInstalled` | Plugin installed | `Plugin $plugin` | No |
| `PluginUninstalled` | Plugin removed | `Plugin $plugin`, `bool $dataDropped` | No |
| `MaintenanceModeChanged` | Maintenance toggled | `bool $enabled`, `?string $message` | No |
| `SettingChanged` | Any `settings` row updated | `$key`, mixed `$oldValue`, mixed `$newValue` | No |

### Scheduled / cron events (`Piwigo\Event\Cron\`)

Plugins subscribe for periodic work without running their own cron:

| Event | Fired | Payload |
|---|---|---|
| `MinuteTick` | Every minute | `\DateTimeImmutable $now` |
| `FiveMinuteTick` | Every 5 minutes | `\DateTimeImmutable $now` |
| `HourlyTick` | Every hour | `\DateTimeImmutable $now` |
| `DailyTick` | Daily 03:00 UTC | `\DateTimeImmutable $now` |
| `WeeklyTick` | Sunday 04:00 UTC | `\DateTimeImmutable $now` |
| `MonthlyTick` | 1st of month 05:00 UTC | `\DateTimeImmutable $now` |

Listeners should enqueue their own `Message`s rather than blocking the tick dispatcher.

### System events (`Piwigo\Event\System\`)

| Event | Fired when | Payload |
|---|---|---|
| `AppBooted` | Once per worker, after container built | `Container $container` |
| `AppShuttingDown` | Worker receiving SIGTERM | `int $requestsHandled` |
| `RequestStarted` | Top of middleware stack | `ServerRequestInterface $req` |
| `RequestCompleted` | Response about to flush | `ServerRequestInterface $req`, `ResponseInterface $res`, `int $elapsedMs` |
| `ExceptionCaught` | Error middleware caught an exception | `\Throwable $exception`, `ServerRequestInterface $req` |
| `DatabaseQueryExecuted` | After every query (dev/trace mode only) | `$sql`, `$params`, `int $elapsedUs` |

### Custom events

Plugins dispatch their own event classes via the same dispatcher. Naming convention: `VendorName\PluginName\Event\*`. Other plugins can subscribe to them — an event bus is a feature, not a closed catalog.

### Event deprecation

If an event is renamed or removed:

- Old event continues firing alongside the new one for one minor version.
- Listener registration to the old name logs a deprecation warning with the new name.
- Removed in the next minor; hard break only at a major version bump.

### Event discovery

```
php bin/gallery events:list
php bin/gallery events:list --filter=Image
php bin/gallery events:show Piwigo\Event\Image\ImageCreated    # full payload schema + listeners currently subscribed
```

Generates event reference docs as part of `docs/events.md` at CI time.

---

## 24. Upload and Filesystem Sync

Two paths bring photos into the app: **uploads** (user-driven, per-file or batch) and **sync** (admin-driven, directory-tree mirror). They share the validation pipeline but have different UX, different concurrency profiles, and different failure modes.

### Upload protocols

Three supported, each for a different client type:

| Protocol | Client | Max size | Resumable |
|---|---|---|---|
| `multipart/form-data` | Browser form (no-JS fallback) | 100 MB default | No |
| Chunked via `multipart` + range | Browser with JS | Unbounded | Yes |
| tus (Resumable Uploads Protocol) | Mobile apps, CLI, third parties | Unbounded | Yes |

tus is the primary path for non-browser clients. The tus server implementation uses `srmklive/laravel-tus-upload` (rewritten for our stack) or a self-rolled minimal tus server — the protocol is small.

### Client-side (browser, JS)

`upload.js` provides:

- **Drag-drop zone + file-picker fallback.**
- **Parallel uploads**, default 3 concurrent; configurable.
- **Per-file progress bar.**
- **Retry on transient errors**, exponential backoff up to 5 attempts.
- **Chunked upload** for files > 5 MB; client splits and uploads chunks, server reassembles.
- **Resume on refresh** — partial uploads recorded in `localStorage` with tus URL; page reload lists them for one-click resume.
- **Client-side pre-checks:** file size, MIME allowlist, max-dim (optional — declines obvious non-images before upload).

No upload library dependency — tus has a small client; we ship it.

### Server-side validation pipeline (recap from §5)

```
Received bytes (stream or completed file)
    ↓
1. Content-length within config max → else 413
    ↓
2. MIME in allowlist → else 415
    ↓
3. File in quarantine dir with randomized name (not web-reachable)
    ↓
4. Magic-byte probe via libvips → else 422 "not an image"
    ↓
5. libvips loads headers (dimensions, format, animation, color profile)
    ↓
6. EXIF extraction (try/catch — non-fatal)
    ↓
7. sha256 + perceptual hash
    ↓
8. Duplicate detection (see below)
    ↓
9. Optional ClamAV scan
    ↓
10. Dispatch ImageUploading + ImageValidating (plugins can veto)
    ↓
11. Move to originals/YYYY/MM/uuid.ext
    ↓
12. Quota debit (transactional with row insert)
    ↓
13. INSERT images row + image_albums rows + image_tags rows
    ↓
14. Dispatch ImageCreated
    ↓
15. Enqueue GenerateDerivativesMessage for all presets
```

Failure at any step → quarantine file deleted, error returned. No partial state.

### Duplicate detection

Two-level:

- **Exact** — `sha256` match. Hard block (or configurable: replace, add to album, skip).
- **Perceptual** — pHash Hamming distance ≤ 6 out of 64. Soft warning with preview: "looks similar to image #42 — upload anyway?"

Configurable per-install: block, warn, or silent.

### Quota enforcement

Before the INSERT (step 13), quota is checked + debited inside the same DB transaction:

```sql
UPDATE user_quotas
SET bytes_used = bytes_used + :size,
    files_used = files_used + 1
WHERE user_id = :user_id
  AND (bytes_limit IS NULL OR bytes_used + :size <= bytes_limit)
  AND (files_limit IS NULL OR files_used + 1 <= files_limit);
```

Zero affected rows → quota exceeded → 413 with current/limit breakdown. Atomic with the image insert: no way to insert the row and skip the debit.

### Batch upload UX

The upload page supports selecting many files at once:

- Files queued client-side; uploaded N at a time (configurable parallelism).
- Each file shows progress; failures show the error inline with a retry button.
- Overall progress bar (e.g. "uploading 42 of 100 — 1.2 GB of 3.4 GB").
- On completion: summary with success/failure counts, links to the created images.

All files receive the same initial album + tags set by the user in the form; per-file adjustments happen in the batch manager afterwards.

### Filesystem sync

For admins importing large directory trees (existing photo library, scanned-film archive, camera SD dump), the CLI sync walks a filesystem and reconciles with the DB.

```
php bin/gallery sync /photos/2024-vacation [--options]
```

Options:

| Flag | Effect |
|---|---|
| `--dry-run` | Report would-be changes, write nothing |
| `--album=17` | Target a specific album as the root (default: infer from top-level dir) |
| `--mirror-tree` | Create sub-albums mirroring the directory structure |
| `--tag-from-path` | Infer tags from directory names (excluding album-mapped ones) |
| `--delete-missing` | Remove DB rows for files no longer on disk |
| `--force-rehash` | Recompute sha256/pHash for unchanged files (disaster-recovery use) |
| `--watch` | Keep running; react to filesystem changes (inotify/fsnotify) |
| `--concurrency=4` | Parallel workers (default: CPU count) |
| `--progress` | Show live progress bar + ETA |
| `--resume` | Pick up from the last checkpoint (sync writes checkpoints every 100 files) |

### Sync walk algorithm

```
1. Scan target directory tree into a list of (path, mtime, size).
2. Load the album's current image set into an in-memory set keyed by storage_path.
3. For each file on disk:
   a. If already in the set with matching mtime + size → skip.
   b. If new → run the validation pipeline, insert.
   c. If path matches but mtime/size differ → re-probe, update row, flush derivatives.
4. If --delete-missing:
   Files in the DB set but not on disk → remove DB row, dispatch ImageDeleted.
5. If --mirror-tree:
   Directories become albums; moved files become album-membership changes.
6. Write checkpoint every 100 files.
```

### Dry-run output

```
$ php bin/gallery sync /photos/2024-vacation --mirror-tree --dry-run

Sync plan for /photos/2024-vacation → album "Vacation 2024"
────────────────────────────────────────────────────────────
Albums to create:
  + /Vacation 2024/Iceland
  + /Vacation 2024/Iceland/Day 1
  + /Vacation 2024/Iceland/Day 2
  + /Vacation 2024/Norway

Images to import: 1,243
  - 1,103 new
  - 42 updated (mtime differs)
  - 98 already in sync (skipped)

Quota impact:
  Author: alice
  Current: 12.4 GB / 50.0 GB
  After:   34.2 GB / 50.0 GB  (+21.8 GB)

Estimated time: 18 min (at 1.2 images/sec)

Run without --dry-run to apply.
```

### Tag inference from paths

With `--tag-from-path`, directory names (other than those mapped to albums) become tag candidates. Example:

```
/photos/2024/Iceland/landscape/sunset/IMG_0042.jpg
```

With `--mirror-tree` mapping `/photos/2024/Iceland/` to albums, the remaining path `landscape/sunset` infers tags `landscape` and `sunset`. Separator is `/`; tags are lowercased + slug-normalized.

A stop-word list filters out nonsense (`thumbnails`, `originals`, `exports`, `.cache`, `__MACOSX`); configurable.

### Watch-folder mode

`--watch` runs the sync continuously using inotify (Linux) or fsnotify (cross-platform fallback). Newly-appearing files trigger the validation pipeline in real time; modified files re-probe; deleted files optionally remove rows.

Debounced — camera uploading to a watched folder often writes in chunks; the watcher waits 5 s of quiescence per file before processing.

### Conflict resolution

Files can change in ways that matter:

| Detection | Action |
|---|---|
| Same path, same sha256, same mtime | No-op |
| Same path, same sha256, different mtime | Update `updated_at` only |
| Same path, different sha256 | Re-probe; if still valid, update the row + flush derivatives |
| New path, known sha256 | "File moved" — update `storage_path`, preserve DB ID + albums + tags |
| Known path gone, same sha256 appears elsewhere | Same as "file moved" |
| Known path gone, no match | If `--delete-missing`, delete; else warn |

Moves preserve identity: the image's DB ID, URLs, album memberships, tags, comments, and ratings all survive a disk reorganization.

### Error handling

The walk is fault-tolerant:

- Single-file failures are logged, sync continues.
- At end, a summary reports per-error-type counts + pointer to log.
- Fatal errors (DB down, disk full) abort with a checkpoint written — `--resume` picks up.

### Sync via the API

The same operation is available as a long-running API operation (see §22):

```
POST /api/v1/albums/17/actions/sync { "directory": "/photos/2024", "mirror_tree": true }
→ 202 Accepted, Location: /api/v1/operations/op-a7b3c2
```

Clients poll the operation endpoint for progress.

### Permissions for upload

- Uploader must have `can_upload` on at least one album the upload targets.
- Sync via admin CLI requires `Admin` access level.
- API uploads need a token with `write` scope.

### Security recap (from §11)

- Files never served from the upload directory.
- UUIDv7 filenames on disk; user's filename is display-only.
- Magic-byte check via libvips.
- SVG rejected by default.
- ClamAV optional.
- Upload pipeline events let plugins veto.

### Testing the pipeline

- Fixture images from `tests/fixtures/images/` (EXIF orientations, HEIC, AVIF, animated WebP, CMYK, pathological JPEGs).
- Each fixture uploaded through the pipeline → assertions on DB state, storage layout, derivative existence.
- Quota enforcement exercised with fixtures that would exceed quota.
- Malicious fixtures (zip bombs disguised as JPEGs, embedded PHP in JFIF comment) must fail gracefully.
- Sync walk tested against a tree fixture with known expected DB state.

---

## 25. Admin UI and UX

The admin UI is an **application in its own right**, not an afterthought. A power user spends most of their time here; slow, clunky, or cryptic admin is a day-to-day tax.

### Design principles

1. **Keyboard-first.** Every action reachable without the mouse. Visible shortcuts everywhere.
2. **Progressive enhancement.** No-JS users can still do basic admin — maybe slower, never broken.
3. **Bulk by default.** A user editing one photo is an accident; the real workload is editing hundreds.
4. **Dense but discoverable.** Information density matters for power users; hover/focus reveals affordances without crowding.
5. **No surprise writes.** Destructive actions confirm; destructive batch actions double-confirm with the affected count.
6. **Audit everything.** Every admin write produces an audit-log entry automatically.

### Navigation structure

```
Dashboard                     (home for admin — system health, recent activity)
├── Content
│   ├── Albums                (tree view, drag-drop reorder, bulk ops)
│   ├── Photos                (batch manager)
│   ├── Tags                  (rename, merge, delete)
│   └── Comments              (moderation queue)
├── People
│   ├── Users
│   └── Groups
├── Plugins
│   ├── Installed
│   └── Browse                (future — marketplace, not v1)
├── Appearance
│   └── Themes
├── System
│   ├── Sync                  (import / filesystem sync)
│   ├── Maintenance           (cache, derivatives, DB checks)
│   ├── Queues                (job queue monitor)
│   ├── Audit Log             (filter + export)
│   ├── Deprecations          (APIs/config in use that are deprecated)
│   └── Settings              (grouped by area)
└── About
    ├── Version
    ├── Health Check
    └── Logs                  (live tail, filtered)
```

Persistent left sidebar with collapsible groups; top bar has breadcrumb + global search + user menu.

### Dashboard

At-a-glance snapshot, refreshed via HTMX every 30 s:

- **System status:** all-green / warning / error light per subsystem (DB, storage, queues, mail).
- **Storage:** originals + derivatives + free space, with trend sparkline.
- **Queues:** depth per queue, processed/min.
- **Recent uploads:** last 20.
- **Recent comments awaiting moderation.**
- **Recent security events:** logins from new IPs, failed login bursts.
- **Deprecation usage:** any APIs/hooks in use that are marked for removal.

### Album admin

Tree view with lazy-loaded children:

- **Drag-drop reorder** (siblings) and **drag-drop move** (different parent).
- **Keyboard:** arrow keys navigate, space expands, `e` edits, `Delete` deletes (with confirm), `n` creates sibling, `Shift+n` creates child.
- **Bulk select** via checkbox; bulk actions: delete, move, change permissions, set cover image.
- **Inline edit** for name/rank; full edit opens a drawer without leaving the tree.
- **Permission editor** per album (next section).

### Photo batch manager

The bread and butter of admin work. Goal: edit metadata on hundreds of photos without carpal tunnel.

Layout:

- **Filter sidebar:** albums, tags, date range, author, camera, has-GPS, rating, permissions, missing-metadata (no title / no description / no tags).
- **Grid view** with thumbnails; infinite scroll with virtualized DOM.
- **Selection:** click to select; Shift-click for range; Cmd/Ctrl-click for toggle; select-all / select-none / invert.
- **Action bar** appears when ≥ 1 photo selected — fixed to top of grid:
  - Add tags / remove tags.
  - Add to album / remove from album / move between albums.
  - Change author.
  - Set rating.
  - Change permissions (min_level, ACL).
  - Regenerate derivatives.
  - Delete (double-confirm with count).
  - Export (ZIP with originals + metadata).
- **Undo** for most actions — the action creates an audit entry that can be reverted within 5 minutes.

Every batch action dispatches a `BulkXxxOperation` (see §22 batch endpoints) which runs async for > 50 items; UI shows progress and links to the operation details.

### Keyboard shortcuts catalog

Documented in `?` help modal:

```
Global
    /         focus global search
    g then d  go to dashboard
    g then p  go to photos
    g then a  go to albums
    g then u  go to users
    ?         keyboard shortcuts help

Batch manager
    j / k     move selection down / up
    x         toggle current photo selection
    Shift+x   extend selection range
    a         select all visible
    A         clear selection
    e         open edit drawer for selection
    t         open tag picker
    Del       delete (with confirm)
    r         regenerate derivatives

Album tree
    ↑ / ↓     navigate siblings
    ← / →     collapse / expand
    Enter     open
    n         new sibling
    Shift+n   new child
    e         edit
    Del       delete
```

### User / group admin

- **Users list:** search, filter by role, filter by last-active window.
- **User detail:** profile, permissions (resolved effective + explicit per-album), recent activity, API tokens, sessions.
- **Bulk actions:** change role, force logout (revoke sessions), delete.
- **Group editor:** members, per-album ACL, default groups auto-assigned to new users.

### Permission editor

The hardest UX in the admin. Goal: "make this album private except for alice and the 'family' group" should be obvious.

Layout per-album:

```
Album: Vacation 2024
───────────────────────────────────────────────────
Access level required:    [Guest ▾]  (inheriting: Guest)

Public (unchecked)

Overrides:
  ┌─────────────────────────────────────────────┐
  │ User / Group      View   Upload    Manage   │
  ├─────────────────────────────────────────────┤
  │ [+] alice          ✓      ✓         —       │
  │ [+] @family        ✓      —         —       │
  │ [×] bob            ✓      —         —       │
  └─────────────────────────────────────────────┘

Effective: Users who can view this album
  alice (explicit), @family (bob, carol, dave via group)
```

- Inheritance from parent shown with "inheriting from: …" labels.
- Conflicts (group grants, user denies) shown with explicit resolution ("user override wins").
- **"Preview as user X"** — shows what a specific user would see if they visited the album.

### Plugin admin

- List of installed plugins, toggleable on/off.
- Per-plugin settings page (plugins declare their settings schema; admin UI auto-renders a form).
- Install by Composer package name (admin UI shells out to `composer require`); uninstall reverses.
- Plugin-shipped migrations visible and runnable.

### Sync UI

Admin-facing wrapper over the CLI sync (§24):

- Form to enter path + options.
- Dry-run preview before committing.
- Live progress (SSE or polling).
- Log tail displayed during run.
- Cancelable (graceful — in-flight file completes, then stop).

### Maintenance page

One-click actions with clear blast radius:

- Clear response cache.
- Clear Latte compile cache.
- Prune orphaned derivatives.
- Recompute album image counts.
- Rebuild search index.
- Check + fix broken-link rows (DB → missing file).
- Trigger a queue flush (process DLQ one by one with operator review).

### Audit log viewer

Filterable table: actor, event, target, date range. Export to CSV for compliance reviews. Retention is configurable (default 365 days).

### Queue monitor

- Per-queue: depth, processed/min, average time, oldest job age, DLQ count.
- DLQ viewer: expand a failed message, see stack trace, retry or drop.
- Restart a worker (if the admin has shell access and the deployment supports it).

### Settings

Grouped by area: general, storage, uploads, derivatives, email, security, privacy, search, advanced. Each setting has a tooltip explaining its effect; some settings require a worker restart (indicated inline).

### Theme and dark mode

Admin UI has its own theme — intentionally neutral (doesn't look like the public gallery). Dark mode follows OS preference or per-user override. WCAG AA contrast throughout.

### Responsive

Admin works on a phone for read-only tasks (check queue, moderate comments, approve a user). Data-heavy tasks (batch manager) degrade to a list view on narrow screens with a note that a larger screen is better.

### Framework choices (recap)

- **HTMX** for most admin interactions — small, no SPA, partial updates via `hx-swap`.
- **Alpine.js** for local state (dropdowns, modals, keyboard-shortcut handling).
- **Vanilla JS** for the batch-manager grid (virtualized rendering, drag-drop).
- No React / Vue / Svelte.

### Testing

- Browser tests cover every admin happy-path workflow (upload → edit → permission → delete).
- Accessibility tests on every admin page (axe-core).
- Keyboard-only test: a scripted test drives every core workflow using only keyboard events.
- Permission tests: non-admin users attempting admin routes return 403, not 500.

---

## 26. Performance and Scaling

Scattered performance notes appear in several sections (FrankenPHP worker model in §2, caching in §14, DB design in §4, derivative pipeline in §5). This section pulls them into a unified story: what the app does when it gets popular.

### Baseline targets

Single-server reference deployment (4 vCPU, 8 GB RAM, SSD, commodity hardware, no CDN):

| Workload | Target (p95) |
|---|---|
| Anonymous gallery index (cached) | < 5 ms |
| Anonymous gallery index (cache miss) | < 20 ms |
| Authenticated album page (50 thumbs) | < 30 ms |
| Picture page | < 15 ms |
| Derivative serve (cache hit, by Caddy) | < 2 ms |
| Derivative serve (cache miss, generate) | < 200 ms for ≤ 10 MP source |
| API GET single resource | < 10 ms |
| API list (30 items, paginated) | < 25 ms |
| Login | < 150 ms (bcrypt/argon2 dominant) |
| Upload (5 MB JPEG, validation only) | < 500 ms |
| Search (10k images, cache miss) | < 100 ms |

Targets are **measured, not aspirational.** The benchmark suite in `tests/Performance/` asserts these; a regression > 20% fails the nightly run.

### Single-server capacity

With the above numbers and the response cache hit rate at ~80% for public gallery traffic, a 4-core single server handles:

- ~2,000 req/s on cached gallery pages.
- ~200 req/s on cache-miss gallery pages.
- ~500 req/s on API reads.
- ~20 uploads/s (CPU-bound on libvips).

Enough for low-six-figure monthly visitors on a commodity server.

### Scaling horizontally

When one server isn't enough, the architecture scales horizontally with these components swapped from local-disk or in-process to shared:

| Component | Local (default) | Scaled-out |
|---|---|---|
| Session store | DB table | Redis |
| Response cache | APCu + local Redis | Shared Redis |
| Queue | DB table (Doctrine transport) | Redis / RabbitMQ |
| Derivative storage | Local disk | S3-compatible bucket |
| Originals storage | Local disk | S3-compatible bucket |
| Search index | Local MySQL / Postgres | Meilisearch cluster |
| Lock store (for derivative stampede) | flock | Redis SETNX |

Nothing else needs to change. Two FrankenPHP instances behind a load balancer serve the same DB + Redis + S3 + search transparently.

### Load balancer configuration

- **Layer 7** (HTTP-aware) preferred — HAProxy, Caddy as LB, or a cloud LB.
- **Session stickiness not required** — sessions are in shared storage. Stickiness is fine, not required.
- **Health checks** hit `/readyz`; remove failing instance from rotation within 10 s.
- **Graceful drain** — LB respects `Connection: close` on shutdown; workers drain before exit.

### Session pinning vs. shared session

Two options:

**Pinned (sticky sessions):**
- LB routes a given user's subsequent requests to the same backend.
- Simpler; a worker's in-process cache is more effective.
- Failure of a backend drops its users' sessions unless storage is still shared.

**Shared (default):**
- Any worker serves any request.
- Requires Redis / DB session storage.
- Slightly higher per-request latency (fetch session from Redis vs. local).

Recommendation: shared. The latency cost (~0.5 ms) is negligible vs. the operational simplicity of any-backend-handles-any-request.

### DB scaling

**Vertical first.** A well-indexed Postgres or MySQL on modern hardware handles tens of millions of images without complaint.

**Read replicas** — when a write-heavy workload saturates the primary:

```php
// Readonly accessor hits a replica; writes always go to primary
$albums = $this->db->readonly()->fetchAll('SELECT ...');
$this->db->execute('INSERT ...');   // primary
```

Opt-in per-repository method; stale-reads acceptable for anonymous gallery listings, unacceptable for a user reading-after-write their own data. Architecture tests flag any `readonly()` call inside a transaction (wrong primitive).

**Sharding** — not planned. A gallery that needs horizontal DB sharding has crossed a threshold this project isn't designed for.

### Queue scaling

Per-queue worker count is the knob. Each queue has a `systemd` unit (`piwigo-worker@images`, `piwigo-worker@mail`, etc.); scaling up is `systemctl start piwigo-worker@images-2 piwigo-worker@images-3 …`.

Auto-scaling: a Prometheus alert fires at "queue depth > 100 for 5 min"; an ops operator adds more workers. More automation (K8s HPA against queue depth) is possible but not scope for v1.

### Search scaling

- **MySQL/Postgres fulltext:** scales with the DB.
- **Meilisearch:** up to ~10M docs on a single instance; cluster mode for larger.

The `SearchEngine` interface makes the swap transparent; no domain code changes.

### CDN for derivatives

Derivative URLs are content-addressed (URL includes a hash of params), so they're `Cache-Control: public, max-age=31536000, immutable` — ideal for any CDN:

- **Cloudflare / Fastly / Bunny.net / CloudFront** all cache by URL + Vary.
- CDN hit serves the derivative without the origin seeing the request at all.
- CDN miss proxies to origin; origin serves from local cache or generates.

For installs with a CDN, the origin is almost never bothered by derivative traffic — it's just responsible for generating new derivatives and serving the HTML that references them.

### HTTP/3 benefits

FrankenPHP's embedded Caddy speaks HTTP/3 over QUIC. For a gallery page with 50 thumbnails:

- HTTP/1.1: serialized requests, connection-per-origin limit, ~2–3 s on a mobile network.
- HTTP/2: multiplexed over one TCP connection, ~800 ms.
- HTTP/3: multiplexed over QUIC, no head-of-line blocking, ~500 ms.

Real gains on mobile networks with packet loss.

### Image delivery optimizations

- **Format negotiation** (§5): AVIF for modern browsers (~50% smaller than JPEG at equivalent quality), WebP for most, JPEG fallback.
- **Responsive images** via `<picture>` + `srcset` + `sizes`: browser chooses the smallest derivative that fits the viewport.
- **Lazy loading** via `loading="lazy"`: below-the-fold thumbnails don't block initial render.
- **Preload hints** on the picture page: `<link rel="preload">` the large derivative while the page HTML renders.

### SLIs and SLOs

Service level indicators the project measures:

| SLI | Definition |
|---|---|
| **Availability** | `1 - (5xx responses / total responses)` over 30-day window |
| **Latency** | p95 response time on `/` and `/albums/*` |
| **Derivative freshness** | p95 time from upload → first derivative available |
| **Queue lag** | p95 time from message enqueued → handled |
| **Error rate** | 5xx per minute |

Example SLOs a deployment might adopt:

| SLO | Target | Budget |
|---|---|---|
| Availability | 99.5% / 30 d | ~3.6 h of 5xx allowed / month |
| p95 gallery latency | < 500 ms | 5% slow tolerated |
| p95 queue lag | < 60 s | 5% stale tolerated |
| Error rate | < 0.1% | 1 in 1000 requests may 5xx |

Burn-rate alerts (2% of monthly budget in 1 h → page; 10% in 6 h → warn) catch trouble early.

### Capacity planning

A rough guide for "how much server":

| Images | Users (monthly unique) | Baseline | Recommended |
|---|---|---|---|
| < 10k | < 1k | 1 vCPU / 2 GB / SSD | 2 vCPU / 4 GB |
| < 100k | < 10k | 2 vCPU / 4 GB | 4 vCPU / 8 GB |
| < 1M | < 100k | 4 vCPU / 8 GB | 8 vCPU / 16 GB + CDN |
| < 10M | < 1M | 8 vCPU / 16 GB + CDN | 2× instance + shared Redis + S3 |
| ≥ 10M | ≥ 1M | 2× instance + CDN | 4× instance + Meilisearch cluster |

Memory scales with `num_threads` (FrankenPHP workers) × ~50 MB baseline per worker. Add libvips cache (~256 MB per derivative worker). Plan for opcache (~256 MB) + JIT buffer (~128 MB) on top.

### Benchmarking methodology

`tests/Performance/` uses `k6` or a custom harness:

- Seed DB with a reproducible fixture (1k / 10k / 100k images).
- Run each scenario 10× for 60 s each.
- Record p50 / p95 / p99.
- Results posted to a dashboard; regressions tracked over time.

Benchmarks run nightly on a dedicated bench host to avoid noise from shared CI runners.

### Common bottlenecks (known unknowns)

Things that historically bite gallery apps and have explicit mitigations:

| Bottleneck | Mitigation |
|---|---|
| N+1 queries on album listings | QueryBuilder + `IN (:ids)` preload pattern; `X-Query-Count` header in dev flags regressions |
| Derivative thundering herd | Lock-based `withLock()` in `DerivativeStorage` |
| Permission check on every image in a listing | Batch-resolve via `PermissionService::allowedAlbumIdsFor($user)` once per request |
| Full-table scan on `images` when sorting by `taken_at` | Composite index `(min_level, taken_at DESC, id DESC)` |
| Session table bloat | Nightly `PurgeExpiredSessions` job + index on `expires_at` |
| Log volume on high-traffic endpoints | Sampling in `RequestLoggerMiddleware` for healthy 2xx; full logging for non-2xx |
| Queue backlog on derivative generation after bulk upload | Dedicated `images` queue with adjustable concurrency |

### Perf budget enforcement

Every PR runs the perf benchmarks on a subset of scenarios (cheap); nightly runs the full suite. A regression of > 20% on any scenario fails the PR; a regression of > 5% posts a CI comment for reviewer awareness.

---

## 27. Data Privacy and Compliance

A photo gallery stores personal data: who uploaded what, when, from what IP, with what metadata, and sometimes who the photos are *of*. GDPR, CCPA, and similar regimes aren't optional for public deployments.

> This section describes design to make compliance achievable. It is not legal advice.

### PII inventory

Exact list of personally identifiable data the app stores, to make consent and export/delete workflows mechanical:

| Data | Where | Purpose | Retention default |
|---|---|---|---|
| Username + email | `users` | Identity, notifications | Until account deletion |
| Password hash | `users.password_hash` | Authentication | Until account deletion |
| IP address (hashed) | `sessions.ip_hash`, `audit_log.actor_ip`, `comments.ip_hash` | Security, rate limiting | 90 days (sessions), per-install (audit), 90 days (comments) |
| User-agent | `sessions.user_agent` | Security diagnostics | Same as session |
| Last-login timestamp | `users.last_login_at` | UX, stale-account detection | Account lifetime |
| Photos (uploaded by user) | Filesystem / S3 | Primary app function | Until account deletion / explicit image delete |
| EXIF metadata (incl. GPS) | `images.exif`, `images.gps_*` | Display, search, organization | Bound to image lifetime |
| Comments | `comments` | App function | Until account/image deletion |
| Search history | `search_log` (if enabled) | Analytics | Configurable; off by default |
| Audit events | `audit_log` | Security, compliance | Configurable; default 365 days |
| Webhook delivery records | `webhook_deliveries` | Debugging | 30 days |

Documented in `docs/privacy/pii-inventory.md` and kept in sync with schema changes via a CI check.

### Retention policies

Configurable, with sane defaults:

```
# .env
RETENTION_SESSIONS_DAYS=90
RETENTION_COMMENT_IP_DAYS=90
RETENTION_AUDIT_LOG_DAYS=365
RETENTION_WEBHOOK_DELIVERIES_DAYS=30
RETENTION_SEARCH_LOG_DAYS=30
RETENTION_DELETED_USER_SOFT_DELETE_DAYS=30       # before hard-delete
```

Scheduled jobs enforce retention:

- `PurgeExpiredSessionsMessage` (daily) — delete expired sessions.
- `RedactCommentIpsMessage` (daily) — clear `comments.ip_hash` on rows older than retention.
- `PruneAuditLogMessage` (weekly) — purge audit rows older than retention.
- `PurgeWebhookDeliveriesMessage` (daily) — remove delivery records older than retention.
- `PurgeSoftDeletedUsersMessage` (daily) — hard-delete users whose `deleted_at` is older than the soft-delete retention.

### Data subject rights (DSR)

Three core rights, each with a workflow:

**Export (right of access / portability):**

```
POST /api/v1/me/export              # self-service
POST /api/v1/admin/users/{id}/export # admin on behalf of user
```

Enqueues a `UserDataExportMessage`; handler produces a ZIP:

```
export-alice-20260501.zip
├── README.txt                    # explains what's inside
├── profile.json                  # user row (minus password hash)
├── sessions.json
├── api_tokens.json               # names + scopes + created_at (not the tokens)
├── audit_events.json             # events where user was actor
├── comments.json                 # comments authored
├── uploaded_photos/
│   ├── manifest.json             # image metadata (EXIF, tags, albums)
│   └── {uuid}.{ext}              # original files
├── photos_you_appear_in.json     # tagged/in-album, requires admin processing
└── consent_log.json              # what the user consented to and when
```

Generated async; user notified by email when ready. Signed download URL expires in 7 days.

**Rectification:**

Users can edit their own profile via `/account`. Corrections to EXIF on their uploads via the batch manager.

**Erasure ("right to be forgotten"):**

```
DELETE /api/v1/me                                     # self-service (confirm via email)
DELETE /api/v1/admin/users/{id}  Header: X-Erase: true   # admin
```

The erasure workflow:

1. `UserDeletingEvent` dispatched; plugins can add side-effects (unsubscribe from external services, revoke integrations).
2. User's uploaded photos: deleted by default, or reassigned to "[deleted user]" via `UserDeletion::reassign($to)` option.
3. Comments: body preserved by default (deleting others' replies-in-context is often wrong) but author identity replaced with "[deleted]". Configurable per-install.
4. Audit log: retained — it's a security/compliance record. Actor entries show `[deleted:42]`.
5. User row soft-deleted (`deleted_at` timestamp).
6. After `RETENTION_DELETED_USER_SOFT_DELETE_DAYS`, hard-deleted; cascade FKs clean up remaining rows.
7. Confirmation email sent (via a one-time-use address the user supplies at erasure — their account email is gone).

Edge cases documented:

- If the user is the sole admin: erasure requires naming a successor first.
- If the user has pending webhook subscriptions: they're revoked immediately.
- If there are comments on the user's photos by others: configurable (delete with photo, or reattach to "[deleted user]").

### Anonymization vs. deletion

Where data can't be fully deleted without breaking referential integrity or compliance-evidence obligations (audit log), we **anonymize**:

- `actor_id` → NULL, `actor_ip` → NULL on audit rows older than retention.
- Comment `author_name` → "[deleted]", `author_email` → NULL, `user_id` → NULL.

Separate from retention purge: anonymization reduces re-identification risk while preserving counts/patterns useful for operations.

### Cookie policy

- **Strictly necessary** cookies (session, CSRF): always set, no consent needed (required for functionality).
- **Functional** cookies (theme, locale preference): set on user action; clearly explained in the privacy policy.
- **Analytics / tracking** cookies: **none by default**. The app ships without any. Plugins that add tracking must register their cookie set with the `CookieRegistry` and surface it in the consent UI.

Consent banner (shown to unauthenticated users in EEA deployments if analytics plugins are enabled):

```
We use cookies.  Required ✓   Functional [Allow] [Deny]   Analytics [Allow] [Deny]
```

Choices stored in a cookie; respected across subsequent page loads.

### Privacy policy template

`docs/privacy/policy-template.md` is a starting point site owners customize. Covers:

- What's collected (from PII inventory above).
- Legal basis for each (contract, legitimate interest, consent).
- Who it's shared with (hopefully nobody outside the install).
- Retention periods (from config).
- User rights + how to exercise them.
- Contact info for the data controller.

Not a generated legal document — operators adapt it to their jurisdiction.

### Processor agreements (DPAs)

If the deployment uses third-party processors (S3 provider, email provider, CDN, error tracker), operators need DPAs with each. The app surfaces the list in admin: `Admin → Privacy → External processors` — lists each integration, its data flow, and a pointer to the vendor's DPA template.

### Audit log of data access

A stricter deployment mode (`PRIVACY_AUDIT_MODE=strict` env flag) logs every admin read of user data to the audit log:

- Admin viewed user profile → `admin.user.viewed`.
- Admin exported user data → `admin.user.exported`.
- Admin accessed comment IPs → `admin.comment.ip_accessed`.

Users can request their access log as part of export.

### Encryption at rest

- **Originals and derivatives:** disk encryption is an operator concern (dm-crypt / LUKS / cloud-provider KMS). The app doesn't re-encrypt files at the application layer — performance cost too high for the marginal threat-model improvement over FDE.
- **DB:** TLS in transit always; at-rest encryption per deployment.
- **Backup volumes:** encryption strongly recommended; operators responsible.
- **Field-level encryption:** available for specific columns via a plugin hook; not core.

### Data minimization defaults

Where in doubt, **collect less**:

- IP addresses stored as salted hashes, not plaintext.
- User-agent truncated to 255 chars.
- Session payload is a compact PHP-serialized blob, not a JSON tree.
- EXIF GPS preserved in uploads by default but a per-install flag can strip it for privacy-conscious deployments (particularly important for public-facing galleries).

### Geo-filtering uploads (optional)

Installs that want to help users avoid geotagging leaks can enable auto-strip GPS on upload:

```
PRIVACY_STRIP_GPS_ON_UPLOAD=true        # removes gps_lat/gps_lng from EXIF before storing
```

Warning shown in upload UI: "GPS coordinates will be removed for privacy."

### Takedown workflow

For content that must be removed (DMCA, defamation, CSAM reporting):

```
php bin/gallery takedown --image={id} --reason="DMCA notice 2026-04-20" --notify-uploader
```

- Image marked `deleted_at` (not purged immediately).
- Hash (sha256 + pHash) added to a takedown blocklist; re-uploading the same file is blocked at validation.
- Uploader notified (configurable).
- Admin gets a receipt ID they can provide to the takedown requester.

### Compliance flags

- **GDPR-strict mode:** shorter default retention, consent banner default-on, strip-GPS default-on, admin-read audit default-on.
- **US operator mode:** consent banner off, 7-year audit retention for some events (configurable).

Not a substitute for legal review; a starting point.

---

## 28. Media Types Beyond Photos

v1 is photo-first. This section states the **scope** for other media types and the design path if they're added.

### v1 scope

**In scope:**

- Still images: JPEG, PNG, WebP, AVIF, HEIC / HEIF, GIF (static + animated), TIFF (first page), JPEG XL (if libvips build supports it).
- RAW file previews: the embedded JPEG preview inside DNG / NEF / CR2 / ARW / RAF is extracted and treated as the image; the RAW file itself is preserved but not rendered.

**Explicitly out of v1:**

- Video (any format).
- Audio.
- PDFs as gallery content (PDFs-as-documents is a different app).
- 360° / VR content.
- 3D / glTF.

"Out of v1" doesn't mean "never" — it means no ship gate, no architecture commitments.

### The test for adding a media type

Before a media type becomes core scope:

1. A real user population requests it with weight (not one-off).
2. The domain model changes (if any) are minor — the `images` table can host it with new columns, not a new table.
3. A derivative strategy exists (renders cleanly in a gallery grid, opens in a picture-view context).
4. Storage implications are bounded (no "each video is 4 GB, plan for petabytes").
5. Maintenance cost is bounded (no dependency on half-abandoned codecs).

If those pass, design proceeds below.

### Video — design sketch (likely post-v1)

If video is added, this is how it would look:

**Storage:**

- `images` → renamed `media` (migration), with a `media_type` enum column (`image` | `video`).
- Originals and derivatives both stored in the same layout; paths and URL grammar unchanged.
- Video-specific columns: `duration_seconds`, `codec`, `has_audio`, `fps`.

**Ingest:**

- `libav` (via `php-ffmpeg/php-ffmpeg`) for probing and transcoding.
- Allowed input formats: MP4 (H.264, H.265, AV1), WebM (VP9, AV1), MOV.
- Reject non-allowlisted containers / codecs.

**Derivatives:**

- **Poster frame** — libvips renders a JPEG from a frame at 10% duration.
- **Thumbnails** — the poster frame is the thumbnail; no video thumbnails in v1.
- **Transcoded variants** — 480p / 720p / 1080p H.264 (broad compatibility) + 720p / 1080p AV1 (smaller) if client supports.
- **HLS / DASH streaming** — generated once, served as static files; Caddy does byte-range serving natively.

**Delivery:**

- `<video>` with `<source>` elements; browser picks the best it supports.
- Playback component in the picture-page view.

**Storage cost:**

- A 10 min 1080p H.264 at reasonable bitrate is ~500 MB.
- Transcoded variants ~2-4× storage of the original.
- Install-wide "max upload video duration" setting is mandatory.

**Admin UX:**

- Upload progress must show transcoding state separately from upload state.
- Transcoding is async, often minutes — jobs visible in the queue monitor.
- Failed transcodes show in admin with re-queue option.

**Permissions, search, tags, comments:** identical to images — the added media type doesn't branch the UX.

**Testing:**

- Fixture videos for each codec/container combo.
- Golden-frame tests for poster generation.
- Transcode output validated by re-probing (dimensions, codec correct).

**Non-trivial concerns:**

- Transcoding is CPU-heavy; dedicated `videos` queue with 1-2 workers, not full parallelism.
- Client bandwidth caps — adaptive bitrate (HLS) is a real requirement at scale.
- Subtitle / caption tracks (out of initial video scope; add if popular).

### RAW — design sketch (likely v1.x)

- libvips supports common RAWs via `dcraw` integration. Configurable per-install (may require a libvips rebuild with specific flags).
- Preview-JPEG extraction is fast; treated as the displayable image.
- Original RAW preserved as a separate download (`GET /api/v1/media/{id}/raw`).
- Not every install wants RAW support — flag-gated, default off.

### Audio — no plan

Piwigo has never been an audio app; v1 isn't either. Audio would require a fundamentally different UI (waveform display, seekbar, playlist) — different enough that a separate app is the right answer.

### 360° / VR — plugin territory

Spherical viewer (Marzipano, Pannellum) integration makes sense as a plugin, not core. The image-upload pipeline handles them as regular images; a plugin detects the metadata (`XMP-GPano`) and replaces the picture-view component with a spherical renderer.

### Scope creep guardrails

The temptation to grow into "a media management system" is real. Guardrails:

- New media types require an RFC-style PR with the test-for-adding checklist answered.
- Each media type increases the test matrix; maintainers commit to that ongoing cost explicitly.
- Features that favor one media type at the expense of others (e.g., auto-generated video trailers that don't apply to images) get plugin treatment, not core treatment.

---

## 29. End-to-End Workflow Walkthroughs

The per-section designs describe each layer; this section traces specific flows end-to-end. Purpose: surface coupling, make the interactions concrete, and give a new contributor a "whole picture" view.

Five walkthroughs:

1. User uploads 50 photos.
2. Admin changes an album's permissions.
3. Admin runs filesystem sync on a 10,000-photo directory.
4. User searches "sunset vacation".
5. Plugin dispatches a webhook to an external service.

Each walkthrough names the files/classes involved — a breadcrumb trail through the codebase.

### Walkthrough 1: Upload 50 photos

**Client:** user on `/upload`, drops 50 files into the drop zone.

1. `upload.js` queues 50 uploads; starts 3 in parallel (configurable).
2. Per file, client issues `POST /api/v1/uploads` (tus init):
   - Middleware chain (§3): `ErrorHandler` → `Logger` → `Cors` → `SecurityHeaders` → `Session` → `Auth` (loads User) → `Csrf` → `Locale` → `Router`.
   - `AuthMiddleware` populates `$request->user`.
   - Router dispatches to `Api\V1\UploadController::init`.
3. Controller validates the user has `can_upload` on the target album (via `PermissionService`).
4. Controller creates a tus upload record in `var/uploads/tmp/` and returns a `Location` header with the upload URL.
5. Client PATCHes chunks to that URL; server appends bytes.
6. After final chunk: `UploadController::complete` triggers the validation pipeline (§5 + §24):
   - `VipsProcessor::probe()` → magic bytes + dimensions.
   - `ExifExtractor::extract()` → EXIF/XMP/IPTC.
   - `PerceptualHasher::hash()` → pHash.
   - `ImageDeduplicator::check()` → sha256 + pHash lookup; if duplicate, configurable behavior.
   - `ImageUploadingEvent` dispatched → plugins may veto.
   - `QuotaService::debit()` inside DB transaction; fails with 413 if exceeded.
   - `StorageBackend::put()` → write to `storage/originals/2026/05/{uuid}.jpg`.
   - `ImageRepository::save()` → INSERT `images` row + `image_albums` + `image_tags`.
   - Transaction commits.
   - `ImageCreatedEvent` dispatched → `QueueReindexOnImageChange` listener enqueues `ReindexSearchMessage`.
   - `GenerateDerivativesMessage` enqueued to the `images` queue for all standard presets.
   - `RefreshAlbumCounts` listener enqueues `UpdateImageCountersMessage`.
   - Cache invalidation listeners bump `content_version` for the album.
7. Response: 201 with the new `PhotoResponse` DTO.
8. Client updates UI: green check on that file's row.
9. Meanwhile, the `images` queue worker consumes `GenerateDerivativesMessage`:
   - `DerivativeService::ensureGenerated()` for each preset.
   - libvips runs, derivative bytes written to `var/derivatives/{shard}/{uuid}/{preset}.{hash}.{ext}`.
   - `DerivativeGeneratedEvent` dispatched.

**What's visible in logs:**

```
[upload] 201 /api/v1/uploads/init user=42 size=5242880 request_id=...
[upload] 200 /api/v1/uploads/{id}/complete image_id=1234 request_id=...
[queue] GenerateDerivativesMessage image=1234 presets=[thumbnail,small,medium,large,xlarge] queued
[queue] GenerateDerivativesMessage image=1234 done elapsed=1840ms
```

**Touched files:**

- `src/Controller/Api/V1/UploadController.php`
- `src/Image/VipsProcessor.php`, `ExifExtractor.php`, `PerceptualHasher.php`
- `src/Domain/Image/ImageRepository.php`
- `src/Storage/StorageBackend.php` (implementation)
- `src/Event/Image/ImageCreatedEvent.php`
- `src/Job/GenerateDerivativesHandler.php`

### Walkthrough 2: Admin changes album permissions

**Actor:** admin on `/admin/albums/17/permissions`.

1. Admin toggles "Public" off and adds user `alice` with `can_view`.
2. Client submits the form (HTMX `hx-patch` to `/admin/albums/17`).
3. Middleware chain runs; `AdminAuthMiddleware` verifies admin role.
4. `Admin\AlbumController::updatePermissions` receives the request:
   - Builds `AlbumPermissionUpdate` DTO from form data.
   - Calls `AlbumService::updatePermissions($album, $update, $admin)`.
5. `AlbumService`:
   - Opens DB transaction.
   - Dispatches `AlbumPermissionsChangingEvent` (mutable, cancellable).
   - Plugins may adjust (e.g., a plugin enforces "Moderator group must always have view").
   - Applies changes to `album_user_access` / `album_group_access`.
   - Updates `albums.is_public`, `albums.min_level`.
   - Inserts `audit_log` row (`album.permissions_changed`, details = diff).
   - Commits.
   - Dispatches `AlbumPermissionsChangedEvent`.
6. `AlbumPermissionsChangedEvent` listeners:
   - `InvalidateAlbumCaches` → drops `album.{id}` cache keys.
   - `InvalidatePermissionCaches` → drops per-user memoized permission resolves that touched this album.
   - `BumpContentVersion` → invalidates the response cache for that album and its ancestors.
   - `NotifyAffectedUsers` → optionally emails users who just gained/lost access.
7. HTMX swaps the updated permission editor fragment into the page — no full reload.

**What changes downstream:**

- Alice's next gallery visit: she sees the album in her listing.
- An anonymous visitor's cached listing: invalidated by the `content_version` bump; next fetch rebuilds and excludes the now-private album.
- Audit-log viewer shows the change with before/after diff.

**Touched files:**

- `src/Controller/Admin/AlbumController.php`
- `src/Domain/Album/AlbumService.php`
- `src/Event/Album/AlbumPermissionsChangedEvent.php`
- `src/Event/Listener/InvalidateAlbumCaches.php`
- `src/Domain/Permission/PermissionService.php`

### Walkthrough 3: Filesystem sync imports 10,000 photos

**Actor:** admin runs `php bin/gallery sync /photos/2024 --mirror-tree --progress` on a server shell.

1. CLI entry point (`bin/gallery`) boots a minimal container (no HTTP middleware needed).
2. `SyncCommand::execute`:
   - Validates the path exists and is readable.
   - Resolves the root album (creates one if `--mirror-tree` and missing).
   - Loads the album's current image set.
3. Walk phase (one pass):
   - `DirectoryScanner::scan()` yields `(path, mtime, size)` tuples.
   - For each tuple, classifier picks: new | update | skip | moved.
4. Import phase (parallel workers, default = CPU count):
   - Each worker pulls from a thread-safe queue of "new" files.
   - Per file, runs the **same validation pipeline as uploads** (§24).
   - `UploadedFile`s come from the filesystem rather than HTTP, but the downstream is identical.
   - Writes progress every 100 files: `[3420/10000] 34.2% — 18 min remaining`.
5. If `--mirror-tree`:
   - New directories → new `AlbumCreatedEvent`s.
   - Album membership set via `image_albums`.
6. If `--tag-from-path` (not in this walkthrough), tags inferred.
7. Cleanup phase (if `--delete-missing`):
   - Files in DB but not on disk → delete rows.
8. Checkpoint written every 100 files (`--resume`-able).
9. End-of-run summary: created / updated / skipped / errored counts; log path.

**Side effects:**

- `GenerateDerivativesMessage` enqueued for every imported file → derivative queue works through them for minutes after sync completes.
- `ReindexSearchMessage` enqueued for each.
- `AlbumCreatedEvent`s fire, bumping caches.
- Audit log: one `sync.completed` row summarizing the run.

**Failure modes handled:**

- File fails validation (corrupt / wrong format): logged, sync continues.
- DB error mid-sync: checkpoint written, retry instructs operator to `--resume`.
- Disk full: sync aborts with actionable error; partial import is consistent (transactions).
- Quota exceeded: per-file 413-equivalent logged; sync continues for subsequent files attributable to other uploaders if `--per-user-quota` is honored.

**Touched files:**

- `src/Cli/Command/SyncCommand.php`
- `src/Sync/DirectoryScanner.php`, `FileClassifier.php`, `SyncWorkerPool.php`
- Same validation pipeline as upload

### Walkthrough 4: User searches "sunset vacation"

**Actor:** logged-in user types in the search box; client submits `/search?q=sunset+vacation`.

1. Middleware chain runs.
2. `SearchController::index`:
   - Parses `q` via `SearchQueryParser` → `SearchQuery(term: "sunset vacation")`.
   - Dispatches `SearchBuildingEvent` (plugins may rewrite the query).
   - Dispatches `SearchQueryBuiltEvent` (plugins may inject filters — e.g., "hide NSFW tag for this user").
   - Calls `SearchEngine::search($query)`.
3. Engine (e.g. Postgres `tsvector`):
   - Runs `WHERE search_doc @@ plainto_tsquery('english', 'sunset vacation') AND min_level <= :user_level ORDER BY ts_rank_cd(...) DESC LIMIT 30`.
   - Returns `SearchResult` with hits + rough total.
4. Controller:
   - Runs permission post-filter (viewer's album access).
   - Dispatches `SearchCompletedEvent` with result + elapsed ms.
   - Passes `SearchViewData` to the template.
5. Template renders; includes facet counts in the sidebar.

**Caching:**

- Search pages are per-user (permission-sensitive); response cache bypassed.
- Facet counts for anonymous users *can* be cached (keyed by content_version).

**Logs:**

```
[search] query="sunset vacation" user=42 hits=18 elapsed=23ms request_id=...
```

**Touched files:**

- `src/Controller/SearchController.php`
- `src/Domain/Search/SearchQueryParser.php`
- `src/Search/{MySqlFullTextSearchEngine,PostgresTsvectorSearchEngine,MeilisearchEngine}.php`
- `src/Event/Search/SearchCompletedEvent.php`

### Walkthrough 5: Plugin dispatches a webhook

**Context:** user has configured a webhook for `image.uploaded` events; plugin translates the event into an outbound POST.

1. An image is uploaded (see Walkthrough 1).
2. `ImageCreatedEvent` fires.
3. `WebhookSubscriptionListener` (core, not a plugin) inspects active webhook subscriptions:
   - Looks up rows in `webhook_subscriptions` where `event = 'image.uploaded'`.
   - For each, dispatches a `DeliverWebhookMessage` to the `webhooks` queue.
4. `webhooks` queue worker processes `DeliverWebhookMessage`:
   - `WebhookDeliverer::deliver()`:
     - Builds payload: `{ event, delivered_at, data: {...} }`.
     - Computes HMAC-SHA256 signature using the subscription's secret.
     - Sends via Guzzle with timeouts (5 s connect, 30 s total).
     - On 2xx: `WebhookDeliveredEvent` fires; marks delivery successful in `webhook_deliveries` table.
     - On 4xx (non-retryable) / 5xx / network (retryable): `WebhookFailedEvent` fires; retry scheduled with exponential backoff.
     - After N retries exhausted: `WebhookDeadLetteredEvent`; entry appears in admin's DLQ view.
5. Admin UI: webhook subscription page shows recent deliveries, success/failure counts, last attempt time, retry button.

**Security:**

- Webhook target URL validated by `SsrfGuard` (no private IPs, no non-HTTP schemes).
- Payload signed; receiver verifies by recomputing HMAC.
- Signature includes a timestamp to prevent replay; receivers reject deliveries older than ~5 minutes.

**Observability:**

- Every delivery attempt logged.
- Metrics: `webhook_deliveries_total{status="ok|failed|dead"}`.
- DLQ growth triggers an admin alert.

**Touched files:**

- `src/Webhook/WebhookSubscriptionListener.php`
- `src/Job/DeliverWebhookHandler.php`
- `src/Webhook/WebhookDeliverer.php`
- `src/Security/SsrfGuard.php`
- `src/Controller/Admin/WebhookController.php`

### What the walkthroughs reveal

Reading these in sequence surfaces the architectural glue:

- **Events are the spine.** Almost every cross-cutting concern (cache invalidation, search indexing, webhooks, audit log) is a listener. The core of a feature is a few hundred lines; the rest is in listeners that can evolve independently.
- **Queues decouple hot paths from slow work.** Upload responds in 100 ms; the derivative generation that actually takes 1-2 s runs out of band.
- **Permission checks batch-resolve.** A list of 30 images does one permission query, not 30.
- **The same validation pipeline serves three entry points** (HTTP upload, API upload, CLI sync). Shared tests cover them all.
- **Audit and observability are never side-projects** — they're part of the request flow, never swept to the side.

These walkthroughs are **executable documentation**. A contributor reading them should be able to trace any request through the codebase and predict the touched files.

---

## 30. What Goes Away

Removed wholesale. Each row has a specific reason listed; nothing is dropped for ideology.

| Current | Replacement | Why dropped |
|---|---|---|
| Apache + PHP-FPM | FrankenPHP (embeds Caddy) | External web server requires per-deploy config synchronization; FrankenPHP is a single-binary deploy with HTTP/3, TLS, static serving, and worker mode out of the box |
| Smarty 5 | Latte | Latte's context-aware escaping eliminates a class of XSS bugs that require constant vigilance in Smarty |
| `functions_*.php` procedural files | Service classes, injected via PHP-DI | Procedural files can't be unit-tested in isolation; DI-wired services can, and they make dependencies visible at the class signature |
| `$conf`, `$user`, `$page`, `$template`, `$lang`, `$header_notes`, `$header_items` globals | Constructor-injected services + PSR-7 request attributes | Global mutation from within includes makes request flow untraceable; explicit dependencies make it auditable |
| `pwg_query()` + `addslashes()` | PDO named parameters | String-concatenated SQL resists static analysis; named parameters don't |
| `trigger_change()` / `trigger_notify()` string hooks | PSR-14 typed events (`league/event`) | String-keyed dispatch loses IDE support, refactor safety, and type checking |
| `exit()` / `die()` for flow control | Exceptions caught by PSR-15 error middleware | Worker runtimes can't recycle the interpreter when code terminates the process; flow control must be in-process |
| `define()` at request time | `readonly` config classes + constructor injection | Constants persist across requests in worker mode, poisoning later requests with stale state |
| 4-way image backend (GD / Imagick / `ext/imagick` / VIPS) | libvips only | Every operation had to be implemented four times with subtle rendering differences; one backend is simpler, faster, and less buggy |
| GDThumb plugin | Core derivative system | Thumbnail generation is a core concern of a photo gallery, not a plugin |
| Multiple entry points (`index.php`, `picture.php`, `admin.php`, `ws.php`, `i.php`, `action.php`, `identification.php`) | Single front controller (`public/index.php`) | Seven bootstrap paths mean seven places to add auth, seven places to forget error handling |
| `.htaccess` rewrite rules | Caddy native routing | Apache-specific; drops when the server changes |
| Custom script/CSS combiner (`FileCombiner`) | Vite build pipeline (already in place) | Modern JS tooling does this better, with ESM, HMR, tree-shaking, and source maps |
| `PwgTemplateAdapter` (Smarty wrapper) | Latte `Engine` directly | Wrapper exists only because Smarty's API is inconvenient; Latte's API doesn't need one |
| `config/database.inc.php` | `.env` + typed `DatabaseConfig` | PHP files for config invite code execution at config load; env vars are strictly data |
| `install.php` / `upgrade.php` web wizards | CLI-first (`php piwigo migrate`, `php piwigo admin:create`) | Web installers historically accumulate their own security issues; CLI is simpler and automatable |
| Smarty `{combine_script}` / `{combine_css}` tags | Vite manifest lookups via `{asset}` Latte tag | Combines compilation-at-request-time concerns with build-time concerns |
| Per-plugin `functions_plugin.php` auto-loaded globs | Composer `type: gallery-plugin` + `PluginInterface::register()` | Explicit entry points beat filesystem-scan magic |
| MagickTransforms / GD chain classes | Small libvips operation helpers in `src/Image/` | Abstraction for its own sake; libvips's fluent API is clearer than a multi-class ceremony |
| `check_status()`, `is_admin()`, `is_webmaster()` free functions | `AccessLevel` enum methods + `AuthorizationMiddleware` | Free functions with implicit global access replaced with explicit enum comparisons |
| Persistent language files built per-request from `.lang` strings | Compiled ICU catalogs per locale, loaded once per worker | Request-time building adds latency; loading a compiled catalog once is instant thereafter |
| `history.php` page-views counter running on every request | Optional, async-dispatched event + a `page_views` table | Synchronous counter insertion on every gallery request is a known hot-path cost |
| In-request `pwg_log()` writing to DB synchronously | Monolog with async DB handler or file handler | Database-synchronous logging inside request scope is a latency tax |
| Legacy email templating (PHP-string concat) | Symfony Mailer + Latte email templates | Safer, testable, produces both HTML and text alternatives automatically |
| Bundled jQuery and legacy JS plugins | Native JS + targeted libraries (PhotoSwipe, TomSelect, HTMX) | jQuery is no longer necessary for DOM work; shedding it lightens every page |

Every one of these is a net reduction in surface area. The rewrite is not adding features here — it's deleting thousands of lines of legacy code that the new architecture makes unnecessary.

---

## 31. What Carries Over (Conceptually)

Nothing is preserved for compatibility. What carries over is the **domain model and feature surface** — what a photo gallery *does* — reimplemented from scratch on a clean schema and a new API. This section is a design checkpoint: "of the things Piwigo does, which of them still belong in the rewrite?"

| Concept | Carries over as | Does **not** carry over |
|---|---|---|
| **Database engines supported** | MySQL 9.7 LTS, MariaDB 11.8 LTS, PostgreSQL 18 (via PDO adapters; older minors accepted on a best-effort basis down to MySQL 8.4 / MariaDB 11.4 / Postgres 16) | Table names, column names, foreign-key layout, charset choices, legacy indexes, `piwigo_` prefix, SQLite support (historically flaky under Piwigo, dropped for simplicity) |
| **Albums** | Hierarchical album tree with permissions + inheritance | Tree representation (nested sets vs. adjacency list vs. closure table — redesigned), `category_id` as a surrogate for "album" — the new model calls them albums everywhere |
| **Photos / images** | Image entity with dimensions, EXIF, filesize, author, description, rating | Legacy `path` format (now a UUID-based content-addressed layout), `high` field distinction (originals are always originals), legacy md5 vs. sha1 handling (SHA-256 everywhere) |
| **Tags** | Many-to-many tags on images, tag-based browsing | Tag URL grammar, tag cloud rendering specifics, tag permission model (kept conceptually but redesigned) |
| **Users & groups** | Users with access levels, groups as permission bundles | Legacy `pwg_user_cache`/`user_cache_categories` denormalizations (computed on the fly or cached explicitly, not via ad-hoc tables) |
| **Permission model** | Album-level ACL (users and groups) plus global access levels (guest/registered/contributor/moderator/admin/webmaster) | Exact representation; `user_access` / `group_access` tables get redesigned around clear parent-child inheritance semantics |
| **Comments** | Per-image comments, moderation queue, pagination | Legacy schema, anti-spam integration (new hooks, same concept) |
| **Search** | Text search on title/description/tags; tag combinators (AND/OR/NOT); date ranges; author/camera filters | Legacy search URL grammar; search-result caching layer (re-engineered) |
| **Calendar / date browsing** | Browse by date taken, date added, or both | Legacy URL shape; rendering specifics |
| **RSS / Atom feeds** | Feeds for recent uploads, comments, etc. | Legacy feed URLs and exact payload shape |
| **Derivative system** | On-demand generation with deterministic URLs + disk cache (served under `/media/`) | Legacy `/i/…` URL grammar, existing cached-derivative path layout, the `standard derivatives` naming (`thumb`, `2small`, `xsmall`, etc. become `thumbnail`/`small`/`medium`/`large`/`xlarge`) |
| **Web-service API** | JSON HTTP API for third-party clients exists, versioned with a clear deprecation policy | `ws.php` method names, `pwg.categories.getList` / `pwg.images.setInfo` style, request/response shape, error codes — **the new API is its own surface**, under `/api/v1/` |
| **Themes** | Child-theme override mechanism (via Latte `{extends}` and `theme.json`) | Smarty `.tpl` files, `{combine_*}`, `{footer_script}` — existing themes do not load |
| **Plugins** | Event/hook extension points, admin UI hooks, asset injection | `trigger_change`/`trigger_notify` string keys, `functions_plugins.php`, existing plugin packages — plugins must be rewritten |
| **Image pipeline** | libvips-backed resize/crop/rotate/sharpen/watermark | `pwg_image` class, GD/Imagick backends, existing derivative params format |
| **Filesystem sync** | CLI command to walk a directory tree and reconcile with DB | Synchronous web-based sync at `/admin/sync` (now CLI-only, with progress output) |
| **Upload** | Web upload + API upload + bulk CLI | Legacy chunked-upload protocol (replaced with RFC 7233 resumable uploads) |
| **Localization** | Multi-locale UI, translator workflow via Transifex/similar | Legacy `.lang` arrays (replaced by ICU message catalogs) |
| **Email** | Notifications on registration, comments, admin events | Legacy template format (replaced by Latte email templates, plain-text alternatives) |
| **Caching** | Page-level response cache for anonymous gallery views, derivative cache | Legacy `_data/template-c` Smarty compile cache (now `var/cache/templates`, same idea, Latte-native) |
| **Logging** | Structured application log, access log, security log | Legacy `pwg_log()` DB-synchronous writes |

### What outright won't exist (even conceptually)

Some features of Piwigo 14 won't be in v1, with no immediate plan:

- **LDAP auth.** The plugin API has hooks for it; anyone needing LDAP can write a plugin. Not core.
- **In-browser theme/plugin installation from a registry.** Everything is installed via Composer or by unpacking into `themes/` / `plugins/`. Safer, auditable, version-pinned.
- **WYSIWYG HTML editor for album descriptions.** Markdown, rendered to HTML on save.
- **Multi-site / multi-gallery-per-install.** One install, one gallery. Simplifies everything.
- **In-app theme editor.** Theme authoring is a developer workflow.

These may return in later versions, but they're not gates on v1.

### Why not more compatibility?

There's a legitimate argument for preserving more — specifically, an import tool from Piwigo 14 that reads the old DB and writes to the new schema. That argument loses because:

1. **Tooling needs to exist to be tested.** Shipping a one-way import tool that no maintainer actively uses means the tool rots; users hit bugs nobody can reproduce.
2. **The import tool would need to preserve edge cases** (legacy permission combinations, orphaned rows from old bugs, charset-ambiguous text) that don't belong in the new schema.
3. **Every site that imports is a site slightly different from the schema the rewrite assumes** — subtly, in ways that surface as bugs weeks later.
4. **Users with large libraries will move them regardless of tooling.** A fresh start with re-upload gives them a clean schema; a half-imported database gives them confusion.

An unsupported example import script may exist in `contrib/` as a starting point, but it is not a product feature and does not gate releases.

### Summary

**Existing Piwigo databases are not importable.** Existing clients of `ws.php` will not work. Existing themes and plugins will not load. This is intentional — dragging the schema and API forward would re-import the constraints the rewrite exists to escape. The value carried over is in the *concept of a photo gallery* and in having thought through the sharp edges over 20 years of Piwigo's life. The new codebase inherits that wisdom without inheriting the code.

---

## 32. Repository Structure

### Top-level layout

```
piwigo-rewrite/
├── public/                           # Web root — served by Caddy
│   ├── index.php                     # Front controller (FrankenPHP entry point)
│   └── favicon.ico, robots.txt, ...
│
├── bin/
│   └── piwigo                        # CLI binary (symfony/console root)
│
├── bootstrap/
│   ├── app.php                       # Builds DI container + middleware stack (prod + dev)
│   ├── container.php                 # Compiled container artifact (prod only; built by CI)
│   └── derivative-app.php            # Thin bootstrap for the derivative worker
│
├── src/                              # Application code — no HTML, no SQL strings outside Database/
│   ├── Controller/                   # Thin HTTP handlers
│   │   ├── GalleryController.php
│   │   ├── AlbumController.php
│   │   ├── PhotoController.php
│   │   ├── AuthController.php
│   │   ├── RegisterController.php
│   │   ├── UploadController.php
│   │   ├── Admin/
│   │   │   ├── DashboardController.php
│   │   │   ├── AlbumController.php
│   │   │   ├── PhotoController.php
│   │   │   ├── UserController.php
│   │   │   ├── GroupController.php
│   │   │   ├── TagController.php
│   │   │   ├── PluginController.php
│   │   │   ├── SyncController.php
│   │   │   └── MaintenanceController.php
│   │   └── Api/
│   │       └── V1/
│   │           ├── AlbumController.php
│   │           ├── PhotoController.php
│   │           ├── AuthController.php
│   │           ├── MeController.php
│   │           ├── SearchController.php
│   │           └── TagController.php
│   │
│   ├── Domain/                       # Core domain — zero HTTP, zero PDO, zero Latte
│   │   ├── Album/
│   │   │   ├── Album.php             # readonly aggregate
│   │   │   ├── AlbumRepository.php   # interface (impl in Infrastructure/)
│   │   │   ├── AlbumTree.php         # tree-traversal service
│   │   │   ├── AlbumNotFoundException.php
│   │   │   └── Slug.php              # value object
│   │   ├── Image/
│   │   │   ├── Image.php
│   │   │   ├── ImageRepository.php
│   │   │   ├── DerivativeParams.php
│   │   │   ├── Exif.php              # typed EXIF value object
│   │   │   └── Perceptual/Hash.php
│   │   ├── User/
│   │   │   ├── User.php
│   │   │   ├── UserRepository.php
│   │   │   ├── AccessLevel.php       # enum
│   │   │   └── Registration/
│   │   ├── Tag/
│   │   ├── Comment/
│   │   ├── Search/
│   │   │   ├── SearchQuery.php       # immutable query object
│   │   │   ├── SearchService.php
│   │   │   └── Result.php
│   │   ├── Permission/
│   │   │   ├── PermissionService.php
│   │   │   └── AccessPolicy.php
│   │   └── Common/
│   │       ├── Pagination.php
│   │       ├── Sort.php
│   │       └── DateRange.php
│   │
│   ├── Http/                         # PSR-7 concerns
│   │   ├── Middleware/               # PSR-15 middleware
│   │   │   ├── ErrorHandlerMiddleware.php
│   │   │   ├── SessionMiddleware.php
│   │   │   ├── AuthMiddleware.php
│   │   │   ├── CsrfMiddleware.php
│   │   │   ├── LocaleMiddleware.php
│   │   │   ├── SecurityHeadersMiddleware.php
│   │   │   ├── CorsMiddleware.php
│   │   │   ├── RequestLoggerMiddleware.php
│   │   │   ├── MaintenanceModeMiddleware.php
│   │   │   └── TrustedProxyMiddleware.php
│   │   ├── FormObject/               # Typed request bodies
│   │   │   ├── LoginInput.php
│   │   │   ├── CreateAlbumInput.php
│   │   │   ├── ...
│   │   ├── Response/
│   │   │   ├── ProblemDetails.php    # RFC 7807 error body
│   │   │   └── ResponseFactory.php
│   │   └── UrlGenerator.php
│   │
│   ├── Event/                        # PSR-14 event classes
│   │   ├── User/
│   │   │   ├── UserAuthenticatedEvent.php
│   │   │   ├── UserCreatedEvent.php
│   │   │   └── ...
│   │   ├── Image/
│   │   ├── Album/
│   │   ├── Render/
│   │   └── Admin/
│   │
│   ├── Database/                     # Only place PDO appears
│   │   ├── Database.php              # Connection + statement cache + transaction
│   │   ├── QueryBuilder.php
│   │   ├── DatabaseAdapterInterface.php
│   │   ├── MySqlAdapter.php
│   │   ├── PostgresAdapter.php
│   │   ├── Migrator/
│   │   │   ├── Migrator.php
│   │   │   ├── MigrationFile.php
│   │   │   └── MigrationRepository.php
│   │   └── DatabaseSessionHandler.php
│   │
│   ├── Image/                        # libvips wrapper — the one place vips is called
│   │   ├── VipsProcessor.php
│   │   ├── DerivativeService.php
│   │   ├── DerivativeStorage.php     # interface
│   │   ├── LocalDiskDerivativeStorage.php
│   │   ├── S3DerivativeStorage.php
│   │   ├── Watermark.php
│   │   └── Format/
│   │       ├── ImageFormat.php       # enum: JPEG, WEBP, AVIF, PNG
│   │       └── FormatNegotiator.php
│   │
│   ├── Template/                     # Latte engine + custom extensions
│   │   ├── TemplateEngine.php
│   │   ├── ThemeLoader.php
│   │   ├── Extension/
│   │   │   ├── AssetExtension.php    # {asset} / {plugin_asset}
│   │   │   ├── LinkExtension.php     # {link} for reverse routing
│   │   │   ├── CsrfExtension.php     # {csrf}
│   │   │   ├── ImageExtension.php    # {image}
│   │   │   └── TranslationExtension.php  # {_}
│   │   └── Snapshot/                 # Snapshot-testing helpers
│   │
│   ├── Plugin/                       # Plugin loader + interfaces
│   │   ├── PluginInterface.php
│   │   ├── BasicPlugin.php           # trait providing no-op lifecycle defaults
│   │   ├── PluginLoader.php
│   │   ├── PluginRegistry.php
│   │   └── Context/
│   │       ├── InstallContext.php
│   │       └── UninstallContext.php
│   │
│   ├── Session/                      # Session service + CSRF + rate limiter
│   │   ├── SessionService.php
│   │   ├── CsrfTokenService.php
│   │   └── RateLimiter.php
│   │
│   ├── Auth/                         # Password hashing, API tokens, remember-me
│   │   ├── PasswordHasher.php
│   │   ├── AuthService.php
│   │   ├── RememberMeService.php
│   │   └── ApiTokenService.php
│   │
│   ├── Mail/                         # Symfony Mailer wrapper
│   │   ├── Mailer.php
│   │   └── Message/
│   │       ├── WelcomeEmail.php
│   │       ├── PasswordResetEmail.php
│   │       └── ...
│   │
│   ├── Log/                          # Monolog wiring
│   │   └── LoggerFactory.php
│   │
│   ├── Config/                       # Typed config classes
│   │   ├── AppConfig.php
│   │   ├── DatabaseConfig.php
│   │   ├── DerivativeConfig.php
│   │   ├── SessionConfig.php
│   │   ├── MailConfig.php
│   │   └── EnvReader.php
│   │
│   ├── Cli/                          # symfony/console commands
│   │   ├── Command/
│   │   │   ├── MigrateCommand.php
│   │   │   ├── MigrateStatusCommand.php
│   │   │   ├── MigrateRollbackCommand.php
│   │   │   ├── MigrateMakeCommand.php
│   │   │   ├── DbSeedCommand.php
│   │   │   ├── AdminCreateCommand.php
│   │   │   ├── SyncCommand.php
│   │   │   ├── DerivativesPruneCommand.php
│   │   │   ├── DerivativesFlushCommand.php
│   │   │   └── DownCommand.php / UpCommand.php
│   │   └── Application.php
│   │
│   └── Support/                      # Small utilities — sparingly
│       ├── Stringable.php
│       └── Clock.php                 # wraps time() / now() for testability
│
├── templates/
│   └── default/                      # Default (parent) theme's Latte templates
│       ├── layout.latte
│       ├── index.latte
│       ├── album.latte
│       ├── picture.latte
│       ├── search.latte
│       ├── error.latte
│       ├── admin/
│       │   └── ...
│       └── partials/
│           └── ...
│
├── themes/                           # User-installable themes
│   ├── darkroom/
│   │   ├── theme.json                # { name, parent, version, assets }
│   │   ├── templates/
│   │   ├── assets/src/               # Vite input
│   │   └── assets/dist/              # Vite output (gitignored in src repo, built on deploy)
│   └── modus/
│
├── plugins/                          # Built-in plugins as Composer packages
│   └── example/
│       └── copyright/
│           ├── src/
│           ├── templates/
│           ├── composer.json
│           └── README.md
│
├── database/
│   ├── SCHEMA.md                     # Human-readable ERD + design notes
│   ├── migrations/
│   │   ├── 20260501120000_initial_schema.sql
│   │   └── ...
│   └── seeds/
│       ├── 00_guest_user.sql
│       └── 01_root_album.sql
│
├── config/
│   ├── container.php                 # PHP-DI service definitions
│   ├── routes.php                    # FastRoute route table
│   ├── middleware.php                # Global middleware stack ordering
│   └── derivative-presets.php        # Named derivative param sets
│
├── tests/
│   ├── Pest.php                      # Pest bootstrap (defines global uses, datasets)
│   ├── TestCase.php                  # Base test case with app bootstrapping
│   ├── Unit/                         # Mirrors src/ structure
│   │   ├── Domain/
│   │   ├── Http/
│   │   ├── Database/
│   │   └── ...
│   ├── Integration/                  # Real DB, real libvips
│   │   ├── Database/
│   │   ├── Image/
│   │   └── ...
│   ├── Http/                         # Full middleware stack, PSR-7 in/out
│   ├── Contract/                     # API v1 contract tests
│   ├── Browser/                      # Panther end-to-end
│   ├── Arch/                         # Pest Arch rules
│   ├── Performance/                  # Benchmarks
│   ├── Quarantine/                   # Known-flaky, on their way to fix-or-delete
│   ├── Factories/                    # Domain object builders
│   ├── Snapshots/                    # Template/response snapshots
│   └── fixtures/
│       ├── images/
│       └── sql/
│
├── docs/
│   ├── CONTRIBUTING.md
│   ├── architecture/
│   │   ├── worker-mode.md
│   │   ├── derivative-pipeline.md
│   │   ├── plugin-system.md
│   │   └── permissions.md
│   ├── api/
│   │   ├── v1/
│   │   │   ├── openapi.yaml
│   │   │   └── schemas/
│   └── events.md                     # Event catalog reference
│
├── storage/                          # Persistent user content — MUST be backed up
│   ├── originals/                    # uploaded source files — never modified after write
│   └── watermark.png
│
├── var/                              # Regenerable runtime state — safe to wipe
│   ├── cache/                        # compiled DI container, FastRoute table, Latte templates
│   │   ├── container.php
│   │   ├── routes.php
│   │   └── templates/
│   ├── derivatives/                  # generated thumbnails / variants
│   ├── uploads/tmp/                  # in-flight multipart uploads
│   ├── log/
│   └── sessions/                     # file-based session fallback
│
├── franken-worker.php                # FrankenPHP worker entry point
├── franken-worker-media.php              # Derivative worker entry point
├── Caddyfile
├── Dockerfile
├── .env.example
├── .gitignore
├── phpstan.neon
├── pint.json
├── infection.json5
├── vite.config.js                    # Core assets (not per-theme)
├── package.json                      # bun / npm
└── composer.json
```

### Naming conventions

- **Classes:** `PascalCase`, nouns. `AlbumRepository`, not `AlbumManager` or `AlbumHandler`.
- **Methods:** `camelCase`, verbs. `findById`, `createAlbum`, `dispatchEvent`.
- **Properties:** `camelCase`. Public on `readonly` classes; private otherwise.
- **Files:** one class per file, filename matches class name.
- **Namespaces:** `Piwigo\<Area>\<SubArea>\ClassName`.
- **Test files:** `tests/Unit/Domain/Album/AlbumTreeTest.php` mirrors `src/Domain/Album/AlbumTree.php`.
- **DB table names:** `snake_case`, plural. `albums`, `images`, `image_tags`.
- **DB column names:** `snake_case`, no prefixes. `id`, `created_at`, `parent_id`.

### What's explicitly flat

- **No `src/Model/`, `src/Entity/`, or `src/Service/` blanket directories.** Everything is organized by domain concept (Album, Image, User), not by layer. Colocation > stratification.
- **No `src/Helpers/` junk drawer.** Utilities live beside their primary consumer; if they're genuinely cross-cutting, they go in `Support/` with a clear, single purpose per file.
- **No `src/Interfaces/` directory.** Interfaces sit next to their primary implementation — `AlbumRepository` (interface) and `AlbumRepository` (impl, in `Infrastructure/Database/`) live in adjacent namespaces.
- **No `src/Traits/` directory.** Traits are rare; when used, they live with the classes that use them.

The directory structure is a map of the domain, not a map of architecture layers. New contributors can find the image-processing code under `src/Image/` and the album logic under `src/Domain/Album/` without needing an architecture primer.

---

## 33. Installation and Rollout

This is a **fresh install**, not a migration. There is no import tool, no legacy adapter, no backward-compatible shim. An existing Piwigo installation and the rewrite are two separate applications that happen to share a problem domain.

### Fresh install (single server)

```
# 1. Clone
git clone https://example.org/piwigo-rewrite.git
cd piwigo-rewrite

# 2. Dependencies
composer install --no-dev --optimize-autoloader
bun install && bun run build          # build core + theme assets

# 3. Config
cp .env.example .env
$EDITOR .env                           # DB creds, app URL, mail, storage paths

# 4. Schema
php bin/gallery migrate                 # apply all migrations
php bin/gallery db:seed                 # default users, root album

# 5. First admin
php bin/gallery admin:create \
    --username=admin \
    --email=admin@example.org

# 6. Run
frankenphp run --config Caddyfile
```

First request hits `/` and returns the empty-gallery welcome page. `/admin` prompts for login.

### Docker install

```
docker run -d \
    --name piwigo \
    -p 80:80 -p 443:443 -p 443:443/udp \
    -v /srv/piwigo/data:/app/_data \
    -v /srv/piwigo/db:/var/lib/mysql \
    -e DATABASE_URL="mysql://piwigo:secret@db/piwigo" \
    -e APP_URL="https://gallery.example.org" \
    ghcr.io/example/piwigo:1.0
```

The container runs `php bin/gallery migrate` on first start if the DB is empty; subsequent starts are no-ops. An auth-bootstrap env var (`INITIAL_ADMIN_USERNAME` / `INITIAL_ADMIN_EMAIL`) creates the admin on first boot.

### Configuration

All configuration is `.env` + typed config classes. No `config/database.inc.php`, no PHP files with mutable config state.

`.env` keys that must be set:

```
APP_URL=https://gallery.example.org
APP_SECRET=                          # 32-byte random hex; used for CSRF + signed URLs
APP_ENV=production                    # production|development|testing

DATABASE_URL=mysql://user:pass@host/piwigo
# or postgres://user:pass@host/piwigo

SESSION_DRIVER=database               # database|redis|file
REDIS_URL=                            # required if SESSION_DRIVER=redis

MAIL_DSN=smtp://user:pass@smtp.example.org:587
MAIL_FROM="Gallery <no-reply@example.org>"

STORAGE_ORIGINALS=/srv/piwigo/originals
STORAGE_DERIVATIVES=/srv/piwigo/derivatives
```

All `APP_*`, `DATABASE_*`, `MAIL_*`, `STORAGE_*` keys are documented in `.env.example` with inline comments. Any missing required key makes the app fail on boot with an actionable message (`"DATABASE_URL is required"`) rather than silently misbehaving.

### CLI tooling

The `bin/gallery` CLI is the primary admin interface. Full command list:

```
# Schema / data
php bin/gallery migrate
php bin/gallery migrate:status
php bin/gallery migrate:rollback
php bin/gallery migrate:fresh           # DEV ONLY — drop + rebuild
php bin/gallery migrate:make {name}
php bin/gallery db:seed

# Users
php bin/gallery admin:create
php bin/gallery user:create
php bin/gallery user:list
php bin/gallery user:promote {username} {level}

# Sync / imports
php bin/gallery sync /path/to/photo/tree
php bin/gallery sync:dry-run /path/to/photo/tree

# Derivatives
php bin/gallery derivatives:generate {preset} [--album={id}]
php bin/gallery derivatives:prune           # remove orphan cached files
php bin/gallery derivatives:flush           # full cache rebuild

# Maintenance
php bin/gallery down "Deploying v1.2"
php bin/gallery up
php bin/gallery cache:clear
php bin/gallery healthcheck                  # exits 0/1

# Plugins
php bin/gallery plugin:list
php bin/gallery plugin:enable {name}
php bin/gallery plugin:disable {name}
php bin/gallery plugin:install {composer-package}
php bin/gallery plugin:uninstall {name}
```

### For users coming from Piwigo

There is no supported upgrade path. Users who want to move their library will use the same tools anyone else uses to move between gallery systems: bulk re-upload via the API/CLI, plus a user-supplied script if they want to preserve tags and album structure.

The project may publish an *unsupported* example script in `contrib/import-from-piwigo/` that:

1. Reads a Piwigo 14 database read-only.
2. Maps its albums → new album tree via the CLI.
3. Copies original files into the new storage layout.
4. Emits `bin/gallery` commands to tag and categorize.

That script is a *starting point*, not a supported feature. Users will need to adapt it to their exact install. It won't gate releases, won't have SLA, and won't be tested against every Piwigo 14 subversion.

### Build order (vertical, not horizontal)

The rewrite is built feature-by-feature, each shippable, each with full test coverage. No "build all the domain models, then all the repositories, then all the controllers" — that path produces lots of untested plumbing and no working software for months.

| # | Milestone | Includes | Definition of done |
|---|---|---|---|
| 1 | **Foundations** | DI container, middleware stack, PDO + QueryBuilder, Latte wiring, CLI skeleton, Pest harness, CI pipeline, arch tests for "no legacy baggage" rules, first migration and seed | `php bin/gallery migrate` creates the DB; a hello-world HTTP route returns 200; CI is green; arch tests enforce all rules |
| 2 | **Auth + users** | User model, password hashing, login/logout, register, sessions, CSRF, access-level middleware, `admin:create` CLI | HTTP + browser tests for login/register/logout/password-reset; security headers set; rate limiting on login; audit log writing |
| 3 | **Albums** | Album tree, album CRUD, permission inheritance, public album browsing, admin album editor | Browser test: admin creates a nested album, public user browses it, permissions deny private albums to guests |
| 4 | **Images + upload + derivatives** | Image model, upload pipeline, EXIF extraction, libvips-backed derivatives, cache layer, derivative URLs | Browser test: admin uploads a photo, sees derivatives generated, views it in a browser with PhotoSwipe |
| 5 | **Gallery rendering** | Default theme, themes infrastructure (`theme.json`, parent/child), PhotoSwipe integration, thumbnails grid, picture page | A second theme overrides a single block; both render; snapshot tests stable |
| 6 | **Search + tags + comments + calendar** | Tag model, tag cloud, tag browsing, search with filters, comment CRUD, calendar view | Each has browser-test coverage; search handles common combinators |
| 7 | **Admin UI** | Batch manager, user/group admin, permission editor, plugin admin, maintenance page | Browser tests for the most-used admin flows; all admin routes gated |
| 8 | **Plugin system** | `PluginInterface`, loader, registry, Composer `type: gallery-plugin` discovery, example plugin | Example plugin installs, registers, and responds to events; DB migration for plugin applies; plugin-asset bundling works |
| 9 | **JSON API v1** | All routes under `/api/v1/*`, OpenAPI spec, API token auth, contract tests | OpenAPI document matches implementation; contract tests pass; token CRUD in the UI |
| 10 | **Mail notifications** | Welcome email, password reset, comment notification, admin digest | Email preview in dev UI; Symfony Mailer + Latte email templates wired |
| 11 | **Polish + perf** | Response caching for anonymous gallery, HTTP/3 verification, accessibility audit, performance benchmarks | All benchmark targets met; a11y audit passes WCAG 2.1 AA on default theme |
| 12 | **v1.0 release** | Release notes, upgrade notes (there aren't any), public documentation site | Cut a tagged release; Docker image published; docs site live |

Each milestone lands behind passing tests. "Ship when the tests pass" is the whole release criterion. Milestones can ship as pre-release versions (`1.0-alpha.1`, `1.0-beta.1`) as they stabilize, so adventurous users can run against the rewrite early and file bugs.

### Versioning

- **SemVer for the CLI and the plugin API.** Breaking changes to either bump the major version.
- **Versioned JSON API** — `/api/v1/`, `/api/v2/`, etc. v1 frozen at release; breaking changes go to v2 in parallel.
- **Database migrations are forward-only in production.** `migrate:rollback` exists for dev convenience but is not a supported prod operation.

### Deprecation policy

Once v1 ships, deprecations follow a 2-minor-version cadence:

1. Feature is marked `@deprecated` in docstrings and release notes.
2. Remains functional for two minor releases.
3. Removed in the following minor.

This policy applies to the plugin API, CLI command signatures, and JSON API fields. It does **not** apply to internals (domain services, middleware wiring) — those are implementation details that can change within minor versions.

### Support window

No formal commitment until v1.0 ships. Once v1.0 ships: the current minor and the previous minor both receive security fixes. Older lines are archived.

### Success metrics for "v1 is done"

v1 is ready to release when:

- All milestones 1–11 above are green.
- The project's own gallery (hosted on the rewrite) has been running in worker mode for 30 days without a restart-required regression.
- 85% / 70% coverage floors hold on main for 14 consecutive days.
- Documentation covers install, admin tasks, plugin development, and theme authoring end-to-end.
- At least one plugin exists in addition to any built-in examples — demonstrates the plugin API on something non-trivial.
- Someone unfamiliar with the codebase can install the app, upload 100 photos, and see them in a gallery within an hour of following the docs.

Anything short of that ships as `1.0-rcN`, not `1.0.0`.

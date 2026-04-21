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
- Provide a one-click migration wizard from an existing Piwigo install. See [Installation and Rollout](#14-installation-and-rollout) for the no-supported-migration stance.
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
    @derivatives path /i/*
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
// franken-worker-i.php
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

A separate worker pool for derivative generation keeps slow image operations from starving gallery page requests. Caddy routes `/i/*` to this pool.

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

MySQL and PostgreSQL are both supported. A `DatabaseAdapterInterface` abstracts the dialect-specific operations:

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

`MySqlAdapter` and `PostgresAdapter` implement this. Everything dialect-specific lives in these two files — repositories call `$adapter->upsertSql(...)` without branching.

### Schema design principles

The new schema is **not** a port of the Piwigo 14 schema. Key principles:

- **Snake_case table and column names.** No `piwigo_` prefix.
- **Surrogate primary keys** (`bigint id`) on every table. Natural keys get unique indexes instead.
- **Timestamps everywhere.** `created_at`, `updated_at` on every row; `deleted_at` on soft-deletable entities (users, comments — not images or albums, which are hard-deleted).
- **Foreign keys with `ON DELETE CASCADE`** for owned relationships (image tags cascade on image delete); `ON DELETE SET NULL` for non-owning (album parent_id).
- **No polymorphic columns.** `taggable_type` / `taggable_id` anti-patterns stay out; separate join tables instead.
- **UTF-8 / utf8mb4 everywhere.** No Latin-1 fallbacks. PostgreSQL uses `UTF8` encoding with `C.UTF-8` collation; MySQL uses `utf8mb4_0900_ai_ci`.
- **Binary UUIDs** for externally-exposed identifiers (API resources), stored as `BINARY(16)` on MySQL and `UUID` on Postgres. Sequential `bigint id`s stay internal.
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

The new derivative URL is its own design — not a port of Piwigo's `/i/...` grammar:

```
/i/{preset}/{image_uuid}.{format}
```

Examples:

```
/i/thumbnail/0198f3c5-7e5a-7c2d-9b1a-b37a2f0c8100.webp
/i/large/0198f3c5-7e5a-7c2d-9b1a-b37a2f0c8100.avif
```

Signed URLs (for custom params, or for private images with time-limited access) add a signature query:

```
/i/custom/{image_uuid}.{format}?w=1024&h=768&crop=coi&s={signature}&exp={unix_ts}
```

Signature is HMAC-SHA256 over the URL components using a per-install secret. Unsigned custom URLs are rejected with 403.

### Format negotiation

The `/i/{preset}/{uuid}` URL (with no extension) returns the best format the client accepts:

- `Accept: image/avif` → AVIF, falls back to WebP then JPEG
- `Accept: image/webp` → WebP, falls back to JPEG
- Otherwise → JPEG

Caddy handles the negotiation via `Vary: Accept`, so a CDN can cache each variant. Explicit-extension URLs (`.webp`, `.avif`, `.jpg`) bypass negotiation.

### Storage backends

`DerivativeStorage` abstracts over:

- **Local disk** — `_data/i/{first-2-of-uuid}/{uuid}/{hash}.{ext}`. Caddy serves these directly after the first generation.
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
    imagePath: '/var/www/piwigo/_data/watermark.png',
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
    "name": "example/piwigo-gps-map",
    "type": "piwigo-plugin",
    "require": {
        "piwigo/piwigo": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "Example\\GpsMap\\": "src/"
        }
    },
    "extra": {
        "piwigo-plugin": {
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

1. Scan `plugins/*/*/composer.json` and any globally-installed Composer packages with `type: piwigo-plugin`.
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
GET    /i/{preset}/{uuid}.{ext}       → DerivativeController::serve
GET    /i/{preset}/{uuid}             → DerivativeController::serveNegotiated
GET    /i/custom/{uuid}               → DerivativeController::serveCustom   (signed)

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
- **MySQL 8 via `testcontainers`** for MySQL-specific behavior.
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
- DB: MySQL 8.4, PostgreSQL 16
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

## 11. What Goes Away

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
| Per-plugin `functions_plugin.php` auto-loaded globs | Composer `type: piwigo-plugin` + `PluginInterface::register()` | Explicit entry points beat filesystem-scan magic |
| MagickTransforms / GD chain classes | Small libvips operation helpers in `src/Image/` | Abstraction for its own sake; libvips's fluent API is clearer than a multi-class ceremony |
| `check_status()`, `is_admin()`, `is_webmaster()` free functions | `AccessLevel` enum methods + `AuthorizationMiddleware` | Free functions with implicit global access replaced with explicit enum comparisons |
| Persistent language files built per-request from `.lang` strings | Compiled ICU catalogs per locale, loaded once per worker | Request-time building adds latency; loading a compiled catalog once is instant thereafter |
| `history.php` page-views counter running on every request | Optional, async-dispatched event + a `page_views` table | Synchronous counter insertion on every gallery request is a known hot-path cost |
| In-request `pwg_log()` writing to DB synchronously | Monolog with async DB handler or file handler | Database-synchronous logging inside request scope is a latency tax |
| Legacy email templating (PHP-string concat) | Symfony Mailer + Latte email templates | Safer, testable, produces both HTML and text alternatives automatically |
| Bundled jQuery and legacy JS plugins | Native JS + targeted libraries (PhotoSwipe, TomSelect, HTMX) | jQuery is no longer necessary for DOM work; shedding it lightens every page |

Every one of these is a net reduction in surface area. The rewrite is not adding features here — it's deleting thousands of lines of legacy code that the new architecture makes unnecessary.

---

## 12. What Carries Over (Conceptually)

Nothing is preserved for compatibility. What carries over is the **domain model and feature surface** — what a photo gallery *does* — reimplemented from scratch on a clean schema and a new API. This section is a design checkpoint: "of the things Piwigo does, which of them still belong in the rewrite?"

| Concept | Carries over as | Does **not** carry over |
|---|---|---|
| **Database engines supported** | MySQL 8+ and PostgreSQL 14+ (via PDO adapters) | Table names, column names, foreign-key layout, charset choices, legacy indexes, `piwigo_` prefix, SQLite support (historically flaky under Piwigo, dropped for simplicity) |
| **Albums** | Hierarchical album tree with permissions + inheritance | Tree representation (nested sets vs. adjacency list vs. closure table — redesigned), `category_id` as a surrogate for "album" — the new model calls them albums everywhere |
| **Photos / images** | Image entity with dimensions, EXIF, filesize, author, description, rating | Legacy `path` format (now a UUID-based content-addressed layout), `high` field distinction (originals are always originals), legacy md5 vs. sha1 handling (SHA-256 everywhere) |
| **Tags** | Many-to-many tags on images, tag-based browsing | Tag URL grammar, tag cloud rendering specifics, tag permission model (kept conceptually but redesigned) |
| **Users & groups** | Users with access levels, groups as permission bundles | Legacy `pwg_user_cache`/`user_cache_categories` denormalizations (computed on the fly or cached explicitly, not via ad-hoc tables) |
| **Permission model** | Album-level ACL (users and groups) plus global access levels (guest/registered/contributor/moderator/admin/webmaster) | Exact representation; `user_access` / `group_access` tables get redesigned around clear parent-child inheritance semantics |
| **Comments** | Per-image comments, moderation queue, pagination | Legacy schema, anti-spam integration (new hooks, same concept) |
| **Search** | Text search on title/description/tags; tag combinators (AND/OR/NOT); date ranges; author/camera filters | Legacy search URL grammar; search-result caching layer (re-engineered) |
| **Calendar / date browsing** | Browse by date taken, date added, or both | Legacy URL shape; rendering specifics |
| **RSS / Atom feeds** | Feeds for recent uploads, comments, etc. | Legacy feed URLs and exact payload shape |
| **Derivative system** | On-demand generation with deterministic URLs + disk cache | Legacy `/i/…` URL grammar, existing cached-derivative path layout, the `standard derivatives` naming (`thumb`, `2small`, `xsmall`, etc. become `thumbnail`/`small`/`medium`/`large`/`xlarge`) |
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

## 13. Repository Structure

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
├── var/
│   ├── cache/
│   │   ├── container.php             # compiled DI container (prod)
│   │   ├── routes.php                # compiled FastRoute table
│   │   └── templates/                # Latte compiled templates
│   └── log/
│
├── _data/                            # Runtime data — originals, derivative cache
│   ├── originals/
│   ├── derivatives/
│   └── uploads/tmp/
│
├── franken-worker.php                # FrankenPHP worker entry point
├── franken-worker-i.php              # Derivative worker entry point
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

## 14. Installation and Rollout

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
php bin/piwigo migrate                 # apply all migrations
php bin/piwigo db:seed                 # default users, root album

# 5. First admin
php bin/piwigo admin:create \
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

The container runs `php bin/piwigo migrate` on first start if the DB is empty; subsequent starts are no-ops. An auth-bootstrap env var (`INITIAL_ADMIN_USERNAME` / `INITIAL_ADMIN_EMAIL`) creates the admin on first boot.

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

The `bin/piwigo` CLI is the primary admin interface. Full command list:

```
# Schema / data
php bin/piwigo migrate
php bin/piwigo migrate:status
php bin/piwigo migrate:rollback
php bin/piwigo migrate:fresh           # DEV ONLY — drop + rebuild
php bin/piwigo migrate:make {name}
php bin/piwigo db:seed

# Users
php bin/piwigo admin:create
php bin/piwigo user:create
php bin/piwigo user:list
php bin/piwigo user:promote {username} {level}

# Sync / imports
php bin/piwigo sync /path/to/photo/tree
php bin/piwigo sync:dry-run /path/to/photo/tree

# Derivatives
php bin/piwigo derivatives:generate {preset} [--album={id}]
php bin/piwigo derivatives:prune           # remove orphan cached files
php bin/piwigo derivatives:flush           # full cache rebuild

# Maintenance
php bin/piwigo down "Deploying v1.2"
php bin/piwigo up
php bin/piwigo cache:clear
php bin/piwigo healthcheck                  # exits 0/1

# Plugins
php bin/piwigo plugin:list
php bin/piwigo plugin:enable {name}
php bin/piwigo plugin:disable {name}
php bin/piwigo plugin:install {composer-package}
php bin/piwigo plugin:uninstall {name}
```

### For users coming from Piwigo

There is no supported upgrade path. Users who want to move their library will use the same tools anyone else uses to move between gallery systems: bulk re-upload via the API/CLI, plus a user-supplied script if they want to preserve tags and album structure.

The project may publish an *unsupported* example script in `contrib/import-from-piwigo/` that:

1. Reads a Piwigo 14 database read-only.
2. Maps its albums → new album tree via the CLI.
3. Copies original files into the new storage layout.
4. Emits `bin/piwigo` commands to tag and categorize.

That script is a *starting point*, not a supported feature. Users will need to adapt it to their exact install. It won't gate releases, won't have SLA, and won't be tested against every Piwigo 14 subversion.

### Build order (vertical, not horizontal)

The rewrite is built feature-by-feature, each shippable, each with full test coverage. No "build all the domain models, then all the repositories, then all the controllers" — that path produces lots of untested plumbing and no working software for months.

| # | Milestone | Includes | Definition of done |
|---|---|---|---|
| 1 | **Foundations** | DI container, middleware stack, PDO + QueryBuilder, Latte wiring, CLI skeleton, Pest harness, CI pipeline, arch tests for "no legacy baggage" rules, first migration and seed | `php bin/piwigo migrate` creates the DB; a hello-world HTTP route returns 200; CI is green; arch tests enforce all rules |
| 2 | **Auth + users** | User model, password hashing, login/logout, register, sessions, CSRF, access-level middleware, `admin:create` CLI | HTTP + browser tests for login/register/logout/password-reset; security headers set; rate limiting on login; audit log writing |
| 3 | **Albums** | Album tree, album CRUD, permission inheritance, public album browsing, admin album editor | Browser test: admin creates a nested album, public user browses it, permissions deny private albums to guests |
| 4 | **Images + upload + derivatives** | Image model, upload pipeline, EXIF extraction, libvips-backed derivatives, cache layer, derivative URLs | Browser test: admin uploads a photo, sees derivatives generated, views it in a browser with PhotoSwipe |
| 5 | **Gallery rendering** | Default theme, themes infrastructure (`theme.json`, parent/child), PhotoSwipe integration, thumbnails grid, picture page | A second theme overrides a single block; both render; snapshot tests stable |
| 6 | **Search + tags + comments + calendar** | Tag model, tag cloud, tag browsing, search with filters, comment CRUD, calendar view | Each has browser-test coverage; search handles common combinators |
| 7 | **Admin UI** | Batch manager, user/group admin, permission editor, plugin admin, maintenance page | Browser tests for the most-used admin flows; all admin routes gated |
| 8 | **Plugin system** | `PluginInterface`, loader, registry, Composer `type: piwigo-plugin` discovery, example plugin | Example plugin installs, registers, and responds to events; DB migration for plugin applies; plugin-asset bundling works |
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

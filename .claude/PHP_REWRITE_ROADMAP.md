# Piwigo PHP Rewrite — Implementation Roadmap

Actionable task list for the full implementation described in [`PHP_REWRITE_PLAN.md`](./PHP_REWRITE_PLAN.md). Tasks are grouped into phases that roughly match plan §33's build order; each phase is gated by a Definition of Done.

## How to use this document

- Each task is a checkbox. Check it when merged to `main`.
- Tasks reference their plan section in parentheses — e.g. `(§4)` points at `PHP_REWRITE_PLAN.md §4 Database Layer` for the design.
- A phase is **done** when all its tasks are checked *and* its Definition-of-Done criteria hold.
- Tasks within a phase can often run in parallel. Across phases: later phases generally depend on earlier ones; call-outs flag exceptions.
- "A task" is sized to land in a single PR. If a task won't fit in one PR, split it.
- Anything not on this list is out of scope for v1.

### Status legend

- `[ ]` — not started
- `[~]` — in progress (claim with a linked PR or issue)
- `[x]` — merged to `main`
- `[!]` — blocked (link to the blocker)
- `[-]` — descoped (link to rationale)

---

## Phase 0 — Project setup

Pre-code work so the repo is ready to receive contributions.

- [ ] Create the repo on the chosen forge; set default branch to `main`.
- [ ] Add `LICENSE` — GPL-2.0-or-later. (§20)
- [ ] Add `README.md` with a one-paragraph project description, status banner ("pre-alpha — do not use"), and link to the plan + this roadmap.
- [ ] Add `CONTRIBUTING.md` — covers dev setup, PR checklist, DCO sign-off. (§19, §20)
- [ ] Add `CODE_OF_CONDUCT.md` — Contributor Covenant 2.1.
- [ ] Add `SECURITY.md` — reporting process, GPG key placeholder. (§11, §20)
- [ ] Add `CHANGELOG.md` — keep-a-changelog format with an empty `Unreleased` section. (§20)
- [ ] Add `.github/ISSUE_TEMPLATE/` — bug, feature request, security (points at `SECURITY.md`).
- [ ] Add `.github/PULL_REQUEST_TEMPLATE.md` — sections: Summary, Tests, Plan link, Changelog entry.
- [ ] Add `docs/adr/0000-template.md` — ADR template for architectural decisions. (§19)
- [ ] Seed `docs/adr/` with ADRs ratifying the plan's core choices: FrankenPHP, no-ORM, Latte, libvips, Pest.
- [ ] Configure branch protection on `main` — require PR, require CI green, require one reviewer.
- [ ] Configure GitHub Security Advisories (or equivalent on the chosen forge).
- [ ] Add repo to Renovate / Dependabot with a config allowing auto-merge of patch bumps after CI.
- [ ] Set up the GHCR / Docker Hub account for eventual image publishing.

**Definition of Done:** an empty repo a contributor can fork, sign, and file an issue against. Nothing runs yet.

---

## Phase 1 — Foundations

The scaffolding every other phase rests on. Arch tests enforce the "no legacy baggage" rules from commit one — if these slip, nothing later can catch them.

### 1.1 Repo layout + Composer

- [ ] Create top-level tree per plan §32 (public/, src/, tests/, templates/, themes/, plugins/, database/, config/, bootstrap/, bin/, docs/, var/, storage/).
- [ ] Write `composer.json` with: `php ^8.5`, `nyholm/psr7`, `nyholm/psr7-server`, `nikic/fast-route`, `relay/relay`, `php-di/php-di`, `league/event`, `monolog/monolog`, `symfony/console`, `symfony/messenger`, `symfony/mailer`, `symfony/translation`, `symfony/cache`, `symfony/scheduler`, `vlucas/phpdotenv`, `jcupitt/vips`, `latte/latte`, `ramsey/uuid`, `open-telemetry/sdk`. Dev: `pestphp/pest`, `pestphp/pest-plugin-arch`, `phpstan/phpstan`, `laravel/pint`, `infection/infection`, `symfony/panther`.
- [ ] Commit `composer.lock`.
- [ ] Write `package.json` + `bun.lockb` with Vite + a minimal CSS/JS setup.
- [ ] `.gitignore` — vendor, node_modules, var/, storage/, .env, dist/.
- [ ] `.editorconfig`.

### 1.2 Config + DI + CLI bootstrap

- [ ] Implement `EnvReader` service (`required`, `optional`, `int`, `bool`, `string` typed accessors). (§3)
- [ ] Implement `AppConfig`, `DatabaseConfig`, `SessionConfig`, `MailConfig`, `DerivativeConfig` — readonly classes with `fromEnv()` constructors. (§3)
- [ ] Commit `.env.example` with every recognized key and inline comments.
- [ ] Wire PHP-DI container in `bootstrap/app.php` with dev (autowiring) and prod (compiled) modes. (§3)
- [ ] Compiled-container output path `var/cache/container.php`; `php bin/gallery container:build` CLI command.
- [ ] `bin/gallery` entry script; Symfony Console application registered with a minimal `about` command.
- [ ] `php bin/gallery about` prints environment summary (PHP version, extensions, DB DSN, worker status).

### 1.3 PSR-15 middleware pipeline

- [ ] Wire `relay/relay` dispatcher. (§3)
- [ ] Stub the middleware ring: `ErrorHandlerMiddleware`, `RequestLoggerMiddleware`, `CorsMiddleware`, `SecurityHeadersMiddleware`, `TrustedProxyMiddleware`, `SessionMiddleware`, `AuthMiddleware`, `LocaleMiddleware`, `CsrfMiddleware`, `MaintenanceModeMiddleware`. Initial implementations can be no-ops or stubs; signatures must be final.
- [ ] `ErrorHandlerMiddleware` maps `NotFoundException`, `ForbiddenException`, `ValidationException` to HTTP status. (§3)
- [ ] `ProblemDetailsFactory` producing RFC 7807 JSON. (§3, §22)
- [ ] `RequestLoggerMiddleware` generates a ULID per request and sets `X-Request-Id`. (§12)
- [ ] `SecurityHeadersMiddleware` sets CSP, HSTS, X-Frame-Options, Referrer-Policy, Permissions-Policy, X-Content-Type-Options, COOP/COEP. (§11)

### 1.4 Routing

- [ ] Wire `nikic/fast-route` with `config/routes.php`. (§9)
- [ ] Compile the route table on boot; cache for prod.
- [ ] `UrlGenerator` for reverse routing. (§9)
- [ ] Implement the `#[Route]` attribute and a scanner. (§9)
- [ ] First route: `GET /healthz` returning `{ "status": "ok" }`. (§12)
- [ ] First route: `GET /` returning a hello-world Latte template.
- [ ] `php bin/gallery route:list`.

### 1.5 Database layer

- [ ] Implement `Database` service — PDO wrapper, statement cache (LRU 500), `ensureConnected()` health probe, `transaction()` helper. (§4)
- [ ] Implement `QueryBuilder` — named-parameter-only, identifier allowlist, composable WHERE/ORDER/LIMIT. (§4)
- [ ] Implement `DatabaseAdapterInterface` + `MySqlAdapter` + `PostgresAdapter`. (§4)
- [ ] Implement `Migrator` — reads `database/migrations/*.sql` with `+migrate up` / `+migrate down` markers, records checksums in `schema_migrations`. (§4)
- [ ] CLI commands: `migrate`, `migrate:status`, `migrate:rollback`, `migrate:fresh`, `migrate:make`. (§4)
- [ ] Initial migration `database/migrations/20260501120000_initial_schema.sql` creating every table from plan §16. (§16)
- [ ] Seed migration `database/seeds/00_guest_user.sql` + `01_root_album.sql`. (§4)
- [ ] `php bin/gallery db:seed`.
- [ ] `DatabaseSessionHandler` stub — `SessionHandlerInterface` over the `sessions` table. (§8)

### 1.6 Testing harness

- [ ] Pest configured; `tests/Pest.php` declares shared contexts.
- [ ] `tests/TestCase.php` base class boots a minimal app; wraps each test in a DB transaction.
- [ ] SQLite in-memory DB by default; Postgres via testcontainers when a dialect-specific test opts in.
- [ ] `tests/Factories/` directory; `UserFactory`, `AlbumFactory`, `ImageFactory`, `TagFactory` stubs.
- [ ] Pest Arch plugin installed; initial `tests/Arch/NoLegacyBaggage.php` with all rules from plan §10. (§10)
- [ ] First smoke test: `GET /healthz` returns 200 with JSON body.
- [ ] PHPStan level max config (`phpstan.neon`); zero errors.
- [ ] Pint config (`pint.json`); passing.
- [ ] Infection config (`infection.json5`); excluded from per-PR runs.
- [ ] `php bin/gallery ci` command — runs pint, phpstan, pest in order.

### 1.7 Template engine

- [ ] Wire Latte engine as a DI singleton; compile cache at `var/cache/templates/`. (§6)
- [ ] `templates/default/layout.latte` — minimal HTML shell.
- [ ] `templates/default/error.latte` — 404/403/422/500 error page.
- [ ] `TemplateEngine` service wrapping Latte with rendered-template helpers. (§6)
- [ ] Register core Latte extensions (stubbed): `AssetExtension`, `LinkExtension`, `CsrfExtension`, `ImageExtension`, `TranslationExtension`. (§6)

### 1.8 Runtime

- [ ] `franken-worker.php` entry point per plan §2. (§2)
- [ ] `franken-worker-media.php` derivative worker entry point. (§2)
- [ ] `Caddyfile` for prod; `Caddyfile.dev` for dev. (§2)
- [ ] `Dockerfile` using `dunglas/frankenphp:1-php8.5`. (§2)
- [ ] `docker-compose.dev.yml` with MySQL, Redis, Mailpit, MinIO. (§19)

### 1.9 CI

- [ ] GitHub Actions workflow `.github/workflows/ci.yml` — runs `pint --test`, `phpstan analyse`, `pest --parallel --coverage --min=70`, `pest tests/Browser`.
- [ ] Matrix: PHP 8.5, MySQL 9.7 LTS + MariaDB 11.8 LTS + PostgreSQL 18.
- [ ] Nightly workflow `.github/workflows/nightly.yml` — Infection mutation testing, performance benchmarks, `composer audit`, `bun audit`.
- [ ] OpenAPI-spec drift check (will do nothing until Phase 9, but the hook exists).
- [ ] Badge in README.

### Phase 1 Definition of Done

- `php bin/gallery migrate` creates every table in the initial schema.
- `php bin/gallery db:seed` populates defaults.
- `GET /` returns 200 with a hello-world page.
- `GET /healthz` returns 200 JSON.
- CI is green.
- Every arch test rule is active (`exit`, `die`, `echo`, `$_GET` in domain code, raw PDO outside `Database/`, etc.). Deliberately committing a violation fails CI.

---

## Phase 2 — Auth + users

Login, registration, sessions, access levels — the base for every later feature that needs to know *who*.

### 2.1 User domain

- [ ] `User` readonly class with `id`, `uuid`, `username`, `email`, `accessLevel`, `locale`, etc. (§8, §16)
- [ ] `AccessLevel` enum with `allows()` method. (§8)
- [ ] `UserRepository` — `findById`, `findByIdOrFail`, `findByUsername`, `findByEmail`, `save`, `delete`. (§4)
- [ ] `UserFactory` for tests. (§10)

### 2.2 Password + auth service

- [ ] `PasswordHasher` — argon2id with configured cost. (§8)
- [ ] `PasswordHasher::needsRehash()` check on every login; upgrades old hashes. (§8)
- [ ] `AuthService::attempt($username, $password)` with timing-attack normalization. (§11)
- [ ] `AuthService::login($user)` — regenerate session ID, write user_id to session. (§8)
- [ ] `AuthService::logout()` — clear session, revoke remember-me.

### 2.3 Session storage

- [ ] `SessionService` with start/save/get/put/flush/regenerateId. (§8)
- [ ] `DatabaseSessionHandler` implementation (the stub from Phase 1). (§8)
- [ ] `RedisSessionHandler` alternative. (§8)
- [ ] `SessionMiddleware` wiring the service around the request. (§3, §8)
- [ ] Arch test: `$_SESSION` is only referenced from `src/Session/`.

### 2.4 CSRF

- [ ] `CsrfTokenService` — per-session token, HMAC-based validation. (§8, §11)
- [ ] `CsrfMiddleware` — validates header or body field on state-changing methods. (§3, §8)
- [ ] `CsrfExtension` for Latte — `{csrf}` emits the hidden input. (§6)
- [ ] Double-submit cookie pattern for API clients. (§11)

### 2.5 Controllers + templates

- [ ] `AuthController::form` renders `login.latte`.
- [ ] `AuthController::submit` processes POST, calls `AuthService::attempt`.
- [ ] `AuthController::logout` processes POST, calls `AuthService::logout`.
- [ ] `RegisterController::form` renders `register.latte`.
- [ ] `RegisterController::submit` validates + creates + dispatches `UserCreatedEvent`.
- [ ] `login.latte`, `register.latte` templates in the default theme.
- [ ] Basic styling — enough to be usable, not pretty yet.

### 2.6 Password reset

- [ ] `PasswordResetController::form`, `::sendEmail`, `::confirmForm`, `::commit`. (§9)
- [ ] Password-reset token table + repository (one-time-use with expiry).
- [ ] Reset email template (plain-text first; HTML when Phase 10 lands mailer).
- [ ] Tokens rate-limited per email + IP. (§11)

### 2.7 Remember-me

- [ ] `remember_tokens` table + `RememberMeService`. (§8)
- [ ] Selector/hash split so cookies can't be replayed against DB. (§8)
- [ ] Middleware auto-logs in when valid cookie present, no active session.
- [ ] One-time-use: old token invalidated on use.

### 2.8 Rate limiting

- [ ] `RateLimiter` service, leaky-bucket, Redis-backed (DB fallback). (§8, §11)
- [ ] Per-endpoint limits from the plan's catalog on login/register/password-reset. (§11)
- [ ] 429 response with `Retry-After`.

### 2.9 Access-level enforcement

- [ ] `AuthMiddleware` populates `$request->user` (guest or authenticated). (§3, §8)
- [ ] `#[RequiresLevel(AccessLevel::Admin)]` attribute. (§8)
- [ ] `AuthorizationMiddleware` reads the attribute, returns 403 if insufficient.
- [ ] `AdminAuthMiddleware` attached to `/admin/*` routes.

### 2.10 Audit log

- [ ] `AuditLogger` service writing to `audit_log`. (§12, §16)
- [ ] Events: `user.login`, `user.login.failed`, `user.logout`, `user.registered`, `user.password_reset.requested`, `user.password_reset.completed`.
- [ ] Listener subscribers fire on the corresponding events.

### 2.11 CLI

- [ ] `php bin/gallery admin:create --username --email [--password]`. (§14 install)
- [ ] `user:create`, `user:list`, `user:promote {username} {level}`.

### 2.12 Tests

- [ ] Unit tests for `PasswordHasher`, `AccessLevel`, `AuthService::attempt` success/failure.
- [ ] Integration tests for `UserRepository` round-trip.
- [ ] HTTP tests for login, logout, register, password-reset flows.
- [ ] HTTP test: anon user → 403 on admin route.
- [ ] Browser test: full login → logout flow via Panther.
- [ ] Rate-limit test: sixth login attempt → 429.
- [ ] Property test: password hash never matches a different password.

### Phase 2 Definition of Done

- A user can register, log in, log out, reset their password, check "remember me".
- Admin routes are gated; unauthorized access returns 403.
- Audit log records all auth events.
- HTTP + browser tests cover the happy paths and the top five failure paths.

---

## Phase 3 — Albums

Hierarchical album tree + permission model. Everything visual comes later; this phase is about data + auth.

### 3.1 Album domain

- [ ] `Album` readonly class with tree fields (`parent_id`, ancestors, descendants helpers). (§16)
- [ ] `Slug` value object — lower, URL-safe, collision suffix. (§16)
- [ ] `AlbumRepository` — `findById`, `findByIdOrFail`, `findChildrenOf`, `findAncestorsOf`, `findDescendantsOf`, `save`, `delete`. (§4)
- [ ] Denormalized `image_count` — keep it honest via event listeners.
- [ ] `AlbumFactory` for tests.

### 3.2 Permission model

- [ ] `PermissionService::canView(User, Album)` — implements the resolution algorithm from plan §16.
- [ ] `PermissionService::canUpload(User, Album)`, `canManage(User, Album)`.
- [ ] `PermissionService::allowedAlbumIdsFor(User)` — batched for listings. (§26)
- [ ] Per-request memoization.
- [ ] Invalidate memoization on `AlbumPermissionsChangedEvent`.
- [ ] Unit tests covering inheritance edge cases (group wins, user overrides, etc.).
- [ ] Property test: no user can see an album their access level / ACL forbids.

### 3.3 Album CRUD (admin)

- [ ] `Admin\AlbumController` — `index`, `edit`, `update`, `create`, `delete`.
- [ ] `CreateAlbumInput` / `UpdateAlbumInput` DTOs with validation. (§11)
- [ ] Events: `AlbumCreatingEvent`, `AlbumCreatedEvent`, `AlbumUpdatingEvent`, `AlbumUpdatedEvent`, `AlbumDeletingEvent`, `AlbumDeletedEvent`, `AlbumMovedEvent`. (§23)

### 3.4 Public album listing + detail

- [ ] `GalleryController::index` — top-level albums visible to the viewer.
- [ ] `AlbumController::show` — album detail (thumbnails will be empty until Phase 4).
- [ ] Breadcrumb helper (renders ancestor chain).
- [ ] `index.latte`, `album.latte` templates.

### 3.5 Permissions UI (admin)

- [ ] Permission editor backend — add/remove user ACL, add/remove group ACL, change `min_level`, toggle `is_public`. (§25)
- [ ] "Preview as user X" — resolves visibility for a different user.
- [ ] Events: `AlbumPermissionsChangingEvent`, `AlbumPermissionsChangedEvent`. (§23)
- [ ] Audit log: `album.permissions_changed` with before/after diff.

### 3.6 Tests

- [ ] Integration: tree operations (move subtree, delete cascades).
- [ ] Integration: permission resolution across depth-5 trees.
- [ ] HTTP: guest cannot see private albums.
- [ ] HTTP: admin can CRUD.
- [ ] Browser: admin creates nested album, navigates public listing.

### Phase 3 Definition of Done

- Admin can build an arbitrary album tree with permissions.
- Public visitors see only what they're allowed to see.
- Permission changes invalidate caches and memoized resolves.

---

## Phase 4 — Images, upload, derivatives

The heaviest domain phase. Upload pipeline, libvips wrapper, derivative generation, storage abstraction.

### 4.1 Storage backend

- [ ] `StorageBackend` interface (`exists`, `put`, `get`, `delete`, `presignedUrl`, `stat`). (§15)
- [ ] `LocalDiskStorageBackend` implementation.
- [ ] Configurable DSN (`file:///...`); readable from `.env`. (§15)
- [ ] Directory creation on demand; year/month tree for originals.
- [ ] `DerivativeStorage` interface + `LocalDiskDerivativeStorage`. (§5)
- [ ] `withLock()` via `flock`. (§5)

### 4.2 libvips wrapper

- [ ] `VipsProcessor` — probe (dimensions + format + animation + color space), resize, crop, rotate (EXIF auto), sharpen, watermark composite, strip metadata, encode to JPEG/WebP/AVIF/PNG. (§5)
- [ ] `vips_cache_set_max_mem()` configured. (§2)
- [ ] Unit tests with fixture images.

### 4.3 Image domain

- [ ] `Image` readonly class — includes EXIF as typed value object. (§16)
- [ ] `Exif` value object — typed accessors for camera, lens, ISO, GPS, DateTimeOriginal, orientation, etc. (§16)
- [ ] `ExifExtractor` using libvips's EXIF accessors.
- [ ] `PerceptualHasher` — pHash via libvips downsize + DCT. (§5, §24)
- [ ] `ImageRepository` — standard repo ops + `findByHash`, `findNearDuplicates`. (§4, §24)
- [ ] `ImageFactory` generating real small JPEGs for tests. (§10)

### 4.4 Upload pipeline

- [ ] `UploadController::form` + `::submit` (multipart, non-resumable). (§9, §24)
- [ ] `UploadValidator` — content-length, MIME allowlist, libvips probe, magic-byte check, reject SVG. (§11, §24)
- [ ] `QuotaService` — atomic debit inside the upload transaction. (§15, §24)
- [ ] `ImageDeduplicator` — sha256 + pHash lookup. (§24)
- [ ] Events: `ImageUploadingEvent`, `ImageValidatingEvent`, `ImageSavingEvent`, `ImageCreatedEvent`. (§23)
- [ ] Quarantine directory with 24 h cleanup. (§15)
- [ ] Move to `originals/YYYY/MM/{uuid}.{ext}` on success.
- [ ] Resumable uploads via tus protocol. (§24)
- [ ] Chunked upload (JS) — client progress, retry. (§24)
- [ ] Browser-side: `assets/src/upload.js` with drag-drop + progress.

### 4.5 Messenger + queues

- [ ] Wire `symfony/messenger` with Doctrine DBAL transport (default). (§13)
- [ ] `MESSENGER_TRANSPORT_DSN` in `.env.example`.
- [ ] Queue routing in `config/messenger.php`. (§13)
- [ ] `messenger:consume {queue}` CLI. (§13)
- [ ] Transactional outbox middleware. (§13)
- [ ] `messages` + `failed_messages` tables in the initial schema (or a follow-up migration). (§16)

### 4.6 Derivative system

- [ ] `DerivativeParams` readonly with `hash()` method. (§5)
- [ ] `DerivativeConfig::resolvePreset($name)`. (§3, §5)
- [ ] Derivative presets: `thumbnail`, `small`, `medium`, `large`, `xlarge` — defined in `config/derivative-presets.php`.
- [ ] `DerivativeService::serve($request)` — cache hit / miss / generate flow. (§5)
- [ ] URL grammar `/media/{preset}/{uuid}.{ext}` + `/media/custom/{uuid}?...&s=...`. (§5)
- [ ] `DerivativeController::serve` + `::serveNegotiated` + `::serveCustom`. (§9)
- [ ] Signed URLs via HMAC-SHA256. (§5)
- [ ] Format negotiation from `Accept`. (§5)
- [ ] `FormatNegotiator` service.

### 4.7 Derivative generation job

- [ ] `GenerateDerivativesMessage`. (§13)
- [ ] `GenerateDerivativesHandler` (calls `DerivativeService::ensureGenerated`).
- [ ] Queued after `ImageCreatedEvent`. (§23)
- [ ] `DerivativeGeneratedEvent` dispatched. (§23)
- [ ] CLI: `derivatives:generate {preset} [--album=] [--all]`.
- [ ] CLI: `derivatives:flush {image_id|--all}`.
- [ ] CLI: `derivatives:prune` (orphan cleanup).
- [ ] Scheduled nightly `DerivativesPruneMessage`. (§13)

### 4.8 Derivative worker

- [ ] `franken-worker-media.php` bootstrap (from Phase 1) runs `DerivativeController::serve`.
- [ ] Caddy routes `/media/*` to the derivative worker pool. (§2)
- [ ] Locking + thundering-herd test. (§5)

### 4.9 Golden-image tests

- [ ] Populate `tests/fixtures/images/` with EXIF orientations 1–8, CMYK, Adobe RGB, HEIC, AVIF, animated WebP, animated GIF, wide panorama, tall portrait, corrupted EXIF, a truncated file, a known-pathological JPEG. (§5, §10)
- [ ] One golden-hash test per fixture: libvips output hash matches the committed expected hash for each preset.
- [ ] `--update-snapshots` flow for intentional changes.

### 4.10 Tests

- [ ] Unit: `DerivativeParams::hash()` stability.
- [ ] Unit: `PerceptualHasher` known-pair test.
- [ ] Integration: upload a sample image via HTTP → DB row + file on disk + derivative queued.
- [ ] Integration: quota exhaustion returns 413.
- [ ] Integration: duplicate detection (exact + perceptual).
- [ ] HTTP: derivative cache hit vs miss.
- [ ] HTTP: signed URL with expired timestamp → 403.
- [ ] HTTP: signed URL with tampered params → 403.
- [ ] Browser: admin uploads a photo, sees it in an album, opens in PhotoSwipe.

### Phase 4 Definition of Done

- Admin uploads a photo via web form, sees it appear in an album with generated thumbnails.
- Same photo accessible via the derivative URL scheme in all configured formats.
- libvips memory bounded under a 100 MB JPEG upload.
- Upload quota enforced.
- Golden-image tests stable across the fixture set.

---

## Phase 5 — Gallery rendering

Make the public side look like a gallery. Themes, thumbnails, lightbox, picture page.

### 5.1 Theme infrastructure

- [ ] `ThemeLoader` — reads `theme.json`, resolves parent chain for template lookup. (§6)
- [ ] `themes/default/theme.json` (parent of all others).
- [ ] `templates/default/` — the parent templates live here; themes override by placing files in `themes/{name}/templates/`.
- [ ] Theme switching via `users.theme` or a request attribute (admin override).

### 5.2 Asset pipeline

- [ ] `vite.config.js` for core + themes. (§17)
- [ ] Vite manifest loader with caching. (§17)
- [ ] `AssetExtension` Latte tag `{asset 'main.js'}`. (§6)
- [ ] `{plugin_asset 'name', 'main.js'}` (stub until Phase 8).
- [ ] Caddy routes `/assets/*` and `/themes/*/assets/*` to static file-server. (§2)

### 5.3 Default theme templates

- [ ] `layout.latte` — shell with head, nav, footer, dark-mode toggle.
- [ ] `index.latte` — top-level album grid.
- [ ] `album.latte` — album view with breadcrumb, photo grid, pagination.
- [ ] `picture.latte` — single image, metadata sidebar, next/prev, open-in-lightbox hook.
- [ ] `error.latte` — generic error page.
- [ ] `partials/nav.latte`, `footer.latte`, `pagination.latte`, `thumbnail.latte`, `breadcrumb.latte`.

### 5.4 Core Latte extensions

- [ ] `LinkExtension` — `{link 'route.name', arg: value}`. (§9)
- [ ] `ImageExtension` — `{image $image, size: 'thumbnail'}` emits `<picture>` with AVIF/WebP/JPEG sources and `srcset`. (§17, §26)
- [ ] `CsrfExtension` — `{csrf}` renders hidden input. (§8)
- [ ] `TranslationExtension` — `{_'key'}` (stubbed; Phase 11 wires real catalogs).

### 5.5 Frontend

- [ ] `assets/src/main.css` — design tokens, base layout, theme dark mode. (§17)
- [ ] `assets/src/main.js` — minimal entry, loads feature modules.
- [ ] `assets/src/photoswipe-init.js` — wires PhotoSwipe against the thumbnail grid. (§17)
- [ ] Keyboard shortcuts on picture page: left/right/esc.
- [ ] Responsive images + lazy loading. (§17, §26)
- [ ] Print stylesheet. (§17)
- [ ] Print test via Chrome headless PDF.

### 5.6 Tests

- [ ] Snapshot tests for `index.latte`, `album.latte`, `picture.latte`, `error.latte`. (§10)
- [ ] axe-core runs in browser tests against every rendered public page; zero violations. (§10, §17)
- [ ] Dark-mode visual snapshot.
- [ ] Keyboard-only nav test.

### Phase 5 Definition of Done

- A public visitor can browse top-level albums, open an album, view a picture, move between pictures via keyboard.
- Dark mode works; respects `prefers-color-scheme`.
- Snapshots stable; a11y clean.

---

## Phase 6 — Search, tags, comments, calendar

### 6.1 Tags

- [ ] `Tag` domain + `TagRepository`. (§16)
- [ ] `image_tags` writes dispatch `ImageTagsChangedEvent`. (§23)
- [ ] Tag CRUD (admin): create, rename (with slug rewrite), merge, delete.
- [ ] Public tag cloud view.
- [ ] Tag-based browse: `/tags/{slug}` shows images tagged with it.
- [ ] Tag autocomplete stub (real impl in Search).

### 6.2 Comments

- [ ] `Comment` domain + `CommentRepository`. (§16)
- [ ] Comment submission form on picture page (anon or logged-in).
- [ ] Events: `CommentSubmittingEvent` (veto for spam plugins), `CommentCreatedEvent`, `CommentApprovedEvent`, `CommentRejectedEvent`. (§23)
- [ ] Moderation queue (admin): approve, reject, delete.
- [ ] Anti-spam hook point; simple rule-based default (rate limit + link count).
- [ ] Display with avatar, date, moderator badge.

### 6.3 Search — core

- [ ] `SearchQuery` + `SearchResult` + `SearchEngine` interface. (§21)
- [ ] `SearchQueryParser` — grammar from plan §21. Unit tests covering every construct.
- [ ] `MySqlFullTextSearchEngine` — `FULLTEXT` index on title/description/original_name; `MATCH AGAINST` with boolean-mode operators. (§21)
- [ ] `PostgresTsvectorSearchEngine` — generated `tsvector` column, GIN index, `to_tsquery` + `ts_rank_cd`. (§21)
- [ ] `MeilisearchEngine` — optional; config-gated. (§21)
- [ ] Engine selection via `SEARCH_ENGINE` env var.
- [ ] Permission post-filter on results.
- [ ] `SearchController::index` + `search.latte`.
- [ ] Faceted sidebar: tag counts, year counts, author counts.
- [ ] Autocomplete endpoint (`GET /api/search/suggest?q=prefix`). Client: TomSelect dropdown.
- [ ] Saved searches (`saved_searches` table, per-user). Migration.
- [ ] `search:reindex` CLI — batched, resumable, progress bar.
- [ ] Event-driven incremental reindex via `ReindexSearchMessage`. (§13, §23)

### 6.4 Search — tests

- [ ] Parser unit tests for every grammar construct.
- [ ] Integration: identical queries return overlapping top-10 hits across MySQL + Postgres + Meilisearch backends. (§21)
- [ ] Property test: random query strings never throw, never leak permissions.
- [ ] HTTP: search results respect viewer's ACL.
- [ ] Perf: p95 < 100 ms at 10k images.

### 6.5 Calendar

- [ ] `CalendarController::show` — views: year, month, day.
- [ ] Dialect-portable `DATE_TRUNC` via adapter. (§4)
- [ ] Sparkline of upload activity on the year view.
- [ ] `calendar.latte` templates.

### 6.6 Feeds

- [ ] `FeedController::rss`, `FeedController::atom`.
- [ ] Recent uploads (global), recent uploads per album, recent comments.
- [ ] Signed private feeds for authenticated users (token-based).

### 6.7 Tests

- [ ] Browser: search "sunset" returns expected fixtures.
- [ ] Browser: tag page with saved-search option.
- [ ] Browser: submit a comment as guest → appears in moderation queue.
- [ ] HTTP: calendar year view structurally correct.

### Phase 6 Definition of Done

- A user can search with tag combinators, date ranges, and phrase matches.
- A user can browse by tag, by calendar, via RSS.
- Comments submitted appear in the moderation queue and respect anti-spam hooks.

---

## Phase 7 — Admin UI

Everything needed to run the gallery day-to-day.

### 7.1 Admin shell

- [ ] `Admin\DashboardController::index`. (§25)
- [ ] Admin layout with left sidebar, breadcrumbs, global search.
- [ ] HTMX + Alpine.js wired. (§25)
- [ ] Admin-only theme (neutral, high-contrast).
- [ ] Keyboard-shortcut manager (`?` help modal). (§25)

### 7.2 Dashboard

- [ ] Status panels: DB, storage, queues, mail.
- [ ] Storage metrics with sparklines.
- [ ] Queue depth per queue.
- [ ] Recent uploads, recent comments needing moderation, recent security events.
- [ ] Deprecation usage list (empty in v1.0).
- [ ] 30-second HTMX refresh.

### 7.3 Album admin

- [ ] Tree view with lazy-loaded children. (§25)
- [ ] Drag-drop reorder (siblings), drag-drop move (reparent).
- [ ] Keyboard nav per plan §25.
- [ ] Bulk select + bulk actions (delete, move, set cover, change permissions).
- [ ] Inline rename; drawer for full edit.
- [ ] Permission editor UI (from Phase 3 backend) with "preview as user X".

### 7.4 Photo batch manager

- [ ] Filter sidebar (album, tags, date, author, camera, has-GPS, rating, min_level, missing-metadata). (§25)
- [ ] Virtualized grid; infinite scroll. (§25)
- [ ] Selection: click / shift-click / cmd-click / select-all / invert.
- [ ] Action bar appearing on selection: add-tag, remove-tag, add-to-album, move-between-albums, change-author, set-rating, change-permissions, regenerate-derivatives, delete, export.
- [ ] Undo within 5 min via audit-entry reversal. (§25)
- [ ] Batch API backend (POST `/api/v1/photos/actions/batch-*` — see Phase 9 for the routes).

### 7.5 User + group admin

- [ ] User list + filter. (§25)
- [ ] User detail: profile, effective permissions, recent activity, tokens, sessions.
- [ ] Force-logout (revoke all sessions).
- [ ] Group editor: members, default groups, per-album ACL convenience.

### 7.6 Tag + comment admin

- [ ] Tag list: rename, merge (with image-count update), delete.
- [ ] Comment moderation queue from Phase 6.6.

### 7.7 Sync UI

- [ ] Admin form wrapping the sync CLI. (§24, §25)
- [ ] Dry-run preview.
- [ ] Live progress via polling or SSE.
- [ ] Log tail display during run.
- [ ] Cancel (graceful — current file completes, then stop).

### 7.8 Maintenance

- [ ] Clear response cache, Latte compile cache, prune orphan derivatives, recompute image counts, rebuild search index, check broken links, process DLQ. (§25)

### 7.9 Queue monitor

- [ ] Per-queue stats (depth, processed/min, avg time, oldest age, DLQ count). (§25)
- [ ] DLQ viewer with retry + drop actions.

### 7.10 Audit log viewer

- [ ] Filter by actor, event, target, date range. (§12, §25)
- [ ] CSV export.

### 7.11 Settings

- [ ] Grouped settings UI (general, storage, uploads, derivatives, email, security, privacy, search, advanced). (§25)
- [ ] Settings stored in `settings` table with JSON value.
- [ ] Per-setting tooltip + "requires worker restart" marker where applicable.

### 7.12 Tests

- [ ] Browser tests for every happy-path admin workflow.
- [ ] axe-core on every admin page.
- [ ] Keyboard-only test scripted across 5 core flows.
- [ ] Non-admin → 403 on every admin route.

### Phase 7 Definition of Done

- An admin can do everything needed to manage the gallery without touching SQL or CLI for routine work.
- Keyboard shortcuts documented and all working.
- Accessibility tests clean.

---

## Phase 8 — Plugin system

Make extensibility first-class. The core ships a minimal example plugin to prove the API.

### 8.1 Plugin infrastructure

- [ ] `PluginInterface`. (§7)
- [ ] `BasicPlugin` trait with no-op lifecycle defaults. (§7)
- [ ] `PluginLoader` — scans `plugins/*/*/composer.json` + installed Composer packages with `type: gallery-plugin`. (§7)
- [ ] `PluginRegistry` — tracks enabled/disabled state in DB; topologically sorts on dependency graph. (§7)
- [ ] `plugins` table migration: name, version, enabled, installed_at.
- [ ] `#[Subscribe]` attribute + scanner for auto-subscribing listeners. (§7)
- [ ] `InstallContext`, `UninstallContext`, `Context` helper classes. (§7)
- [ ] `PluginSettings` key-value service per-plugin.
- [ ] Plugin-shipped migrations applied on `install()` with namespaced version IDs. (§7)

### 8.2 Event catalog

- [ ] Implement every event class from plan §23.
- [ ] Wire each dispatch point in the core (most were stubbed in earlier phases; now make them real).
- [ ] `CancellableEventInterface` where relevant.
- [ ] `docs/events.md` generator: `php bin/gallery events:dump`.
- [ ] `php bin/gallery events:list [--filter=X]`.
- [ ] `php bin/gallery events:show {EventClass}` — payload schema + current subscribers.

### 8.3 Plugin admin

- [ ] Plugin list page (enable/disable toggle, version, author). (§25)
- [ ] Install via Composer package name (shells out to `composer require`).
- [ ] Uninstall (with data-drop confirmation).
- [ ] Per-plugin settings page — plugins declare a settings schema; admin UI auto-renders.

### 8.4 Example plugin

- [ ] `plugins/example/copyright/` — adds "© $author" to picture pages. (§7)
- [ ] Package published to Packagist (or example registry) under `example/gallery-copyright`.
- [ ] Tests cover the example plugin's event subscription + rendering.

### 8.5 Plugin asset pipeline

- [ ] Plugins have their own `vite.config.js`. (§7, §17)
- [ ] `{plugin_asset 'name', 'main.js'}` Latte tag resolves via plugin manifest.
- [ ] Caddy routes `/plugins/{name}/assets/*` to static files.

### 8.6 Tests

- [ ] Plugin discovery test (picks up `plugins/example/*/composer.json`).
- [ ] Install → enable → dispatch event → listener fires → disable → listener no longer fires.
- [ ] Uninstall with data-drop runs the `down` migrations.
- [ ] Plugin with a broken bootstrap fails loudly, doesn't crash the app.

### Phase 8 Definition of Done

- `php bin/gallery plugin:install example/gallery-copyright`, enable in admin, see the plugin's output in the gallery.
- Plugin API documented end-to-end in `docs/`.

---

## Phase 9 — JSON API v1

Public, versioned, documented. Powers the admin UI of future versions and any third-party client.

### 9.1 API infrastructure

- [ ] `/api/v1/*` route group. (§9, §22)
- [ ] `ApiAuthMiddleware` — session OR token. (§8, §22)
- [ ] `ApiThrottleMiddleware` — per-endpoint rate limits from plan §11. (§22)
- [ ] `JsonResponseMiddleware` — forces `Content-Type: application/json`, handles `no-store` etc. (§22)
- [ ] `JsonResponse` wrapper using `json_encode(JSON_THROW_ON_ERROR)`.
- [ ] Request-DTO base class + `fromRequest()` convention. (§22)
- [ ] Response-DTO base class + `toJson()` convention.
- [ ] Pagination helper — cursor-based. (§22)
- [ ] Filter + sort parser (`tags[in]=a,b`, `sort=-taken_at`). (§22)
- [ ] Sparse fieldset support (`fields=a,b,c`).
- [ ] `include` side-loading. (§22)
- [ ] `ProblemDetailsFactory` — RFC 7807. (§11, §22)

### 9.2 API token system

- [ ] `api_tokens` table already in initial schema (§16).
- [ ] `ApiTokenService` — create, verify, revoke. (§8)
- [ ] Token CRUD at `/account/tokens` (UI) + `/api/v1/me/tokens` (API).
- [ ] Scope enforcement (`read` / `write` / `admin`).

### 9.3 Endpoints

For each resource: `index`, `show`, `create`, `update`, `delete` where applicable.

- [ ] `/api/v1/albums` — full CRUD.
- [ ] `/api/v1/photos` — index + show + update + delete. Upload via a dedicated endpoint.
- [ ] `/api/v1/photos/upload` — multipart (link into Phase 4 pipeline).
- [ ] `/api/v1/photos/actions/batch-tag` / `batch-move` / `batch-delete`. (§22)
- [ ] `/api/v1/tags` — index + CRUD.
- [ ] `/api/v1/users` + `/api/v1/groups` — admin scope.
- [ ] `/api/v1/me` — profile + tokens + sessions.
- [ ] `/api/v1/search` — delegates to search service.
- [ ] `/api/v1/operations/{id}` — long-running operation status. (§22)

### 9.4 Long-running operations

- [ ] `Operation` domain + `OperationsRepository` + `operations` table migration.
- [ ] `StartOperationMessage` / `OperationFinished` events.
- [ ] Sync, reindex, and bulk-batch ops return 202 with `Location: /api/v1/operations/{id}`. (§22)
- [ ] Progress updates via `OperationsRepository::updateProgress`.

### 9.5 Webhooks out

- [ ] `webhook_subscriptions` table + CRUD.
- [ ] `webhook_deliveries` table.
- [ ] `WebhookSubscriptionListener` — listens for events on `webhook_subscriptions.event` and enqueues `DeliverWebhookMessage`. (§29 walkthrough 5)
- [ ] `DeliverWebhookMessage` + handler — signed HMAC-SHA256, retry policy, DLQ on exhaustion. (§22)
- [ ] `SsrfGuard` used on subscription URL on creation. (§11)
- [ ] Admin UI: manage subscriptions, view delivery history, manual retry.

### 9.6 OpenAPI

- [ ] `#[OpenApi\Operation]`, `#[OpenApi\Filter]`, `#[OpenApi\Sort]` attributes. (§22)
- [ ] `php bin/gallery openapi:dump > docs/api/v1/openapi.yaml`. (§22)
- [ ] Ship Scalar or Swagger UI at `/api/v1/docs`.
- [ ] CI check: regenerate spec, fail if it differs from the committed copy.

### 9.7 Contract tests

- [ ] Per-endpoint test asserting status, headers, and body validates against the committed schema. (§22)
- [ ] Breaking a schema → PR must update both impl and schema.

### 9.8 CORS

- [ ] `CorsMiddleware` with `ALLOWED_ORIGINS` config. (§11, §22)
- [ ] Credentialed routes enforce allowlist; public read routes permit `*`.

### Phase 9 Definition of Done

- `curl -H "Authorization: Bearer $TOKEN" https://.../api/v1/me` works.
- `openapi.yaml` committed and matches the implementation.
- Contract tests cover every endpoint.

---

## Phase 10 — Mail + notifications

### 10.1 Mailer

- [ ] Wire `symfony/mailer` with `MAIL_DSN` from `.env`. (§3)
- [ ] `Mailer` service wrapping it; PSR-3 logger attached.
- [ ] Mailpit integration for dev (docker-compose already runs it).

### 10.2 Templates

- [ ] `Piwigo\Mail\Message\WelcomeEmail`, `PasswordResetEmail`, `CommentNotification`, `UploadFailedNotification`, `AdminDigest`, `AccountDeletedConfirmation`.
- [ ] Latte templates under `templates/default/mail/` — HTML + plain-text alternates.
- [ ] Dev-only "email preview" admin page: lists templates; render with sample data.

### 10.3 Send-paths

- [ ] `UserCreatedEvent` → queue `SendEmailMessage(WelcomeEmail)`.
- [ ] `PasswordResetRequestedEvent` → queue `SendEmailMessage(PasswordResetEmail)`.
- [ ] `CommentCreatedEvent` → notify image author (opt-in per user).
- [ ] Admin digest: scheduled daily; summary of queue state + new comments + failed jobs. (§13)

### 10.4 Testing

- [ ] Integration tests: event fires → queued message → mailer transport records the email.
- [ ] Snapshot tests on rendered email bodies.
- [ ] No email content leaks the user's password, API token, or session ID.

### Phase 10 Definition of Done

- Registration triggers a welcome email (visible in Mailpit).
- Password reset works end-to-end via email.
- Comment notifications opt-in in user account settings.

---

## Phase 11 — Polish, perf, observability, privacy

Everything that was stubbed earlier gets its real implementation.

### 11.1 Caching

- [ ] Wire `symfony/cache` with APCu + Redis adapters. (§14)
- [ ] `ResponseCacheMiddleware` — store anonymous gallery pages keyed by URL + locale + theme + content_version. (§14)
- [ ] `ContentVersionBumper` — event listeners increment the version. (§14, §29 walkthrough 2)
- [ ] Cache-Control headers on every response class per plan §14 table.
- [ ] `ETag` + `If-None-Match` on gallery pages.
- [ ] `cache:warm --routes --permissions --top-albums=10`. (§14)
- [ ] `cache:clear`.

### 11.2 Observability — real

- [ ] Structured JSON Monolog output. (§12)
- [ ] Request-ID processor injects the ULID into every line. (§12)
- [ ] PII redaction processor. (§12, §27)
- [ ] OpenTelemetry SDK wired; OTLP exporter configured via env. (§12)
- [ ] Tracing: request, DB query, libvips op, external HTTP, cache get/put. (§12)
- [ ] Prometheus metrics endpoint `/metrics` with IP allowlist. (§12)
- [ ] `MetricsCollector` instrumented across hot paths.
- [ ] Sentry integration gated on `SENTRY_DSN`. (§12)
- [ ] `/readyz` probes DB + storage + Redis. (§12)
- [ ] `/version` returns SemVer + git SHA.
- [ ] `docs/monitoring/grafana-dashboard.json` + `alerts.yaml`. (§12)

### 11.3 Security hardening

- [ ] Full CSP with per-response nonces injected by Latte. (§11)
- [ ] `SsrfGuard` implementation + arch test: all outbound user-URL fetches go through it. (§11)
- [ ] Password-reset and login timing-attack normalization verified by test. (§11)
- [ ] Upload path: optional ClamAV integration (gated on env). (§11)
- [ ] Security audit: run `composer audit`, `bun audit`, zero open findings.
- [ ] `/.well-known/security.txt` dynamic route. (§11)
- [ ] Threat-model document at `docs/security/threat-model.md`. (§11)

### 11.4 Background job catalog — complete

- [ ] `SendEmailMessage` handler. (§13)
- [ ] `PurgeExpiredSessionsMessage` (daily). (§13)
- [ ] `PruneExpiredApiTokensMessage`.
- [ ] `PruneOrphanedDerivativesMessage`.
- [ ] `AuditLogPruneMessage`.
- [ ] `ReindexSearchMessage`.
- [ ] `DeliverWebhookMessage` (Phase 9 but catalog here for completeness).
- [ ] `SyncAlbumMessage` (Phase 4 upload sync CLI; message handler for async dispatch).
- [ ] `UpdateImageCountersMessage`.
- [ ] `DefaultSchedule::getSchedule()` registers every recurring message. (§13)

### 11.5 Storage — S3 backend

- [ ] `S3StorageBackend` using AWS SDK. (§15)
- [ ] `S3DerivativeStorage`. (§15)
- [ ] Presigned URLs for private originals. (§15)
- [ ] Integration tests against MinIO (docker-compose already runs it).
- [ ] Migration doc: how to move an existing install from local to S3.

### 11.6 Internationalization

- [ ] Wire `symfony/translation` with gettext loader. (§18)
- [ ] `translations:extract` CLI (scans PHP, Latte, JS). (§18)
- [ ] `translations:compile` CLI (`.po` → `.mo`). (§18)
- [ ] `translations:audit --locale=xx`. (§18)
- [ ] Initial `en.po` populated with every string in the UI.
- [ ] One additional locale fully translated (community pick — e.g. fr or de) as a proof of the pipeline.
- [ ] RTL test: render all templates with `dir="rtl"` and a mock Hebrew locale; snapshot passes.
- [ ] `IntlDateFormatter`, `NumberFormatter` wired into Latte filters. (§18)

### 11.7 Data privacy

- [ ] PII inventory doc at `docs/privacy/pii-inventory.md`; CI check keeps it in sync with schema. (§27)
- [ ] Retention jobs running on schedule (sessions, comment IPs, audit, webhook deliveries). (§27)
- [ ] `UserDataExport` flow — CLI + API + admin UI. (§27)
- [ ] `UserErasure` flow — soft-delete + scheduled hard-delete. (§27)
- [ ] Comment anonymization on user deletion (configurable). (§27)
- [ ] Cookie-policy banner + `CookieRegistry`. (§27)
- [ ] Privacy-policy template at `docs/privacy/policy-template.md`. (§27)
- [ ] `PRIVACY_STRIP_GPS_ON_UPLOAD` flag implementation. (§27)
- [ ] Takedown CLI + blocklist. (§27)

### 11.8 Performance

- [ ] `tests/Performance/` benchmark suite. (§10, §26)
- [ ] Nightly CI job runs benchmarks; > 20% regression fails the run. (§10)
- [ ] Benchmarks include: anonymous gallery index (cached + uncached), album, picture, derivative hit, derivative miss, API list, search.
- [ ] Dashboard of benchmark trend lines published.

### 11.9 Accessibility

- [ ] Full axe-core run against every theme template. (§17)
- [ ] Keyboard-only navigation test across public + admin.
- [ ] Screen-reader smoke test (NVDA or VoiceOver manual pass, documented).
- [ ] `prefers-reduced-motion` respected everywhere.
- [ ] `docs/accessibility.md` describes the project's WCAG stance.

### Phase 11 Definition of Done

- Every "we'll do it later" TODO closed.
- Benchmarks published and in budget.
- Observability stack runs in the dev docker-compose; metrics visible in a sample Grafana.
- i18n pipeline complete; adding a new locale is a 3-line change.
- Privacy workflows (export, erasure) exercised by tests.

---

## Phase 12 — v1.0 release

### 12.1 Release engineering

- [ ] `RELEASING.md` — step-by-step release procedure. (§20)
- [ ] Tag-triggered CI workflow: build + push Docker image + build source tarball + generate SBOM + sign + attach to GitHub Release. (§20)
- [ ] Cosign signing keys configured; public key published. (§20)
- [ ] GPG key for source tarball signing; public key published. (§20)
- [ ] Packagist registration. (§20)

### 12.2 Docs site

- [ ] Static site generator (mkdocs-material or similar). (§19, §20)
- [ ] Getting-started guide.
- [ ] Admin guide.
- [ ] Plugin development guide.
- [ ] Theme authoring guide.
- [ ] API reference (embeds OpenAPI spec).
- [ ] Events reference (embeds `docs/events.md`).
- [ ] ADR index.
- [ ] Operations runbooks (`docs/operations/runbooks/*`). (§12)

### 12.3 Release candidate

- [ ] Cut `v1.0.0-rc.1`.
- [ ] Dogfood on the project's own gallery for ≥ 30 days. (§14 plan, §20)
- [ ] Track regressions to exhaustion.
- [ ] Security advisory review (no outstanding issues).

### 12.4 v1.0.0

- [ ] Promote last RC to `v1.0.0`.
- [ ] Publish release notes on GitHub.
- [ ] Update README status banner from pre-alpha → stable.
- [ ] Announce on maintainer's chosen channels.
- [ ] Open issues for v1.1 roadmap.

### Phase 12 Definition of Done

- `v1.0.0` tag exists.
- Docker image at `:1.0.0`, `:1.0`, `:1`, `:latest` published.
- Composer package installable.
- Docs site live.
- Coverage + test floors have held for 14 consecutive days on main.
- Someone who's never used the project can install it and upload 100 photos within an hour using only the docs.

---

## Ongoing / cross-cutting

Work that doesn't live in a phase but runs continuously once the project is active.

### Architecture tests

- [ ] Every arch rule in plan §10 is active and green.
- [ ] New classes that violate a rule fail CI.
- [ ] When adding a new rule, a PR documents it in `tests/Arch/RULES.md`.

### Dependencies

- [ ] Renovate / Dependabot config accepts patch auto-merges after CI.
- [ ] Minor bumps: weekly triage.
- [ ] Major bumps: issue-tracked.
- [ ] Weekly `composer audit` + `bun audit` on all release branches.

### Translations

- [ ] Weblate (or Crowdin) project exists.
- [ ] PR-based sync of `.po` files.
- [ ] CI check: `msgfmt --check` on every `.po`.

### Security

- [ ] `SECURITY.md` kept current.
- [ ] GHSA monitored; advisories filed within SLA. (§20)
- [ ] Annual threat-model review. (§11)

### Performance

- [ ] Benchmark dashboard public.
- [ ] Nightly runs on dedicated bench host (not CI shared runners).
- [ ] Regressions triaged within a week.

### Documentation

- [ ] Every PR touching user-visible behavior updates `CHANGELOG.md` `Unreleased`.
- [ ] Every significant architectural change adds/updates an ADR. (§19)
- [ ] `docs/events.md` regenerated on every release. (§23)
- [ ] Tutorial content updated when UX changes.

### Community

- [ ] Weekly issue triage rotation among maintainers. (§20)
- [ ] Monthly roadmap review — milestones adjusted for reality.
- [ ] Stale-bot with generous timeouts (90d, with grace-period ping).
- [ ] Contributor Covenant enforcement; incident response documented.

---

## Deferred to v1.1+

Tasks explicitly acknowledged as *after* v1.0:

- [ ] Video support (§28).
- [ ] RAW support beyond embedded-preview (§28).
- [ ] 2FA / TOTP (§8).
- [ ] WebAuthn / passkeys (§8).
- [ ] Service worker / PWA (§17).
- [ ] Plugin marketplace / in-app install UI (§7 plugin admin).
- [ ] Multi-tenancy (§15).
- [ ] DB sharding (§26).
- [ ] Read-replicas full wiring (§4 / §26 — interface is ready, runtime switch isn't).

These are not on the v1.0 path; opening an issue is fine, but they don't block the release.

# Piwigo Rust Rewrite — Implementation Roadmap

Actionable task list for the full implementation described in [`RUST_REWRITE_PLAN.md`](./RUST_REWRITE_PLAN.md). Tasks are grouped into phases matching the plan's build order (§6–§13); each phase is gated by a Definition of Done.

> **Scope reminder:** this is a **greenfield** photo-gallery server. No Piwigo data migration, no `ws.php` compat, no `/i/...` URLs, no Smarty theme port, no PHP plugin loader. The value carried over is the domain knowledge, not the code. See plan §1 and §17 for the full "out of scope" list.

## How to use this document

- Each task is a checkbox. Check it when merged to `main`.
- Tasks reference their plan section in parentheses — e.g. `(§6.3)` points at `RUST_REWRITE_PLAN.md §6.3 Database Layer` for the design.
- A phase is **done** when all its tasks are checked *and* its Definition-of-Done criteria hold.
- Tasks within a phase can often run in parallel. Across phases: later phases generally depend on earlier ones; call-outs flag exceptions.
- "A task" is sized to land in a single PR. If a task won't fit in one PR, split it.
- Anything not on this list is out of scope for v1.0. Post-parity items live under "Modernization (post-v1.0)".

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
- [ ] Add `LICENSE` — GPL-2.0-or-later (matches upstream Piwigo). (§17)
- [ ] Add `README.md` with a one-paragraph description, status banner ("pre-alpha — do not use"), and links to the plan + this roadmap.
- [ ] Add `CONTRIBUTING.md` — dev setup, PR checklist, DCO sign-off.
- [ ] Add `CODE_OF_CONDUCT.md` — Contributor Covenant 2.1.
- [ ] Add `SECURITY.md` — reporting process, GPG key placeholder. (§8.4)
- [ ] Add `CHANGELOG.md` — keep-a-changelog format with an empty `Unreleased` section.
- [ ] Add `.github/ISSUE_TEMPLATE/` — bug, feature request, security.
- [ ] Add `.github/PULL_REQUEST_TEMPLATE.md` — Summary, Tests, Plan link, Changelog entry.
- [ ] Add `docs/adr/0000-template.md` — ADR template for architectural decisions.
- [ ] Seed `docs/adr/` with ADRs ratifying the plan's core choices: Axum (ADR-001), sqlx (ADR-002), Tera (ADR-003), libvips-rs (ADR-004), mlua (ADR-005), thiserror+anyhow (ADR-006). (§2)
- [ ] Configure branch protection on `main` — require PR, require CI green, require one reviewer.
- [ ] Configure GitHub Security Advisories.
- [ ] Add repo to Renovate / Dependabot with a config allowing auto-merge of patch bumps after CI.
- [ ] Set up GHCR / Docker Hub for eventual image publishing.

**Definition of Done:** an empty repo a contributor can fork, sign, and file an issue against. Nothing runs yet.

---

## Phase 1 — Foundation

The scaffolding every other phase rests on. Duration target: 6–8 weeks.

### 1.1 Cargo workspace + tooling

- [ ] Initialize Cargo workspace with all crates listed in plan §4: `gallery-core`, `gallery-db`, `gallery-image`, `gallery-metadata`, `gallery-search`, `gallery-plugins`, `gallery-auth`, `gallery-sync`, `gallery-mail`, and the top-level `gallery` binary. (§4)
- [ ] `rustfmt.toml` and `clippy.toml` for project conventions. (§6.1)
- [ ] `.gitignore` — `target/`, `_data/`, `local/config/*.toml`, `*.rs.bk`.
- [ ] `.editorconfig`.
- [ ] GitHub Actions CI workflow `.github/workflows/ci.yml`:
  - `cargo test` on push (MySQL + PostgreSQL via testcontainers)
  - `cargo clippy -- -D warnings`
  - `cargo fmt -- --check`
  - `cargo sqlx prepare --check`
- [ ] `cargo-deny` config for dependency auditing. (§6.1)
- [ ] `SQLX_OFFLINE=true` in CI; commit `.sqlx/` prepared query metadata.
- [ ] `Dockerfile` (multi-stage: builder + minimal runtime with libvips).
- [ ] `compose.yaml` for local dev: MySQL 8, PostgreSQL 17, Mailpit, MinIO.
- [ ] `cargo-watch` recipe in `justfile` or `Makefile` for hot-reload.

### 1.2 Configuration system

- [ ] Define `GalleryConfig` struct with domain sub-structs: `ServerConfig`, `DatabaseConfig`, `GalleryConfig`, `AlbumConfig`, `ImageConfig`, `DerivativeConfig`, `UploadConfig`, `SyncConfig`, `MailConfig`, `AuthConfig`, `UrlConfig`, `PluginConfig`. (§6.2)
- [ ] Implement 3-tier loading: code defaults (via `Default` + `#[serde(default)]`) → `local/config/config.toml` → DB `config` table. (§6.2)
- [ ] `gallery install` subcommand writes `local/config/config.toml` + `local/config/database.toml`. (§6.2)
- [ ] Expose config to handlers via `axum::extract::State<Arc<AppState>>`. (§6.2)
- [ ] Hot-reload on SIGHUP (Unix) or admin "reload config" action. (§6.2)
- [ ] Config validation on load: enum values, numeric ranges, non-empty strings where required. Log warnings and fall back to defaults.
- [ ] Unit tests for each config type: default values, TOML deserialization, DB override precedence.

### 1.3 Database layer (`gallery-db`)

- [ ] Define `DbPool` enum wrapping `sqlx::MySqlPool` and `sqlx::PgPool`. (§6.3)
- [ ] Typed fetch helpers: `query_one`, `query_opt`, `query_all`, `execute`. (§6.3)
- [ ] `last_insert_id()` / `affected_rows()` abstractions (MySQL vs PG `RETURNING`). (§6.3)
- [ ] `mass_inserts(table, columns, rows, batch_size)` — chunked batch insert honoring parameter limits (MySQL 65535, PG 32767), one transaction. (§6.3)
- [ ] `mass_updates(table, update_cols, where_cols, rows)` — CASE/WHEN on MySQL, `UPDATE FROM VALUES` on PG. (§6.3)
- [ ] `QueryBuilder` for dynamic WHERE clauses — `push`, `push_bind`, `push_bind_array`, `build`. Never uses `format!()` for values. (§14.2)
- [ ] `DbDialect` trait: `regex_operator`, `random_function`, `boolean_true/false`, `date_sub`, `upsert_prefix/suffix`, `concat_ws`, `limit_offset`. (§6.3)
- [ ] Initial migration `migrations/mysql/001_initial_schema.sql` + `migrations/postgres/001_initial_schema.sql` — greenfield schema per Appendix A, all FKs present from day one. No porting of Piwigo's 34-table layout.
- [ ] Seed data: root album, guest user, default access levels, settings defaults.
- [ ] `gallery install` runs `sqlx::migrate!()`; `gallery upgrade` runs pending migrations. (§6.3)
- [ ] Connection pool config: `max_connections=10`, `min=2`, `acquire_timeout=5s`, `idle_timeout=600s`, `max_lifetime=1800s` — all tunable. (§6.3)
- [ ] Query logging behind `RUST_LOG=piwigo_db=debug`. (§6.3)
- [ ] Integration tests (testcontainers) for MySQL + PostgreSQL: connection, migration, `mass_inserts`/`mass_updates` round-trip, `QueryBuilder` output.

### 1.4 Core domain types (`gallery-core`)

- [ ] `AccessLevel` enum (`Free`, `Guest`, `Classic`, `Administrator`, `Webmaster`) with `allows()` comparator. (§6.4)
- [ ] `UserStatus` enum with `sqlx::Type` derive for the `user_infos_status` DB enum; `is_admin_or_above()` helper. (§6.4)
- [ ] `CategoryStatus` enum: `Public`, `Private`.
- [ ] `Image` struct with `sqlx::FromRow` mapping the `images` table. (§6.4, Appendix A.14)
- [ ] `Category` struct with `is_physical()`, `is_virtual()`, `parent_ids()` helpers. (§6.4, Appendix A.3)
- [ ] `User` struct from `users` + `user_infos` JOIN; `password` field marked `#[serde(skip_serializing)]`. (§6.4)
- [ ] `DerivativeType` enum with 9 standard variants + `Custom(CustomDerivativeParams)`. `code()` / `from_code()` mapping to 2-char codes (`sq`, `th`, `2s`, `xs`, `sm`, `me`, `la`, `xl`, `xx`, `cu`). (§6.4)
- [ ] `Tag`, `Comment`, `Rate` structs matching their schemas.
- [ ] `GalleryError` type hierarchy via `thiserror` (see ADR-006 for full variant list).
- [ ] `Serialize` + `Deserialize` on all types where appropriate.

### 1.5 Auth + sessions (`gallery-auth`)

- [ ] `GallerySessionStore` implementing `tower_sessions::SessionStore`. Session ID format: `{ipv4_hex_4bytes}{random}` matching PHP IP-binding. (§6.5.1)
- [ ] `create`/`load`/`save`/`delete` against `sessions` table; session data as JSON in `data_json`. (§6.5.1)
- [ ] Session GC: probabilistic (1% of requests) + `piwigo maintenance sessions` CLI. (§6.5.1)
- [ ] `AuthenticatedUser` extractor — tries session → `?auth=` / `Authorization: Bearer` → `X-Remote-User` → guest. (§6.5.2)
- [ ] `AdminUser`, `WebmasterUser` extractors returning 403 on insufficient level. (§6.5.2)
- [ ] `PermissionCache` backed by `moka::sync::Cache<u32, CachedPermissions>` (TTL 5 min). (§6.5.3)
- [ ] `calculate_permissions(user_id)` — reproduces PHP algorithm (private cats MINUS user_access MINUS group_access PLUS invisible for non-admins). (§6.5.3)
- [ ] `invalidate_user_cache(user_id)` (per-user) + `invalidate_all_caches()` (global). (§6.5.3)
- [ ] `build_permission_condition(&CachedPermissions) -> (String, Vec<i32>)` for SQL predicate generation. (§6.5.3, §14.3)
- [ ] `POST /api/v1/auth/login` → argon2id verify, session regenerate, insert `user_id`. (§6.5.4)
- [ ] `POST /identification?logout` → destroy session, clear cookies. (§6.5.4)
- [ ] Remember-me: `{user_id}-{timestamp}-{hmac_sha1}` cookie, `auto_login()` middleware, timing-safe HMAC compare via `constant_time_eq`. (§6.5.4, §8.4)
- [ ] CSRF token: `HMAC-SHA256(session_id, secret_key)`, exposed in `GET /api/v1/auth/me` response body. (§6.5.4)
- [ ] Rate limit login: 10 attempts/IP/minute via `tower_governor`. (§6.5.4)
- [ ] Integration tests: login/logout, API-key auth, remember-me, permission cache correctness, IP-change rejection.

### 1.6 AppState + server bootstrap

- [ ] `AppState` struct holding `db`, `config: Arc<RwLock<_>>`, `template: Arc<Tera>`, `plugins: Arc<EventBus>`, `permissions: Arc<PermissionCache>`, `image_params: Arc<RwLock<ImageStdParams>>`, `i18n: Arc<I18n>`. (§6.6)
- [ ] Axum router with middleware stack: `TraceLayer` → `CompressionLayer` → `MaintenanceModeLayer` → `SessionLayer` → `UserLayer` → `LanguageLayer` → `CsrfLayer`. (§6.6)
- [ ] Graceful shutdown on SIGTERM/SIGINT — drain in-flight requests, close DB pool. (§6.6)
- [ ] `GET /health` returns 200 for load balancers. (§6.6)
- [ ] Startup checks: DB connectivity, writable `_data/`, libvips version probe. (§6.6)
- [ ] `piwigo serve` subcommand via `clap`.

### 1.7 i18n (`src/i18n.rs`)

- [ ] PHP `$lang[...]` → JSON export script (one-time), run against ~150 language directories × 2 files. (§6.7)
- [ ] `I18n` struct holding `HashMap<String, LanguageStrings>`. (§6.7)
- [ ] `l10n(lang, key)`, `l10n_dec(lang, s, p, n)`, `l10n_args(lang, key, args)`. (§6.7)
- [ ] All languages loaded on startup (~5MB memory). (§6.7)
- [ ] Tera filters: `{{ 'key' | translate }}`, `{{ n | translate_dec(s='singular', p='plural') }}`. (§6.7)
- [ ] `LanguageLayer` middleware: user preference → `Accept-Language` → config default. (§6.7)
- [ ] Unit tests: English + French lookup, plural forms (0/1/many), missing-key fallback.

### 1.8 Testing harness

- [ ] `tests/common/` helpers: `start_test_db()` spins MySQL + PG via testcontainers, seeds fixtures.
- [ ] `tests/fixtures/sql/basic_gallery.sql` — baseline fixture set.
- [ ] Fixture images in `tests/fixtures/photos/` (at least 1 JPEG, 1 PNG, 1 WebP, 1 GIF, 1 with EXIF, 1 with IPTC).
- [ ] First smoke test: `GET /health` returns 200.
- [ ] Criterion benchmark skeleton in `benches/`.

### Phase 1 Definition of Done

- `cargo build --release` succeeds.
- `gallery install` creates `local/config/*.toml` and applies migrations on a fresh DB.
- `piwigo serve` starts the server; `/health` returns 200.
- Login / logout / remember-me integration tests pass against both MySQL and PostgreSQL.
- Permission cache returns correct forbidden-category lists for the fixture dataset.
- CI green on both dialects.

---

## Phase 2 — Core read paths

A working gallery users can browse. No uploads, no admin, no write operations. Duration target: 4–6 weeks.

### 2.1 URL routing + section dispatch

- [ ] `GallerySection` enum: `Home`, `Category`, `Tags`, `Search`, `Favorites`, `MostVisited`, `BestRated`, `RecentPics`, `RecentCats`, `List`. (§7.1)
- [ ] URL tokenizer: split path info into tokens.
- [ ] Two-phase dispatch mirroring PHP `section_init.php`: `parse_section_url(tokens)` + `parse_well_known_params(remaining)`. (§7.1)
- [ ] All 3 URL style variants: categories (`id`, `id-name`), pictures (`id`, `id-file`, `file`), tags (`id-tag`, `id`, `tag`) driven by `config.url.*_style`. (§7.1, Appendix F)
- [ ] Category resolution cascade: numeric → `{id}-{slug}` (301 on slug change) → `categories.permalink` → `old_permalinks` (301) → 404. (§7.1)
- [ ] Tag resolution with AND/OR combination per `tag_mode_and`. (§7.1)
- [ ] Chronology token parser: `created-monthly-2026-04` → `ChronologyParams`. (§7.1)
- [ ] Pagination tokens: `start-N`, `startcat-N`. (§7.1)
- [ ] URL generators: `make_index_url`, `make_picture_url`, etc. (§7.1)
- [ ] Canonical URL `<link>` tag emitted on all pages.
- [ ] `PicturePageContext` carries section context for prev/next. (§7.1)

### 2.2 Gallery index handler

- [ ] Category image listing query with `category_id != ALL($forbidden) AND level <= ?`. (§7.2)
- [ ] Persistent query cache (moka, keyed by MD5 of SQL + user cache key). (§7.2)
- [ ] `ImageOrder` enum with 10 whitelisted variants (Date*, FileName, Rating, Visits, Random, Rank); `to_sql()` returns the ORDER BY string. Never user-supplied. (§7.2)
- [ ] Sub-category query using `user_cache_categories` join for counts + representative. (§7.2)
- [ ] Representative-image selection cascade: `categories.representative_picture_id` → `user_cache_categories.user_representative_picture_id` → random (cached). (§7.2)
- [ ] Flat view: fetch all descendants via `uppercats LIKE '...,%'`, union images. (§7.2)
- [ ] Chronology (calendar) queries: 3 levels (year/month/day) via `EXTRACT`. (§7.2)
- [ ] Breadcrumb builder from `uppercats` string. (§7.2)
- [ ] Template variables: `TITLE`, `items`, `categories`, `BREADCRUMB`, `START`, `NB_IMAGES`, `derivative_params`, `navbar`. (§7.2)
- [ ] Hooks (stubbed until Phase 6; dispatch points must be real): `loc_begin_index`, `loc_end_index`, `loc_begin_index_category_thumbnails_query` (C), `loc_end_index_thumbnails` (C). (§7.2, §11.1)

### 2.3 Picture detail handler

- [ ] Fetch image metadata + category membership + approved comments. (§7.3)
- [ ] Hit counter: `UPDATE images SET hit = hit + 1`, rate-limited to one per session per image, gated by `conf.count_views`. (§7.3)
- [ ] Privacy check: `image.level <= user.level`. (§7.3)
- [ ] Navigation with bounded queries (no full-list fetch): `WHERE (order_fields) < (current) ORDER BY ... DESC LIMIT 1` for prev, mirror for next. (§7.3)
- [ ] Download link: `GET /action.php?id=&part=e&download` with `Content-Disposition: attachment` (sanitized filename). (§7.3)
- [ ] Alternative-format downloads when `enable_formats=true`, listed from `image_format`. (§7.3)
- [ ] Related tags + slideshow JSON data. (§7.3)
- [ ] Hooks: `loc_begin_picture`, `loc_end_picture`, `allow_increment_element_hit_count` (C), `picture_pictures_data` (C). (§7.3, §11.1)

### 2.4 Derivative (thumbnail) serving

- [ ] URL parser: `/i.php?/path/to/photo-sq.jpg` → source path + derivative type. (§7.4)
- [ ] Custom derivative parser: `th_cx200y150` → `{width: 200, height: 150, crop: true}`. (§7.4)
- [ ] Cache check: `stat(derivative).mtime` vs `stat(source).mtime` AND `params.last_modified`. (§7.4)
- [ ] Cache hit: `Last-Modified`, `Expires: +10d`, `ETag`, 304 on `If-Modified-Since`. (§7.4)
- [ ] Cache miss: queue generation (Phase 3 pipeline); return 202 or serve synchronously depending on config.
- [ ] Stream via `tower_http::services::ServeFile`. (§7.4)
- [ ] Rate limit custom derivatives: 1 new per 5s per IP. (§7.4)

### 2.5 Search handler (read-only)

- [ ] Tokenizer: split into `Scope` variants (`FreeText`, `Tag`, `Category`, `Author`, `DateRange`, `NumericRange`). (§7.5)
- [ ] Parameterized SQL builder from scopes (via `QueryBuilder`). (§7.5)
- [ ] Save search to `search` table (JSON rules) and return `search_id`. (§7.5)
- [ ] Quick search (`qsearch.php`): tag/category autocomplete. (§7.5)
- [ ] Permission post-filter on results. (§7.5)
- [ ] Hook stubs: `qsearch_pre`, `qsearch_get_scopes`, `qsearch_expression_parsed`, `qsearch_results`. (§11.1)

### 2.6 Feeds

- [ ] RSS 2.0 via `rss` crate. (§7.6)
- [ ] Atom via `atom_syndication`. (§7.6)
- [ ] Feed variants: latest photos, per-user digest, per-category. (§7.6)
- [ ] `?auth_key=` auth for private feeds. (§7.6)

### 2.7 Additional read pages

- [ ] Tags listing — all tags with counts. (§7.7)
- [ ] Comments page — approved comments across gallery. (§7.7)
- [ ] Random image redirect. (§7.7)
- [ ] Favorites listing (authenticated users). (§7.7)
- [ ] Most visited / best rated / recent pics / recent cats — pre-built permission-filtered queries. (§7.7)

### 2.8 Tests

- [ ] Integration: category listing honors `forbidden_categories`.
- [ ] Integration: picture nav prev/next across custom ORDER BY.
- [ ] HTTP: 301 on slug change, 301 on old-permalink lookup.
- [ ] HTTP: 403 for guest on private album.
- [ ] HTTP: 304 on derivative `If-Modified-Since`.
- [ ] Property test: no search query string panics or leaks forbidden images.

### Phase 2 Definition of Done

- Anonymous visitor can browse top-level albums, open an album, view a picture, navigate prev/next, view by tag/calendar, search with scopes.
- Feeds serve valid RSS + Atom.
- Derivative cache hits return 304 correctly; misses queue generation or 404 gracefully (Phase 3 fills the gap).
- Permission filtering proven across fixture galleries with private albums.

---

## Phase 3 — Image pipeline

Full image processing — derivative generation, upload pipeline, metadata extraction. Duration target: 4–6 weeks.

### 3.1 libvips backend (`gallery-image`)

- [ ] `ImageBackend` trait: `load`, `width`, `height`, `rotate`, `crop`, `resize`, `sharpen`, `compose`, `strip_metadata`, `set_quality`, `write`. (§8.1)
- [ ] `VipsImage` implementation: Lanczos resize, COI-aware crop, `vips_rot()` for 90° + `vips_similarity()` for arbitrary, `vips_sharpen()` with configurable sigma, `vips_composite2(OVER)` with opacity, `vips_autorot()` + clear EXIF, progressive JPEG with 4:2:2 chroma subsampling. (§8.1)
- [ ] `vips_cache_set_max_mem()` configured on startup.
- [ ] Format detection via `infer` crate (magic bytes, not extension). (§8.1)
- [ ] Animated WebP detection (VP8X + ANIM chunks). (§8.1)
- [ ] Animated WebP quality capped at 70 (PHP parity). (§8.1)
- [ ] Unit tests with golden fixture images per format.

### 3.2 Derivative params + sizing

- [ ] `SizingParams` struct: `max_width`, `max_height`, `max_crop`. (§8.2)
- [ ] `ImageRect` + COI crop algorithm — matches PHP values bit-for-bit for the fixture set. (§8.2)
- [ ] `DerivativeParams` with `hash()` method for cache keys. (§8.2)
- [ ] `ImageStdParams` loaded from DB config on startup; refreshed on admin change. (§8.2)
- [ ] 9 standard sizes defined as `DerivativeType` variants with exact PHP dimensions. (§6.4)
- [ ] Cache invalidation: setting `params.last_modified = now` invalidates all older derivatives. (§8.2)

### 3.3 Generation pipeline

- [ ] `generate_derivative(source, type, params, output)` pipeline: load → rotate (EXIF) → crop (COI) → resize → sharpen → watermark → strip metadata → set quality → write. (§8.3)
- [ ] Atomic writes: temp file + `std::fs::rename`. (§8.3, R-15)
- [ ] Per-path lock map to serialize concurrent requests for the same derivative (prevents R-15 corruption). (§R-15)
- [ ] `tokio::sync::Semaphore` caps concurrent generations (default: CPU count). (§8.3)
- [ ] `make_derivative_url(source, type)` matches PHP naming exactly — existing caches compatible. (§14.4)
- [ ] `GET /admin/maintenance?action=generate_derivatives` triggers background missing-derivative scan. (§8.3)

### 3.4 Watermark

- [ ] `WatermarkParams`: file path, `min_output_size`, x/y position %, xrepeat/yrepeat, opacity %. (§8.4)
- [ ] Load watermark once into `Arc<VipsImage>` shared across handlers. (§8.4)
- [ ] Scale watermark to fit if output < watermark dims. (§8.4)
- [ ] Tiled positioning when `xrepeat > 0`. (§8.4)
- [ ] Alpha-premultiplied composite. (§8.4)

### 3.5 Metadata extraction (`gallery-metadata`)

- [ ] `extract_metadata(path, config) -> ImageMetadata`. (§8.5)
- [ ] Dimensions + filesize via libvips (fast header-only read).
- [ ] EXIF via `kamadak-exif`: `date_creation`, camera make/model, GPS, orientation. (§8.5)
- [ ] IPTC via `rexiv2`: title, description, author, keywords. (§8.5)
- [ ] Character-encoding detection for IPTC: UTF-8 → ISO-8859-1 fallback. (§8.5)
- [ ] `use_exif_mapping` / `use_iptc_mapping` honored. (§8.5)
- [ ] Exhaustive date-format parser (30+ EXIF variants); log warnings on unparseable. (§R-17)
- [ ] DMS → decimal GPS conversion. (§8.5)
- [ ] IPTC keywords → `Vec<String>` tag names. (§8.5)
- [ ] Filesize-unchanged short-circuit: if `db.filesize == fs.filesize`, skip extraction (matches PHP). (§8.5, §5.6.5)

### 3.6 Upload pipeline

- [ ] `POST /admin/photos/upload` multipart handler. (§8.6)
- [ ] File-type validation: extension allowlist + `infer` magic bytes. (§8.6, §8.4)
- [ ] MD5 checksum + duplicate detection (`images.md5sum`). (§8.6)
- [ ] Destination path: `_data/upload/{YYYY}/{MM}/{DD}/{timestamp}-{random}.{ext}`. (§8.6)
- [ ] `tokio::io::copy` async write. (§8.6)
- [ ] Trigger `upload_file` (C) hook for format-specific handlers (PDF, HEIC, video). Ship built-ins for HEIC→JPEG (libvips) and PDF→PNG (`poppler-rs`). (§R-19)
- [ ] Optional original resize if dims > config limits. (§8.6)
- [ ] EXIF auto-rotation. (§8.6)
- [ ] Insert into `images` + `image_category` + extract metadata in one transaction.
- [ ] Resumable upload via tus.io protocol (`POST /api/v1/uploads`, `PATCH`, `HEAD`, `DELETE`). Staging at `var/uploads/tus/{upload_id}/`. Authenticated via session cookie or API bearer token. (§8.6)

### 3.7 Tests

- [ ] Golden-image tests: each fixture image × each standard size → expected libvips hash matches committed value.
- [ ] Integration: upload JPEG → DB row + file + derivative queued.
- [ ] Integration: duplicate upload → linked to existing image.
- [ ] Integration: chunked upload (3 chunks, in and out of order) → concatenated correctly.
- [ ] Integration: EXIF-rotated JPEG → stored upright.
- [ ] HTTP: derivative cache hit vs miss, 304 flow.
- [ ] HTTP: signed URL tampering → 403.
- [ ] Concurrent derivative requests for same path → single generation (per-path lock). (§R-15)

### Phase 3 Definition of Done

- Admin uploads a photo via web form; DB row created, derivatives generated, metadata extracted.
- All 9 standard sizes served correctly for the fixture set.
- libvips memory bounded under a 100 MB JPEG upload (load test).
- Golden-image tests stable across every fixture.

---

## Phase 4 — Write paths + admin

Full REST API with write operations + complete admin panel. Duration target: 8–12 weeks.

### 4.1 REST infrastructure

- [ ] `#[Route]` attribute scanner + build-time route-table generator.
- [ ] Request DTO convention (`Path<T>` / `Query<T>` / `Json<T>` / `Form<T>` with `Deserialize + Validate`).
- [ ] Response DTO convention (`Result<Json<T>, ApiError>`, RFC 7807 errors as `application/problem+json`).
- [ ] Cursor pagination helper: `Page<T> { data, next_cursor }` with signed opaque cursors.
- [ ] Filter + sort grammar parser: `?tags[in]=a,b&min_rating=3&sort=-taken_at`.
- [ ] Sparse fieldsets: `?fields=id,title,derivatives`.
- [ ] Include side-loading: `?include=author,albums`.
- [ ] `ApiAuthMiddleware` — session cookie OR bearer token.
- [ ] `ApiThrottleMiddleware` — per-endpoint rate budgets.
- [ ] `#[RequiresLevel(...)]` attribute.
- [ ] `#[OpenApiOperation]` metadata; `gallery openapi:dump` CLI.
- [ ] Scalar UI at `/api/v1/docs`; OpenAPI JSON at `/api/v1/openapi.json`.
- [ ] CORS middleware (`ALLOWED_ORIGINS` config).

No `MethodRegistry` / `pwg.*` name dispatch / XML-RPC / PHP-serialize encoder. Contract lives in `#[Route]` handlers + OpenAPI.

### 4.2 Endpoint implementation

All endpoints are catalogued in plan Appendix B. Implementation order, grouped by resource boundary (each group = one PR-sized unit):

- [ ] **Auth + tokens** (Appendix B.1): login, logout, `/me`, password-reset flow, session CRUD, API token CRUD.
- [ ] **Albums** (Appendix B.2): tree reads (`index`/`show`/`children`/`descendants`/`images`/`image_ids`), CRUD, move (rebuilds materialized path), cover, reorder, permissions (show/replace), orphans.
- [ ] **Photos — reads** (Appendix B.3): index with cursor pagination + filters, show with EXIF+tags+albums+derivative URLs, duplicate lookup, comments, ratings, favorites toggle.
- [ ] **Photos — uploads** (Appendix B.3): single-shot multipart; tus.io resumable (`/api/v1/uploads`). Quota-debited on accept. `IngestUploadMessage` enqueued on completion.
- [ ] **Photos — writes** (Appendix B.3): PATCH update (tags/albums replace wholesale), hard delete, regenerate derivatives, sync metadata, batch operation.
- [ ] **Tags** (Appendix B.4): CRUD + merge; deleted slugs → 301 redirect.
- [ ] **Search** (Appendix B.5): query, suggest, saved searches.
- [ ] **Users + groups** (Appendix B.6): admin CRUD, force-logout, membership replace, merge.
- [ ] **Comments** (Appendix B.7): moderation queue, approve, reject, delete.
- [ ] **Operations** (Appendix B.8): show, SSE events, cancel, admin list.
- [ ] **Sync** (Appendix B.9): start (returns 202 + operation UUID), status, cancel.
- [ ] **Admin diagnostics** (Appendix B.10): stats, storage, queue monitor + DLQ retry/drop, audit log + CSV export, maintenance triggers.
- [ ] **Settings + plugins + themes** (Appendix B.11): settings get/patch, plugin activate/deactivate/uninstall, theme set-default.
- [ ] **Webhooks** (Appendix B.12): subscription CRUD, delivery history, retry; SSRF-guard on target URL.
- [ ] **Feeds + health** (Appendix B.13, B.14): Atom/RSS (signed-token-gated), sitemap, `/healthz`, `/readyz`, `/version`, `/metrics`.

### 4.2.X Cross-cutting acceptance criteria

- [ ] Every endpoint has a contract test: status + schema validation + auth boundary (401/403).
- [ ] Every mutation endpoint validates CSRF for session clients; bearer-token clients exempt.
- [ ] Every write emits at least one event from Appendix D.
- [ ] Every read applies ACL filtering at query time, not post-hoc.
- [ ] Operations > 2s wall-clock return `202 + Operation`; cancellation honored ≤ 5s.

### 4.10 Admin panel — infrastructure

- [ ] Admin base template (`admin/base.html`): sidebar, breadcrumb, flash messages, CSRF token. (§9.3.1)
- [ ] Admin-auth middleware redirects to login when not Administrator. (§9.3.1)
- [ ] `TabSheet` struct for multi-tab pages. (§9.3.1)
- [ ] Flash messages via one-shot session storage.

### 4.11 Admin panel — high-priority pages

- [ ] Dashboard (`/admin`): pending comments, orphans, updates, activity sparkline, storage breakdown, gallery stats, `DashboardPageRenderedEvent` for plugin widgets. (§9.3.2)
- [ ] Album management (`/admin/albums`): tree view, CRUD, drag-drop reorder, status/visibility icons, bulk toggles. (§9.3.2)
- [ ] Album edit — 4 tabs: properties, sort (drag-drop images), permissions (dual-listbox user + group), notification. (§9.3.2)
- [ ] Photo upload (`/admin/photos/upload`): drag-drop + tus resumable upload (`tus-js-client`), progress bars, album selector, min-level selector. (§9.3.2)
- [ ] Photo edit — 3 tabs: properties, COI (interactive crop → 4-char `coi`), formats. (§9.3.2)
- [ ] Batch manager (`/admin/photos`): global + unit modes, 10 prefilters, 15 actions, filter state in session, events `BatchManagerPrefiltersEvent` / `BatchManagerFiltersEvent` / `BatchManagerActionAppliedEvent`. (§9.3.2)
- [ ] Configuration (5 sub-sections: main, display, sizes, watermark, comments, defaults). (§9.3.2)
- [ ] User management (`/admin/users`) + permissions dual-listbox. (§9.3.2)
- [ ] Group management + permissions dual-listbox. (§9.3.2)

### 4.12 Admin panel — medium-priority pages

- [ ] Sync page (`/admin/sync`) with SSE progress — full implementation lands in Phase 5; admin page UI scaffolded here. (§9.3.3)
- [ ] Maintenance (`/admin/maintenance`): lock/unlock, purge history, purge sessions, rebuild DB cache, delete/generate derivative sizes, repair tables. (§9.3.3)
- [ ] Tags admin: rename, merge, delete, add, delete orphans. (§9.3.3)
- [ ] Comments moderation: validate / reject / select all / paginate. (§9.3.3)
- [ ] History viewer: date range, type, IP, image, user filters; pagination; summary refresh. (§9.3.3)
- [ ] Stats page with Chart.js: 72h hourly / 90d daily / 60mo monthly / year-over-year. (§9.3.3)
- [ ] Rating admin: aggregates + per-image rater detail. (§9.3.3)

### 4.13 Admin panel — lower-priority pages

- [ ] Plugins admin (`installed`, `new`, `update` tabs). (§9.3.4)
- [ ] Themes admin. (§9.3.4)
- [ ] Languages admin. (§9.3.4)
- [ ] Permalinks + old_permalinks admin. (§9.3.4)
- [ ] Photo formats admin. (§9.3.4)
- [ ] Menubar block-ordering form (store as JSON, not PHP serialized). (§9.3.4)
- [ ] Notification by mail admin (param/subscribe/send modes). (§9.3.4)
- [ ] FTP import info page (likely deprecate — sync replaces it). (§9.3.4)
- [ ] Updates admin (core + extensions tabs). (§9.3.4)

### 4.14 User-facing write operations

- [ ] Comment submission (`POST /comments`): ephemeral CSRF, spam/email/collision/flood/plugin checks, insert with `anonymous_id` = last 3 IP octets, auto-validate for admins, `user_comment_validation` (N) hook, admin notification email. (§9.4.1)
- [ ] User registration (`POST /api/v1/auth/register`): username + email uniqueness (case-insensitive), argon2id hashing, default user preferences, auto-login into new session, welcome email optional. (§9.4.2)
- [ ] Profile update (`POST /profile`): guest blocked, current-password required for email/password changes, `mass_updates` on `users` + `user_infos`, activity log. (§9.4.3)
- [ ] Image rating: whitelist against `conf.rate_items`, Bayesian `rating_score` recompute, `update_rating_score` (C) hook. (§9.4.4)
- [ ] Favorites add/remove/remove_all; `check_user_favorites` purges inaccessible. (§9.4.5)
- [ ] Caddie add/remove/empty/view. (§9.4.6)

### 4.15 Email (`gallery-mail`)

- [ ] `PiwigoMailer` wrapping `lettre::AsyncSmtpTransport<Tokio1Executor>`. (§9.5.2)
- [ ] Config keys: `smtp_host`, `smtp_user`, `smtp_password`, `smtp_secure` (ssl/tls), `mail_sender_name`, `mail_sender_email`, `mail_allow_html`, `mail_theme`. (§9.5.1)
- [ ] Render HTML + text templates (Tera); inline CSS via `css-inline` crate (Emogrifier equivalent). (§9.5.2)
- [ ] `before_send_mail` (C) hook can cancel or modify. (§9.5.2)
- [ ] `conf.debug_mail` writes copies to `_data/tmp/mail_{ts}.eml`. (§9.5.2)
- [ ] Mail templates: `header`, `footer`, `notification_by_mail`, `notification_admin`, `cat_group_info`, plus clear/dark themes. (§9.5.3)
- [ ] Notification send paths: new comment (validated/pending), new user, password reset, welcome, digest, album notification. (§9.5.4)
- [ ] Digest system: `user_mail_notification(user_id, check_key, enabled, last_send)`; `custom_notification_query()` counts new content per user with permission filtering; timeout-aware dispatch loop. (§9.5.5)
- [ ] Subscribe/unsubscribe endpoints authenticated by `check_key`, no login required. (§9.5.5)

### 4.16 OpenAPI / contract tests

- [ ] Generate OpenAPI spec from `MethodRegistry` (one entry per API method).
- [ ] Commit `docs/api/openapi.yaml`.
- [ ] CI check: regenerate and diff against committed copy.
- [ ] Per-endpoint contract test: status, headers, body validate against schema.

### 4.17 Tests

- [ ] Every one of 84 API methods has at least one integration test: happy path + auth boundary + missing param (1002) + invalid type (1003) + array-param handling + CSRF where required.
- [ ] Backward-compat: response JSON matches PHP field names/nesting/types for critical methods (documented in §15 backward-compat test list).
- [ ] Browser test (Playwright): admin creates album → uploads photo → tags via batch manager → moderates a comment → sends a notification.

### Phase 4 Definition of Done

- All 84 API methods implemented with contract tests passing.
- OpenAPI spec committed and matches the implementation.
- Admin can run the gallery end-to-end without touching the DB or CLI for routine work.
- Backward-compat tests prove API response format parity with PHP for the methods listed in §15.
- Email (welcome, password reset, notifications) works against Mailpit in docker-compose.

---

## Phase 5 — Filesystem sync

Full 3-phase sync with streaming progress, profiling, and optional Windows MFT reader. Duration target: 4–6 weeks.

### 5.1 Orchestrator

- [ ] `SyncJob` + `SyncOptions` (`metadata`, `metadata_only_new`, `formats`, `simulate`, `batch_size`). (§10.1)
- [ ] `SyncEvent` SSE stream enum: `PhaseStart`, `Progress`, `PhaseComplete`, `Error`, `Complete`. (§10.1)
- [ ] `POST /admin/sync/start` → `job_id` + background task. (§10.1)
- [ ] `GET /admin/sync/events/{job_id}` → SSE. (§10.1)
- [ ] `GET /admin/sync/status/{job_id}` → poll for non-SSE clients. (§10.1)
- [ ] Concurrent-sync lock via `Arc<Mutex<Option<SyncHandle>>>`. (§10.1)
- [ ] `sync_jobs` table migration to track incomplete jobs. (§5.6.1)

### 5.2 Phase 1 — directory sync

- [ ] Load DB categories (`id`, `dir`, `uppercats`). (§10.2)
- [ ] Compute `fulldir` for each; diff against filesystem scan. (§10.2)
- [ ] New dirs: parent resolution by longest-path prefix, inherit `status`/`visible`/`commentable`, inherit permissions when `inheritance_by_default=true`, `mass_inserts` within one transaction. (§10.2, §5.6.1)
- [ ] Deleted dirs: cascade via `delete_categories(ids)` (image_category links, derivatives, access records). (§10.2)
- [ ] Recompute `uppercats` + `global_rank` for the affected subtree, with null-check for orphaned parents (fix the PHP vulnerability). (§5.6.4)

### 5.3 Phase 2 — file sync

- [ ] Load DB images for site categories. (§10.3)
- [ ] Scan filesystem for image files; detect `pwg_representative/` + `pwg_format/` subdirs. (§10.3)
- [ ] Parallel MD5 via `rayon::par_iter`. (§10.3)
- [ ] Dimensions via libvips header-only access mode. (§10.3)
- [ ] `mass_inserts` for `images` + `image_category` + `image_format` in one transaction per chunk. (§5.6.1)
- [ ] Deleted files: `delete_elements(ids)` cascading. (§10.3)
- [ ] Format change detection per existing image. (§10.3)

### 5.4 Scanners

- [ ] `Scanner` trait: `scan_directories`, `scan_files`. (§10.4.1)
- [ ] `WalkdirScanner` (all platforms): skip `.git`, `node_modules`, `pwg_high`, `pwg_representative`, `pwg_format`, `thumbnail`, `_data`; honor `file_exclude_pattern`; no symlink following. (§10.4.1, §8.4)
- [ ] Per-directory cache of representative + format lookups.
- [ ] `MftScanner` (`cfg(windows)`): `windows-rs` + `IOCTL_QUERY_USN_JOURNAL` / direct MFT parsing, fallback to `WalkdirScanner` on non-admin / non-NTFS / network drives. (§10.4.2)
- [ ] MFT benchmark: <100ms for 400k files on modern NVMe. (§18)

### 5.5 Phase 3 — metadata sync

- [ ] Build file list, optionally filter to `md5sum IS NULL OR date_metadata_update IS NULL`. (§10.5)
- [ ] Parallel extraction via `rayon::par_iter`; skip unchanged (filesize equality). (§10.5)
- [ ] Batch tag-name resolution: single SELECT + INSERT of missing tags → complete `name → id` map. (§10.5)
- [ ] `mass_updates` for metadata fields; `mass_inserts` for new `image_tag` rows — all in one transaction per batch. (§5.6.1)
- [ ] Profile extraction time per file; compute p50/p95/p99 at phase end. (§10.5)

### 5.6 Recovery + integrity

- [ ] `SAVEPOINT`-based nested transactions per batch; rollback on interruption; mark `sync_jobs` row `incomplete`. (§5.6.1)
- [ ] Integrity CLIs: `images_integrity`, `categories_integrity`, `delete_orphan_tags`, `update_category`. (§5.6.2)
- [ ] Admin button: "Repair after interrupted sync". (§5.6.2)
- [ ] Fulltext index guard: wrap `DROP/REBUILD INDEX` in a `Drop`-guarded RAII struct so a panic restores the index. (§5.6.3)
- [ ] Status + permission inheritance in the same transaction as category insert (fix PHP race). (§5.6.4)
- [ ] `update_global_rank()` null-check + log warning on orphaned parents. (§5.6.4)
- [ ] Simulate mode: compute full diff, wrap in rolled-back transaction to catch FK/unique errors. (§5.6.6)

### 5.7 Tests

- [ ] Phase 1: create temp dir tree → sync → categories created with correct `uppercats`/`global_rank`.
- [ ] Phase 2: add image files → sync → rows inserted; delete files → orphans removed.
- [ ] Phase 3: EXIF/GPS/IPTC populated correctly.
- [ ] Interrupted sync: kill mid-phase → `sync_jobs` marked `incomplete` → re-run cleans up.
- [ ] MFT path (Windows CI runner): 10k-file tree scanned in <1s.
- [ ] Simulate mode: no DB changes; catches FK violation on a malformed input.

### Phase 5 Definition of Done

- 3-phase sync works against a 10k-image fixture gallery on both MySQL and PostgreSQL.
- Interrupted sync recovers cleanly on re-run.
- SSE progress visible in admin UI with pause/resume/abort buttons.
- MFT scanner benchmark meets target (<10s for 400k files on Windows NVMe).
- Simulate mode accurately predicts real-run outcome.

---

## Phase 6 — Plugin system

Full event hook system + Lua plugin support + 6 built-in plugins reimplemented. Duration target: 8–10 weeks.

### 6.1 Event bus (`gallery-plugins`)

- [ ] `GalleryEvent` enum — all 144 variants (91 notify, 53 change) per plan §11.1, fully annotated with source location + data shape. (§11.1)
- [ ] `GalleryEvent::event_type(&self) -> EventType` enforces notify-vs-change at compile time. (§11.1)
- [ ] `GalleryEvent::from_str(&str) -> Option<Self>` for Lua string registration. (§11.1)
- [ ] `EventBus` with `DashMap<GalleryEvent, BTreeMap<u32, Vec<HandlerFn>>>` for priority ordering. (§11.1)
- [ ] `HandlerFn = Arc<dyn Fn(EventContext) -> BoxFuture<EventResult> + Send + Sync>`. (§11.1)
- [ ] `add_handler`, `remove_handler`, `trigger_notify`, `trigger_change<T>`. (§11.1)
- [ ] `RequestContext` passed to every handler: `user`, `config`, `db`, `template`, `i18n`, `section`. (§14.5)
- [ ] Wire every core dispatch point — most were stubbed in Phases 2–5; make them real now.
- [ ] `docs/events.md` generator: `piwigo events:dump` CLI.
- [ ] `piwigo events:list [--filter=X]`, `piwigo events:show {EventName}`.

### 6.2 Lua bridge

- [ ] `LuaPluginHost` struct with sandbox-mode `mlua::Lua`. (§11.2)
- [ ] `Lua::new_with(StdLib::ALL_SAFE, LuaOptions::new().catch_rust_panics(true))`. (§11.2.1)
- [ ] Override `require` to only resolve within `plugins/{plugin_id}/`. (§11.2.1)
- [ ] Per-plugin `_ENV` table with whitelisted stdlib (string, table, math, utf8, coroutine, pcall, pairs, ipairs, type, tostring, tonumber, select, unpack, error, assert). (§11.2.1)
- [ ] CPU limit: `lua.set_hook(EVERY_NTH_INSTRUCTION, 1_000_000, ...)`. (§11.2.1)
- [ ] Memory limit: `lua.set_memory_limit(16 * 1024 * 1024)` (configurable). (§11.2.1)
- [ ] Wall-clock timeout: `tokio::time::timeout(Duration::from_secs(5), ...)`. (§11.2.1)
- [ ] `plugin.toml` format (replaces `pem_metadata.txt`) with `[capabilities]` block: `db_read`, `db_write`, `config_write`, `filesystem_read`, `http_client`, `user_impersonation`. (§11.2)
- [ ] Capability stored in `plugins.capabilities` DB column as JSON; runtime check at every host-API call. (§11.2)
- [ ] `gallery` Lua host API:
  - `piwigo.event.register` / `piwigo.event.register_change`
  - `piwigo.template.assign` / `piwigo.template.concat`
  - `piwigo.conf.get` / `piwigo.conf.set` (capability-gated)
  - `piwigo.db.query` (SELECT-only for non-admin) / `piwigo.db.execute` (INSERT/UPDATE/DELETE only; reject DROP/TRUNCATE/ALTER) — parameters never concatenated. (§11.2.1)
  - `piwigo.l10n`, `piwigo.log`
  - `piwigo.http.get` / `piwigo.http.post` (capability-gated; SSRF guard)
  - `piwigo.fs.read` restricted to `plugins/{plugin_id}/`, reject `..` and absolute paths. (§11.2.1)
- [ ] Error isolation: every Lua handler wrapped in `lua.scope` + match; notify errors continue chain, change errors pass data through unmodified. (§11.2.1)
- [ ] Hot-reload: in `PIWIGO_DEV=1`, watch `plugins/` and reload affected VM. (§11.2.1)
- [ ] Security-critical host APIs for AdminTools-class plugins: `verify_password`, `log_user`, `build_user`, `register_event_handler`, `register_ws_method`, `create_pwg_error`, `get_url_param`. (§20.1.3)

### 6.3 Lifecycle (`PluginMaintain`)

- [ ] `PluginMaintain` trait for native plugins: `install`, `activate`, `deactivate`, `update`, `uninstall`. (§11.3)
- [ ] Lua plugin lifecycle via exported functions: `plugin_install`, `plugin_activate`, etc. (§11.3)
- [ ] Auto-update on load: compare file version vs DB, call `update()` if different. (§11.3)
- [ ] Admin actions via `POST /admin/plugins/{id}/{action}`. (§11.3)

### 6.4 Built-in plugin rewrites

- [ ] **AdminTools** (HIGH) — MultiView (user impersonation), audit-log suppression, template privacy stripping. Proof-of-concept for the bridge. (§11.4)
- [ ] **GDThumb** (LOW) — thumbnail aspect-ratio options. (§11.4)
- [ ] **language_switch** (LOW) — language selector UI + session persistence. (§11.4)
- [ ] **LocalFilesEditor** (MEDIUM) — sandbox-restricted `local/` config + language editor. (§11.4)
- [ ] **TakeATour** (VERY LOW) — onboarding overlay on `loc_begin_index`. (§11.4)
- [ ] **rv_tscroller** (LOW) — scrolling carousel. (§11.4)

### 6.5 Plugin admin + marketplace

- [ ] Plugin list page: enable/disable/install/uninstall, capability approval on install. (§11.3)
- [ ] Install by ID from PEM marketplace (or equivalent). (§9.3.4)
- [ ] Per-plugin settings page auto-rendered from plugin-declared schema.

### 6.6 Tests

- [ ] Event-bus tests: priority ordering, `trigger_change` chaining, notify errors isolated.
- [ ] Lua sandbox escape attempts: `io`, `os.execute`, `dofile`, path-traversal `fs.read` — all rejected.
- [ ] Capability enforcement: `db_write=false` plugin calling `piwigo.db.execute` → refused.
- [ ] AdminTools MultiView: viewing gallery as impersonated user shows their filtered album list.
- [ ] Hot-reload: edit plugin file in dev mode → next request uses new code.
- [ ] Plugin with broken bootstrap: server starts, plugin marked `status=Error`, other plugins unaffected.

### Phase 6 Definition of Done

- All 144 events exist and dispatch at the right sites.
- AdminTools reimplemented in Lua and passes the MultiView scenario tests.
- Plugin install/activate/deactivate/uninstall flow works end-to-end.
- Lua sandbox rejects known-unsafe patterns; capability enforcement tested.
- `piwigo events:dump` generates complete docs.

---

## Phase 7 — Templates & themes

Write Tera templates covering every UI surface + ship five default themes. Duration target: 6–8 weeks.

Note: this is greenfield template authoring, not a Smarty-to-Tera transpile. The template inventory is derived from the feature surface defined in Phases 2–4; the shape of each template is driven by the handler's view-model, not by any existing `.tpl` file.

### 7.1 Tera configuration + filters

- [ ] Initialize `Tera` from `templates/` at startup. (§12.1)
- [ ] Register custom filters: `translate`, `translate_dec`, `sprintf`, `urlencode`, `int`, `json_encode`, `escape`, `join`, `lower`, `trim`, `md5`, `theme_override`. (§12.1)
- [ ] Register custom functions: `combine_script`, `combine_css`, `get_combined_scripts`, `get_combined_css`, `footer_script`, `define_derivative`, `make_index_url`, `make_picture_url`, `derivative_url`. (§12.1)
- [ ] Plugin prefilter registration API: `template_engine.add_prefilter(handle, fn, weight)`. (§12.1)
- [ ] Template hot-reload when `PIWIGO_DEV=1`. (§12.1)

### 7.2 Asset pipeline

- [ ] `ScriptRegistry` with Kahn's-algorithm topological sort, cycle detection, async-constraint propagation. (§12.2)
- [ ] `CssRegistry` with dedup + version-based replacement. (§12.2)
- [ ] `FileCombiner`: domain grouping, `crc32`-keyed cache, mtime check, `.css.tpl`/`.js.tpl` pre-render. (§12.2)
- [ ] CSS minification via `lightningcss`. (§12.2)
- [ ] JS minification via `swc_ecma_minifier` or `oxc_minifier`. (§12.2)
- [ ] CSS URL rewriting after combining. (§12.2)
- [ ] Combined file output to `var/combined/{hash}.{css,js}`.
- [ ] `config.template_combine_files` toggle for dev. (§12.2)

### 7.3 BlockManager

- [ ] `BlockManager` + `RegisteredBlock` + `DisplayBlock`. (§12.3)
- [ ] `blockmanager_register_blocks` (N), `blockmanager_prepare_display` (N), `blockmanager_apply` (N). (§11.1)
- [ ] `prepare_display()` loads order from `blk_menubar` config (JSON, not PHP serialized). (§9.3.4)
- [ ] `apply(template_var)` renders blocks into template context.

### 7.4 Theme system

- [ ] `ThemeConfig` loaded from `themeconf.php`-equivalent (JSON/TOML). (§12.4)
- [ ] Theme-aware template loader: `themes/{active}/templates/{name}.html` → parent chain → `templates/{name}.html`. (§12.4)
- [ ] `theme_override` filter. (§12.4)

### 7.5 Template authoring execution

- [ ] Build order (dependency-first): `layout.html` → `partials/` (nav, footer, pagination, breadcrumb, thumbnail card) → gallery core (`index`, `album`, `picture`, `search`, `tags`) → auth (`login`, `register`, `password-reset`, `profile`) → admin shell (`admin/layout`, sidebar, dashboard) → admin pages → mail templates.
- [ ] Five default themes authored: `default` (the parent), plus four variants exercising the theme override system (e.g. `dark`, `minimal`, `mosaic`, `print-friendly`). Final theme names TBD; the goal is to prove the parent/override mechanism with real divergent designs.
- [ ] Playwright snapshot tests per template. (§8.2)

### 7.6 Tests

- [ ] Snapshot tests for 10 hot templates (index, album, picture, search, login, register, dashboard, batch manager, sync, error).
- [ ] Self-consistency snapshot regression: pixel-diff threshold configured; key pages covered. No Piwigo-PHP baseline to compare against — this is a regression guard against unintentional future changes, not a parity check.
- [ ] axe-core run in browser tests against every rendered public page — zero violations.
- [ ] Dark-mode snapshot for supporting themes.
- [ ] Keyboard-only navigation across login + admin flows.

### Phase 7 Definition of Done

- Every handler has a matching Tera template that renders cleanly.
- All 5 themes working; user can switch themes in profile.
- Visual regression suite green against PHP baseline within threshold.
- a11y tests clean.

---

## Phase 8 — Polish, testing, release

Production-ready system. Regression-tested against PHP. Performance validated. Duration target: 8–12 weeks.

### 8.1 Integration test suite

- [ ] API round-trip tests for all 84 methods (already started in Phase 4 — now comprehensive: happy + every error code). (§13.1)
- [ ] Auth: session, remember-me, API key, permission boundary matrix. (§13.1, §15)
- [ ] Sync: 3-phase dirty-restart, format detection, orphan cleanup. (§13.1)
- [ ] Derivative: 9 sizes × dimension check × watermark × EXIF rotation. (§13.1)
- [ ] Template render: every gallery + admin page returns 200 on its canonical URL. (§13.1)

### 8.2 Visual regression

- [ ] Playwright suite with baseline + Rust captures. (§13.2)
- [ ] Pixel-diff threshold per page type. (§13.2)
- [ ] Coverage: home, album, picture, search, login, dashboard, batch manager, sync, error pages. (§13.2)

### 8.3 Load testing

- [ ] `oha` or `k6` scripts for each workload profile. (§13.3)
- [ ] Targets: gallery p95 <100ms (cached), derivative miss p95 <500ms, 100 concurrent users sustained for 60s. (§13.3, §18)
- [ ] Nightly bench job; >20% regression fails CI.

### 8.4 Security audit

SQL injection:
- [ ] `grep -rn 'format!.*SELECT\|format!.*INSERT\|format!.*UPDATE\|format!.*DELETE'` returns zero. (§8.4)
- [ ] Every ported query uses `QueryBuilder` or `sqlx::query!`.
- [ ] LIKE queries escape `%` and `_`. (§20.2.2)
- [ ] ORDER BY via enum only. (§8.4)
- [ ] Lua `db.query`/`db.execute` require separate params arg.

XSS:
- [ ] Tera autoescape on globally. (§8.4)
- [ ] Every `| safe` use justified in review.
- [ ] `render_*` hooks sanitize plugin output.
- [ ] JSON-in-`<script>` uses `JSON_HEX_TAG | JSON_HEX_AMP`-equivalent escaping.
- [ ] `Content-Disposition` filenames sanitized.

CSRF:
- [ ] Every state-changing endpoint validates `X-CSRF-Token` for session clients (bearer-token clients exempt). (§8.4)
- [ ] All admin forms include + validate CSRF token.
- [ ] Logout is POST-only.

Auth / authz:
- [ ] Session fixation: ID regenerated on login. (§8.4)
- [ ] Session IP-octet binding.
- [ ] Remember-me: timing-safe HMAC.
- [ ] API keys stored as SHA-256. (§8.4)
- [ ] argon2id parameters tuned for target hardware; `verify_encoded` auto-detects on-disk params so upgrades are transparent. (§R-20)
- [ ] Login rate limit: 10/IP/min.
- [ ] Every admin endpoint checks `status >= Admin`.
- [ ] Plugin-install + core-upgrade endpoints check `AccessLevel::Webmaster`.
- [ ] Image privacy-level check on every view/download/derivative.

File handling:
- [ ] Path traversal rejected everywhere.
- [ ] Upload validation by magic bytes.
- [ ] Upload size enforced server-side.
- [ ] Derivative URL parser rejects arbitrary paths.
- [ ] `walkdir` configured to not follow symlinks.

HTTP headers:
- [ ] CSP, X-Frame-Options DENY, X-Content-Type-Options nosniff, Referrer-Policy strict-origin-when-cross-origin, HSTS (HTTPS), X-Request-Id. (§8.4)

### 8.5 Performance optimization

- [ ] `tokio-console` profile; fix async bottlenecks. (§13.5)
- [ ] `flamegraph` CPU profile; fix hot paths. (§13.5)
- [ ] Derivative cache pre-warm on startup. (§13.5)
- [ ] `EXPLAIN ANALYZE` every query; add missing indexes.
- [ ] Connection-pool size tuning.
- [ ] Template caching confirmed (compile once).
- [ ] Cache-Control headers set on every response class.

### 8.6 Install + upgrade runbooks

- [ ] `docs/install.md` — fresh install per §17: provision DB → configure → `gallery install` → `gallery admin:create` → `gallery serve` → smoke test. Target: under 10 minutes on a clean host.
- [ ] `docs/upgrade.md` — between-version upgrades: backup → `gallery upgrade` (applies pending sqlx migrations) → smoke test → rollback plan (restore from DB dump).
- [ ] `docs/no-migration-from-piwigo.md` — explains why there is no Piwigo 14 import path, what the alternatives are, and which third-party tools (if any) exist out-of-tree.

### 8.7 Documentation

- [ ] `docs/install.md` — binary, Docker, from source. (§13.6)
- [ ] `docs/configuration.md` — every config option (Appendix C). (§13.6)
- [ ] `docs/plugins.md` — Lua plugin API reference with AdminTools example. (§13.6)
- [ ] `docs/api.md` — REST reference auto-generated. (§13.6)
- [ ] `docs/themes.md` — Tera templates + asset pipeline. (§13.6)
- [ ] `docs/sync.md` — MFT requirements, performance tuning. (§13.6)
- [ ] `cargo doc --no-deps` covers every `pub` item.

### 8.8 Release engineering

- [ ] `RELEASING.md` — tag → CI → build + sign binaries + Docker image + source tarball → SBOM → signed GitHub Release.
- [ ] Cosign signing keys published. (§20)
- [ ] GPG key for source tarball signing. (§20)
- [ ] Packaged binaries for Linux (x86_64, aarch64), macOS (x86_64, aarch64), Windows (x86_64).
- [ ] Docker images at `:1.0.0`, `:1.0`, `:1`, `:latest`.

### 8.9 Release candidate → v1.0

- [ ] Cut `v1.0.0-rc.1`.
- [ ] Dogfood on maintainer's own gallery for ≥30 days.
- [ ] Track regressions to exhaustion.
- [ ] Security advisory review (zero outstanding).
- [ ] Promote to `v1.0.0`.
- [ ] Publish release notes.
- [ ] Update README status banner from pre-alpha → stable.
- [ ] Open issues for v1.1 roadmap.

### Phase 8 Definition of Done

- `v1.0.0` tag exists; binaries + Docker image + source tarball published and signed.
- All tests green for 14 consecutive days on `main`.
- Migration runbook validated against a real PHP 14.x gallery.
- Performance targets met per §18 on the reference hardware.
- Someone who's never used the project can install and upload 100 photos within an hour using only the docs.

---

## Ongoing / cross-cutting

Work that runs continuously once the project is active.

### Architecture tests

- [ ] Arch rule: no `format!` into SQL. Enforced by regex-based CI check. (§8.4)
- [ ] Arch rule: no raw `sqlx::query` outside `gallery-db` queries modules. (§5)
- [ ] Arch rule: no direct `$_SESSION`-equivalent — session access only via `SessionService`.
- [ ] Arch rule: ORDER BY strings only from enum `to_sql()`. (§8.4)

### Dependencies

- [ ] Renovate accepts patch auto-merges after CI.
- [ ] Weekly `cargo audit` + `bun audit` (if any JS in asset pipeline).
- [ ] Major bumps tracked as issues.

### Translations

- [ ] Weblate (or Crowdin) project exists.
- [ ] PR-based sync of JSON language files.
- [ ] CI check: every language file is valid JSON with the same key set as `en_UK` (warn on missing, error on extras).

### Security

- [ ] `SECURITY.md` kept current.
- [ ] GHSA monitored; advisories filed within SLA.
- [ ] Annual threat-model review. (§8.4)

### Performance

- [ ] Nightly benchmark run on a dedicated bench host (not CI shared runners).
- [ ] Regressions triaged within one week.
- [ ] Benchmark trend lines published.

### Documentation

- [ ] Every user-visible PR updates `CHANGELOG.md` `Unreleased`.
- [ ] Every architectural change adds/updates an ADR.
- [ ] `docs/events.md` regenerated on every release.

### Community

- [ ] Weekly issue triage rotation.
- [ ] Monthly roadmap review.
- [ ] Stale-bot with generous timeouts.
- [ ] Contributor Covenant enforcement documented.

---

## Modernization (post-v1.0)

Features the Rust rewrite unlocks but that are **not** on the v1.0 path. These match plan §21 and are tracked for v1.x / v2.0. Each item is tiered:

- **T1** — ship with or soon after v1.0 (low effort, high value)
- **T2** — v1.x series (medium effort)
- **T3** — v2.0 (major new subsystem)

### Image & media (§21.1)

- [ ] **T1** Next-gen format negotiation (AVIF > JXL > WebP > JPEG per Accept). (§21.1.1)
- [ ] **T1** BlurHash / LQIP placeholders. (§21.1.2)
- [ ] **T3** Video support (MP4/WebM/MOV, HLS, ffmpeg). (§21.1.3)
- [ ] **T2** RAW support (CR2/CR3/NEF/ARW/DNG/...) via `rawloader` or `dcraw`. (§21.1.4)
- [ ] **T2** Perceptual hashing for near-duplicate detection. (§21.1.5)

### AI (§21.2, opt-in, local only)

- [ ] **T2** ONNX Runtime integration (`ort` crate). (§21.2.1)
- [ ] **T2** Auto-tagging (RAM / WD-Tagger). (§21.2.2)
- [ ] **T3** Face detection + recognition (RetinaFace + ArcFace). (§21.2.3)
- [ ] **T2** Semantic search via CLIP + `usearch` ANN index. (§21.2.4)
- [ ] **T2** Smart albums (rule engine + optional ML signals). (§21.2.5)

### Authentication (§21.3)

- [ ] **T2** WebAuthn / passkeys via `webauthn-rs`. (§21.3.1)
- [ ] **T1** TOTP 2FA via `totp-rs`. (§21.3.2)
- [ ] **T1** OAuth2 / OIDC SSO.
- [ ] **T1** Audit log viewer with export.

### Search (§21.4)

- [ ] **T2** Tantivy full-text search backend (alternative to MySQL/PG native).
- [ ] **T2** Meilisearch integration.

### Storage (§21.5)

- [ ] **T2** S3-compatible backend.
- [ ] **T2** Multi-tenancy.
- [ ] **T2** Read-replica wiring.

### Frontend (§21.6)

- [ ] **T1** HTMX/Alpine admin enhancements.
- [ ] **T2** Service worker / PWA.
- [ ] **T2** Offline favorites.

### API (§21.7)

- [ ] **T1** JSON:API- or JSON-RPC-compliant v2.
- [ ] **T1** Webhooks out.
- [ ] **T2** GraphQL.

### Observability (§21.8)

- [ ] **T1** OpenTelemetry tracing + metrics.
- [ ] **T1** Prometheus `/metrics` endpoint.
- [ ] **T1** Structured JSON logs with request-ID correlation.
- [ ] **T2** Sentry error reporting.

### Operations (§21.9)

- [ ] **T1** Background job queue (proper retry + DLQ).
- [ ] **T1** Admin DLQ viewer.
- [ ] **T2** Single-binary release with embedded assets via `rust-embed`.

### Collaboration (§21.10)

- [ ] **T2** Shared private galleries with expiring links.
- [ ] **T2** Per-album contributor permissions.

### Privacy (§21.11)

- [ ] **T1** GDPR data export + erasure flows.
- [ ] **T1** Cookie policy banner + registry.
- [ ] **T1** `PRIVACY_STRIP_GPS_ON_UPLOAD` flag.

These are **not on the v1.0 path**; opening an issue is fine but they don't block the release.

---

## Risk tracking

The 20 risks catalogued in plan §16 each have at least one entry above that mitigates them. When a risk materializes, link the issue/PR back to the risk ID (R-01 … R-20) and update the risk register.

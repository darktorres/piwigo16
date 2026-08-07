# Piwigo Rust Rewrite

> No legacy baggage. No PHP. No Smarty. No `ws.php`. No `i.php`. No backward compatibility — greenfield schema, greenfield API, greenfield URL grammar, modern tools and concepts throughout.
>
> A from-scratch photo-gallery server in Rust. It borrows the **domain** (albums, photos, tags, permissions, derivatives, plugins) from two decades of Piwigo experience, but preserves none of Piwigo's code, data model, or wire contracts. Existing Piwigo installations cannot be "upgraded" to this. Framing it any other way would smuggle the constraints the rewrite exists to escape back in through the front door.

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Architecture Decisions](#2-architecture-decisions)
3. [Technology Stack](#3-technology-stack)
4. [Repository Structure](#4-repository-structure)
5. [Database & Schema Strategy](#5-database--schema-strategy)
6. [Phase 1 — Foundation](#6-phase-1--foundation)
7. [Phase 2 — Core Read Paths](#7-phase-2--core-read-paths)
8. [Phase 3 — Image Pipeline](#8-phase-3--image-pipeline)
9. [Phase 4 — Write Paths & Admin](#9-phase-4--write-paths--admin)
10. [Phase 5 — Filesystem Sync](#10-phase-5--filesystem-sync)
11. [Phase 6 — Plugin System](#11-phase-6--plugin-system)
12. [Phase 7 — Templates & Themes](#12-phase-7--templates--themes)
13. [Phase 8 — Polish & Testing](#13-phase-8--polish--testing)
14. [Subsystem Specifications](#14-subsystem-specifications)
15. [Testing Strategy](#15-testing-strategy)
16. [Risk Register](#16-risk-register)
17. [No migration — fresh install only](#17-no-migration--fresh-install-only)
18. [Performance Targets](#18-performance-targets)
19. [Milestone Summary](#19-milestone-summary)
20. [Pain Point Deep Dives — lessons from Piwigo 14](#20-pain-point-deep-dives--lessons-from-piwigo-14)
    - [20.1 Plugin & Hook System](#201-plugin--hook-system)
    - [20.2 Dynamic SQL & Query Safety](#202-dynamic-sql--query-safety)
    - [20.3 Template System & Asset Pipeline](#203-template-system--asset-pipeline)
    - [20.4 Permission System & Caching](#204-permission-system--caching)
    - [20.5 Filesystem Sync Complexity](#205-filesystem-sync-complexity)
    - [20.6 Image Pipeline & Derivative System](#206-image-pipeline--derivative-system)
    - [20.7 Admin Batch Operations & Upload Pipeline](#207-admin-batch-operations--upload-pipeline)
    - [20.8 Session Serialization & Auth Edge Cases](#208-session-serialization--auth-edge-cases)
21. [Modernization Roadmap](#21-modernization-roadmap)
    - [21.1 Modern Image & Media Pipeline](#211-modern-image--media-pipeline)
    - [21.2 AI-Powered Features](#212-ai-powered-features)
    - [21.3 Modern Authentication & Security](#213-modern-authentication--security)
    - [21.4 Advanced Search](#214-advanced-search)
    - [21.5 Storage Backends](#215-storage-backends)
    - [21.6 Modern Frontend](#216-modern-frontend)
    - [21.7 API Modernization](#217-api-modernization)
    - [21.8 Observability](#218-observability)
    - [21.9 Deployment & Operations](#219-deployment--operations)
    - [21.10 Collaboration & Sharing](#2110-collaboration--sharing)
    - [21.11 Privacy & Compliance](#2111-privacy--compliance)

**Appendices — Reference Material**

- [A. Complete Database Schema](#appendix-a-complete-database-schema)
- [B. Complete API Surface](#appendix-b-complete-api-surface)
- [C. Configuration reference](#appendix-c-configuration-reference)
- [D. Event Catalog (Piwigo ↔ Rust cross-reference)](#appendix-d-event-catalog-piwigo--rust-cross-reference)
- [E. Template Inventory](#appendix-e-template-inventory)
- [F. Complete URL Routing Map](#appendix-f-complete-url-routing-map)

---

## 1. Project Overview

### What

A greenfield photo-gallery server in Rust. A single binary (plus a libvips runtime dependency) that listens on HTTP, speaks a new REST API, serves a new set of templates, reads a new database schema, and owns the on-disk storage layout from `storage/originals/` down to `var/derivatives/`.

The value carried over from Piwigo is the **domain knowledge** — two decades of "what a photo gallery actually needs to do": album trees, permission inheritance, derivative caching, EXIF handling, search grammars, sync flows, the exact sharp edges the PHP version bumped into. This document restates those problems and solves them on a clean foundation.

**This is not a Piwigo-compatible replacement.** It does not preserve Piwigo's database schema, web-service API, URL layout, theme format, or plugin contracts. Existing Piwigo installations cannot be "upgraded" to it.

### Why a rewrite rather than a fork

| Problem with the PHP codebase | Why a fork can't fix it | What the rewrite does |
|---|---|---|
| ~500ms average page load | PHP-FPM process-per-request model has a floor | Long-running Axum server, ~75ms target (6–7× faster) |
| No compile-time SQL safety (523 raw queries with `addslashes`) | Would need to rewrite every query; no in-situ way to enforce it | `sqlx::query!` compile-time checks every SQL statement; `QueryBuilder` for dynamic shapes |
| Memory ~50MB per request | PHP-FPM allocator, global state per request | ~2MB per tokio task; shared state via `Arc` |
| Plugin system requires PHP runtime; no sandbox | PHP is the plugin API | Lua via mlua with capability-scoped host API and per-plugin memory/CPU limits |
| No concurrency within a request | PHP concurrency model is "spawn another process" | `tokio::join!`, `rayon` for parallel metadata extraction |
| Sync of 400k+ dirs bottlenecked by PHP/MySQL overhead | PHP process startup + MySQL roundtrip per file | Parallel async scan; optional native NTFS MFT reader on Windows |
| Twenty years of schema accretion: `uppercats` comma-string, `user_cache_categories`, mixed `image_category` semantics | Each table has years of feature work assuming it | Domain-driven schema designed once, documented once |
| `ws.php` REST API invented before JSON Schema / OpenAPI were standard | Thousands of clients assume every field | New `/api/v1/*` designed with OpenAPI-first contract tests |

### Scope — v1.0

The system covers the full photo-gallery feature surface:

- **Browsing:** album tree, photo detail, tags, search, calendar, feeds, favorites
- **Upload:** single + chunked + resumable (tus), MIME + magic-byte validation, EXIF-aware rotation, auto-derivative generation
- **Filesystem sync:** 3-phase (directories → files → metadata), SSE progress, idempotent re-run, optional Windows MFT scanner
- **Admin:** SSR admin panel (no SPA v1.0), drag-drop album tree, batch manager, user/group management, settings, queue monitor, audit log
- **Derivative pipeline:** libvips backend, 9 standard presets, signed custom derivatives, AVIF/WebP/JPEG format negotiation
- **Permissions:** per-album ACLs (user + group), inheritance, `AccessLevel` gating, cached resolve
- **Plugins:** Lua via mlua with sandbox + capabilities + typed event bus
- **Themes:** Tera templates with parent-child override, asset pipeline via Vite
- **i18n:** gettext-compatible catalogs, plural rules, RTL support
- **Mail:** SMTP via lettre, templated transactional + digest notifications
- **API:** versioned `/api/v1/*` REST surface, OpenAPI-generated from source, token auth
- **Observability:** structured logs, OpenTelemetry traces, Prometheus metrics, audit log

### Out of Scope (v1.0)

Explicitly excluded — not hidden behind flags, not "coming soon" in the codebase:

- **Piwigo data migration.** Existing Piwigo databases are not importable. No PHP-serialized session/search/preferences migration. Users re-upload.
- **Piwigo REST API compatibility.** `ws.php`, XML-RPC, PHP-serialized response format — all gone. The new API lives at `/api/v1/*`.
- **Piwigo URL compatibility.** No `i.php`, `index.php`, `picture.php`, `action.php`, `feed.php`, `ws.php`, `identification.php`. New URL grammar throughout.
- **Piwigo plugin / theme compatibility.** Third-party PHP plugins and Smarty themes cannot run. Built-in plugins are reimplemented in Lua (not ported).
- **Derivative cache compatibility.** `_data/i/{path}/photo-sq.jpg` is replaced by `var/derivatives/{uuid[:2]}/{uuid}/thumbnail.avif` — fresh regeneration on install.
- **Video, RAW beyond embedded preview, 2FA, passkeys, PWA, multi-tenancy** — deferred to §21 (v1.1+).
- **SQLite, MSSQL, Oracle** — supported engines are MySQL 9.7 LTS, MariaDB 11.8 LTS, PostgreSQL 18. (Same set as the PHP rewrite.)

The v1.1+ roadmap (§21) covers modernization features that take advantage of the rewrite to deliver capabilities Piwigo never could. Everything there is explicitly post-v1.0.

---

## 2. Architecture Decisions

### ADR-001: Axum as Web Framework

**Decision:** Use Axum over Actix-web or Rocket.

**Rationale:**
- Tower middleware ecosystem — composable, well-tested layers for auth, sessions, rate limiting, logging
- Typed extractors map directly to Piwigo's parameter validation system
- First-class async support without actor model complexity
- Large ecosystem (hyper, tower, tokio all from the same org)

### ADR-002: sqlx Over Diesel

**Decision:** Use sqlx with raw parameterized queries over an ORM.

**Rationale:**
- Piwigo has 523 raw SQL queries with complex dynamic WHERE/ORDER construction
- An ORM would require translating this logic into a query-builder DSL — equal or greater effort with added abstraction cost
- sqlx `query!` and `query_as!` macros provide compile-time SQL validation without an ORM
- Dynamic queries (search, bulk filters) are handled with a lightweight `QueryBuilder` pattern
- MySQL and PostgreSQL are both first-class sqlx targets

### ADR-003: Tera as Template Engine

**Decision:** Use Tera over Askama, MiniJinja, or Handlebars.

**Rationale:**
- Tera's syntax is the closest to Smarty (blocks, filters, inheritance, conditionals, loops)
- Dynamic filter and function registration at runtime — required for the plugin hook system
- Template inheritance (`extends`/`block`) supports the child-theme override pattern
- Askama requires compile-time templates — incompatible with runtime plugin injection
- Handlebars lacks the filter/modifier pipeline Piwigo templates rely on

### ADR-004: libvips-rs as Sole Image Backend

**Decision:** Replace all 4 PHP image backends (GD, Imagick, ext_imagick, VIPS) with a single `libvips-rs` backend.

**Rationale:**
- libvips is 5–10× faster than GD for resize operations
- Handles all required operations: resize (Lanczos), crop (COI-aware), rotate (EXIF), sharpen (convolution), alpha compositing (watermarks), progressive JPEG, chroma subsampling, metadata stripping, animated WebP
- Eliminates 4-way backend fragmentation — one code path to test and maintain
- `libvips-rs` (jcupitt/vips-rs) provides safe Rust bindings

### ADR-005: Lua (mlua) for Plugin System

**Decision:** Use embedded Lua via `mlua` for plugin extensibility.

**Rationale:**
- WASM plugins cannot access the filesystem or database without a complex host API — too restrictive for Piwigo's plugin ecosystem which does both
- `mlua` embeds a full Lua 5.4 VM in the Rust process with safe bidirectional FFI
- Lua is significantly simpler to learn than Rust for plugin authors — lower barrier
- PHP plugin logic ports to Lua more naturally than to WASM
- The Lua sandbox provides isolation — plugins cannot access Rust internals not explicitly exposed
- Alternative considered: Rhai (pure Rust scripting) — rejected due to smaller ecosystem and missing features

### ADR-006: Error Handling Strategy

**Decision:** `thiserror` for typed errors in library crates, `anyhow` at the application boundary, HTTP error mapping via Axum `IntoResponse`.

**Error Hierarchy:**

```rust
// gallery-core: typed, matchable errors
#[derive(Debug, thiserror::Error)]
pub enum GalleryError {
    // === Authentication ===
    #[error("Invalid credentials")]
    InvalidCredentials,
    #[error("Account disabled")]
    AccountDisabled,
    #[error("Session expired")]
    SessionExpired,
    #[error("Insufficient permissions: requires {required}, have {actual}")]
    InsufficientPermissions { required: AccessLevel, actual: AccessLevel },
    #[error("CSRF token mismatch")]
    CsrfMismatch,
    #[error("Rate limited: retry after {retry_after_secs}s")]
    RateLimited { retry_after_secs: u64 },

    // === Resource errors ===
    #[error("Image not found: {0}")]
    ImageNotFound(i32),
    #[error("Category not found: {0}")]
    CategoryNotFound(i32),
    #[error("User not found: {0}")]
    UserNotFound(i32),
    #[error("Tag not found: {0}")]
    TagNotFound(i32),
    #[error("Resource not found: {resource_type} {id}")]
    NotFound { resource_type: &'static str, id: String },

    // === Validation ===
    #[error("Invalid parameter: {name} — {reason}")]
    InvalidParameter { name: String, reason: String },
    #[error("Missing required parameter: {0}")]
    MissingParameter(String),
    #[error("Parameter out of range: {name} must be {constraint}")]
    ParameterOutOfRange { name: String, constraint: String },

    // === Upload/file ===
    #[error("File type not allowed: {0}")]
    FileTypeNotAllowed(String),
    #[error("File too large: {size_bytes} exceeds limit {limit_bytes}")]
    FileTooLarge { size_bytes: u64, limit_bytes: u64 },
    #[error("Duplicate image: md5 {md5} matches image {existing_id}")]
    DuplicateImage { md5: String, existing_id: i32 },
    #[error("Upload chunk missing: expected {expected}, got {actual}")]
    UploadChunkMissing { expected: u32, actual: u32 },

    // === Database ===
    #[error("Database error: {0}")]
    Database(#[from] sqlx::Error),

    // === Image processing ===
    #[error("Image processing failed: {0}")]
    ImageProcessing(String),

    // === Plugin ===
    #[error("Plugin error in {plugin}: {message}")]
    PluginError { plugin: String, message: String },

    // === Configuration ===
    #[error("Configuration error: {0}")]
    Config(String),
    #[error("Maintenance mode active")]
    MaintenanceMode,
}
```

**HTTP Status Code Mapping:**

| Error Variant | HTTP Status | WS Error Code |
|---|---|---|
| `InvalidCredentials` | 401 | 999 |
| `AccountDisabled` | 403 | 999 |
| `SessionExpired` | 401 | 999 |
| `InsufficientPermissions` | 403 | 401 |
| `CsrfMismatch` | 403 | 403 |
| `RateLimited` | 429 | 429 |
| `*NotFound` | 404 | 501 |
| `InvalidParameter` / `MissingParameter` | 400 | 1002/1003 |
| `FileTypeNotAllowed` / `FileTooLarge` | 400 | 500 |
| `DuplicateImage` | 409 | 500 |
| `Database` | 500 | 500 |
| `ImageProcessing` | 500 | 500 |
| `PluginError` | 500 | 500 |
| `MaintenanceMode` | 503 | 503 |

**WS Error Format** (backward-compatible with PHP `ws.php`):
```json
{"stat": "fail", "err": 1002, "message": "Missing required parameter: image_id"}
```

**HTML error pages:** Custom Tera templates for 403, 404, 500, 503. Plugin hook `render_error_page` allows override.

**Rationale:**
- Typed errors catch missing error handling at compile time
- HTTP mapping is deterministic — same error always produces same status code
- WS error codes maintain compatibility with existing API clients (Lightroom, DigiKam, Piwigo mobile apps)
- Plugins can throw `GalleryError::PluginError` which is caught and logged without crashing the request

### ADR-007: Caching Strategy

**Decision:** Three-layer caching with explicit invalidation contracts.

**Layer 1 — In-Process Cache (moka):**
- Permission cache: `user_id → CachedPermissions` (TTL: 5 min, invalidated explicitly)
- Config cache: `key → Value` (TTL: none, invalidated on admin change or SIGHUP)
- Template cache: Tera compiles templates once at startup (hot-reload in dev mode)
- i18n cache: all language strings loaded into `HashMap` at startup

**Layer 2 — Database Cache (user_cache, user_cache_categories):**
- Computed permission data persisted to DB for cold starts
- `need_update` flag triggers recomputation on next request
- Survives server restart, unlike Layer 1

**Layer 3 — HTTP Cache (browser + CDN):**
- Derivatives: `Cache-Control: public, max-age=864000` (10 days) when source is stable
- Static assets (CSS/JS): `Cache-Control: public, max-age=31536000, immutable` with hash-based filenames
- Gallery pages: `Cache-Control: private, no-cache` (vary by user permissions)
- API responses: `Cache-Control: no-store` (dynamic data)
- `ETag` and `Last-Modified` on all derivative responses for conditional requests

**Invalidation Contract:**

| Event | Layer 1 | Layer 2 | Layer 3 |
|---|---|---|---|
| Category status change | Evict affected users | `need_update = true` for all | N/A (private) |
| Image privacy level change | Evict affected users | `need_update = true` for all | N/A |
| User permission change | Evict specific user | `need_update = true` for user | N/A |
| Config change | Replace config entry | Update `config` table | N/A |
| Derivative params change | Reload `ImageStdParams` | — | `params.last_mod_time` triggers regen on next request |
| Template edit | Tera reload (dev only) | — | — |
| Image metadata edit | — | — | New `Last-Modified` on derivatives |

**Rationale:**
- In-process moka cache avoids DB round-trip for the hottest data (permissions checked on every request)
- DB cache survives restarts without full recomputation
- HTTP caching offloads derivative serving to browser/CDN — the highest-volume endpoint

### ADR-008: SSR Admin UI (Not SPA)

**Decision:** Admin panel uses server-side rendering with Tera templates, not a JS SPA.

**Rationale:**
- Piwigo's admin is form-heavy and navigation-heavy, not interaction-heavy
- SSR requires no frontend build pipeline, no JS bundler, no API contract between admin and backend
- Incremental migration is simpler — each admin page is an isolated Tera template
- HTMX can be added later for selective interactivity without architectural change

### ADR-009: Single Binary, Multiple Modes

**Decision:** Compile to a single binary with subcommands.

```
gallery serve          # HTTP server
gallery sync           # Run filesystem sync
gallery install        # First-time DB setup
gallery upgrade        # Run DB migrations
gallery maintenance    # Cache clear, integrity check, etc.
```

**Rationale:**
- Simplifies deployment (copy one binary)
- Shared code between server and CLI modes (DB layer, config, image processing)
- `sync` can be invoked via cron independently of the server

---

## 3. Technology Stack

### Core Dependencies

```toml
[dependencies]
# Web
axum                 = { version = "0.8", features = ["macros", "multipart"] }
tower                = { version = "0.5", features = ["full"] }
tower-http           = { version = "0.6", features = ["fs", "compression-gzip", "trace"] }
tokio                = { version = "1", features = ["full"] }
hyper                = "1"

# Database
sqlx                 = { version = "0.8", features = ["runtime-tokio", "mysql", "postgres", "chrono", "uuid", "json"] }

# Templating
tera                 = "1"

# Image processing
libvips              = "0.4"          # libvips-rs bindings

# Metadata
kamadak-exif         = "0.5"         # EXIF parsing
rexiv2               = "0.10"        # IPTC/XMP via gexiv2

# Plugin system
mlua                 = { version = "0.10", features = ["lua54", "async", "vendored"] }

# Auth / crypto
argon2               = "0.5"
hmac                 = "0.12"
sha2                 = "0.10"
hex                  = "0.4"
rand                 = "0.8"
constant_time_eq     = "0.3"
subtle               = "2.5"
base64               = "0.22"

# Sessions
tower-sessions       = "0.13"

# HTTP client
reqwest              = { version = "0.12", features = ["json"] }

# Email
lettre               = { version = "0.11", features = ["tokio1-native-tls", "builder"] }

# Config
serde                = { version = "1", features = ["derive"] }
serde_json           = "1"
toml                 = "0.8"
config               = "0.14"

# Serialization
serde_with           = "3"
chrono               = { version = "0.4", features = ["serde"] }
uuid                 = { version = "1", features = ["v4", "serde"] }

# Logging / tracing
tracing              = "0.1"
tracing-subscriber   = { version = "0.3", features = ["env-filter", "json"] }

# Error handling
thiserror            = "2"
anyhow               = "1"

# Utilities
dashmap              = "6"           # Concurrent hashmap (permission cache)
moka                 = "0.12"        # Async LRU cache
rayon                = "1"           # CPU-parallel iteration (sync/metadata)
walkdir              = "2"           # Filesystem traversal
regex                = "1"
once_cell            = "1"
bitflags             = "2"
strum                = { version = "0.26", features = ["derive"] }

# ZIP
zip                  = "2"

# Feed generation
atom_syndication     = "0.12"
rss                  = "2"

# CLI
clap                 = { version = "4", features = ["derive"] }

[target.'cfg(windows)'.dependencies]
windows              = { version = "0.58", features = ["Win32_Storage_FileSystem", "Win32_System_Ioctl"] }  # MFT reader
```

### Dev Dependencies

```toml
[dev-dependencies]
tokio-test           = "0.4"
axum-test            = "0.15"
testcontainers       = "0.22"        # Spin up MySQL/PostgreSQL in tests
testcontainers-modules = { version = "0.10", features = ["mysql", "postgres"] }
criterion            = { version = "0.5", features = ["async_tokio"] }
proptest             = "1"
fake                 = "2"
wiremock             = "0.6"
```

---

## 4. Repository Structure

```
gallery-rust-wip/
├── Cargo.toml
├── Cargo.lock
│
├── crates/
│   ├── gallery-core/           # Domain types, traits, error types
│   │   └── src/
│   │       ├── lib.rs
│   │       ├── types/
│   │       │   ├── user.rs        # User, AccessLevel, UserStatus
│   │       │   ├── category.rs    # Category, CategoryTree
│   │       │   ├── image.rs       # Image, ImageFormat, Derivative
│   │       │   ├── tag.rs
│   │       │   ├── comment.rs
│   │       │   └── permission.rs
│   │       ├── error.rs
│   │       └── config.rs
│   │
│   ├── gallery-db/             # Database layer
│   │   └── src/
│   │       ├── lib.rs
│   │       ├── pool.rs            # Connection pool setup
│   │       ├── queries/
│   │       │   ├── images.rs
│   │       │   ├── categories.rs
│   │       │   ├── users.rs
│   │       │   ├── tags.rs
│   │       │   ├── comments.rs
│   │       │   ├── permissions.rs
│   │       │   ├── sessions.rs
│   │       │   ├── config.rs
│   │       │   └── activity.rs
│   │       ├── bulk.rs            # mass_inserts, mass_updates equivalents
│   │       └── migrations/
│   │           ├── mysql/
│   │           │   ├── 0001_initial.sql
│   │           │   └── ...
│   │           └── postgres/
│   │               ├── 0001_initial.sql
│   │               └── ...
│   │
│   ├── gallery-image/          # Image processing (libvips wrapper)
│   │   └── src/
│   │       ├── lib.rs
│   │       ├── backend.rs         # ImageBackend trait
│   │       ├── vips.rs            # libvips-rs implementation
│   │       ├── pipeline.rs        # Orchestration: load → crop → resize → watermark → write
│   │       ├── sizing.rs          # SizingParams, ImageRect (port of PHP COI logic)
│   │       ├── derivatives.rs     # DerivativeParams, cache management, mtime checks
│   │       ├── exif.rs            # EXIF orientation, metadata extraction
│   │       ├── watermark.rs       # Alpha compositing
│   │       └── formats.rs         # Format detection, animated WebP
│   │
│   ├── gallery-metadata/       # EXIF/IPTC extraction
│   │   └── src/
│   │       ├── lib.rs
│   │       ├── exif.rs
│   │       ├── iptc.rs
│   │       └── mapping.rs         # Configurable field mapping (from $conf->use_exif_mapping)
│   │
│   ├── gallery-search/         # Search query building
│   │   └── src/
│   │       ├── lib.rs
│   │       ├── parser.rs          # Query string tokenizer
│   │       ├── scopes.rs          # Scope types: date, numeric, text, tag
│   │       └── builder.rs         # SQL query builder from parsed scopes
│   │
│   ├── gallery-plugins/        # Plugin & hook system
│   │   └── src/
│   │       ├── lib.rs
│   │       ├── event_bus.rs       # EventBus: trigger_notify, trigger_change
│   │       ├── events.rs          # All 144 event types as enum
│   │       ├── lua_bridge.rs      # mlua plugin host
│   │       ├── plugin_loader.rs   # Discovery, activation, lifecycle
│   │       ├── host_api.rs        # Lua-callable Rust functions
│   │       └── maintain.rs        # PluginMaintain trait
│   │
│   ├── gallery-auth/           # Authentication & sessions
│   │   └── src/
│   │       ├── lib.rs
│   │       ├── session.rs         # DB-backed session store
│   │       ├── extractors.rs      # Axum extractors: AuthenticatedUser, AdminUser
│   │       ├── permissions.rs     # Permission computation & caching
│   │       ├── login.rs           # Login flow, argon2id verify
│   │       ├── remember_me.rs     # HMAC-SHA1 cookie tokens
│   │       ├── api_keys.rs        # user_auth_keys management
│   │       └── csrf.rs
│   │
│   ├── gallery-sync/           # Filesystem synchronization
│   │   └── src/
│   │       ├── lib.rs
│   │       ├── orchestrator.rs    # 3-phase sync coordination
│   │       ├── scanner/
│   │       │   ├── mod.rs
│   │       │   ├── walkdir.rs     # Standard walkdir-based scanner
│   │       │   └── mft.rs         # Windows MFT reader (cfg(windows))
│   │       ├── phases/
│   │       │   ├── directories.rs # Phase 1: dir diff → category insert/delete
│   │       │   ├── files.rs       # Phase 2: file diff → image insert/delete
│   │       │   └── metadata.rs    # Phase 3: EXIF/IPTC extraction + DB update
│   │       ├── progress.rs        # SSE progress event types
│   │       └── profiler.rs        # Per-phase timing: p50/p95/p99
│   │
│   └── gallery-mail/           # Email (lettre wrapper)
│       └── src/
│           ├── lib.rs
│           ├── sender.rs
│           └── templates.rs
│
├── src/                       # Main binary
│   ├── main.rs
│   ├── cli.rs                 # Clap subcommands
│   ├── server.rs              # Axum router setup
│   │
│   ├── handlers/
│   │   ├── mod.rs
│   │   ├── gallery/
│   │   │   ├── index.rs       # Gallery browsing (index.php)
│   │   │   ├── picture.rs     # Image detail (picture.php)
│   │   │   ├── search.rs      # Search (search.php, qsearch.php)
│   │   │   ├── tags.rs        # Tags (tags.php)
│   │   │   ├── comments.rs    # Comments (comments.php)
│   │   │   ├── feed.rs        # RSS/Atom feeds (feed.php)
│   │   │   ├── identification.rs # Login/logout (identification.php)
│   │   │   ├── register.rs    # Registration
│   │   │   └── profile.rs     # User profile
│   │   ├── api/
│   │   │   ├── mod.rs         # ws.php equivalent — method registry + dispatch
│   │   │   ├── registry.rs    # MethodRegistry, ParamSchema, method registration
│   │   │   ├── encoders.rs    # JSON + REST/XML response encoding
│   │   │   ├── methods/
│   │   │   │   ├── session.rs
│   │   │   │   ├── categories.rs
│   │   │   │   ├── images.rs
│   │   │   │   ├── tags.rs
│   │   │   │   ├── users.rs
│   │   │   │   ├── groups.rs
│   │   │   │   └── permissions.rs
│   │   │   └── upload.rs      # Chunked upload handling
│   │   ├── admin/
│   │   │   ├── mod.rs         # Admin router, auth check
│   │   │   ├── intro.rs       # Dashboard
│   │   │   ├── albums.rs      # Album management
│   │   │   ├── photos.rs      # Photo management, uploads
│   │   │   ├── sync.rs        # Sync trigger + SSE stream
│   │   │   ├── batch.rs       # Bulk operations
│   │   │   ├── users.rs
│   │   │   ├── groups.rs
│   │   │   ├── permissions.rs
│   │   │   ├── configuration.rs
│   │   │   ├── maintenance.rs
│   │   │   ├── plugins.rs
│   │   │   ├── themes.rs
│   │   │   ├── tags.rs
│   │   │   ├── comments.rs
│   │   │   ├── history.rs
│   │   │   ├── stats.rs
│   │   │   └── updates.rs
│   │   └── derivative.rs      # i.php equivalent — on-demand thumbnail serving
│   │
│   ├── middleware/
│   │   ├── mod.rs
│   │   ├── session.rs         # Session extraction/persistence
│   │   ├── user.rs            # User loading + permission cache
│   │   ├── language.rs        # Language selection + i18n loading
│   │   ├── template_ctx.rs    # Global Tera context population
│   │   └── maintenance_mode.rs
│   │
│   ├── state.rs               # AppState: db pool, config, plugin bus, template engine
│   ├── config.rs              # Config loading (code defaults → file → DB)
│   └── i18n.rs                # Language file loading, l10n(), l10n_dec()
│
├── templates/                 # Tera templates (authored fresh; see Appendix E)
│   ├── base.html
│   ├── gallery/
│   │   ├── index.html
│   │   ├── picture.html
│   │   ├── search.html
│   │   ├── tags.html
│   │   └── comments.html
│   ├── user/
│   │   ├── identification.html
│   │   ├── register.html
│   │   └── profile.html
│   └── admin/
│       ├── base.html
│       ├── intro.html
│       ├── albums.html
│       ├── sync.html
│       ├── batch.html
│       ├── configuration.html
│       └── ... (all 57 admin pages)
│
├── static/                    # Static assets (CSS, JS, images)
│   ├── themes/
│   │   ├── default/
│   │   └── bootstrap_darkroom/
│   └── admin/
│
├── plugins/                   # Built-in Lua plugins
│   ├── AdminTools/
│   │   ├── plugin.toml        # Metadata (replaces pem_metadata.txt)
│   │   └── main.lua
│   ├── GDThumb/
│   ├── language_switch/
│   └── ...
│
├── language/                  # i18n files (JSON, migrated from PHP arrays)
│   ├── en_UK/
│   │   ├── common.json
│   │   └── admin.json
│   └── ...
│
├── migrations/                # Unified DB migrations
│   ├── mysql/
│   └── postgres/
│
└── tests/
    ├── integration/
    │   ├── api/               # Full API round-trip tests
    │   ├── auth/
    │   ├── sync/
    │   └── image/
    └── fixtures/
        ├── photos/            # Test images (JPEG, PNG, WebP, GIF)
        └── sql/               # Test database states
```

---

## 5. Database & Schema Strategy

### Supported Backends

- **MySQL 9.7 LTS** (primary target)
- **MariaDB 11.8 LTS** (compatibility target — shares the MySQL adapter with narrow overrides for JSON type, collation, UUID)
- **PostgreSQL 18**

Older minors accepted on best-effort basis down to MySQL 8.4 / MariaDB 11.4 / Postgres 16.

### Schema design principles

The new schema is **not** a port of Piwigo 14's schema. No `piwigo_` prefix, no `uppercats` comma-string ancestry, no denormalized `user_cache_categories`, no PHP-serialized blobs in `sessions.data` / `search.rules` / `user_infos.preferences`. Key principles:

- **snake_case table + column names.** No prefix.
- **`bigint` surrogate PKs** on every table for internal relations. `AUTO_INCREMENT` on MySQL/MariaDB, `GENERATED ALWAYS AS IDENTITY` on Postgres.
- **UUIDv7** for externally-exposed identifiers (API resources, derivative URLs). `BINARY(16)` on MySQL, native `UUID` on Postgres + MariaDB 10.7+. Time-ordered so B-tree-friendly.
- **Timestamps everywhere.** `created_at`, `updated_at` on every row; `deleted_at` on soft-deletable entities (users, comments — not photos or albums, which hard-delete).
- **Foreign keys with `ON DELETE CASCADE`** for owned relationships (image_tag → image); `ON DELETE SET NULL` for non-owning (album parent_id).
- **No polymorphic columns.** `taggable_type` / `taggable_id` anti-patterns stay out; separate join tables instead.
- **Adjacency-list album tree** with a cached materialized-path column (`path_ltree` on Postgres, generated column on MySQL) for `WHERE path LIKE '1/5/%'` descendant queries. Rebuilt on move via trigger, not application code.
- **JSON for EXIF, settings, plugin metadata.** Postgres `JSONB` with GIN indexes where queried; MySQL native `JSON`; MariaDB `LONGTEXT`-with-check-constraint alias accessed via `JSON_VALUE(...)`.
- **UTF-8 / utf8mb4 everywhere.** No Latin-1 fallbacks. Postgres `UTF8` + `C.UTF-8` collation; MySQL `utf8mb4_0900_ai_ci`; MariaDB `utf8mb4_uca1400_ai_ci`.
- **Email uniqueness is case-insensitive.** Generated column on MySQL/MariaDB; `CITEXT` on Postgres.
- **Explicit indexes on every foreign key and every WHERE-clause column.** No reliance on implicit engine behavior.

The full schema is catalogued in Appendix A (see also §16 of the PHP plan for the shared domain model — the two rewrites arrive at near-identical schema shapes because the domain is the same).

### Migration strategy

sqlx migrations in `migrations/mysql/` and `migrations/postgres/`, numbered and immutable (modifying an applied migration is caught by checksum).

- `gallery install` creates the schema from scratch on an empty database.
- `gallery upgrade` applies pending migrations (additive only within a major version).
- No import path from Piwigo 14. The repo does not ship a PHP-serialized-data reader. Users who want their content move it via re-upload or — at their own risk — write a standalone migration tool using the public REST API.
- Destructive migrations (drop columns, drop tables) happen only across major-version bumps and are called out in `CHANGELOG.md`.

### Query Safety Contract

**Rule:** No SQL query in this codebase may use string interpolation to inject user-supplied values. All user input must go through sqlx bind parameters.

Dynamic queries (search filters, bulk operations, category permission conditions) use an internal `QueryBuilder` struct that constructs parameterized queries — never string concatenation.

```rust
// FORBIDDEN:
let q = format!("WHERE category_id NOT IN ({})", user.forbidden_categories);

// REQUIRED:
let q = QueryBuilder::new("WHERE category_id != ALL($1)")
    .bind(&user.forbidden_categories);
```

---

## 6. Phase 1 — Foundation

**Duration: 6–8 weeks**  
**Goal:** A running Axum server with database connectivity, configuration loading, authentication, and session management. No gallery pages yet — just the skeleton everything else is built on.

> **On "Prior art" references in subsequent sections:** each task group cites the Piwigo PHP file(s) that implement equivalent behavior. Those citations are **reading material**, not things being translated line by line. The Rust implementation is designed fresh against the greenfield schema and API; the Piwigo files are consulted to understand edge cases, failure modes, and real-world surprise the PHP codebase already discovered. "Read this, then write the Rust version cleanly" — not "port this function."

---

### 1.1 Project Scaffold & Tooling

- [ ] Initialize Cargo workspace with all crates listed in §4
- [ ] Configure `rustfmt.toml` and `clippy.toml` for project conventions
- [ ] Set up GitHub Actions CI:
  - `cargo test` on push (MySQL + PostgreSQL via testcontainers)
  - `cargo clippy -- -D warnings`
  - `cargo fmt -- --check`
  - `cargo sqlx prepare --check` (verifies query metadata is up to date)
- [ ] Set up `cargo-deny` for dependency auditing
- [ ] Configure `SQLX_OFFLINE=true` for CI (uses prepared query metadata)
- [ ] Write `Dockerfile` and `compose.yaml` for local dev (MySQL + PostgreSQL + the `gallery` binary)
- [ ] Set up `cargo-watch` for hot-reload during development
- [ ] Establish `CHANGELOG.md` and semantic versioning policy

**Acceptance:** `cargo build --release` succeeds, CI passes on empty repo.

---

### 1.2 Configuration System

**Prior art:** `inc/config_default.php`, `inc/Config.php` (1,266 lines), `local/config/config.php`

- [ ] Define `GalleryConfig` struct with all ~900 config options, grouped by domain:
  ```rust
  pub struct GalleryConfig {
      pub server: ServerConfig,
      pub database: DatabaseConfig,
      pub gallery: GalleryConfig,
      pub upload: UploadConfig,
      pub image: ImageConfig,
      pub sync: SyncConfig,
      pub mail: MailConfig,
      pub auth: AuthConfig,
      pub plugins: PluginConfig,
  }
  ```
- [ ] Implement 3-tier loading:
  1. Code defaults via `Default` trait and `#[serde(default)]`
  2. File override from `local/config/config.toml` (new format) or `local/config/config.php` (parsed as key=value, legacy support)
  3. DB override: `SELECT param, value FROM config` — deserialize typed values (bool, int, float, string, JSON array)
- [ ] `gallery install` subcommand writes `local/config/config.toml` and `local/config/database.toml`
- [ ] Expose config to handlers via `axum::extract::State<Arc<AppState>>`
- [ ] Hot-reload of DB-sourced config on SIGHUP (Unix) or admin panel "reload" action

- [ ] Config domain sub-structs (see Appendix C for complete list):
  ```rust
  pub struct GalleryConfig {
      pub title: String,                          // "Just another Piwigo gallery"
      pub url: Option<String>,                    // null = auto-detect
      pub locked: bool,                           // false
      pub page_banner: String,                    // HTML with %gallery_title%
      pub show_version: bool,                     // false
      pub show_thumbnail_caption: bool,           // true
      pub level_separator: String,                // " / "
      pub paginate_pages_around: u32,             // 2
      pub top_number: u32,                        // 15
      pub allow_html_descriptions: bool,          // true
      pub random_index_redirect: Vec<String>,     // []
  }
  pub struct AlbumConfig {
      pub default_commentable: bool,              // true
      pub default_visible: bool,                  // true
      pub default_status: CategoryStatus,         // Public
      pub default_position: Position,             // First
      pub nb_categories_page: u32,                // 12
      pub allow_random_representative: bool,      // false
      pub inheritance_by_default: bool,           // false
  }
  pub struct ImageConfig {
      pub picture_ext: Vec<String>,               // jpg,jpeg,png,gif,webp
      pub format_ext: Vec<String>,                // cr2,tif,tiff,nef,dng,ai,psd
      pub enable_formats: bool,                   // false
      pub uniqueness_mode: UniquenessMode,        // Md5sum
      pub available_permission_levels: Vec<u8>,   // 0,1,2,4,8
      pub graphics_library: GraphicsLibrary,      // Auto
  }
  pub struct DerivativeConfig {
      pub params: ImageStdParams,                 // DB: serialized derivative sizes
      pub default_size: DerivativeType,           // Medium
      pub strip_metadata_threshold: u32,          // 256000 pixels
      pub animated_webp_quality: u8,              // 70
      pub original_resize: bool,                  // false
      pub original_resize_maxwidth: u32,          // 2016
      pub original_resize_maxheight: u32,         // 2016
      pub original_resize_quality: u8,            // 95
  }
  pub struct UploadConfig {
      pub dir: PathBuf,                           // ./upload
      pub chunk_size_kb: u32,                     // 500
      pub max_file_size_mb: u32,                  // 1000
      pub automatic_rotation: bool,               // true
  }
  pub struct AuthConfig {
      pub guest_id: i32,                          // 2
      pub webmaster_id: i32,                      // 1
      pub guest_access: bool,                     // true
      pub allow_registration: bool,               // false
      pub insensitive_case_logon: bool,           // false
      pub session_length: u64,                    // 3600
      pub session_use_ip: bool,                   // true
      pub remember_me_enabled: bool,              // true
      pub remember_me_length: u64,                // 5184000 (60 days)
      pub auth_key_duration: u64,                 // 259200 (3 days)
      pub secret_key: String,                     // random per install
  }
  pub struct UrlConfig {
      pub category_style: UrlStyle,               // Id
      pub picture_style: PictureUrlStyle,         // Id
      pub tag_style: TagUrlStyle,                 // IdTag
  }
  ```
- [ ] **Structured DB-stored config:** complex config values (derivative-preset table, watermark params, per-theme settings, plugin settings) are stored as JSON in the `settings` table — native `JSON` on MySQL/Postgres, `LONGTEXT`-with-check on MariaDB. No PHP-serialized format anywhere; the repo does not ship a `serialize()`/`unserialize()` parser.
- [ ] **Validation:** Validate config values on load — e.g., `session_length > 0`, `original_resize_quality` in 1..100, `uniqueness_mode` is valid enum value. Log warnings for invalid values and use defaults.
- [ ] **Test cases:** Unit test for each config type: default values, TOML round-trip, DB override precedence, validation rejection for out-of-range values.

**Acceptance:** Config loads, all 3 tiers merge correctly, unit tests cover all value types.

---

### 1.3 Database Layer (gallery-db crate)

**Prior art:** `inc/dblayer/functions_mysqli.php` (890 lines), `inc/dblayer/functions_pgsql.php` (858 lines)

- [ ] Define `DbPool` enum wrapping `sqlx::MySqlPool` and `sqlx::PgPool`:
  ```rust
  pub enum DbPool {
      Mysql(MySqlPool),
      Postgres(PgPool),
  }
  ```
- [ ] Port all abstraction functions:
  - `query_one`, `query_opt`, `query_all` — typed fetch variants
  - `execute` — for inserts/updates/deletes
  - `mass_inserts(table, columns, rows, batch_size)` — chunked batch insert with single transaction
  - `mass_updates(table, update_cols, where_cols, rows)` — batch update
  - `last_insert_id()` — MySQL vs PostgreSQL (RETURNING id)
  - `affected_rows()`
- [ ] Implement `QueryBuilder` for dynamic WHERE clauses:
  ```rust
  let mut qb = QueryBuilder::new("SELECT id FROM images WHERE 1=1");
  if let Some(level) = filter.level {
      qb.push(" AND level <= ").push_bind(level);
  }
  if !forbidden.is_empty() {
      qb.push(" AND category_id != ALL(").push_bind(forbidden.as_slice()).push(")");
  }
  ```
- [ ] Write SQL migrations for both MySQL and PostgreSQL (all 34 tables)
- [ ] `gallery install` runs `sqlx::migrate!()` to apply all migrations
- [ ] `gallery upgrade` runs pending migrations
- [ ] Connection pool configuration: max connections, acquire timeout, idle timeout
- [ ] Query logging in debug mode (`RUST_LOG=piwigo_db=debug` logs all queries)

- [ ] **`mass_inserts` implementation detail:**
  ```rust
  /// Batch insert with chunking to avoid parameter limit (MySQL: 65535, PG: 32767).
  /// Each chunk is a single INSERT ... VALUES (...), (...), ...
  /// Wraps all chunks in a transaction.
  pub async fn mass_inserts(
      pool: &DbPool,
      table: &str,
      columns: &[&str],
      rows: &[Vec<SqlValue>],
      batch_size: usize,  // default: 50 for MySQL, 100 for PG
  ) -> Result<u64> {
      let params_per_row = columns.len();
      let max_params = match pool {
          DbPool::Mysql(_) => 65535,
          DbPool::Postgres(_) => 32767,
      };
      let effective_batch = batch_size.min(max_params / params_per_row);
      // ...chunk and execute in transaction
  }
  ```
- [ ] **`mass_updates` implementation detail:**
  ```rust
  /// Batch update using CASE/WHEN for MySQL, or UPDATE FROM VALUES for PG.
  /// MySQL: UPDATE t SET col1 = CASE id WHEN 1 THEN 'a' WHEN 2 THEN 'b' END WHERE id IN (1,2)
  /// PG: UPDATE t SET col1 = v.col1 FROM (VALUES (1,'a'),(2,'b')) AS v(id,col1) WHERE t.id = v.id
  pub async fn mass_updates(
      pool: &DbPool,
      table: &str,
      update_cols: &[&str],
      where_cols: &[&str],  // usually just ["id"]
      rows: &[HashMap<String, SqlValue>],
  ) -> Result<u64>;
  ```
- [ ] **Cross-DB helper functions:**
  ```rust
  /// Returns DB-specific SQL fragments
  pub trait DbDialect {
      fn regex_operator(&self) -> &str;        // MySQL: "REGEXP", PG: "~"
      fn random_function(&self) -> &str;        // MySQL: "RAND()", PG: "random()"
      fn boolean_true(&self) -> &str;           // MySQL: "'true'", PG: "true"
      fn boolean_false(&self) -> &str;          // MySQL: "'false'", PG: "false"
      fn date_sub(&self, interval_days: i32) -> String;  // DB-specific date math
      fn upsert_prefix(&self) -> &str;          // MySQL: "REPLACE INTO", PG: "INSERT INTO"
      fn upsert_suffix(&self, conflict_cols: &[&str]) -> String;  // PG: "ON CONFLICT (...) DO UPDATE SET ..."
      fn concat_ws(&self, sep: &str, cols: &[&str]) -> String;
      fn limit_offset(&self, limit: u32, offset: u32) -> String;
  }
  ```
- [ ] **Migration file naming:** `{NNN}_{description}.sql` in `migrations/mysql/` and `migrations/postgres/`.
  - `001_initial_schema.sql` — creates all 34 tables (see Appendix A)
  - `002_add_json_columns.sql` — adds `sessions.data_json`, `search.rules_json`, `user_infos.preferences_json`
  - `003_add_foreign_keys.sql` — adds FK constraints to all implicit relationships (see Appendix A §Key Schema Notes)
  - `004_migrate_php_serialized.sql` — one-time data migration of PHP serialized → JSON
- [ ] **Connection pool defaults:**
  - `max_connections`: 10 (tunable via config)
  - `min_connections`: 2
  - `acquire_timeout`: 5s
  - `idle_timeout`: 600s
  - `max_lifetime`: 1800s

**Acceptance:** Both MySQL and PostgreSQL connect and migrate successfully. Integration tests with testcontainers pass for all query modules.

---

### 1.4 Core Domain Types (gallery-core crate)

- [ ] `AccessLevel` enum:
  ```rust
  #[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord)]
  #[repr(u8)]
  pub enum AccessLevel {
      Free          = 0,
      Guest         = 1,
      Classic       = 2,
      Administrator = 3,
      Webmaster     = 4,
  }
  ```
- [ ] `UserStatus` enum (maps to `user_infos_status` DB enum):
  ```rust
  #[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Serialize, Deserialize, sqlx::Type)]
  #[sqlx(type_name = "user_infos_status", rename_all = "lowercase")]
  pub enum UserStatus {
      Guest     = 0,
      Generic   = 1,
      Normal    = 2,
      Admin     = 3,
      Webmaster = 4,
  }
  impl UserStatus {
      pub fn is_admin_or_above(&self) -> bool { *self >= Self::Admin }
  }
  ```
- [ ] `CategoryStatus` enum: `Public`, `Private`
- [ ] `Image` struct (maps to `images` table — see Appendix A.14):
  ```rust
  #[derive(Debug, Clone, Serialize, Deserialize, sqlx::FromRow)]
  pub struct Image {
      pub id: i32,
      pub file: String,
      pub date_available: DateTime<Utc>,
      pub date_creation: Option<DateTime<Utc>>,
      pub name: Option<String>,
      pub comment: Option<String>,
      pub author: Option<String>,
      pub hit: i32,
      pub filesize: Option<i32>,          // KB
      pub width: Option<i32>,
      pub height: Option<i32>,
      pub coi: Option<String>,            // 4-char center-of-interest
      pub representative_ext: Option<String>,
      pub date_metadata_update: Option<NaiveDate>,
      pub rating_score: Option<f32>,
      pub path: String,
      pub storage_category_id: Option<i32>,
      pub level: i32,                     // privacy level: 0,1,2,4,8
      pub md5sum: Option<String>,         // 32-char hex
      pub added_by: i32,
      pub rotation: Option<i32>,
      pub latitude: Option<f64>,
      pub longitude: Option<f64>,
      pub lastmodified: Option<DateTime<Utc>>,
  }
  ```
- [ ] `Category` struct (maps to `categories` table — see Appendix A.3):
  ```rust
  #[derive(Debug, Clone, Serialize, Deserialize, sqlx::FromRow)]
  pub struct Category {
      pub id: i32,
      pub name: String,
      pub id_uppercat: Option<i64>,       // parent category ID
      pub comment: Option<String>,
      pub dir: Option<String>,            // physical directory name (null for virtual albums)
      pub sort_rank: Option<i32>,
      pub status: CategoryStatus,
      pub site_id: Option<i32>,
      pub visible: bool,
      pub representative_picture_id: Option<i32>,
      pub uppercats: Option<String>,      // "1,5,12" — ancestor chain
      pub commentable: bool,
      pub global_rank: Option<String>,    // "1.2.3" — sort rank path
      pub image_order: Option<String>,    // custom ORDER BY for this album
      pub permalink: Option<String>,
      pub lastmodified: Option<DateTime<Utc>>,
  }
  impl Category {
      pub fn is_physical(&self) -> bool { self.dir.is_some() }
      pub fn is_virtual(&self) -> bool { self.dir.is_none() }
      pub fn parent_ids(&self) -> Vec<i32> {
          self.uppercats.as_deref().unwrap_or("")
              .split(',').filter_map(|s| s.parse().ok()).collect()
      }
  }
  ```
- [ ] `User` struct (from `users` + `user_infos` JOIN):
  ```rust
  #[derive(Debug, Clone, Serialize, Deserialize, sqlx::FromRow)]
  pub struct User {
      // from users table
      pub id: i32,
      pub username: String,
      #[serde(skip_serializing)]
      pub password: Option<String>,
      pub mail_address: Option<String>,
      // from user_infos table
      pub status: UserStatus,
      pub level: i32,
      pub language: String,
      pub theme: String,
      pub nb_image_page: i32,
      pub recent_period: i32,
      pub expand: bool,
      pub show_nb_comments: bool,
      pub show_nb_hits: bool,
      pub enabled_high: bool,
      pub registration_date: DateTime<Utc>,
      pub last_visit: Option<DateTime<Utc>>,
  }
  ```
- [ ] `DerivativeType` enum with 2-char code mapping:
  ```rust
  #[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
  pub enum DerivativeType {
      Square,   // sq  120×120 crop=1.0
      Thumb,    // th  144×144
      XXSmall,  // 2s  240×240
      XSmall,   // xs  432×324
      Small,    // sm  576×432
      Medium,   // me  792×594
      Large,    // la  1008×756
      XLarge,   // xl  1224×918
      XXLarge,  // xx  1656×1242
      Custom(CustomDerivativeParams),
  }
  impl DerivativeType {
      pub fn code(&self) -> &str {
          match self {
              Self::Square => "sq", Self::Thumb => "th",
              Self::XXSmall => "2s", Self::XSmall => "xs",
              Self::Small => "sm", Self::Medium => "me",
              Self::Large => "la", Self::XLarge => "xl",
              Self::XXLarge => "xx", Self::Custom(_) => "cu",
          }
      }
      pub fn from_code(code: &str) -> Option<Self> { /* reverse mapping */ }
  }
  ```
- [ ] `Tag`, `Comment`, `Rate` structs matching their respective table schemas
- [ ] `GalleryError` type hierarchy with `thiserror` (see ADR-006 for full definition)
- [ ] All types implement `serde::Serialize` + `serde::Deserialize` where appropriate

---

### 1.5 Authentication & Session Middleware (gallery-auth crate)

**Prior art:** `inc/functions_session.php`, `inc/functions_user.php`, `inc/user.php`, `identification.php`

#### 1.5.1 Database-Backed Session Store

- [ ] Define `GallerySessionStore` implementing `tower_sessions::SessionStore`
- [ ] Session ID format: `{ipv4_hex_4bytes}{random_session_id}` — matches PHP's IP-binding
- [ ] `create`: INSERT INTO sessions (id, data_json, expiration)
- [ ] `load`: SELECT data_json FROM sessions WHERE id = ? AND expiration > NOW()
- [ ] `save`: INSERT ... ON CONFLICT (id) DO UPDATE SET data_json, expiration
- [ ] `delete`: DELETE FROM sessions WHERE id = ?
- [ ] GC: DELETE FROM sessions WHERE expiration < NOW() — triggered probabilistically (1% of requests) or via `gallery maintenance sessions`
- [ ] Session data stored as JSON (not PHP serialized)
- [ ] Write one-time migration that reads PHP serialized sessions and writes JSON equivalent

#### 1.5.2 Auth Extractors

- [ ] `AuthenticatedUser` extractor — checks in order:
  1. Session cookie → `session.user_id`
  2. `?auth=` query param or `Authorization: Bearer` → API key lookup
  3. `X-Remote-User` header (Apache passthrough) → username lookup
  4. Falls back to guest user (id from config)
- [ ] `AdminUser` extractor — wraps `AuthenticatedUser`, returns 403 if `access_level < Administrator`
- [ ] `WebmasterUser` extractor — same, requires Webmaster level

#### 1.5.3 Permission Computation & Caching

- [ ] `PermissionCache` struct backed by `moka::sync::Cache<u32, CachedPermissions>` (in-process, TTL 5 min)
- [ ] `CachedPermissions`:
  ```rust
  pub struct CachedPermissions {
      pub forbidden_categories: Vec<i32>,
      pub image_access_type: AccessType,    // NotIn or In
      pub image_access_list: Vec<i32>,
      pub nb_total_images: u32,
  }
  ```
- [ ] `calculate_permissions(user_id, db)`:
  - SELECT private categories
  - MINUS direct user grants (user_access)
  - MINUS group grants (user_group → group_access)
  - PLUS invisible categories (if non-admin)
  - Result = `forbidden_categories`
- [ ] `invalidate_user_cache(user_id)` — removes from in-process cache AND sets `need_update=true` in DB
- [ ] `invalidate_all_caches()` — for category structure changes
- [ ] Permission SQL helper `build_permission_condition(perms: &CachedPermissions) -> String`

#### 1.5.4 Login/Logout Flow

- [ ] `POST /api/v1/auth/login` → validate username/password via argon2id, create session
- [ ] `POST /identification` (logout) → destroy session, clear cookies
- [ ] Remember-me cookie: generate `{user_id}-{timestamp}-{hmac_sha1}`, validate on `auto_login()`
- [ ] Session regeneration on login (delete old session ID, create new)
- [ ] CSRF token: `HMAC-SHA256(session_id, secret_key)` — exposed in `GET /api/v1/auth/me` response body (browser clients read it from there and echo via `X-CSRF-Token` header on mutations)
- [ ] Rate limiting on login endpoint: max 10 attempts per IP per minute (tower governor)

**Acceptance:** Login, logout, remember-me, API key auth all work. Permission cache returns correct forbidden categories. Integration tests cover all auth paths.

---

### 1.6 AppState & Server Bootstrap

- [ ] `AppState` struct:
  ```rust
  pub struct AppState {
      pub db: DbPool,
      pub config: Arc<RwLock<GalleryConfig>>,
      pub template: Arc<Tera>,
      pub plugins: Arc<EventBus>,
      pub permissions: Arc<PermissionCache>,
      pub image_params: Arc<RwLock<ImageStdParams>>,
      pub i18n: Arc<I18n>,
  }
  ```
- [ ] Axum router setup with all middleware layers:
  ```
  TraceLayer (HTTP logging)
  → CompressionLayer (gzip)
  → MaintenanceModeLayer (503 when gallery locked)
  → SessionLayer (DB-backed)
  → UserLayer (loads authenticated user into extensions)
  → LanguageLayer (selects language, loads strings)
  → CsrfLayer (validates token on POST)
  ```
- [ ] Graceful shutdown on SIGTERM/SIGINT (drain in-flight requests, close DB pool)
- [ ] Health check endpoint: `GET /health` → 200 OK (for load balancers)
- [ ] Startup checks: DB connectivity, writable `storage/` and `var/` directories, libvips version

**Acceptance:** `gallery serve` starts, health check returns 200, middleware stack processes a request end-to-end.

---

### 1.7 i18n System

**Prior art:** `inc/functions.php` (l10n, l10n_dec), language loading in `inc/common.php`

- [ ] Language files: migrate PHP `$lang['key'] = 'value'` arrays to JSON
  - Write one-time PHP script to export all language files as JSON
  - ~150+ language directories, 2 files each
- [ ] `I18n` struct:
  ```rust
  pub struct I18n {
      languages: HashMap<String, LanguageStrings>,
      default_lang: String,
  }
  pub struct LanguageStrings {
      strings: HashMap<String, String>,
      zero_plural: bool,
      direction: TextDirection,
  }
  ```
- [ ] `l10n(lang, key) -> &str` — lookup with fallback to `en_UK`
- [ ] `l10n_dec(lang, key_singular, key_plural, count) -> String` — plural forms
- [ ] `l10n_args(lang, key, args) -> String` — sprintf-style interpolation
- [ ] Language loading on startup (all languages into memory, ~5MB total)
- [ ] Tera filter registration: `{{ 'key' | translate }}`, `{{ count | translate_dec('singular', 'plural') }}`
- [ ] Language selection middleware: checks user preference → browser `Accept-Language` → config default

**Acceptance:** `{{ 'Username' | translate }}` renders correct string in English and French. Plural forms work for 0/1/many.

---

## 7. Phase 2 — Core Read Paths

**Duration: 4–6 weeks**  
**Goal:** A working gallery that users can browse. No uploads, no admin, no write operations. At the end of this phase, the gallery can serve as a read-only replacement.

---

### 2.1 URL Routing

**Prior art:** `inc/section_init.php` (648 lines), `inc/functions_url.php`

- [ ] Define `GallerySection` enum:
  ```rust
  pub enum GallerySection {
      Home,
      Category { id: i32, flat: bool },
      Tags { ids: Vec<i32> },
      Search { id: String },
      Favorites,
      MostVisited,
      BestRated,
      RecentPics,
      RecentCats,
      List,
  }
  ```
- [ ] URL parser: tokenize path info (`/category/12-album/start-24` → `[category, 12-album, start-24]`)
- [ ] **Two-phase dispatch** (matches PHP `section_init.php` — see Appendix F):
  1. `parse_section_url(tokens)` → identify section type and primary resource
  2. `parse_well_known_params(remaining_tokens)` → extract modifiers (flat, start-N, startcat-N, chronology)
- [ ] Implement all 3 URL style variants per entity type:
  - Categories: `id`, `id-name` (per `config.url.category_style`)
  - Pictures: `id`, `id-file`, `file` (per `config.url.picture_style`)
  - Tags: `id-tag`, `id`, `tag` (per `config.url.tag_style`)
- [ ] **Category resolution algorithm:**
  1. Try numeric parse → direct ID lookup
  2. Try `{id}-{slug}` parse → ID lookup + validate slug matches, 301 redirect if slug changed
  3. Try permalink lookup in `categories.permalink` → resolve to ID
  4. Try `old_permalinks` table → 301 redirect to current URL
  5. 404 if none match
- [ ] **Tag resolution:** Tags can appear as `{id}-{url_name}` pairs separated by `/` in the URL. Multiple tags = AND filter by default, OR if `tag_mode_and=false`.
- [ ] **Chronology parsing:** Tokens like `created-monthly-2026-04` are parsed into:
  ```rust
  pub struct ChronologyParams {
      pub field: ChronologyField,     // Created or Posted (date_creation or date_available)
      pub style: ChronologyStyle,     // Monthly or Weekly
      pub view: ChronologyView,       // List or Calendar
      pub date: Vec<i32>,             // [year] or [year, month] or [year, month, day]
  }
  ```
- [ ] Pagination: extract `start-N` token, validate against item count. `startcat-N` for sub-album pagination (separate from image pagination).
- [ ] URL generation helpers: `make_index_url(section, start)`, `make_picture_url(image_id, cat_id)`, etc.
- [ ] Canonical URL header on all pages
- [ ] **Picture page context:** `picture.php` URLs carry section context (which album/tag/search the user navigated from). This determines prev/next navigation. Parse as:
  ```rust
  pub struct PicturePageContext {
      pub image_id: i32,
      pub section: GallerySection,    // the album/tag/search context
      pub start: u32,                 // pagination position in the list
  }
  ```

---

### 2.2 Gallery Index Handler

**Prior art:** `index.php` (726 lines), `inc/section_init.php`

- [ ] Category image listing query with permission filtering:
  ```sql
  SELECT DISTINCT image_id, [order_fields]
  FROM image_category
  INNER JOIN images ON id = image_id
  WHERE category_id = ? AND category_id != ALL(?) AND level <= ?
  ORDER BY [config_order]
  ```
- [ ] Persistent query cache (moka, keyed by MD5 of full SQL + user cache key)
- [ ] Image ordering — 7 built-in sort orders (all whitelisted, never user-supplied SQL):
  ```rust
  pub enum ImageOrder {
      DateAvailableDesc,    // "date_available DESC, file ASC, id ASC"
      DateAvailableAsc,     // "date_available ASC, file ASC, id ASC"
      DateCreationDesc,     // "date_creation DESC, file ASC, id ASC"
      DateCreationAsc,      // "date_creation ASC, file ASC, id ASC"
      FileName,             // "file ASC, id ASC"
      FileNameDesc,         // "file DESC, id DESC"
      Rating,               // "rating_score DESC, file ASC, id ASC"
      Visits,               // "hit DESC, file ASC, id ASC"
      Random,               // "RANDOM()" (PG) or "RAND()" (MySQL)
      Rank,                 // "sort_rank ASC, id ASC" (inside category only)
  }
  ```
- [ ] Pagination: `user_infos.nb_image_page` images per page (default 15). Offset via `start-N` URL token.
- [ ] **Sub-categories query:**
  ```sql
  SELECT c.*, ucc.nb_images, ucc.count_images, ucc.count_categories,
         ucc.max_date_last, ucc.user_representative_picture_id
  FROM categories c
  JOIN user_cache_categories ucc ON c.id = ucc.cat_id AND ucc.user_id = ?
  WHERE c.id_uppercat = ?
    AND c.id NOT IN (?)  -- forbidden categories
  ORDER BY c.sort_rank, c.name
  ```
- [ ] **Representative image selection:** For each sub-category, show a cover image:
  1. Use `categories.representative_picture_id` if set
  2. Else use `user_cache_categories.user_representative_picture_id` if set
  3. Else pick a random image from the category (and cache the choice in `user_cache_categories`)
- [ ] **"Flat" view:** Fetch all descendant category IDs via `uppercats LIKE '{current_uppercats},%'`, then query images across all:
  ```sql
  SELECT DISTINCT i.id, [order_fields]
  FROM images i
  JOIN image_category ic ON i.id = ic.image_id
  WHERE ic.category_id IN (?)   -- all descendant categories
    AND ic.category_id NOT IN (?) -- forbidden
    AND i.level <= ?
  ORDER BY [config_order]
  ```
- [ ] **Chronology (calendar) view:** Group images by date field (creation or available):
  - Level 1: years → `SELECT EXTRACT(YEAR FROM date_creation) AS year, COUNT(*) FROM images ... GROUP BY year`
  - Level 2: months within year → same with `EXTRACT(MONTH ...)`
  - Level 3: days within month → same with `EXTRACT(DAY ...)`
  - Display as calendar grid or list (per chronology_view parameter)
- [ ] **Breadcrumb:** Parse `uppercats` string ("1,5,12") → fetch names for each ID → build `[{id, name, url}]` trail
- [ ] **Template variables assigned** (key ones): `TITLE`, `items` (image array), `categories` (sub-album array), `BREADCRUMB`, `START`, `NB_IMAGES`, `derivative_params`, `navbar` (pagination HTML)
- [ ] `trigger_notify('loc_begin_index')` and `trigger_notify('loc_end_index')`
- [ ] `trigger_change('loc_begin_index_category_thumbnails_query', sql)` — plugin can modify SQL
- [ ] `trigger_change('loc_end_index_thumbnails', tpl_var, pictures)` — plugin can modify thumbnail template data

---

### 2.3 Image Detail Handler

**Prior art:** `picture.php` (976 lines)

- [ ] Fetch image metadata: all fields from `images` table
- [ ] Category membership: `SELECT category_id FROM image_category WHERE image_id = ?`
- [ ] Comments: fetch approved comments for image with pagination
- [ ] Hit counter increment: `UPDATE images SET hit = hit + 1 WHERE id = ?`
  - Rate-limited: one increment per session per image
  - Configurable: `$conf->count_views`
- [ ] Privacy level check: `image.level <= user.level` — 403 if inaccessible
- [ ] **Navigation algorithm** (previous/next/first/last within section context):
  1. Use the same query as the index page (same section, same filters, same ORDER BY)
  2. Fetch the full ordered image ID list for the section (or a window around current position)
  3. Find current `image_id` in the list → `prev = list[pos-1]`, `next = list[pos+1]`
  4. Assign to template: `previous.id`, `next.id`, `first.id`, `last.id` with their derivative URLs
  5. **Optimization:** Don't fetch the full list for large sections. Instead, use two bounded queries:
     ```sql
     -- Previous image
     SELECT id FROM images ... WHERE (order_fields) < (current_fields) ORDER BY order_fields DESC LIMIT 1
     -- Next image
     SELECT id FROM images ... WHERE (order_fields) > (current_fields) ORDER BY order_fields ASC LIMIT 1
     ```
- [ ] Download link: served via `GET /action.php?id={image_id}&part=e&download` → `Content-Disposition: attachment`
- [ ] **Alternative formats download:** If `enable_formats` is true, show download links for each format in `image_format` table (CR2, DNG, etc.)
- [ ] Related tags: `SELECT t.* FROM tags t JOIN image_tag it ON t.id = it.tag_id WHERE it.image_id = ?`
- [ ] Slideshow: JSON data for JS slideshow (image list, derivative URLs)
- [ ] `trigger_notify('loc_begin_picture')` and `trigger_notify('loc_end_picture')`

---

### 2.4 On-Demand Derivative (Thumbnail) Serving

**Prior art:** `i.php` (350 lines), `inc/ImageStdParams.php`, `inc/DerivativeParams.php`

This is a high-traffic endpoint — every thumbnail request hits it.

- [ ] Parse derivative URL: `GET /media/{preset}/{uuid}.{ext}` → extract UUID, preset, and format
- [ ] Custom derivative parsing: `th_cx200y150` format → width=200, height=150, crop=true
- [ ] Cache check: compare `stat(derivative_path).mtime` vs `stat(source_path).mtime` and `params.last_modified`
- [ ] If cache hit: return with `Last-Modified`, `Expires: +10 days`, `ETag`
- [ ] If cache miss: invoke image pipeline (§8)
- [ ] `304 Not Modified` on `If-Modified-Since` match
- [ ] Serve via `tokio::fs::File` with `tower_http::services::ServeFile`
- [ ] Rate limit custom derivatives: max 1 new custom derivative per 5 seconds per IP

---

### 2.5 Search Handler

**Prior art:** `inc/functions_search.php` (1,254 lines), `search.php`, `qsearch.php`

- [ ] Port tokenizer: split query into scopes (tag:, category:, author:, date range, free text)
- [ ] `SearchQuery` type with multiple `Scope` variants:
  ```rust
  pub enum Scope {
      FreeText(String),
      Tag(Vec<i32>),
      Category(Vec<i32>),
      Author(String),
      DateRange { field: DateField, from: NaiveDate, to: NaiveDate },
      NumericRange { field: NumericField, min: i64, max: i64 },
  }
  ```
- [ ] Build parameterized SQL from scopes — no string interpolation
- [ ] Store search in `search` table: save rules as JSON, return `search_id`
- [ ] Quick search (`qsearch.php`): autocomplete suggestions for tags, categories
- [ ] Apply permission filtering to all search results

---

### 2.6 Feed Handler

**Prior art:** `feed.php`

- [ ] RSS 2.0 feed using `rss` crate
- [ ] Atom feed using `atom_syndication` crate
- [ ] Feed types: latest photos, per-user notification digest, per-category
- [ ] Feed items: image title, thumbnail URL, date, description
- [ ] Authentication via `?auth_key=` query param for private feeds

---

### 2.7 Additional Read Pages

- [ ] Tags listing (`tags.php`) — list all tags with image counts, link to tag image listing
- [ ] Comments page (`comments.php`) — list approved comments across gallery
- [ ] Random image (`random.php`) — redirect to random accessible image
- [ ] User favorites listing — requires authentication
- [ ] Most visited / best rated / recent pics — pre-built SQL queries with permission filtering

---

## 8. Phase 3 — Image Pipeline

**Duration: 4–6 weeks**  
**Goal:** Full image processing — derivative generation, upload pipeline, metadata extraction.

---

### 3.1 libvips-rs Backend (gallery-image crate)

- [ ] Define `ImageBackend` trait:
  ```rust
  pub trait ImageBackend: Send + Sync {
      fn load(path: &Path) -> Result<Self>;
      fn width(&self) -> u32;
      fn height(&self) -> u32;
      fn rotate(&mut self, degrees: u32) -> Result<()>;      // 0, 90, 180, 270
      fn crop(&mut self, w: u32, h: u32, x: u32, y: u32) -> Result<()>;
      fn resize(&mut self, w: u32, h: u32) -> Result<()>;
      fn sharpen(&mut self, amount: f64) -> Result<()>;
      fn compose(&mut self, overlay: &Self, x: i32, y: i32, opacity: f64) -> Result<()>;
      fn strip_metadata(&mut self) -> Result<()>;
      fn set_quality(&mut self, quality: u8);
      fn write(&self, dest: &Path) -> Result<()>;
  }
  ```
- [ ] `VipsImage` implementing `ImageBackend`:
  - `resize`: Lanczos filter, preserve aspect ratio
  - `crop`: native VIPS crop
  - `rotate`: `vips_rot()` for 90° increments; `vips_similarity()` for arbitrary (EXIF correction)
  - `sharpen`: `vips_sharpen()` with configurable sigma/m1/m2/x1/y2/y3
  - `compose`: `vips_composite2(VIPS_BLEND_MODE_OVER)` with opacity
  - `strip_metadata`: `vips_autorot()` + clear EXIF fields
  - `write`: `vips_image_write_to_file()` — format inferred from extension; JPEG uses chroma subsampling 4:2:2, progressive encoding
- [ ] Format detection: `infer` crate for magic bytes, not file extension
- [ ] Animated WebP detection: parse RIFF headers for VP8X + ANIM chunks
- [ ] Animated WebP quality cap: 70 (matches PHP behavior)

---

### 3.2 Derivative Parameters & Sizing

**Prior art:** `inc/ImageStdParams.php`, `inc/DerivativeParams.php`, `inc/SizingParams.php`, `inc/ImageRect.php`

- [ ] Port `SizingParams` struct: `max_width`, `max_height`, `max_crop`
- [ ] Port `ImageRect` struct and COI (center-of-interest) crop algorithm:
  - Input: source dimensions, target dimensions, COI coordinates (from DB)
  - Output: crop rectangle that centers on COI while fitting target dimensions
- [ ] Port `DerivativeParams` struct: type, sizing, quality, watermark config, last_modified timestamp
- [ ] `ImageStdParams` loaded from DB config on startup, refreshed on admin change
- [ ] All 9 standard sizes defined as `DerivativeType` enum
- [ ] Cache invalidation: when `ImageStdParams` change, set `params.last_modified` to now — all cached derivatives with older mtime are regenerated on next request

---

### 3.3 Derivative Generation Pipeline

**Prior art:** `i.php` main generation block (lines 196–290)

- [ ] `generate_derivative(source_path, derivative_type, params, output_path)`:
  1. `VipsImage::load(source_path)`
  2. Read EXIF orientation → `image.rotate(degrees)` (before anything else)
  3. Crop (with COI if configured): `SizingParams::compute_crop_rect()`
  4. Resize to target dimensions
  5. Sharpen if configured
  6. Composite watermark if configured and output size ≥ watermark minimum
  7. Strip metadata if output size < threshold
  8. Set JPEG quality
  9. `image.write(output_path)`
- [ ] Atomic write: write to temp file, then `std::fs::rename` (atomic on POSIX, near-atomic on Windows)
- [ ] Concurrent generation: `tokio::sync::Semaphore` to cap concurrent derivative generations (default: CPU count)
- [ ] Derivative URL generation: `make_derivative_url(source_path, derivative_type)` → `/var/derivatives/path/to/photo-sq.jpg`
- [ ] Missing derivatives scan: `GET /admin/maintenance?action=generate_derivatives` triggers background generation for all missing sizes

---

### 3.4 Watermark System

**Prior art:** `inc/WatermarkParams.php`, watermark block in `i.php`

- [ ] `WatermarkParams`: file path, min output size, x/y position (0–100%), x/y repeat count, opacity (0–100%)
- [ ] Load watermark image once at startup into shared `Arc<VipsImage>`
- [ ] Scale watermark to fit output if output < watermark dimensions
- [ ] Position calculation: `x = (xpos/100) * (output_width - wm_width)`
- [ ] Tiling: if `xrepeat > 0`, tile horizontally at interval
- [ ] Opacity: VIPS composite with alpha premultiplication

---

### 3.5 EXIF/IPTC Metadata Extraction (gallery-metadata crate)

**Prior art:** `inc/functions_metadata.php`, `admin/inc/functions_metadata_admin.php` (533 lines)

- [ ] `extract_metadata(path: &Path, config: &MetadataConfig) -> ImageMetadata`:
  - File size, dimensions via libvips (fast, no EXIF parse needed for dimensions)
  - EXIF: date_creation, camera make/model, GPS lat/lon, orientation tag — via `kamadak-exif`
  - IPTC: title, description, author, keywords, date — via `rexiv2`
  - Character encoding detection for IPTC strings: try UTF-8, fall back to ISO-8859-1
  - Apply `use_exif_mapping` / `use_iptc_mapping` field mapping from config
- [ ] `ImageMetadata` struct with all extractable fields
- [ ] Date parsing: handle 30+ date format variants found in EXIF data (YYYY:MM:DD HH:MM:SS and many others)
- [ ] GPS coordinate conversion: DMS (degrees, minutes, seconds) → decimal degrees
- [ ] Tag extraction: IPTC keywords → `Vec<String>` tag names
- [ ] Filesize-based unchanged detection: if `db_filesize == fs_filesize`, skip re-extraction

---

### 3.6 Upload Pipeline

**Prior art:** `admin/inc/functions_upload.php` (991 lines), `admin/photos_add_direct.php`

- [ ] `POST /admin/photos/upload` (multipart form):
  - Validate file type against allowed extensions
  - Compute MD5 checksum
  - Duplicate detection: `SELECT id FROM images WHERE md5sum = ?`
  - If duplicate: link to existing image, don't store new file
  - Generate destination path: `var/uploads/{YYYY}/{MM}/{DD}/{timestamp}-{random}.{ext}`
  - Async file write via `tokio::io::copy`
  - Trigger `upload_file` plugin event (for special format handlers: PDF, HEIC, video, etc.)
  - Optional original resize: apply if dimensions exceed config limits
  - Apply EXIF rotation
  - Insert into `images` table
  - Insert into `image_category` table (link to target album)
  - Extract and store metadata
- [ ] Resumable upload: **tus.io protocol** (`POST /api/v1/uploads`, `PATCH /api/v1/uploads/{id}`, `HEAD`, `DELETE`)
  - Staging at `var/uploads/tus/{upload_id}/`
  - On completion: validate checksum, move to `storage/originals/`, process as normal upload
  - Auth via session cookie OR `Authorization: Bearer {api_token}` — no bespoke POST-body-auth flavor. Batch uploaders (digiKam, Lightroom plugin) provision an API token once, use it for every upload.

---

## 9. Phase 4 — Write Paths & Admin

**Duration: 8–12 weeks**  
**Goal:** Full REST API with write operations. Complete admin panel. Users can manage their gallery through both the web UI and API.

---

### 4.1 REST infrastructure

- [ ] `#[Route]` attribute scanner. At build time a `build.rs` discovers all handlers and emits the FastRoute-compatible route table. Path + method + handler name are authoritative; there is no central `routes.php`-equivalent. (§9.1 of Appendix F)
- [ ] Request DTO convention: each handler takes an `axum::extract::Path<T>`, `Query<T>`, `Json<T>`, or `Form<T>` parameter. DTOs derive `Deserialize + Validate`.
- [ ] Response DTO convention: handlers return `Result<Json<T>, ApiError>` where `T: Serialize`. `ApiError` renders as RFC 7807 `application/problem+json`.
- [ ] Cursor pagination helper: `Page<T> { data: Vec<T>, next_cursor: Option<String> }`. Cursors are signed opaque strings encoding `(sort_key, last_id)`.
- [ ] Filter + sort parser: `?tags[in]=a,b&min_rating=3&sort=-taken_at` shape. Sort columns are whitelisted via enum, never user-supplied strings.
- [ ] Sparse fieldset support: `?fields=id,title,derivatives`. Tera's `to_json` filter respects the include set.
- [ ] Include side-loading: `?include=author,albums` joins the related resources into the response envelope.
- [ ] `ApiAuthMiddleware` — extracts session cookie OR `Authorization: Bearer {token}`; populates `AuthenticatedUser` extension.
- [ ] `ApiThrottleMiddleware` — per-user + per-IP rate limits via `tower-governor`. Limits per endpoint category (auth: 10/min/IP, mutations: 60/min/user, reads: 600/min/user).
- [ ] `#[RequiresLevel(...)]` attribute gating admin/webmaster endpoints.
- [ ] `#[OpenApiOperation]` attribute emitting operation metadata for the spec generator.
- [ ] `gallery openapi:dump > docs/api/openapi.yaml` CLI command. CI re-generates and fails if the committed copy drifts.
- [ ] Scalar UI served at `/api/v1/docs`.
- [ ] CORS middleware with `ALLOWED_ORIGINS` config. Public read routes permit `*`; credentialed routes require allowlist.

No `MethodRegistry` with `pwg.*` name lookup. No `WS_TYPE_*` flags. No XML-RPC encoder. No `RestXmlEncoder`. No `reflection.getMethodList`. Contract lives entirely in `#[Route]`-annotated handlers + OpenAPI.

---

### 4.2 Endpoint implementation

The full REST surface is catalogued in Appendix B. Below is the implementation order, grouped by resource boundary. Each bullet corresponds to a group of endpoints that ship together in a single PR-sized unit; their exact paths and methods are in Appendix B.

#### 4.2.1 Auth + tokens (Appendix B.1)

- [ ] `AuthController::login` + `::logout` — argon2id verify, session regeneration on login, IP-hash binding. Rate-limited at 10 attempts / IP / minute.
- [ ] `AuthController::me` — current user + effective `AccessLevel` + permission-cache signature.
- [ ] `PasswordResetController::request` + `::confirm` — one-time-use tokens in `password_reset_tokens`, rate-limited per email + IP.
- [ ] `SessionController::index` + `::revoke` — list my sessions; delete revokes and forces re-auth.
- [ ] `TokenController::index` + `::create` + `::revoke` — `api_tokens` CRUD; plaintext token returned exactly once on create, stored as SHA-256.

#### 4.2.2 Albums (Appendix B.2)

- [ ] `AlbumController::index` + `::show` + `::children` + `::descendants` — tree traversal via materialized `path` column with permission filtering.
- [ ] `AlbumController::images` + `::image_ids` — image listing; the `ids` variant is lightweight for prev/next nav.
- [ ] `AlbumController::create` + `::update` + `::delete` — admin CRUD; delete exposes explicit `photo_action=keep|delete_orphans|cascade` query param (no silent dangerous defaults).
- [ ] `AlbumController::move` — reparent + rebuild materialized path for subtree in a single transaction.
- [ ] `AlbumController::set_cover` + `::reorder_images`.
- [ ] `AlbumPermissionsController::show` + `::replace` — dual-listbox backend; supports "apply to descendants" flag.
- [ ] `AlbumController::orphans` — images not in any album.
- [ ] Emit events: `AlbumCreatedEvent`, `AlbumUpdatedEvent`, `AlbumDeletedEvent`, `AlbumMovedEvent`, `AlbumPermissionsChangedEvent` → permission-cache invalidation.

#### 4.2.3 Photos — reads (Appendix B.3)

- [ ] `PhotoController::index` — list with filters + cursor pagination + permission filter.
- [ ] `PhotoController::show` — full detail including EXIF, tags, albums, derivative URLs for all 9 presets, comment count.
- [ ] `PhotoController::lookup_duplicates` — batch sha256 lookup; used by upload clients to skip re-upload.
- [ ] `PhotoCommentsController::index` + `::create` — ACL-filtered comment list; submission goes to moderation unless caller is admin.
- [ ] `PhotoRatingController::rate` + `::unrate` — upsert into `ratings` table; recompute Bayesian `rating` column.
- [ ] `PhotoFavoriteController::toggle`.

#### 4.2.4 Photos — uploads (Appendix B.3)

- [ ] `PhotoController::create_multipart` — single-shot upload; streams to `var/uploads/tmp/`, validates (MIME allowlist + `infer` magic bytes + libvips probe), debits quota in same transaction, moves to `storage/originals/{yyyy}/{mm}/{uuid}.{ext}`.
- [ ] `UploadController::create` + `::patch` + `::head` + `::delete` — **tus.io** resumable upload protocol. Staging at `var/uploads/tus/{upload_id}/`. No ad-hoc chunk protocol; tus handles offset/resume/cleanup.
- [ ] `UploadController::finalize` — tus completion callback enqueues `IngestUploadMessage` to the background queue; derivative generation kicks off asynchronously.
- [ ] Emit: `ImageUploadingEvent` (vetoable by plugins), `ImageCreatedEvent`, `DerivativeGenerationQueued`.

No `pwg.images.addChunk` / `addFile` / `uploadAsync` / `uploadCompleted` protocol variants. The PHP API shipped four upload flavors because each generation of the API invented a new one; v1 has **one** upload protocol (single-shot for small, tus for resumable). Clients that need authenticated-without-session uploads use an API token.

#### 4.2.5 Photos — writes (Appendix B.3)

- [ ] `PhotoController::update` — metadata + tags + album assignments. `Patch` semantics: unset fields are left alone; explicit `null` clears. No `single_value_mode` / `multiple_value_mode` toggle — tags and albums are `Vec<Uuid>` that replace wholesale when provided.
- [ ] `PhotoController::delete` — hard delete with FK cascade. Files removed via `ImageDeletedEvent` listener.
- [ ] `PhotoController::regenerate_derivatives` — accepts optional preset list; returns `202 + Operation`.
- [ ] `PhotoController::sync_metadata` — re-extract EXIF from file on disk.
- [ ] `PhotoController::batch` — body shape: `{photo_uuids: [...], operations: [{tag_add: ["a","b"]}, {move_album: "uuid"}, {set_min_level: 2}, ...]}`. Returns `202 + Operation` UUID. Each operation type is an enum variant with typed fields — no stringly-typed `action=...` query param.
- [ ] `PhotoFormatsController::index` + `::upload` + `::delete` — alternative formats (CR2/DNG/HEIC) linked to the base image.

#### 4.2.6 Tags (Appendix B.4)

- [ ] `TagController::index` + `::show` + `::images` — listing with denormalized counts; image listing permission-filtered.
- [ ] `TagController::create` + `::update` + `::delete`.
- [ ] `TagController::merge` — atomic: move `image_tags`, drop sources, fire events.
- [ ] Deleted tag slug → 301 from `slug_redirects` table for one year.

No `pwg.tags.duplicate` — the use case (forking a tag to preserve history) is better served by merge-with-rename; redundant API.

#### 4.2.7 Search (Appendix B.5)

- [ ] `SearchController::query` — tokenized scopes → parameterized SQL via `QueryBuilder`; permission post-filter.
- [ ] `SearchController::suggest` — tag/album/user autocomplete.
- [ ] `SavedSearchController::index` + `::create` + `::delete`.

#### 4.2.8 Users + groups (Appendix B.6)

- [ ] `UserController` CRUD (admin scope).
- [ ] `UserController::force_logout` — revoke all sessions for a user.
- [ ] `GroupController` CRUD + membership replace + merge.

No `pwg.users.duplicate` or `pwg.groups.duplicate` — same reasoning as tags.

#### 4.2.9 Comments (Appendix B.7)

- [ ] `CommentModerationController::queue` + `::approve` + `::reject` + `::delete`.

#### 4.2.10 Operations + sync (Appendix B.8, B.9)

- [ ] `OperationController::show` + `::events` (SSE) + `::cancel` + `::index`.
- [ ] `SyncController::start` + `::status` + `::cancel`. Start returns `202 + Location` pointing at the operation resource.

#### 4.2.11 Admin diagnostics (Appendix B.10)

- [ ] `AdminController::stats` + `::storage` + `::queues` + `::audit` + CSV export + DLQ viewer + maintenance triggers.

#### 4.2.12 Settings + plugins + themes (Appendix B.11)

- [ ] `SettingsController::show` + `::update` — grouped JSON; partial updates allowed.
- [ ] `PluginController` — install / activate / deactivate / uninstall via admin UI; capability approval on install.
- [ ] `ThemeController::activate`.

#### 4.2.13 Webhooks (Appendix B.12)

- [ ] `WebhookSubscriptionController` CRUD; server-generated signing secret returned once on create.
- [ ] `DeliverWebhookMessage` queue handler — HMAC-SHA256 signed payloads, retry policy, DLQ on exhaustion.
- [ ] `WebhookDeliveryController::retry`.
- [ ] `SsrfGuard` used on `target_url` creation.

#### 4.2.14 Feeds + health (Appendix B.13, B.14)

- [ ] `FeedController::atom` + `::rss` — signed-token-gated private feeds.
- [ ] `SitemapController::xml` — public content only.
- [ ] `HealthController::live` (`/healthz`) + `::ready` (`/readyz`) + `::version`.
- [ ] `MetricsController::prometheus` — IP-allowlisted.

---

### 4.2.X Cross-cutting

- [ ] Every endpoint has a contract test: status, response body validates against OpenAPI schema, auth boundary enforced (403 for insufficient level).
- [ ] Every POST/PATCH/DELETE endpoint has a CSRF token check for browser clients. API-token clients are exempt (bearer token is the authentication + CSRF is moot).
- [ ] Every write endpoint emits at least one event from the catalog in Appendix D.
- [ ] Every endpoint serving user content applies ACL filtering at the query level (not post-hoc in the handler).
- [ ] Long-running operations (anything > 2 seconds wall time) return `202 + Operation` rather than blocking the HTTP request. Cancellation honored within 5 seconds.

---

### 4.3 Admin panel

All admin pages use SSR with Tera templates. Each page is an isolated handler. Templates live under `templates/admin/` per Appendix E.2; route paths per Appendix F.5.

> **On legacy references below:** where a task mentions a Piwigo hook name, field name, or SQL pattern (e.g. `user_cache`, `get_batch_manager_prefilters`, `anonymous_id`), that is an indicator of **equivalent behavior in the old PHP version** — a hint about the expected feature surface, not a thing being ported. The greenfield implementation uses the new schema (§5 + Appendix A), event catalog (Appendix D), and URL/API surface (Appendix F + B). CSRF uses `X-CSRF-Token` header, not `pwg_token` query/body param. Destructive actions are all rejected without a valid token; the middleware handles this uniformly — no per-task reminder.

#### 4.3.1 Infrastructure
- [ ] Admin base template (`admin/base.html`): sidebar navigation, breadcrumb, flash messages, CSRF token in all forms
- [ ] Admin auth middleware: redirect to login if not Administrator
- [ ] Tab system: `TabSheet` struct for multi-tab pages (maintenance, config, user edit)
- [ ] Flash messages: one-shot session messages for success/error feedback
- [ ] HTMX integration for partial page updates (optional enhancement, not required for v1)

#### 4.3.2 High Priority Pages

- [ ] **Dashboard** (`/admin`)
  - Pending comments count + link
  - Orphan images count + link
  - Update notifications (core + extensions)
  - Activity summary: uploads/comments/logins per week (last 4 weeks, bar chart)
  - Storage breakdown: originals, derivatives, cache (pie chart from `images_disk_usage` config)
  - Quick links: add photos, sync, batch manager
  - Gallery stats: total photos, albums, tags, users, comments
  - Hook: `loc_end_intro` for plugin widgets

- [ ] **Album management** (`/admin/albums`)
  - Interactive tree view of all albums (drag-and-drop reordering via JS or form-based)
  - For each album: name, photo count, sub-album count, status icon (public/private), visibility icon
  - Actions: create new album (modal), edit, move, delete
  - Bulk actions: set all to public/private, lock/unlock
  - Two views: flat list and nested tree

- [ ] **Album edit** (`/admin/albums/{uuid}` — tabbed: properties, sort, permissions, notification)
  - **Properties tab**: name, description (rich text), public/private, visibility, commentable, cover-image picker, slug
  - **Sort tab**: drag-and-drop image ordering within album, or sort by date/name/id
  - **Permissions tab**: dual-listbox for user + group grants; "apply to descendants" checkbox
  - **Notification tab**: send notification to subscribers about new content

- [ ] **Photo upload** (`/admin/photos/upload`)
  - Drag-and-drop zone + file picker fallback
  - Album selector (searchable dropdown or tree)
  - Upload progress: per-file progress bar + overall progress
  - Resumable upload: `tus-js-client` against the endpoints in Appendix B.3
  - Privacy level (min-level) selector
  - Post-upload: link to batch manager for tagging
  - Event: `PhotoUploadPageRenderedEvent` (for plugin widget injection)

- [ ] **Photo edit** (`/admin/photos/{uuid}` — tabbed: properties, coi, formats)
  - **Properties tab**: title, author, description, `taken_at` (datepicker), min-level, tags (autocomplete), linked albums (multi-select), rotation
  - **COI tab**: interactive crop tool — click/drag to set center-of-interest rectangle. Saves 4-char `coi` value.
  - **Formats tab**: list alternative formats (CR2, DNG, HEIC, etc.), upload new, delete
  - Events: `PhotoEditPageRenderedEvent`, `PhotoUpdatingEvent` (vetoable)

- [ ] **Batch manager** (`/admin/photos`, unit mode at `/admin/photos/unit/{uuid}`)
  - **Global mode**: filter photos by prefilter (no_album, no_tag, duplicates, last_import, all, ...) → virtualized grid → select all/some → apply action
  - **Unit mode**: edit photos one at a time with full detail form
  - **Prefilters** (10 shipped, see §20.7.1): selection shortcuts into common subsets
  - **Actions** (15 shipped, see §20.7.2): add/remove tags, associate/move/dissociate albums, set author/title/taken_at/min_level, delete, sync metadata, regenerate/delete derivatives
  - Filter state stored in the user's session payload (JSON)
  - Events: `BatchManagerPrefiltersEvent` (C, returns `Vec<Prefilter>`), `BatchManagerFiltersEvent` (C), `BatchManagerActionAppliedEvent` (N)

- [ ] **Configuration** (`/admin/settings/*` — see Appendix F.5 for the full list of sub-paths)
  - **General** (`/admin/settings/general`): gallery title, banner, guest access, registration
  - **Storage** (`/admin/settings/storage`): `storage/` + `var/` paths, S3 backend config, originals quota
  - **Uploads** (`/admin/settings/uploads`): MIME allowlist, max file size, tus chunk size, EXIF rotation, auto-orient
  - **Derivatives** (`/admin/settings/derivatives`): the 9 standard presets (width/height/crop/quality), original-resize, watermark config (image + position + opacity + repeat + minimum output size)
  - **Mail** (`/admin/settings/mail`): SMTP DSN, sender identity, template theme
  - **Security** (`/admin/settings/security`): session length, remember-me TTL, API token TTL, rate-limit budgets, CORS allowlist
  - **Search** (`/admin/settings/search`): engine selection (native FTS / Meilisearch / Tantivy per §21.4)

- [ ] **User management** (`/admin/users`)
  - Paginated, searchable, sortable table of all users
  - Columns: username, email, access level, groups, registration date, last visit, photo count (via aggregate query)
  - Actions: edit, delete (soft), change level, assign groups
  - Filter by level, group, registration date range
  - Bulk actions: delete selected, change level, assign to group
  - Create new user form

- [ ] **User permissions** (`/admin/users/{uuid}/permissions`)
  - Dual-listbox: available albums on left, granted albums on right
  - Shows inheritance (permissions granted via group membership)
  - "Apply" writes to `album_user_access`

- [ ] **Group management** (`/admin/groups`)
  - List all groups with member count, is_default flag
  - Create / edit / delete / merge
  - Add/remove members (searchable user picker)

- [ ] **Group permissions** (`/admin/groups/{uuid}/permissions`)
  - Same dual-listbox as user permissions, writes to `album_group_access`

#### 4.3.3 Medium Priority Pages

##### Sync (`/admin/sync`)

- [ ] **Form fields (POST to `/api/v1/sync/start`):**
  - `mode`: `"dirs"` (directories only) or `"files"` (files + directories)
  - `min_level`: newly discovered photos' privacy level
  - `metadata`: boolean — run metadata extraction phase
  - `metadata_only_new`: boolean — skip files that already have `sha256` set
  - `metadata_empty_overrides`: boolean — allow empty EXIF values to clear existing DB values
  - `simulate`: boolean (default: true) — dry-run, no DB writes
- [ ] **Quick sync:** `?quick=1` query param pre-populates form with `mode=files, metadata=true, simulate=false`
- [ ] **SSE progress:** `GET /api/v1/operations/{uuid}/events` streams `PhaseStart`, `Progress`, `PhaseComplete`, `Error`, `Complete` events
- [ ] **Client-side:** JS subscribes to the operation SSE stream, updates per-phase progress bars, shows elapsed time, supports cancel via `POST /api/v1/operations/{uuid}/cancel`

##### Maintenance (`/admin/maintenance`)

- [ ] **Tabs:** `actions`, `env`
- [ ] **Actions tab operations:**
  - Toggle `gallery_locked` setting (503-on-read mode for planned maintenance)
  - Purge audit log rows older than retention window
  - Purge expired sessions
  - Purge expired `password_reset_tokens`, expired `api_tokens`
  - Invalidate permission cache (clears moka cache for all users)
  - Delete derivative sizes: checkboxes per preset (`thumbnail`, `small`, ..., `xlarge`) → enqueue cleanup job against `var/derivatives/`
  - Regenerate missing derivatives: enqueue background job
  - Recompute denormalized counters (`albums.image_count`, `tags.image_count`)
  - VACUUM / OPTIMIZE / ANALYZE (dialect-appropriate)
- [ ] **Env tab:** read-only display of Rust version + libvips version + DB engine + OS + cache sizes + active plugins list + photo count + storage usage. `/admin/maintenance/env` populates via concurrent `/api/v1/admin/*` fetches.

##### Tags (`/admin/tags`)

- [ ] **Display:** Paginated tag list (100/200/500/1000 per page) with denormalized `image_count`
- [ ] **Operations:**
  - Rename: update `tags.name`, recompute `slug`, store old slug in redirect table
  - Merge: select multiple tags → merge into destination. Reassigns `image_tags` rows, deletes sources in one transaction.
  - Delete: remove tag + all `image_tags` links
  - Add: create new tag directly from admin
  - Delete orphan tags: removes tags with `image_count = 0`
- [ ] **Selection mode:** checkbox column for batch operations
- [ ] **Events:** `TagRenderNameEvent` (C) for custom display rendering, `TagAltNamesEvent` (C) for search aliases
- [ ] **Query:** `SELECT id, name, slug, image_count FROM tags` — denormalized count is authoritative; nightly reconcile via maintenance.

##### Comments (`/admin/comments`)

- [ ] **Filter tabs:** "All" / "Pending" / "Rejected" with live counts
- [ ] **Batch operations** (via `POST /api/v1/comments/{id}/approve` and `POST /api/v1/comments/{id}/reject`, parallelized):
  - Approve: `UPDATE comments SET status = 'approved', approved_at = NOW()`
  - Reject: `UPDATE comments SET status = 'rejected'`
  - Delete: `DELETE FROM comments WHERE id IN (?)`
- [ ] **Per-comment display:** author name (via `CommentRenderAuthorEvent` C), body (via `CommentRenderBodyEvent` C), date, photo thumbnail, `ip_hash` (for admin spam-analysis)
- [ ] **Selection UI:** select-all / none / invert, checkbox per comment
- [ ] **Pagination:** configurable items-per-page (default 10)

##### Audit log (`/admin/audit`)

- [ ] **Filter form:**
  - Date range (datepicker `start` + `end`)
  - Event types (multi-select): `user.login`, `user.logout`, `photo.upload`, `photo.delete`, `album.permission_changed`, `plugin.install`, ...
  - Actor IP filter (IPv4/IPv6 pattern validation)
  - Target type + ID filter (`album` / `photo` / `user` + uuid)
  - Actor user filter
- [ ] **Data retrieval:** `GET /api/v1/admin/audit` with query filters (Appendix B.10)
- [ ] **Pagination:** configurable items-per-page; cursor pagination
- [ ] **Export:** CSV via `GET /api/v1/admin/audit/export.csv`

##### Stats (`/admin/stats`)

- [ ] **Chart.js visualization** with data selectors:
  - Last 72 hours (hourly)
  - Last 90 days (daily)
  - Last 60 months (monthly)
  - Year-over-year comparison (current month across years)
- [ ] **Data queries:** time-bucketed aggregates from `audit_log` via `DATE_TRUNC` (dialect-portable through the adapter)
- [ ] **Data format:** inline JSON on chart canvas `data-*` attributes (no extra fetch)
- [ ] **Locale support:** month labels via `Intl.DateTimeFormat` (no Moment.js)

##### Ratings (`/admin/ratings`)

- [ ] **Filter form:**
  - `sort`: 7 sort options (most-recent, top-rated, most-rated, total-score, filename, taken_at, created_at)
  - `raters`: "all", "authenticated", "anonymous"
  - `per_page`: items per page (default 10)
  - `album`: album filter (searchable dropdown)
- [ ] **Data display:** per-photo aggregates (COUNT, AVG, SUM of ratings), expandable per-photo detail showing individual raters + values + dates
- [ ] **Query:** `SELECT i.*, MAX(r.rated_at) AS last_rated, ROUND(AVG(r.rating), 2) AS avg, COUNT(r.rating) AS n, SUM(r.rating) AS total FROM ratings r JOIN images i ON r.image_id = i.id GROUP BY i.id`
- [ ] **Album filter:** joins `image_albums` when `album` filter provided

#### 4.3.4 Lower Priority Pages

##### Plugins (`/admin/plugins`, marketplace at `/admin/plugins/marketplace`)

- [ ] **Tabs:** `installed`, `marketplace`, `updates`
- [ ] **Installed tab:**
  - List active/inactive plugins with name, version, description, author, declared capabilities
  - Details toggle persisted in session payload
  - Actions per plugin: activate, deactivate, uninstall (with optional `drop_data`)
  - Events: `PluginAdminMenuLinksEvent` (C) — plugins contribute their own admin subpages
- [ ] **Marketplace tab:** Lua plugin catalog (TBD — whether the project runs its own index or lists Packagist entries tagged `type: gallery-plugin`)
- [ ] **Updates tab:** compare installed vs marketplace, show available updates

Upgrading the core binary is out of the admin UI's scope — it's a Docker-image pull or binary replacement. No "self-update" path.

##### Themes (`/admin/themes`)

- [ ] **Tabs:** `installed`, `marketplace`, `updates`
- [ ] **Installed tab:**
  - List themes with name, screenshot preview, version, author
  - Actions: activate (sets as site default), deactivate, delete
  - Only one theme is "default" for new users; others are available as user preference
  - Events: `ThemeActivatedEvent`, `ThemeDeactivatedEvent`, `ThemeDeletedEvent`, `ThemesPageRenderedEvent`
- [ ] **Marketplace tab:** install from marketplace; fires `ThemeInstalledEvent`
- [ ] Deactivating a theme in use migrates affected users' `users.theme` to the new default in a single UPDATE.

##### Languages (`/admin/settings/languages`)

- [ ] **Tabs:** `installed`, `marketplace`, `updates`
- [ ] **Installed tab:**
  - List languages with display name, locale code, active/inactive status
  - Actions: activate, deactivate, set_default, delete
  - Set default: `UPDATE users SET locale = ? WHERE locale = ?` (migrates users from a deleted/deactivated locale)
- [ ] **Marketplace tab:** install JSON catalogs from marketplace

##### Slug redirects (`/admin/settings/slug-redirects`)

- [ ] **Active slugs table:** albums/tags with their current slug — sortable by id, name, slug
- [ ] **Redirect history table:** previously-assigned slugs that now 301 to current — sortable by target, old_slug, retired_at, last_hit
- [ ] **Actions:**
  - Set slug (`PATCH /api/v1/albums/{uuid}` or `/api/v1/tags/{slug}`): validates uniqueness, stores old slug in redirects for the retention window
  - Delete redirect: manually retire a redirect entry before its automatic expiry (default one year)

##### Photo formats (`/admin/photos/{uuid}/formats`)

- [ ] **Display:** list of alternative file formats for a photo (stored in `image_formats` table — Appendix A.4)
- [ ] **Format types:** CR2, CR3, NEF, ARW, DNG, ORF, RW2, RAF, PEF (detected during sync) + HEIC + RAW-with-embedded-JPEG
- [ ] **Actions:** delete individual format files, upload new format variant

##### Menubar (`/admin/settings/menubar`)

- [ ] **Block ordering form:**
  - Per-block: `visible` checkbox, `position` number input
  - Drag-and-drop reorder (JS falls back to number inputs)
  - Normalize positions on save (1-indexed, consecutive)
- [ ] **Config storage:** JSON array in `settings.menubar_blocks`
- [ ] **BlockManager integration:** registered blocks come from the core + plugin-registered via `BlockmanagerRegisterBlocksEvent` (per Appendix D)

##### Notification by mail (`/admin/notifications`)

- [ ] **Sections:** `settings`, `subscribers`, `send`
- [ ] **Settings section:**
  - `html_mail`: boolean toggle
  - `digest_detailed`: include photo thumbnails in digest
  - `digest_include_dates`: include per-photo dates
  - `sender_override`: optional From address
  - `complementary_content`: custom text appended to all notifications
  - `unsubscribe_token_ttl`: unsubscribe-link validity (seconds)
- [ ] **Subscribers section:** dual-listbox (subscribed / unsubscribed). Actions: subscribe, unsubscribe
- [ ] **Send section:** list of subscribed users with checkboxes; dispatch button enqueues `SendDigestMessage` per user; optional per-send extra content
- [ ] **Subscription table:** `user_mail_notification(user_id, unsubscribe_token, enabled, last_send_at)` — `unsubscribe_token` is a 32-byte random for token-authenticated unsubscribe links
- [ ] **Bounded dispatch:** digest dispatcher honors a wall-clock budget; remaining users are picked up on the next run.

##### Updates (`/admin/updates`)

- [ ] **Tab:** `extensions` only. Core updates are out of band (Docker pull, binary replacement) — no self-update UI.
- [ ] **Extensions tab:**
  - Checks marketplace for newer versions of installed plugins, themes, language packs
  - Compares `installed_version` vs `available_version`
  - One-click update per extension (downloads release asset, verifies signature, extracts into `plugins/` / `themes/` / `locales/`, restarts the relevant VM for Lua plugins)
  - `updates_ignored` setting: array of extension IDs to skip
- [ ] **Config flags:** `enable_extensions_install` (allow admin to install new), `enable_extensions_update` (allow updates). Both default `false` in production profiles that manage extensions through the container image.

#### 4.3.5 Cross-cutting admin operations

- [ ] Materialized `albums.path` + adjacency `parent_id` both updated atomically on move/create/delete (§5 schema principles). No separate `uppercats` / `global_rank` columns exist.
- [ ] Permission inheritance on album create: optional flag copies parent's `album_user_access` + `album_group_access` rows to the new child.
- [ ] Batch permission assignment: apply same permissions to an album and all descendants via a single `INSERT ... SELECT` from the ACL editor's "apply to descendants" checkbox.

---

### 4.4 User-facing write operations

#### 4.4.1 Comment submission

- [ ] `POST /api/v1/photos/{uuid}/comments`:
  - **CSRF:** `X-CSRF-Token` header validated by middleware (browser clients); bearer-token API clients exempt
  - **Validation chain:**
    1. Body not empty, length within configured bounds
    2. Spam filter: reject if body contains more than `comments.spam_max_links` URLs
    3. Anonymous: validate email format if provided
    4. Anonymous: reject `author_name` that collides with an existing username
    5. Honeypot field: only accepted if `comments.honeypot_enabled`
    6. Anti-flood: reject if same `ip_hash` posted within `comments.anti_flood_seconds`
    7. Event: `CommentSubmittingEvent` (C) — handlers can reject with a reason
  - **Persist:** `INSERT INTO comments (image_id, user_id, author_name, author_email, body, status, ip_hash, created_at)`
    - `status = 'approved'` for admin/webmaster users; `'pending'` for moderation (gated by `comments.require_moderation`)
  - **Post-insert events:**
    - `CommentCreatedEvent` always
    - `CommentApprovedEvent` if auto-approved
    - Email notification to subscribers (per admin preferences) via event listener

#### 4.4.2 User registration

- [ ] `POST /api/v1/auth/register`:
  - **CSRF:** handled by middleware
  - **Validation:**
    1. Username uniqueness (case-insensitive via generated `username_ci` column — same mechanism as email)
    2. Username charset allowlist; no HTML; no leading/trailing whitespace
    3. Password + confirmation match; complexity policy
    4. Email format + uniqueness (via `email_ci`)
  - **Persist:**
    - `INSERT INTO users (uuid, username, email, password_hash, access_level, locale, timezone, theme)` — password via `argon2id` (not bcrypt — new hashes use argon2; bcrypt path exists only for imported migrations which §17 rules out)
  - **Post-registration:**
    - Create session immediately (auto-login)
    - Optionally send welcome email (locale from `Accept-Language` at registration time)
    - `UserRegisteredEvent` dispatched; admin-notification subscriber handles the "new user" email

#### 4.4.3 User profile update

- [ ] `PATCH /api/v1/auth/me`:
  - **Access control:** guests blocked; non-admin users must include `current_password` in the body to change password or email
  - **Editable fields:**
    - `password` + `password_confirmation` — must match, rehashed with argon2id
    - `email` — format + uniqueness
    - `locale` — must be an enabled locale
    - `theme` — must be an enabled theme
    - `timezone` — IANA zone
    - `photos_per_page` — 1..200
    - `recent_days` — 1..365
    - `show_comment_counts`, `show_view_counts`, `auto_expand_tree` — booleans
  - **Persist:** single `UPDATE users` with dialect-specific `RETURNING *` to fetch the new row.
  - **Side effects:**
    - Password change → revoke all `api_tokens` + `remember_tokens` + non-current sessions
    - Email change → invalidate pending `password_reset_tokens`
    - Event: `UserProfileUpdatedEvent` with changed-fields diff
    - Audit log: `user.profile_updated` with before/after of changed scalar fields (never secrets)

#### 4.4.4 Photo rating

- [ ] `POST /api/v1/photos/{uuid}/rate` (body: `{rating: 1..5}`):
  - **Validation:**
    - `ratings.enabled` setting must be `true`
    - `rating` value must be in `ratings.allowed_values` whitelist (default: `[1,2,3,4,5]`)
    - If anonymous: `ratings.allow_anonymous` must be `true`; uses `ip_hash` for dedup
  - **Persist:** `UPSERT INTO ratings (user_id, image_id, rating, rated_at)` keyed on `(user_id, image_id)`. For anonymous raters, `user_id = 0` (the sentinel guest user) and the dedup key is `(image_id, ip_hash)` via a separate anonymous table if retained — see the design note in §20.8.
  - **Bayesian scoring:**
    ```
    global_avg = AVG(rating) across all photos
    per_photo_avg = AVG(rating) for this photo
    per_photo_n = COUNT(rating) for this photo
    confidence = settings.ratings.bayesian_confidence (default: 2)
    score = (confidence * global_avg + per_photo_n * per_photo_avg) / (confidence + per_photo_n)
    ```
    - `UPDATE images SET rating = ROUND(score * 20) WHERE id = ?` — stored as `TINYINT UNSIGNED` 0..100 (scale × 20) for numeric stability; NULL for unrated.
  - **Event:** `RatingSubmittedEvent` (C) — listeners can modify the computed score; default listener runs the Bayesian calculation.

#### 4.4.5 Favorites management

- [ ] `POST /api/v1/photos/{uuid}/favorite` (toggle):
  - **Schema:** `favorites(user_id, image_id)` — Appendix A.7
  - Idempotent: request that matches current state is a 200 no-op
  - On permission change (album ACL removes user's view access): `FavoriteAccessRevokedEvent` listener purges unreachable favorites via a scheduled job (no synchronous cascade)
  - Guest users cannot have favorites (401 if called unauthenticated)

No "caddie" / working-set persistent state. The batch manager's selection is session-scoped, not DB-backed. Admin workflow "sync new photos → review in batch manager → tag": use the batch manager's `last_import` prefilter, which selects photos uploaded during the most recent sync operation.

---

### 4.5 Email (`gallery-mail` crate)

#### 4.5.1 SMTP configuration

- [ ] Settings (stored in `settings` table, keys under `mail.*`):
  - `mail.smtp_host`: SMTP server (format: `host:port` or `host`, default 25)
  - `mail.smtp_user`: auth username (empty = no auth)
  - `mail.smtp_password`: auth password (stored encrypted with the app secret key)
  - `mail.smtp_secure`: `"ssl"` (implicit TLS, port 465) or `"tls"` (STARTTLS, port 587)
  - `mail.sender_name`: From display name (default: gallery title)
  - `mail.sender_email`: sender address (default: the first webmaster's email)
  - `mail.allow_html`: boolean — send HTML multipart (with plain-text alternate)
  - `mail.template_theme`: `"clear"` or `"dark"` (or a custom theme shipping its own CSS)

#### 4.5.2 Mailer implementation

- [ ] `GalleryMailer` wrapping `lettre::AsyncSmtpTransport`:
  ```rust
  pub struct GalleryMailer {
      transport: lettre::AsyncSmtpTransport<lettre::Tokio1Executor>,
      sender: lettre::message::Mailbox,
      config: MailConfig,
      templates: Arc<Tera>,
  }
  impl GalleryMailer {
      pub async fn send(
          &self, to: &str, subject: &str,
          template: &str, context: &tera::Context,
      ) -> Result<()>;
  }
  ```
- [ ] **Pipeline:**
  1. Render HTML body (`templates/mail/{template}.html`)
  2. Render plain-text body (`templates/mail/{template}.txt`)
  3. Inline CSS into HTML (via `css-inline` crate) — no separate "clear"/"dark" template directories; CSS theme applied at render time
  4. Fire `BeforeSendMailEvent` (C) — listeners can modify subject/body/recipients or cancel by returning `None`
  5. Build `lettre::Message` multipart
  6. Send via SMTP transport
  7. If `mail.debug_dump_eml`, also write to `var/tmp/mail_{ts}.eml`
- [ ] **Queue integration:** every send goes through `SendMailMessage` on the `mail` queue — not called inline from request handlers. Retry policy: 5 attempts with exponential backoff; permanent failure → DLQ.

#### 4.5.3 Notification catalog

| Event | Recipient | Template |
|---|---|---|
| `UserRegisteredEvent` (new user) | admins (gated by `notifications.admin_on_new_user`) | `admin_new_user` |
| `UserRegisteredEvent` (new user) | the new user, if opted in | `welcome` |
| `CommentCreatedEvent` (auto-approved) | admins (gated by `notifications.admin_on_new_comment`) | `admin_new_comment` |
| `CommentCreatedEvent` (pending moderation) | admins (gated by `notifications.admin_on_comment_pending`) | `admin_comment_pending` |
| `PasswordResetRequestedEvent` | the requesting user | `password_reset` |
| `DigestScheduledEvent` (cron-triggered) | subscribed users | `digest` |
| `AlbumNotificationTriggeredEvent` | selected users | `album_notification` |

Templates listed in Appendix E.3.

#### 4.5.4 Digest notifications

- [ ] **Subscription table:** `user_mail_notifications(user_id, unsubscribe_token, enabled, last_send_at)` — Appendix A.
  - `unsubscribe_token`: 32-byte random, base64url-encoded; used in unsubscribe links without requiring login.
- [ ] **Digest computation:** per subscribed user, compute counts of content since their `last_send_at`, respecting their permission set:
  - New photos: `SELECT COUNT(*) FROM images WHERE created_at > ? AND <permission_filter>`
  - Updated albums: `SELECT COUNT(*) FROM albums WHERE updated_at > ?`
  - New approved comments: `SELECT COUNT(*) FROM comments WHERE approved_at > ? AND status = 'approved'`
- [ ] **Dispatch:**
  1. `DispatchDigestsMessage` (scheduled daily) enumerates subscribed users with `enabled = TRUE` and non-null email
  2. For each user, enqueues a `SendDigestMessage` — distributes work across queue workers; no single-process time budget
  3. Each `SendDigestMessage` handler: compute new content, render template, send via `GalleryMailer`, update `last_send_at`, fire `DigestDeliveredEvent`
- [ ] **Subscribe/unsubscribe:** `GET /notifications/subscribe?token={unsubscribe_token}` / `GET /notifications/unsubscribe?token={unsubscribe_token}` — no login required, token is the only auth.

---

## 10. Phase 5 — Filesystem Sync

**Duration: 4–6 weeks**  
**Goal:** Full 3-phase sync with streaming progress, profiling, and optional Windows MFT reader.

---

### 5.1 Sync Orchestrator

**Prior art:** `admin/site_update.php` (1,389 lines)

- [ ] `SyncJob` struct: config for a sync run (site_id, target categories, options)
- [ ] `SyncOptions`:
  ```rust
  pub struct SyncOptions {
      pub metadata: bool,           // Run phase 3
      pub metadata_only_new: bool,  // Only extract metadata for new images
      pub formats: bool,            // Detect alternative formats
      pub simulate: bool,           // Dry run, no DB writes
      pub batch_size: usize,        // Images per batch in phase 3
  }
  ```
- [ ] `SyncProgress` event stream (SSE):
  ```rust
  pub enum SyncEvent {
      PhaseStart { phase: u8, name: String },
      Progress { phase: u8, current: u64, total: u64, message: String },
      PhaseComplete { phase: u8, inserted: u64, deleted: u64, duration_ms: u64 },
      Error { message: String },
      Complete { total_duration_ms: u64 },
  }
  ```
- [ ] `POST /admin/sync/start` → returns `job_id`, starts background task
- [ ] `GET /admin/sync/events/{job_id}` → SSE stream of `SyncEvent`s
- [ ] `GET /admin/sync/status/{job_id}` → poll endpoint (for non-SSE clients)
- [ ] Concurrent sync prevention: `Arc<Mutex<Option<SyncHandle>>>` — only one sync at a time

---

### 5.2 Phase 1 — Directory Synchronization

- [ ] Load DB state: `SELECT id, dir, uppercats FROM categories WHERE site_id = ?`
- [ ] Compute `fulldir` for each category
- [ ] Scan filesystem (§5.4) → set of directory paths
- [ ] Diff: `new_dirs = fs_dirs - db_dirs`, `deleted_dirs = db_dirs - fs_dirs`
- [ ] **Insert new directories:**
  - Compute parent category by matching longest path prefix
  - Assign `name` from directory basename
  - Inherit parent's `status`, `visible`, `commentable`
  - Inherit parent's `user_access` and `group_access` if `inheritance_by_default=true`
  - `mass_inserts` into `categories` table
  - One `pwg_activity` log entry for the entire batch (not per-category)
  - Recompute `uppercats` and `global_rank` for affected subtree
- [ ] **Delete removed directories:**
  - Call `delete_categories(ids)` for each deleted dir
  - `delete_categories`: removes `image_category` links, purges derivative cache, deletes image files if no other category, removes `user_access`/`group_access` records

---

### 5.3 Phase 2 — File Synchronization

- [ ] Load DB state: `SELECT id, path FROM images WHERE storage_category_id IN (?)`
- [ ] Scan filesystem for image files — skip non-image extensions
- [ ] For each image file, optionally detect:
  - Representative file in `pwg_representative/` subdirectory
  - Alternative formats in `pwg_format/` subdirectory
- [ ] Diff: `new_files = fs_files - db_files`, `deleted_files = db_files - fs_files`
- [ ] **Insert new images:**
  - Compute MD5 checksum for each (parallel via `rayon::par_iter`)
  - Get dimensions via libvips `vips_image_new_from_file` with access mode `random` (reads header only, no full decode)
  - Prepare `images` insert rows
  - Prepare `image_category` insert rows (storage_category_id link)
  - Prepare `image_format` insert rows if formats detected
  - `mass_inserts` for all three tables in one transaction
  - Emit progress events
- [ ] **Delete removed images:**
  - `delete_elements(ids)` — delete image records, category links, tag links, derivative files, activity records
- [ ] **Format change detection:**
  - Compare `fs_formats` vs `db_formats` for each existing image
  - Insert new format records, delete removed format records

---

### 5.4 Filesystem Scanners

#### 5.4.1 walkdir Scanner (All Platforms)

- [ ] `WalkdirScanner` implementing `Scanner` trait:
  ```rust
  pub trait Scanner: Send + Sync {
      fn scan_directories(&self, root: &Path) -> Result<Vec<PathBuf>>;
      fn scan_files(&self, dir: &Path) -> Result<Vec<FileEntry>>;
  }
  pub struct FileEntry {
      pub path: PathBuf,
      pub filesize: u64,
      pub representative_ext: Option<String>,
      pub formats: Vec<String>,
  }
  ```
- [ ] Skip known non-gallery directories: `.git`, `node_modules`, `pwg_high`, `pwg_representative`, `pwg_format`, `thumbnail`, `_data`
- [ ] Skip files matching `$conf->file_exclude_pattern`
- [ ] Per-directory caching of representative and format lookups (hash map keyed by dir path)

#### 5.4.2 Windows MFT Scanner (Windows Only)

- [ ] `MftScanner` implementing `Scanner` trait (only compiled on Windows)
- [ ] Use `windows-rs` crate with `IOCTL_QUERY_USN_JOURNAL` or direct MFT parsing via `DeviceIoControl(FSCTL_GET_NTFS_MFT_RECORD)`
- [ ] Read NTFS Master File Table directly from `\\.\C:` device handle (requires admin)
- [ ] Filter by parent directory IDs (build parent map from MFT records)
- [ ] Build full path by walking parent chain from MFT file reference numbers
- [ ] Fallback to `WalkdirScanner` if MFT access fails (non-admin, non-NTFS, network drives)
- [ ] Benchmarks: target <100ms for 400k file index on modern NVMe

---

### 5.5 Phase 3 — Metadata Synchronization

- [ ] Build file list: all images for site, optionally filtered to `md5sum IS NULL OR date_metadata_update IS NULL`
- [ ] Parallel extraction: `rayon::par_iter` over file list
  - Per-file: load EXIF + IPTC via `extract_metadata()`
  - Skip unchanged files: `fs_filesize == db.filesize`
  - Return `Option<ImageMetadata>` (None = unchanged)
- [ ] Batch tag name resolution:
  - Collect all tag names from all extracted metadata
  - `batch_tag_ids_from_tag_names(names)`: single query to fetch existing tags, INSERT new tags, return complete `name → id` map
- [ ] `mass_updates` for all changed images: metadata fields, md5sum, date_metadata_update
- [ ] `mass_inserts` for new image_tag rows
- [ ] Profiling: record extraction time per file, compute percentiles on completion

---

### 5.6 Edge Cases, Failure Modes & Recovery

#### 5.6.1 Transaction Safety Per Phase

The PHP sync is **NOT transactional across phases** — each phase commits independently. The Rust version should improve this:

| Operation | PHP Transaction? | Rust Target |
|---|---|---|
| Phase 1: Category inserts | NO (individual `mass_inserts`) | Transaction per batch |
| Phase 1: Category deletes | YES (explicit) | Transaction per batch |
| Phase 1: Permission inheritance (user_access, group_access) | NO (separate from category insert) | Same transaction as category insert |
| Phase 2: Image inserts | NO (50k-row chunks via LOAD DATA) | Transaction per chunk with SAVEPOINT |
| Phase 2: image_category links | NO (separate from image insert) | Same transaction as image insert |
| Phase 2: image_format records | NO (separate) | Same transaction as image insert |
| Phase 2: Image deletes | Per-element (not batched) | Batched transaction |
| Phase 3: Metadata updates | NO (mass_updates, no transaction) | Transaction per batch |
| Phase 3: Tag inserts | NO (separate mass_inserts) | Same transaction as metadata update |

- [ ] Wrap each phase in an outer transaction with SAVEPOINTs for sub-operations
- [ ] On interruption: ROLLBACK the current phase, mark sync as `incomplete` in a `sync_jobs` table
- [ ] Next sync detects incomplete job → offers resume or clean restart

#### 5.6.2 Interrupted Sync Recovery

| Failure Point | State Left Behind | Detection | Recovery |
|---|---|---|---|
| Mid Phase 1 (category inserts) | Orphaned categories without proper `uppercats`/`global_rank` | `categories WHERE uppercats IS NULL` | Rerun `update_uppercats()` + `update_global_rank()` |
| Mid Phase 1 (permission inheritance) | Categories created but missing `user_access`/`group_access` | Private categories with zero access grants | Rerun permission inheritance from parent |
| Mid Phase 2 (image inserts) | `images` rows without `image_category` links | `images LEFT JOIN image_category WHERE image_category.image_id IS NULL` | `images_integrity()` cleanup |
| Mid Phase 2 (image deletes) | `image_category` links to deleted images | `image_category LEFT JOIN images WHERE images.id IS NULL` | `images_integrity()` cleanup |
| Mid Phase 3 (metadata extraction) | Some images have EXIF, others don't | `images WHERE md5sum IS NULL AND storage_category_id IS NOT NULL` | Re-run Phase 3 only |
| Mid Phase 3 (tag sync) | `image_tag` cleared but not fully repopulated | Count mismatch between IPTC keywords and `image_tag` rows | Re-run Phase 3 with `meta_all=true` |

- [ ] **Idempotent re-run:** Sync is safe to re-run because:
  - Duplicate categories detected via `db_fulldirs` key lookup
  - Duplicate images detected via `db_paths_set` lookup
  - New inserts check against existing DB state before inserting
- [ ] **Integrity functions** (from `admin/inc/functions_admin.php`):
  - `images_integrity()`: Removes `image_category` links to deleted images via LEFT JOIN detection
  - `categories_integrity()`: Removes access/link records for deleted categories
  - `delete_orphan_tags()`: Removes tags with zero image links
  - `update_category()`: Clears broken `representative_picture_id` references
- [ ] **Admin maintenance button:** "Repair after interrupted sync" — runs all integrity functions in sequence

#### 5.6.3 Fulltext Index Management During Sync

- [ ] **Problem:** Bulk inserts with an active FULLTEXT index cause per-row index maintenance overhead
- [ ] **PHP behavior:** Drops `image_ft` FULLTEXT index on first new file found (line 714), rebuilds after insert loop (line 896)
- [ ] **Failure mode:** If sync interrupted after DROP but before REBUILD — FULLTEXT search disabled for entire `images` table. No auto-recovery in PHP; manual `CREATE INDEX` required.
- [ ] **Rust improvement:**
  - Use PostgreSQL tsvector triggers (already exist) — no manual index management needed
  - For MySQL: check if index exists before DROP, rebuild in a `finally` block (Rust `Drop` trait on a guard struct)
  - Alternatively, disable index updates during bulk insert via `ALTER TABLE ... DISABLE KEYS` (MySQL) and re-enable after

#### 5.6.4 Category Hierarchy Edge Cases

- [ ] **Status inheritance during sync:**
  - New subcategory inherits parent's `status = 'private'` if parent is private
  - New subcategory inherits parent's `visible = false` if parent is hidden
  - Permission grants (`user_access`, `group_access`) copied from parent if `conf.inheritance_by_default = true`
  - **Edge case:** If parent permissions change AFTER child creation but BEFORE access tables updated → race condition. Mitigation: permission inheritance and category insert in same transaction.

- [ ] **Representative picture assignment:**
  - After all files synced, `update_category('all')` assigns random image as representative for categories with images but NULL representative
  - **Edge case:** Category created by sync, then directory deleted from filesystem before Phase 2 completes → category has NULL representative and zero images. Harmless but clutters admin view.
  - Categories with `allow_random_representative = false` get a random image anyway (it's required for display)

- [ ] **Global rank calculation vulnerability:**
  - `update_global_rank()` accesses `$cat_map[$parent_id]` without null check
  - If a category's parent is deleted but `id_uppercat` not yet cleared → undefined behavior
  - **Rust fix:** Validate parent exists before building rank string. Log warning and assign rank `"0"` for orphaned categories.

#### 5.6.5 Metadata Skip Logic Limitations

- [ ] **Current PHP skip logic:** `fs_filesize == db.filesize` → skip extraction
- [ ] **False negative:** File modified in-place without changing filesize (e.g., EXIF edited, metadata stripped) → metadata not re-extracted
- [ ] **Rust improvement options:**
  1. Use `mtime` comparison in addition to filesize
  2. Store file hash (fast xxHash, not MD5) for reliable change detection
  3. Accept the limitation (matches PHP behavior) — user can force re-extraction with `meta_all=true`
- [ ] **Decision:** Match PHP behavior (filesize-only) for parity, document the `meta_all` override for edge cases

#### 5.6.6 Simulate (Dry Run) Mode

- [ ] Simulate mode skips: category/image inserts, deletes, metadata updates, attribute updates
- [ ] Simulate mode does NOT skip: filesystem scanning, filesize reads, SSE progress reporting
- [ ] **Limitation:** Simulate can report success but real sync may fail due to:
  - File permissions changed between simulate and real run
  - Disk space exhausted by temp files during real run
  - DB constraint violations not caught in simulate (FK checks, unique constraints)
- [ ] **Rust approach:** Simulate computes the full diff but wraps DB operations in a transaction that is rolled back instead of committed — catches constraint errors.

---

## 11. Phase 6 — Plugin System

**Duration: 8–10 weeks**  
**Goal:** Full event hook system with Lua plugin support. All 6 built-in PHP plugins reimplemented.

---

### 6.1 Event Bus (gallery-plugins crate)

**Prior art:** `inc/functions_plugins.php` (369 lines), **144 unique event names** (91 notify + 53 change) across 222 call sites

- [ ] Define all 144 event names as a Rust enum. Each variant is annotated with its type (`N` = trigger_notify, `C` = trigger_change):
  ```rust
  #[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
  pub enum GalleryEvent {
      // ─── Lifecycle / Initialization (6 events) ───
      Init,                               // N — inc/common.php:296
      PluginsLoaded,                       // N — inc/functions_plugins.php:366
      LoadingLang,                         // N — inc/common.php:192, inc/functions.php:1094, inc/functions_mail.php:280
      LoadConf,                            // N — inc/functions.php:1471
      FunctionsMailIncluded,               // N — inc/functions_mail.php:957
      FunctionsHistoryIncluded,            // N — admin/inc/functions_history.php:452

      // ─── Authentication / Session (8 events) ───
      UserInit,                            // N — inc/user.php:80
      TryLogUser,                          // C — inc/functions_user.php:1090 (data: bool, username, password, remember_me)
      UserLogin,                           // N — inc/functions_user.php:1017
      LoginSuccess,                        // N — inc/functions_user.php:1043,1165,1502
      LoginFailure,                        // N — inc/functions_user.php:1170
      UserLogout,                          // N — inc/functions_user.php:1181
      PwgLogAllowed,                       // C — inc/functions.php:531 (data: bool, image_id, image_type)
      PwgLogUpdateLastVisit,               // C — inc/functions.php:552 (data: bool)

      // ─── Page Lifecycle — loc_begin_* (22 events) ───
      LocBeginAbout,                       // N — about.php:34
      LocBeginAdmin,                       // N — admin.php:30
      LocBeginAdminPage,                   // N — admin.php:318
      LocBeginCatList,                     // N — admin/cat_list.php:28
      LocBeginCatModify,                   // N — admin/cat_modify.php:32
      LocBeginComments,                    // N — comments.php:97
      LocBeginElementSetGlobal,            // N — admin/batch_manager_global.php:45
      LocBeginElementSetUnit,              // N — admin/batch_manager_unit.php:37
      LocBeginIdentification,              // N — identification.php:35
      LocBeginIndex,                       // N — index.php:46
      LocBeginIndexCategoryThumbnails,     // N — inc/category_cats.php:266 (data: categories array)
      LocBeginIndexThumbnails,             // N — inc/category_default.php:95 (data: pictures array)
      LocBeginNotification,                // N — notification.php:30
      LocBeginPassword,                    // N — password.php:33
      LocBeginPageHeader,                  // N — inc/page_header.php:23
      LocBeginPageTail,                    // N — inc/page_tail.php:22
      LocBeginPicture,                     // N — picture.php:140
      LocBeginProfile,                     // N — profile.php:39
      LocBeginRegister,                    // N — register.php:34
      LocBeginSearch,                      // N — search.php:27
      LocBeginTags,                        // N — tags.php:29
      LocActionBeforeHttpHeaders,          // N — action.php:147

      // ─── Page Lifecycle — loc_end_* (23 events) ───
      LocEndAdmin,                         // N — admin.php:332
      LocEndCatList,                       // N — admin/cat_list.php:260
      LocEndCatModify,                     // N — admin/cat_modify.php:298
      LocEndComments,                      // N — comments.php:527
      LocEndElementSetGlobal,              // N — admin/batch_manager_global.php:731
      LocEndElementSetUnit,                // N — admin/batch_manager_unit.php:250
      LocEndHelp,                          // N — admin/help.php:33
      LocEndIdentification,                // N — identification.php:135
      LocEndIndex,                         // N — index.php:719
      LocEndIntro,                         // N — admin/intro.php:205
      LocEndNoPhotoYet,                    // N — inc/no_photo_yet.php:87
      LocEndNotification,                  // N — notification.php:91
      LocEndPassword,                      // N — password.php:145
      LocEndPageHeader,                    // N — inc/page_header.php:105
      LocAfterPageHeader,                  // N — inc/page_header.php:110
      LocEndPageTail,                      // N — inc/page_tail.php:103
      LocEndPicture,                       // N — picture.php:962
      LocEndPictureModify,                 // N — admin/picture_modify.php:450
      LocEndPhotoAddDirect,                // N — admin/photos_add_direct.php:153
      LocEndProfile,                       // N — profile.php:84
      LocEndRegister,                      // N — register.php:110
      LocEndSectionInit,                   // N — inc/section_init.php:696
      LocEndTags,                          // N — tags.php:172
      LocEndThemesInstalled,               // N — admin/themes_installed.php:178
      LocEndAddUploadedFile,               // N — admin/inc/functions_upload.php:383 (data: image_infos)
      LocEndAddFormat,                     // N — admin/inc/functions_upload.php:488 (data: format_infos)

      // ─── Data Modification / CRUD (11 events) ───
      CreateVirtualCategory,               // N — admin/inc/functions_admin.php:1655 (data: category fields)
      DeleteCategories,                    // N — admin/inc/functions_admin.php:175 (data: category_ids)
      BeginDeleteElements,                 // N — admin/inc/functions_admin.php:298 (data: image_ids)
      DeleteElements,                      // N — admin/inc/functions_admin.php:378 (data: image_ids)
      DeleteUser,                          // N — admin/inc/functions_admin.php:432 (data: user_id)
      DeleteTags,                          // N — admin/inc/functions_admin.php:1777 (data: tag_ids)
      DeleteGroup,                         // N — admin/inc/functions_admin.php:2913 (data: group_ids)
      EmptyLounge,                         // N — admin/inc/functions_admin.php:2246 (data: rows)
      ElementSetGlobalAction,              // N — admin/batch_manager_global.php:393 (data: action, collection)
      InvalidateUserCache,                 // N — admin/inc/functions_admin.php:2516 (data: full bool)
      UserCommentDeletion,                 // N — inc/functions_comment.php:320 (data: comment_id)
      UserCommentValidation,               // N — inc/functions_comment.php:526 (data: comment_id)

      // ─── Rendering Filters (14 events) ───
      RenderCategoryName,                  // C — inc/functions_category.php:816+ (data: name, cat) → String
      RenderTagName,                       // C — inc/functions_tag.php:102+ (data: name, tag) → String
      RenderTagUrl,                        // C — admin/inc/functions_admin.php:1810+ (data: tag_name) → String
      RenderCommentAuthor,                 // C — comments.php:456, admin/comments.php:164 (data: author) → String
      RenderCommentContent,                // C — comments.php:459, admin/comments.php:166 (data: content) → String
      RenderElementName,                   // C — inc/functions_html.php:604 (data: name, info) → String
      RenderElementDescription,            // C — inc/functions_html.php:621 (data: comment, param) → String
      RenderLostPasswordMailContent,       // C — inc/functions.php:3004 (data: message) → String
      GetThumbnailTitle,                   // C — inc/functions_html.php:667 (data: title, info) → String
      GetTagAltNames,                      // C — admin/tags.php:153 (data: [], raw_name) → Vec<String>
      GetTagNameLikeWhere,                 // C — admin/inc/functions_admin.php:1821 (data: [], tag_name) → Vec<String>
      GetPopupHelpContent,                 // C — admin/popuphelp.php:72 (data: content, page) → String
      GetMimetypeLocation,                 // C — inc/SrcImage.php:58 (data: path, ext) → String
      GetSrcImageUrl,                      // C — inc/SrcImage.php:121 (data: url, src_image) → String

      // ─── Search (6 events) ───
      QsearchPre,                          // C — inc/functions_search.php:981 (data: query_string) → String
      QsearchGetScopes,                    // C — inc/functions_search.php:1010 (data: scopes) → Vec<Scope>
      QsearchGetImagesSqlScopes,           // C — inc/functions_search.php:642 (data: clauses, token, expr) → Vec<String>
      QsearchExpressionParsed,             // N — inc/functions_search.php:1042 (data: expression)
      QsearchBeforeEval,                   // N — inc/functions_search.php:1055 (data: expression, qsr)
      QsearchResults,                      // C — inc/functions_search.php:1073 (data: results, expression, qsr) → SearchResults

      // ─── Index / Thumbnails (7 events) ───
      LocIndexThumbnailsSelection,         // C — inc/category_default.php:35 (data: selection) → Vec<i32>
      GetIndexDerivativeParams,            // C — inc/category_default.php:151 (data: DerivativeParams) → DerivativeParams
      LocEndIndexThumbnails,               // C — inc/category_default.php:154 (data: tpl_var, pictures) → Vec<TplThumbnail>
      LocBeginIndexCatThumbsQuery,         // C — inc/category_cats.php:75 (data: SQL query) → String
      GetIndexAlbumDerivativeParams,       // C — inc/category_cats.php:336 (data: DerivativeParams) → DerivativeParams
      LocEndIndexCategoryThumbnails,       // C — inc/category_cats.php:337 (data: tpl_var) → Vec<TplCategory>
      GetCategoryPreferredImageOrders,     // C — inc/functions_category.php:234 (data: orders) → Vec<ImageOrder>

      // ─── Picture Page (3 events) ───
      AllowIncrementElementHitCount,       // C — picture.php:353 (data: bool, image_id) → bool
      PicturePicturesData,                 // C — picture.php:544 (data: picture) → PictureData
      GetCommentsDerivativeParams,         // C — comments.php:511 (data: DerivativeParams) → DerivativeParams

      // ─── Comments (already covered in Rendering + Data Modification) ───

      // ─── Batch Manager (5 events) ───
      GetBatchManagerPrefilters,           // C — admin/batch_manager_global.php:451 (data: prefilters) → Vec<Prefilter>
      BatchManagerRegisterFilters,         // C — admin/batch_manager.php:189 (data: filter) → BatchFilter
      BatchManagerUrlFilter,               // C — admin/batch_manager.php:270 (data: filter, url_filter) → BatchFilter
      PerformBatchManagerPrefilters,       // C — admin/batch_manager.php:444 (data: filter_sets, prefilter) → Vec<Vec<i32>>
      BatchManagerPerformFilters,          // C — admin/batch_manager.php:584 (data: filter_sets, filter) → Vec<Vec<i32>>

      // ─── API / WS (4 events) ───
      WsAddMethods,                        // N — inc/PwgServer.php:80 (data: &PwgServer)
      WsInvokeAllowed,                     // C — inc/PwgServer.php:383 (data: bool, method_name, params) → bool | PwgError
      SendResponse,                        // N — inc/PwgServer.php:96 (data: encoded_response)
      WsUsersGetList,                      // C — inc/ws_functions/pwg_users.php:319 (data: users) → Vec<User>

      // ─── Image / Upload (3 events) ───
      UploadFile,                          // C — admin/inc/functions_upload.php:272 (data: None, file_path) → Option<String>
      LoadImageLibrary,                    // N — admin/inc/pwg_image.php:36 (data: &PwgImage)
      PictureModifyBeforeUpdate,           // C — admin/picture_modify.php:126 (data: image_data) → ImageData

      // ─── Derivative / Image URLs (2 events) ───
      // (GetSrcImageUrl, GetMimetypeLocation already in Rendering above)
      UpdateRatingScore,                   // C — inc/functions_rate.php:122 (data: false, element_id) → Option<f64>

      // ─── Template / CSS+JS Combination (5 events) ───
      CombinablePreparse,                  // N — inc/FileCombiner.php:195 (data: template, combinable, combiner)
      CombinedCss,                         // C — inc/Template.php:597 (data: href, combi) → String
      CombinedCssPostfilter,               // C — inc/FileCombiner.php:269 (data: css) → String
      CombinedScript,                      // C — inc/Template.php:1302 (data: ret, script) → String
      TabsheetBeforeSelect,                // C — admin/inc/tabsheet.php:89 (data: sheets, uniqid) → Vec<Tab>

      // ─── Block Manager (3 events — already covered above) ───
      BlockmanagerRegisterBlocks,          // N — inc/BlockManager.php:38
      BlockmanagerPrepareDisplay,          // N — inc/BlockManager.php:97
      BlockmanagerApply,                   // N — inc/BlockManager.php:151

      // ─── Mail (4 events) ───
      BeforeParseMailTemplate,             // N — inc/functions_mail.php:721 (data: cache_key, content_type)
      BeforeSendMail,                      // C — inc/functions_mail.php:869 (data: true, to, args, mail) → bool
      NbmEventHandlerAdded,               // N — admin/notification_by_mail.php:59
      NbmRenderGlobalCustomizeMailContent, // C — admin/inc/functions_admin.php:4273 (data: content) → String

      // ─── Themes (4 events) ───
      ThemeActivated,                      // N — admin/inc/themes.php:113 (data: theme info)
      ThemeDeactivated,                    // N — admin/inc/themes.php:166 (data: theme info)
      ThemeDeleted,                        // N — admin/inc/themes.php:199 (data: theme info)
      ThemeInstalled,                      // N — admin/themes_new.php:83 (data: theme info)

      // ─── Profile / User Operations (3 events) ───
      SaveProfileFromPost,                 // N — inc/functions.php:3467 (data: user_id)
      LoadProfileInTemplate,               // N — inc/functions.php:3537 (data: userdata)
      GetPwgThemes,                        // C — inc/functions.php:1189 (data: themes) → Vec<Theme>

      // ─── Metadata (2 events) ───
      CleanIptcValue,                      // C — inc/functions_metadata.php:92 (data: value) → String
      FormatExifData,                      // C — inc/functions_metadata.php:142,151 (data: exif, filename, map) → ExifData

      // ─── Misc (5 events) ───
      GetAdminPluginMenuLinks,             // C — admin/plugins_installed.php:69 (data: []) → Vec<MenuLink>
      ListCheckIntegrity,                  // N — admin/inc/check_integrity.php:53 (data: checker)
      SetStatusHeader,                     // N — inc/functions_html.php:548 (data: code, text)
      GetWebmasterMailAddress,             // C — inc/functions.php:1420 (data: email) → String
      GetHistory,                          // C — inc/ws_functions/pwg.php:831 (data: [], search, types) → Vec<HistoryRow>

      // ─── Plugin-specific (1 event — from TakeATour built-in plugin) ───
      TatBeforeParseTour,                  // N — plugins/TakeATour/functions_TakeATour.php:60
  }
  ```

  **Total: 144 unique events** (91 `trigger_notify`, 53 `trigger_change`).

  Implement `GalleryEvent::event_type(&self) -> EventType` returning `Notify` or `Change` for each variant — enforced at compile time so callers cannot accidentally call `trigger_change` on a notify-only event or vice versa.

  Implement `GalleryEvent::from_str(s: &str) -> Option<Self>` for Lua plugins that register by string name.
- [ ] `EventBus`:
  ```rust
  pub struct EventBus {
      handlers: DashMap<GalleryEvent, BTreeMap<u32, Vec<HandlerFn>>>,
  }
  impl EventBus {
      pub fn add_handler(&self, event: GalleryEvent, handler: HandlerFn, priority: u32);
      pub fn remove_handler(&self, event: GalleryEvent, handler_id: HandlerId);
      pub async fn trigger_notify(&self, event: GalleryEvent, ctx: &RequestContext);
      pub async fn trigger_change<T: Serialize + DeserializeOwned>(
          &self, event: GalleryEvent, data: T, ctx: &RequestContext
      ) -> T;
  }
  ```
- [ ] Handler priority: `BTreeMap<u32, Vec<HandlerFn>>` — handlers at same priority run in registration order
- [ ] `HandlerFn` is `Arc<dyn Fn(EventContext) -> BoxFuture<EventResult> + Send + Sync>`
- [ ] Native Rust handlers registered at startup (for core behaviors)
- [ ] Lua handlers registered by plugin `main.lua` at plugin load time

---

### 6.2 Lua Plugin Bridge

- [ ] `LuaPluginHost` struct:
  ```rust
  pub struct LuaPluginHost {
      lua: Lua,                          // mlua::Lua with sandbox enabled
      plugins: Vec<LoadedPlugin>,
      capability_cache: HashMap<String, PluginCapabilities>,
  }
  pub struct LoadedPlugin {
      pub id: String,
      pub name: String,
      pub version: String,
      pub capabilities: PluginCapabilities,
      pub status: PluginStatus,           // Active, Inactive, Error
      pub handler_ids: Vec<HandlerId>,    // For cleanup on deactivate
  }
  ```
- [ ] Plugin discovery: scan `plugins/` directories for `plugin.toml`
- [ ] `plugin.toml` format (replaces `pem_metadata.txt`):
  ```toml
  [plugin]
  id = "AdminTools"
  name = "Admin Tools"
  version = "14.0.0"
  author = "Piwigo"
  description = "..."
  min_piwigo_version = "14.0.0"

  [capabilities]
  db_read = true          # SELECT queries
  db_write = false        # INSERT/UPDATE/DELETE — requires admin approval
  config_write = false    # piwigo.conf.set() — requires admin approval
  filesystem_read = false # Read files outside plugin directory
  http_client = false     # Outbound HTTP requests
  user_impersonation = false  # set_user() — AdminTools needs this
  ```
- [ ] Plugin loading: for each active plugin in DB, `require("plugins/{id}/main.lua")`
- [ ] Host API exposed to Lua (via `mlua::UserData`):
  ```lua
  -- Available in all plugins:
  piwigo.event.register("loc_begin_index", function(ctx) end, priority)
  piwigo.event.register_change("try_log_user", function(data, ctx) return data end, priority)
  piwigo.template.assign("VAR_NAME", value)
  piwigo.template.concat("VAR_NAME", value)
  piwigo.conf.get("key")
  piwigo.conf.set("key", value)  -- requires config_write capability
  piwigo.db.query(sql, params)   -- requires db_read capability (SELECT only)
  piwigo.db.execute(sql, params) -- requires db_write capability
  piwigo.l10n("key")
  piwigo.log(level, message)
  piwigo.http.get(url, headers)  -- requires http_client capability
  piwigo.http.post(url, body, headers)  -- requires http_client capability
  ```
- [ ] Capability model: plugin declares required capabilities in `plugin.toml`, user approves on install. Capabilities are stored in `plugins.capabilities` DB column as JSON. At runtime, each host API call checks the calling plugin's capabilities before executing.

#### 6.2.1 Lua Sandbox Security Model

- [ ] **mlua sandbox mode:** Create Lua VM with `Lua::new_with(StdLib::ALL_SAFE, LuaOptions::new().catch_rust_panics(true))`
  - Removes `io`, `os.execute`, `os.remove`, `os.rename`, `os.exit`, `loadfile`, `dofile` from Lua standard library
  - `os.clock`, `os.date`, `os.difftime`, `os.time` remain available (read-only time operations)
  - `require` is overridden to only resolve modules within `plugins/{plugin_id}/` directory
- [ ] **Per-plugin Lua environments:** Each plugin runs in its own `_ENV` table (Lua 5.4 environment), preventing global namespace pollution between plugins:
  ```rust
  fn create_plugin_env(lua: &Lua, plugin_id: &str, caps: &PluginCapabilities) -> LuaTable {
      let env = lua.create_table().unwrap();
      env.set("piwigo", create_piwigo_api(lua, plugin_id, caps)).unwrap();
      // Copy safe stdlib into env
      for name in ["string", "table", "math", "utf8", "coroutine", "pcall", "pairs", "ipairs",
                    "type", "tostring", "tonumber", "select", "unpack", "error", "assert"] {
          env.set(name, lua.globals().get::<LuaValue>(name).unwrap()).unwrap();
      }
      env
  }
  ```
- [ ] **Resource limits:**
  - **CPU:** `lua.set_hook(HookTriggers::EVERY_NTH_INSTRUCTION, 1_000_000, |_, _| Err(LuaError::RuntimeError("CPU limit exceeded".into())))` — kill handler after ~1M instructions
  - **Memory:** `lua.set_memory_limit(16 * 1024 * 1024)` — 16MB per plugin VM (configurable)
  - **Execution time:** `tokio::time::timeout(Duration::from_secs(5), handler_future)` — 5s wall-clock timeout per handler invocation
- [ ] **SQL injection prevention in `piwigo.db.query()`:**
  - SQL string is parsed to verify it begins with `SELECT` (for non-admin plugins)
  - Parameters MUST be passed as the second argument (never concatenated into SQL)
  - Host creates a parameterized query via `sqlx::query()` with bind parameters
  - `piwigo.db.execute()` additionally rejects `DROP`, `TRUNCATE`, `ALTER` — only `INSERT`/`UPDATE`/`DELETE` allowed, even for admin plugins
- [ ] **Filesystem sandboxing:** `piwigo.fs.read(path)` resolves `path` relative to `plugins/{plugin_id}/` and rejects any path containing `..` or absolute paths. No write access to filesystem from Lua.
- [ ] **Error isolation:** Every Lua handler invocation is wrapped in `pcall` equivalent:
  ```rust
  match lua.scope(|scope| { /* invoke handler */ }) {
      Ok(result) => result,
      Err(e) => {
          tracing::error!(plugin = %plugin_id, event = %event, error = %e, "Plugin handler failed");
          // For trigger_notify: continue to next handler
          // For trigger_change: return unmodified data (skip this handler's transformation)
          default_result
      }
  }
  ```
- [ ] **Hot-reload in dev mode:** When `PIWIGO_DEV=1`, watch `plugins/` for file changes → reload affected plugin's Lua VM without server restart. Production mode loads once at startup.

---

### 6.3 Plugin Lifecycle (PluginMaintain)

**Prior art:** `inc/PluginMaintain.php`

- [ ] `PluginMaintain` trait in Rust (for native plugins):
  ```rust
  pub trait PluginMaintain: Send + Sync {
      fn install(&self, version: &str) -> Result<()> { Ok(()) }
      fn activate(&self, version: &str) -> Result<()> { Ok(()) }
      fn deactivate(&self) -> Result<()> { Ok(()) }
      fn update(&self, old_version: &str, new_version: &str) -> Result<()> { Ok(()) }
      fn uninstall(&self) -> Result<()> { Ok(()) }
  }
  ```
- [ ] Lua plugins implement lifecycle via exported functions:
  ```lua
  function plugin_install(version) end
  function plugin_activate(version) end
  function plugin_deactivate() end
  function plugin_update(old_version, new_version) end
  function plugin_uninstall() end
  ```
- [ ] Auto-update: on load, compare plugin file version vs DB version, call `update()` if different
- [ ] Admin actions: activate/deactivate/delete via `POST /admin/plugins/{id}/{action}`

---

### 6.4 Built-in Plugin Rewrites (Lua)

Each built-in plugin is reimplemented as a Lua plugin (or native Rust if performance-critical).

#### AdminTools
**Complexity: HIGH** — most invasive plugin

- [ ] MultiView system: simulate viewing gallery as different user
  - Hook `user_init` → override effective user in request context
  - Admin UI overlay showing "Viewing as: {username}"
- [ ] Auth hooks: hook `try_log_user` to intercept admin auto-login
- [ ] Audit suppression: hook `pwg_log_allowed` and `pwg_log_update_last_visit` → return false
- [ ] Template privacy stripping: hook template prefilter to remove privacy elements

#### GDThumb
**Complexity: LOW** — thumbnail display options

- [ ] Custom thumbnail aspect ratio options
- [ ] Template hooks via `loc_end_index_thumbnails` and `loc_end_index_category_thumbnails`

#### language_switch
**Complexity: LOW** — language selector UI

- [ ] Hook `loc_end_page_header` → inject language switcher HTML
- [ ] Handle `?lang={code}` parameter → store in session

#### LocalFilesEditor
**Complexity: MEDIUM** — file editing in admin

- [ ] Admin page for editing `local/` config and language files
- [ ] Read/write access to files in `local/` directory only (sandboxed)

#### TakeATour
**Complexity: VERY LOW** — onboarding guide UI

- [ ] Hook `loc_begin_index` → inject tour overlay HTML for new installs

#### rv_tscroller
**Complexity: LOW** — scrolling carousel

- [ ] Template prefilter to inject carousel JS/CSS

---

## 12. Phase 7 — Templates & Themes

**Duration: 6–8 weeks**  
**Goal:** All 277 Smarty templates migrated to Tera. All 5 default themes working.

---

### 7.1 Tera Configuration & Extensions

- [ ] Initialize `Tera` with all templates from `templates/` directory at startup
- [ ] Register all custom filters (equivalents of Smarty modifiers):
  | Smarty | Tera Filter | Implementation |
  |---|---|---|
  | `\|translate` | `\| translate` | `l10n(lang, key)` |
  | `\|translate_dec` | `\| translate_dec(s, p)` | `l10n_dec` with count |
  | `\|sprintf` | `\| sprintf` | printf-style format |
  | `\|urlencode` | `\| urlencode` | `urlencoding::encode` |
  | `\|intval` | `\| int` | built-in Tera |
  | `\|json_encode` | `\| json_encode` | serde_json |
  | `\|htmlspecialchars` | `\| escape` | built-in Tera |
  | `\|implode` | `\| join` | built-in Tera |
  | `\|strtolower` | `\| lower` | built-in Tera |
  | `\|trim` | `\| trim` | built-in Tera |
  | `\|md5` | `\| md5` | md5 crate |
  | `\|get_extent` | `\| theme_override` | theme file lookup |
- [ ] Register all custom functions:
  - `combine_script(id, load, require, path, version)` — registers JS asset
  - `combine_css(path, version, order)` — registers CSS asset
  - `get_combined_scripts(load)` — emit `<script>` tags
  - `get_combined_css()` — emit `<link>` tags
  - `footer_script(require)` — inline JS with dependency
  - `define_derivative(name, type, width, height, crop)` — define custom derivative
  - `make_index_url(...)`, `make_picture_url(...)` — URL generation
- [ ] Plugin filter registration: `template_engine.add_prefilter(handle, fn, weight)`
- [ ] Template hot-reload in dev mode (`PIWIGO_DEV=1`)

---

### 7.2 Asset Pipeline

**Prior art:** `inc/ScriptLoader.php` (373 lines), `inc/CssLoader.php` (89 lines), `inc/FileCombiner.php` (300+ lines)

#### ScriptLoader (Rust equivalent)
- [ ] `ScriptRegistry` — register JS files with load mode (header/footer/async) and dependencies
- [ ] Topological sort for dependency resolution: Kahn's algorithm, detect cycles
- [ ] Constraint propagation: if B depends on A and B is async, A cannot be async
- [ ] Inline script management with dependency requirements
- [ ] Known paths: `jquery`, `jquery.ui`, `bootstrap`, etc. mapped to file paths
- [ ] Output: `get_scripts(load_mode)` → ordered list of file paths

#### CssLoader (Rust equivalent)
- [ ] `CssRegistry` — register CSS files with numeric order weights
- [ ] Deduplication by ID
- [ ] Version-based replacement (keep higher version)
- [ ] Output: `get_css()` → ordered list of file paths

#### FileCombiner (Rust equivalent)
- [ ] Group combinable files by domain (same protocol + host)
- [ ] Cache key: `crc32({file_paths_versions_joined})` → hex string
- [ ] Check if combined file exists and is newer than all sources
- [ ] Template assets (`.css.tpl`, `.js.tpl`): render through Tera first, then combine
- [ ] CSS URL rewriting: adjust relative paths after combining
- [ ] CSS minification: `lightningcss` crate
- [ ] JS minification: `swc_ecma_minifier` crate or `oxc_minifier`
- [ ] Write combined file to `var/combined/{hash}.css` or `.js`
- [ ] Configurable: `config.template_combine_files` — disable for development

---

### 7.3 BlockManager (Rust equivalent)

**Prior art:** `inc/BlockManager.php` (184 lines)

- [ ] `BlockManager` struct:
  ```rust
  pub struct BlockManager {
      registered: Vec<RegisteredBlock>,
      display: Vec<DisplayBlock>,
  }
  pub struct RegisteredBlock { pub id: String, pub name: String, pub owner: String }
  pub struct DisplayBlock {
      pub id: String,
      pub position: u32,
      pub template: Option<String>,
      pub raw_content: Option<String>,
      pub data: HashMap<String, Value>,
  }
  ```
- [ ] `trigger_notify("blockmanager_register_blocks", &mut manager)` — plugins register blocks
- [ ] `prepare_display()`: load block order from config, instantiate `DisplayBlock`s
- [ ] `trigger_notify("blockmanager_prepare_display", &mut manager)` — plugins reorder/hide
- [ ] `apply(template_var)`: render blocks into template context variable

---

### 7.4 Theme System

**Prior art:** `themes/*/themeconf.php`

- [ ] `ThemeConfig` struct:
  ```rust
  pub struct ThemeConfig {
      pub name: String,
      pub parent: Option<String>,
      pub load_parent_css: bool,
      pub load_parent_local_head: bool,
      pub local_head: Option<String>,
      pub colorscheme: Option<String>,
  }
  ```
- [ ] Theme inheritance: child theme templates override parent's by name
  - Template lookup: `themes/{active}/templates/{name}.html` → `themes/{parent}/templates/{name}.html` → `templates/{name}.html`
  - Implement as custom Tera template loader
- [ ] `get_extent` filter: `{{ 'template_name' | theme_override }}` → resolved path
- [ ] 5 themes to migrate: `default`, `bootstrap_darkroom`, `elegant`, `modus`, `smartpocket`

---

### 7.5 Template authoring execution

Templates are authored fresh against the handler view-models defined in Phases 2–4. There is no Smarty-to-Tera transpile step, no `.tpl` source tree, no 265-file migration checklist. The template inventory is fully specified in Appendix E.

#### 7.5.1 Conventions

- [ ] **Strict handler contract.** Every template receives a typed view-model serialized to Tera context; no "pass `$pwg` and let the template figure it out." Handlers compute every displayed value before render.
- [ ] **No method calls in templates.** If a template needs `image.derivative_url("medium")`, the handler pre-computes `image.derivatives.medium` as a flat field. Tera templates are read-only projections of data.
- [ ] **Auto-escape on.** `{{ user.name }}` is always HTML-escaped; explicit `| safe` required (and justified) for pre-sanitized HTML.
- [ ] **i18n via `translate` filter.** `{{ 'photo.upload.submit' | translate }}` resolves through the gettext catalog. Plural forms via `translate_dec(singular, plural, n)`.
- [ ] **Theme-cascade lookup via `theme_override` function.** `{% include theme_override("partials/thumbnail_card") %}` walks the active theme → parent chain → default, returning the first hit.
- [ ] **Tag/slot helpers.** `{% footer_script %}...{% endfooter_script %}` and `{% html_head %}...{% endhtml_head %}` are custom tags that append to post-render buffers — emitted into `<head>` and before `</body>` respectively.
- [ ] **Asset helpers.** `{{ script(id='main', path='main.js', require=['tus']) }}` and `{{ stylesheet(path='main.css', order=10) }}` register into the `ScriptRegistry` / `CssRegistry` for the request.

#### 7.5.2 Helper catalog (Tera functions + filters)

Functions registered on the Tera engine at startup:

| Function | Signature | Purpose |
|---|---|---|
| `route(name, **kwargs)` | `route("album.show", slug=album.slug)` | Reverse-routing against the compiled route table |
| `asset(path)` | `asset("img/logo.svg")` | Resolves to `/assets/...` with content-hash |
| `theme_override(handle)` | `theme_override("partials/nav")` | Theme-cascade path resolver |
| `script(...)` | Register JS into `ScriptRegistry` | See §12.2 |
| `stylesheet(...)` | Register CSS into `CssRegistry` | See §12.2 |
| `emit_scripts(load)` | Output registered `<script>` tags for `header`/`footer`/`async` | Post-dependency resolve |
| `emit_stylesheets()` | Output registered `<link>` tags in order | Post-dedup |
| `derivative_url(photo, preset)` | `derivative_url(photo, "medium")` | Builds `/media/medium/{uuid}.avif` |
| `csrf_token()` | Returns current session's CSRF token | Emits into forms |
| `block(handle)` | Render a BlockManager block by handle | See §12.3 |

Filters:

| Filter | Purpose |
|---|---|
| `translate` | i18n lookup |
| `translate_dec(singular, plural, n)` | plural lookup |
| `format_date(format)` | via `IntlDateFormatter` equivalent |
| `format_number(style)` | decimal / percent / currency |
| `human_filesize` | 1024³ → "1.2 GB" |
| `relative_time` | "3 hours ago" |
| `md5`, `sha256` | content hashing (for cache keys, not secrets) |
| `json_encode` | for inline-script JSON |

All other commonly needed filters (`escape`, `upper`, `lower`, `trim`, `urlencode`, `join`, `length`, `default`, `first`, `last`) are Tera built-ins.

#### 7.5.3 Template build order (dependency-first)

Each line lands in its own PR; later rows assume earlier rows exist.

1. `layout.html` — root HTML shell (head, body, nav slot, content slot, footer slot).
2. `partials/nav.html` + `partials/footer.html` + `partials/flash_messages.html` — layout slot fills.
3. `partials/pagination.html` + `partials/breadcrumb.html` + `partials/thumbnail_card.html` — shared grid + breadcrumb primitives.
4. `error.html` — 404/403/422/500.
5. `home.html` + `album/show.html` — gallery home + album detail.
6. `photo/show.html` + `partials/comment_list.html` + `partials/comment_form.html` + `partials/rating_widget.html` — picture page.
7. `auth/login.html` + `auth/register.html` + `auth/password_reset_request.html` + `auth/password_reset_confirm.html`.
8. `auth/account/*.html` — profile, security, tokens, sessions, notifications.
9. `tag/index.html` + `tag/show.html`.
10. `search/form.html` + `search/results.html`.
11. `favorites.html` + `highlights/*.html`.
12. `album/flat.html` + `album/calendar.html`.
13. `photo/slideshow.html`.
14. Admin shell: `admin/layout.html` + `admin/dashboard.html`.
15. Admin pages: albums, photos (batch + unit + upload + coi + formats), users, groups, tags, comments, history, stats, ratings, sync, maintenance, queues, audit, settings/*, plugins/*, themes, webhooks.
16. Mail templates: `mail/layout.html`, `welcome.html`, `password_reset.html`, `comment_notification.html`, `digest.html`, `album_notification.html` (each with `.txt` sibling).
17. Themes 2–5: override files in `themes/{name}/templates/` as the theme design requires (no mandatory override count; each theme overrides as much or as little as its design wants).

#### 7.5.4 Testing

- [ ] Per-template snapshot test (Playwright) — golden screenshots regenerated with `--update-snapshots` on intentional visual changes.
- [ ] axe-core accessibility scan on every rendered public + admin page — zero violations required.
- [ ] Dark-mode snapshot for themes that ship a dark variant.
- [ ] Keyboard-only walkthrough: login → browse → upload → admin CRUD — must complete without mouse.
- [ ] Screen-reader smoke test (NVDA or VoiceOver manual pass, documented).

---

## 13. Phase 8 — Polish & Testing

**Duration: 8–12 weeks**  
**Goal:** Production-ready system. Regression-tested against the PHP version. Performance validated.

---

### 8.1 Integration Test Suite

- [ ] API round-trip tests: every one of the 84 REST methods
  - Spin up MySQL + PostgreSQL test containers
  - Seed test database with fixture data
  - Call method via HTTP, verify response body and DB state
- [ ] Auth tests:
  - Session login/logout
  - Remember-me token validation and expiry
  - API key authentication
  - Permission boundary tests (e.g., admin-only endpoint returns 403 for normal user)
- [ ] Sync tests:
  - Phase 1: create temp directory tree, run sync, verify categories created
  - Phase 2: add image files, run sync, verify images inserted
  - Phase 3: verify EXIF extracted and stored
  - Delete files, run sync, verify orphans removed
- [ ] Derivative tests:
  - Request each of the 9 standard sizes
  - Verify output dimensions match spec
  - Verify watermark applied correctly
  - Verify EXIF rotation corrected
- [ ] Template render tests:
  - Each gallery page renders without error
  - Each admin page renders without error

---

### 8.2 Visual Regression Tests

- [ ] Set up `playwright` test suite with headless browser
- [ ] Capture screenshots of key pages in PHP version (baseline)
- [ ] Capture screenshots from Rust version
- [ ] Pixel-diff comparison with configurable threshold
- [ ] Pages to cover: gallery home, album, picture detail, search, login, admin dashboard, batch manager, sync page

---

### 8.3 Load Testing

- [ ] `k6` or `oha` load tests:
  - Gallery browse: 100 concurrent users, 60 seconds
  - Image detail: 50 concurrent users
  - Derivative serving: 200 concurrent requests for mixed sizes
  - API calls: 50 concurrent API clients
- [ ] Targets: p95 < 100ms for cached pages, p95 < 500ms for derivative generation

---

### 8.4 Security Audit

**SQL Injection:**
- [ ] Automated scan: `grep -rn 'format!.*SELECT\|format!.*INSERT\|format!.*UPDATE\|format!.*DELETE'` in all Rust source — must return zero results
- [ ] Verify all 523+ ported queries use bind parameters via `QueryBuilder` or `sqlx::query!`
- [ ] Verify LIKE queries escape `%` and `_` metacharacters (see §20.2.2 for the 4 known-vulnerable patterns in PHP)
- [ ] Verify ORDER BY clauses use enum-based column selection, never user-supplied strings
- [ ] Verify Lua plugin `db.query()` / `db.execute()` use parameterized queries (params passed separately from SQL)

**XSS (Cross-Site Scripting):**
- [ ] Verify Tera auto-escaping is enabled globally (`autoescape = true` in Tera config)
- [ ] Audit all `| safe` filter uses — each must be justified (intentional raw HTML)
- [ ] Verify `render_*` hooks that output HTML (e.g., `render_element_content`, `render_page_banner`) sanitize plugin output
- [ ] Verify JSON embedded in `<script>` tags uses `JSON_HEX_TAG | JSON_HEX_AMP` equivalent
- [ ] Verify user-supplied content in `Content-Disposition` headers is sanitized (filename injection)

**CSRF (Cross-Site Request Forgery):**
- [ ] Verify every state-changing endpoint validates `X-CSRF-Token` header for browser clients (bearer-token API clients exempt)
- [ ] Verify all admin form submissions include and validate CSRF token
- [ ] Verify CSRF token is per-session (HMAC of session ID + secret key), not predictable
- [ ] Verify logout is POST-only (prevent CSRF logout attacks)

**Authentication:**
- [ ] Session fixation: session ID regenerated on login
- [ ] Session binding: IP octets included in session validation
- [ ] Remember-me: HMAC-SHA1 with timing-safe comparison (`constant_time_eq`)
- [ ] API keys: stored as SHA-256 hash, never plaintext
- [ ] Password: argon2id with `m_cost=19 MiB, t_cost=2, p_cost=1` (tune with `argon2` crate benchmarks for target hardware); `argon2::verify_encoded` auto-detects encoded params so hash-parameter upgrades work without migration
- [ ] Rate limiting: max 10 login attempts per IP per minute

**Authorization:**
- [ ] Verify every admin endpoint checks `user.status >= Admin` before processing
- [ ] Verify plugin-install and core-upgrade endpoints require `AccessLevel::Webmaster` (not just Admin)
- [ ] Verify image privacy level checked on every image access (view, download, derivative)
- [ ] Verify `ws_invoke_allowed` hook runs before every API method dispatch

**File Handling:**
- [ ] Path traversal: reject `../` in all file paths, derivative URLs, upload filenames
- [ ] Upload validation: check file type by magic bytes (`infer` crate), not just extension
- [ ] Upload size: enforce `upload_form_max_file_size` on server side (don't trust client)
- [ ] Derivative URL parsing: validate derivative type code is recognized, reject arbitrary file paths
- [ ] Symlink prevention: `walkdir` configured to not follow symlinks during sync

**HTTP Headers:**
- [ ] `Content-Security-Policy`: restrict script/style sources
- [ ] `X-Frame-Options: DENY` (or `SAMEORIGIN` if admin uses iframes)
- [ ] `X-Content-Type-Options: nosniff`
- [ ] `Referrer-Policy: strict-origin-when-cross-origin`
- [ ] `Strict-Transport-Security` when HTTPS is detected
- [ ] `X-Request-Id` on all responses for correlation

---

### 8.5 Performance Optimization

- [ ] Profile with `tokio-console` — identify async task bottlenecks
- [ ] Profile with `flamegraph` — identify CPU hot paths
- [ ] Derivative cache: pre-warm cache for most-requested sizes on startup
- [ ] DB query analysis: `EXPLAIN ANALYZE` for all queries, add missing indexes
- [ ] Connection pool tuning: optimize pool size for typical workload
- [ ] Template caching: verify Tera compiles templates once and caches
- [ ] HTTP caching: set appropriate `Cache-Control` headers on all responses

---

### 8.6 Documentation

- [ ] `docs/install.md` — installation guide (binary, Docker, building from source)
- [ ] `docs/upgrade.md` — upgrade between versions of this project (major + minor bumps)
- [ ] `docs/configuration.md` — all config options with defaults
- [ ] `docs/plugins.md` — Lua plugin API reference with examples
- [ ] `docs/api.md` — REST API reference (auto-generated from method registry)
- [ ] `docs/themes.md` — theme development guide (Tera templates, asset pipeline)
- [ ] `docs/sync.md` — filesystem sync guide (MFT requirements, performance tuning)
- [ ] In-code: `///` doc comments on all public API surfaces
- [ ] `cargo doc --no-deps` generates complete API docs

---

## 14. Subsystem Specifications

### 14.1 Session Storage

Sessions are stored as JSON in the `sessions.data` column (native `JSON` on MySQL/Postgres, `LONGTEXT`-with-check on MariaDB). No PHP serialization format anywhere — the repo does not ship a deserializer for `s:7:"pwg_uid";i:3;` because there is no migration from Piwigo.

Session columns:

- `id VARCHAR(128) PRIMARY KEY` — `{ipv4_hex_4bytes}{random}` format
- `user_id BIGINT NULL REFERENCES users(id) ON DELETE CASCADE`
- `data JSON NOT NULL` — application session state
- `ip_hash BYTEA` / `BINARY(32)` — SHA-256 of requester IP for binding checks
- `user_agent VARCHAR(255)` — optional, for audit
- `last_active TIMESTAMP NOT NULL DEFAULT NOW()`
- `expires_at TIMESTAMP NOT NULL`

Indexes: `(user_id)`, `(expires_at)`. GC via probabilistic sweep (1% of requests) and `gallery maintenance sessions`.

### 14.2 Dynamic Query Building

All dynamic WHERE clauses use the `QueryBuilder` pattern — no string format!:

```rust
pub struct QueryBuilder {
    sql: String,
    bindings: Vec<SqlValue>,
}

impl QueryBuilder {
    pub fn new(base: &str) -> Self { ... }
    pub fn push(&mut self, sql: &str) -> &mut Self { ... }
    pub fn push_bind<T: Into<SqlValue>>(&mut self, value: T) -> &mut Self { ... }
    pub fn push_bind_array<T: Into<SqlValue>>(&mut self, values: &[T]) -> &mut Self {
        // PostgreSQL: ANY($N::int[])
        // MySQL: IN (?,?,?,...)
    }
    pub fn build(self) -> (String, Vec<SqlValue>) { ... }
}
```

Usage:
```rust
let mut qb = QueryBuilder::new(
    "SELECT id FROM images WHERE 1=1"
);
if let Some(level) = filter.max_level {
    qb.push(" AND level <= ").push_bind(level);
}
if !perms.forbidden_categories.is_empty() {
    qb.push(" AND category_id != ALL(")
      .push_bind_array(&perms.forbidden_categories)
      .push(")");
}
if let Some(order) = filter.order_by {
    // order_by must be an enum, never user-supplied string
    qb.push(" ORDER BY ").push(order.to_sql());
}
```

### 14.3 Permission SQL Conditions

The permission filtering logic that was embedded in dozens of PHP functions as string interpolation becomes a typed helper:

```rust
pub struct PermissionConditions {
    // Applied to queries that filter by category
    pub category_condition: Option<String>,  // e.g., "category_id != ALL($1)"
    pub category_binding: Vec<i32>,
    // Applied to queries that filter images directly
    pub image_condition: Option<String>,
    pub image_binding: Vec<i32>,
    // Applied to level-filtered queries
    pub level_condition: Option<String>,
    pub level_binding: i32,
}

impl PermissionConditions {
    pub fn for_user(perms: &CachedPermissions) -> Self { ... }
    pub fn apply_to(&self, qb: &mut QueryBuilder) { ... }
}
```

### 14.4 Derivative Cache Structure

UUIDv7-sharded layout — one directory per image, one file per preset-plus-format:

```
var/derivatives/
├── {uuid[:2]}/                       # first 2 hex chars — 256 buckets
│   └── {uuid}/
│       ├── thumbnail.avif
│       ├── thumbnail.webp
│       ├── thumbnail.jpg
│       ├── small.avif
│       ├── medium.avif
│       ├── large.avif
│       ├── xlarge.avif
│       └── custom-{param-hash}.avif  # signed custom params
```

Clean-break from Piwigo's `photo-sq.jpg`/`photo-th.jpg` scheme: preset names are spelled out (`thumbnail`, `small`, …), one directory per image avoids filesystem-level name collisions, format is a pure file extension (one preset → N formats). Content-addressed hashing of custom parameters makes each URL's derivative deterministic and cacheable.

### 14.5 Plugin Event Context

Every hook invocation receives a `RequestContext` giving the plugin access to request-scoped data:

```rust
pub struct RequestContext {
    pub user: AuthenticatedUser,
    pub config: Arc<GalleryConfig>,
    pub db: DbPool,
    pub template: Arc<TemplateContext>,
    pub i18n: Arc<I18n>,
    pub section: Option<GallerySection>,
}
```

In Lua:
```lua
-- ctx is passed as first argument to every handler
piwigo.event.register("loc_begin_index", function(ctx)
    local user = ctx:user()
    local conf = ctx:conf("my_plugin_setting")
    ctx:template_assign("MY_PLUGIN_VAR", "value")
end)
```

---

## 15. Testing Strategy

### Unit Tests

Every module in every crate has unit tests. Key areas:

| Module | What to Test |
|---|---|
| `gallery-auth/permissions` | Permission calculation for users with various group/direct access combinations |
| `gallery-auth/session` | Session ID generation, IP binding, expiry |
| `gallery-auth/remember_me` | HMAC generation and validation, expiry |
| `gallery-image/sizing` | COI crop rectangle calculation against known-good values from PHP |
| `gallery-image/derivatives` | Mtime-based cache invalidation logic |
| `gallery-search/parser` | Tokenization of search queries |
| `gallery-search/builder` | Generated SQL matches expected parameterized form |
| `gallery-db/bulk` | `mass_inserts` correct with various batch sizes |
| `gallery-sync/diff` | Directory diff, file diff — set operations |
| `gallery-plugins/event_bus` | Priority ordering, chaining in `trigger_change` |
| `config` | 3-tier merge, all value types |
| `i18n` | l10n fallback, l10n_dec plural forms |

### Integration Tests

All integration tests use `testcontainers` to spin up real MySQL and PostgreSQL instances.

```rust
#[tokio::test]
async fn test_category_permission_filtering() {
    let db = start_test_db().await;
    seed_fixture(&db, "basic_gallery").await;

    let perms = PermissionCache::new(db.clone());
    let user_perms = perms.get_or_compute(user_id_3).await.unwrap();

    assert_eq!(user_perms.forbidden_categories, vec![10, 40]);
}
```

### Integration Test Scenarios

Critical cross-subsystem scenarios that must pass before release:

#### Permission Boundary Tests
- [ ] Guest user cannot see private album images
- [ ] User with group access to private album CAN see its images
- [ ] User with direct access but no group access CAN see private album
- [ ] Admin can see `visible=false` albums; normal user cannot
- [ ] Image with `level=4` invisible to user with `level=2`
- [ ] Image in both public and private album: accessible to all (at least one public link)
- [ ] Empty forbidden list (sentinel `[0]`): all categories visible
- [ ] Permission cache invalidation: after granting access, user sees album on next request

#### Auth Edge Cases
- [ ] Login with correct password → session created, user_login event fired
- [ ] Login with wrong password → 401, login_failure event fired
- [ ] Case-insensitive login when `insensitive_case_logon=true`
- [ ] Remember-me cookie: close browser, reopen → auto-login works
- [ ] Remember-me expired (>60 days) → redirect to login
- [ ] API key auth: `?auth=KEY` in URL works for normal/generic users
- [ ] API key auth: admin users cannot use API keys (returns error)
- [ ] API key expired → 401
- [ ] Session with IP change (first 2 octets differ) → session rejected
- [ ] CSRF token mismatch on POST → 403
- [ ] Concurrent sessions: user logged in from two browsers, both work

#### Upload & Sync
- [ ] Upload JPEG → image created, derivatives generated, metadata extracted
- [ ] Upload duplicate (same MD5) → rejected or linked (per config)
- [ ] Chunked upload: 3 chunks in order → concatenated correctly
- [ ] Chunked upload: chunks out of order → still works (sorted by position)
- [ ] Sync: new directory on disk → category created in DB
- [ ] Sync: file deleted from disk → image marked/deleted in DB
- [ ] Sync: file unchanged (same filesize) → metadata extraction skipped
- [ ] Sync: EXIF with GPS → latitude/longitude populated
- [ ] Sync interrupted mid-phase → next sync recovers cleanly

#### API Contract

No backward-compatibility goal with Piwigo's `ws.php` response shapes — v1 is its own contract, documented by OpenAPI.

- [ ] Every endpoint in Appendix B has at least one contract test that asserts: HTTP status, headers, and body validates against the committed OpenAPI schema.
- [ ] Cursor pagination: cursor from page N opens page N+1 without skips or dupes; invalid cursor → 400.
- [ ] Filter + sort grammar tested across every indexable column.
- [ ] `?fields=` sparse-fieldset honored.
- [ ] `?include=` side-loading produces consistent serialization across envelope + nested resources.

### API Tests

Each endpoint in Appendix B has at least one test covering:
- Happy path with schema validation on the response body
- Authentication required: 401 for missing auth, 403 for insufficient `AccessLevel`
- Validation failures: 422 + RFC 7807 problem document with `errors` array
- CSRF: browser-session mutations without `X-CSRF-Token` → 403
- Rate-limit: exceeding the per-endpoint budget → 429 + `Retry-After` header
- Array parameters (FORCE_ARRAY methods accept both scalar and array)
- CSRF token validation on state-changing endpoints (`X-CSRF-Token` header required for session-cookie clients)

### Benchmark Suite

```
benches/
├── derivative_generation.rs   # Time to generate each of 9 sizes
├── sync_scan.rs               # Time to scan directory tree of 10k/100k/400k files
├── permission_computation.rs  # Permission calculation for users with 100/1000/10000 categories
├── api_gallery.rs             # Latency for GET /api/v1/albums on large galleries
└── db_bulk.rs                 # mass_inserts throughput at various batch sizes
```

---

## 16. Risk Register

| ID | Risk | Probability | Impact | Mitigation |
|---|---|---|---|---|
| R-01 | libvips not available on target system | Medium | High | Bundle static libvips, provide fallback to system-installed, document requirements |
| R-02 | Lua plugin API insufficient for complex plugins | Medium | High | Build AdminTools as proof-of-concept first; expand API based on what it needs |
| R-03 | Tera missing features needed by some templates | Low | Medium | Fork Tera to add missing feature; or preprocess templates to work around limitation |
| R-04 | MFT reader requires admin privileges on Windows | High | Low | MFT is opt-in; walkdir fallback always works; document privilege requirement |
| R-05 | sqlx query validation fails for dynamic queries | Medium | Low | Dynamic queries use `QueryBuilder` which bypasses compile-time checks — add runtime tests |
| R-06 | Without an import tool, early adopters with existing Piwigo libraries refuse to move | High | Low | Accepted as a positioning tradeoff — see §17. A fresh install + re-upload is the supported path. If the lost-adoption cost proves too high, an out-of-tree import tool can be written against the public REST API without altering the core project. |
| R-07 | API shape changes after v1.0 break third-party clients | Low | High | OpenAPI contract tests run per-PR (§8.4); breaking changes require a new API version namespace (`/api/v2/*`) and a deprecation window on v1. |
| R-08 | Template visual regression too large to automate | Medium | Medium | Prioritize high-traffic pages; accept manual review for others. No PHP baseline to compare against — snapshot tests are self-consistency checks only. |
| R-09 | Memory usage higher than expected at scale | Low | Medium | Profile early with realistic dataset; use `jemalloc` for reduced fragmentation. |
| R-10 | Community plugin ecosystem ignores the Lua-only stance | High | Low | Accepted breakage; clearly documented in §17. AdminTools reimplementation demonstrates the Lua API can cover the hardest plugin in the old catalog. |
| R-11 | `mass_inserts` hits parameter limit on large sync (100k+ files) | Medium | Medium | Chunk inserts to stay under MySQL's 65535 / PG's 32767 param limit — already accounted for in §1.3. |
| R-12 | Concurrent derivative generation causes file corruption (two requests generate the same derivative simultaneously) | Medium | Medium | Atomic write (write to temp file, then rename); per-UUID+preset lock map serializes concurrent requests. |
| R-13 | i18n plural forms — some languages have complex rules (Russian 3, Arabic 6, Chinese 1) | Low | Low | Use ICU plural rules via `icu` crate; test against CLDR reference data. |
| R-14 | EXIF date parsing fails on non-standard formats from specific camera brands | High | Low | Maintain an exhaustive format list from real-world data; log unparseable dates as warnings rather than failing. |
| R-15 | Windows path handling differs from Unix (backslashes, drive letters, UNC paths, case insensitivity) | Medium | Medium | Use `PathBuf` consistently; normalize paths on input; CI matrix covers both platforms. |
| R-16 | Non-image uploads (PDF, HEIC, RAW, video preview) — Lua plugin handlers need external tools | Medium | High | Ship built-in Rust handlers for common types (HEIC→JPEG via libvips, PDF→PNG via `poppler-rs`, RAW→JPEG via `rawloader`); Lua hooks for exotic types. |
| R-17 | Schema design locks in early assumptions that hurt at scale | Medium | High | Milestone 1 designs the schema against the appendix before any feature code is written; an `schema/` directory with ADRs documents decisions; §26 covers the scaling strategy when surrogate keys need replication partitioning. |
| R-18 | Permission resolution has a subtle semantic bug (group vs. user override, visibility rules) | Medium | High | Plan §6.5.3 ports the resolution algorithm verbatim into pure Rust with property-based tests covering every combination; boundary tests documented in §15. |
| R-19 | Lua sandbox escape (plugin breaks out of capability model) | Low | High | Resource limits (CPU instruction count, memory, wall-clock timeout); `mlua`'s `ALL_SAFE` stdlib preset; per-plugin `_ENV` isolation; known-malicious patterns tested in §8.4. |
| R-20 | Greenfield schema proves wrong in a way that requires a data-migrating v2 within two years | Low | Medium | Accept. Plan §17 commits to clean-break major versions with deprecation windows. The risk of getting the schema wrong is lower than the risk of preserving Piwigo's. |

---

## 17. No migration — fresh install only

**Existing Piwigo databases are not importable. Existing clients of `ws.php` will not work. Existing themes and plugins will not load.** This is intentional — dragging the schema and API forward would re-import the constraints the rewrite exists to escape. The value carried over is in the *concept of a photo gallery* and in having thought through the sharp edges over 20 years of Piwigo's life. The new codebase inherits that wisdom without inheriting the code.

### What this means in practice

| Thing | Status |
|---|---|
| Piwigo 14.x database | Not importable. No deserialization of `s:...;i:...;` PHP-serialized blobs. |
| `ws.php` REST API | Gone. New surface at `/api/v1/*`. |
| PHP plugins (community + built-in) | Incompatible. Six built-in plugins reimplemented in Lua; community plugins require rewriting. |
| Smarty themes | Incompatible. Five default themes reimplemented in Tera; community themes require rewriting. |
| `/i/...` derivative URLs | Gone. New `/media/{preset}/{uuid}.{ext}` grammar. |
| `/index.php?/category/...`, `/picture.php?/...`, `/action.php`, `/feed.php`, `/identification.php` | Gone. Clean REST-shaped paths throughout. |
| `_data/i/` derivative cache | Gone. New `var/derivatives/{uuid[:2]}/{uuid}/{preset}.{ext}` layout. |
| `config.php` / `database.php` | Gone. TOML replaces PHP arrays. |
| `language/*/common.lang.php` | Gone. JSON replaces PHP associative arrays. |
| `piwigo_user` etc. DB prefix | Gone. No table prefix. |
| Session cookies | Users log in. |
| bcrypt password hashes | Not carried. Users set new passwords on first login. |

### Why no import tool

There's a legitimate argument for preserving more — specifically, a one-shot import tool that reads the old DB and writes to the new schema. That argument loses because:

1. **Users with large libraries will move them regardless of tooling.** A fresh start with re-upload gives them a clean schema; a half-imported database gives them confusion.
2. **The import tool would have to preserve edge cases** (legacy permission combinations, orphaned rows from old bugs, charset-ambiguous text) that don't belong in the new schema — and every site imported is subtly different from the schema the rewrite assumes.
3. **The "value" of Piwigo's existing data is mostly the originals.** Users keep those; they re-upload them.
4. **Writing and testing an import tool is weeks of work** that would freeze the new schema early, before v1.0 has proven its shape. A permanent import commitment is a permanent design constraint.

### If someone still wants to migrate

Out-of-tree tooling is welcome:

- The REST API at `/api/v1/*` is public and documented. A third-party tool that reads Piwigo 14 via direct DB access and writes through the API is feasible.
- The filesystem sync (§10) can ingest an existing `galleries/` tree without modification.
- Permission state, tag assignments, album structure, user accounts — all writable via the API.

The core project ships no such tool and accepts no PR that adds one. This is a policy decision; see §1 "Out of Scope".

### Fresh install runbook

For the supported path (new database):

```bash
# 1. Provision the database (MySQL 9.7 LTS, MariaDB 11.8 LTS, or Postgres 18)
createdb gallery
# or: mysql -e "CREATE DATABASE gallery CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"

# 2. Configure
cp local/config/config.example.toml local/config/config.toml
${EDITOR} local/config/config.toml   # DB DSN, secret_key, mail, storage paths

# 3. Install schema + bootstrap admin
gallery install
gallery admin:create --username=admin --email=you@example.com

# 4. Start
gallery serve

# 5. Verify
curl -f http://localhost:8080/healthz
```

Total time on a clean host: ~10 minutes.

### Upgrading between versions of this project

`gallery upgrade` applies pending sqlx migrations. Additive within a major version. Destructive migrations (drop columns, drop tables) only happen across major-version bumps and are announced in `CHANGELOG.md` with a rollback note.

---

## 18. Performance Targets

| Metric | PHP 14.x (Baseline) | Rust Target | Method |
|---|---|---|---|
| Gallery page (cold, no cache) | ~500ms | <80ms | Benchmark |
| Gallery page (warm, DB cached) | ~150ms | <30ms | Benchmark |
| Derivative generation (MEDIUM, cold) | ~800ms | <150ms | Benchmark |
| Derivative serving (cache hit) | ~20ms | <5ms | Benchmark |
| API `GET /api/v1/albums` | ~200ms (PHP ws.php equivalent) | <40ms | Benchmark |
| Sync: 10,000 files (walkdir) | ~45s | <8s | Benchmark |
| Sync: 400,000 files (MFT) | ~5min | <10s | Benchmark |
| Memory per request | ~50MB | <3MB | Profiler |
| Peak memory at 100 concurrent | ~5GB | <200MB | Load test |
| Binary size | N/A | <50MB | `cargo build --release` |
| Startup time | ~2s (PHP-FPM) | <500ms | Manual |

---

## 19. Milestone Summary

| Milestone | Phase(s) | Deliverable | Est. Completion |
|---|---|---|---|
| **M1: Running skeleton** | Phase 1 | Server starts, DB connects, auth works, health endpoint | Month 2 |
| **M2: Read-only gallery** | Phase 1–2 | Gallery browseable, search works, feeds served | Month 4 |
| **M3: Image pipeline** | Phase 3 | Upload works, derivatives generated, metadata extracted | Month 6 |
| **M4: Full write + admin** | Phase 4 | All 84 API methods, complete admin panel, email | Month 10 |
| **M5: Sync + MFT** | Phase 5 | Full 3-phase sync, SSE progress, MFT reader (Windows) | Month 12 |
| **M6: Plugin system** | Phase 6 | EventBus, Lua bridge, all 6 built-in plugins reimplemented | Month 15 |
| **M7: Templates complete** | Phase 7 | All 277 templates migrated, all 5 themes, asset pipeline | Month 18 |
| **M8: v1.0 Release** | Phase 8 | Regression tested, load tested, security audited, documented | Month 20 |

---

*Last updated: 2026-04-15*  
*Based on deep investigation of Piwigo PHP 14.x (branch `14.x`, commit `34ae81ec1`)*

---

## 20. Pain Point Deep Dives — lessons from Piwigo 14

> Detailed technical investigation of Piwigo-specific pain points that shaped the greenfield design.
>
> **Framing:** every section below is "here is what the PHP codebase taught us, and here is how the Rust rewrite avoids the trap." References to PHP function names, config keys, and hook signatures describe the old system; they are inputs to design, not things being ported. The Rust implementation is free to pick a different structure as long as the lesson is respected. Where a section ends with explicit "Rust approach" guidance, that is design commitment; where it doesn't, the design remains open.

---

## 20.1 Plugin & Hook System

**Severity: CRITICAL — This is the single hardest architectural problem in the rewrite.**

### 20.1.1 Hook Inventory

The codebase has **222 hook call sites** across 52 files, invoking **144 unique event names** (91 notify + 53 change).

| Metric | Count |
|---|---|
| `trigger_notify()` call sites | 105 |
| `trigger_change()` call sites | 117 |
| Unique notify events | 91 |
| Unique change events | 53 |
| Built-in plugins | 6 |
| Total handler registrations by built-in plugins | 28 |

### 20.1.2 Hook Type Classification

Every hook falls into one of these categories:

| Category | Count | Lua-Safe | Examples |
|---|---|---|---|
| **Page lifecycle** (`loc_begin_*`, `loc_end_*`) | ~40 | YES | `loc_begin_index`, `loc_end_picture` |
| **Text rendering** (`render_*`) | 12 | YES | `render_category_name`, `render_comment_content` |
| **Object lifecycle** (`delete_*`, `create_*`) | 8 | YES | `delete_elements`, `create_virtual_category` |
| **Auth/session** | 6 | PARTIAL | `try_log_user`, `user_init`, `login_success` |
| **SQL query modification** | 5 | YES (string ops) | `loc_begin_index_category_thumbnails_query`, `qsearch_get_images_sql_scopes` |
| **Batch operations** | 6 | YES | `batch_manager_register_filters`, `element_set_global_action` |
| **Block/template injection** | 4 | PARTIAL | `blockmanager_register_blocks`, `combinable_preparse` |
| **Image/derivative params** | 5 | PARTIAL | `get_src_image_url`, `get_index_derivative_params` |
| **Config/init** | 5 | YES | `load_conf`, `init`, `plugins_loaded` |
| **WS/API** | 3 | PARTIAL | `ws_add_methods`, `ws_invoke_allowed` |
| **Mail** | 4 | PARTIAL | `before_send_mail`, `nbm_render_global_customize_mail_content` |
| **Metadata/search** | 5 | PARTIAL | `format_exif_data`, `qsearch_results` |
| **Theme/extension management** | 5 | YES | `theme_activated`, `theme_deleted` |
| **CSS/JS combination** | 4 | YES | `combined_css`, `combined_script` |
| **Misc data filters** | ~20 | YES | `get_admin_plugin_menu_links`, `tabsheet_before_select` |

### 20.1.3 Security-Critical Hooks (5 hooks requiring special attention)

#### `try_log_user` — Authentication Interception

- **Type:** `trigger_change`
- **Location:** `inc/functions_user.php:1090`
- **Data:** `bool $success, string $username, string $password, bool $remember_me`
- **Default handler:** `pwg_login()` — queries DB, verifies bcrypt hash
- **Risk:** HIGH — a malicious plugin can bypass authentication entirely
- **Lua challenge:** Cannot call native `password_verify()` from Lua
- **Required Rust host API:**
  ```rust
  fn verify_password(hashed: &str, plaintext: &str) -> bool;
  fn log_user(user_id: i32, remember_me: bool);
  ```

#### `user_init` — User Context Mutation

- **Type:** `trigger_notify`
- **Location:** `inc/user.php:80`
- **Data:** `$user` array (passed by reference)
- **Critical consumer:** AdminTools MultiView — rebuilds the `$user` object as a different user, modifies `$conf` globals, and dynamically registers additional handlers (`pwg_log_allowed`, `pwg_log_update_last_visit`)
- **Risk:** HIGH — impersonates users, disables audit logging
- **Lua challenge:** Requires mutable access to request-scoped state + dynamic handler registration
- **Required Rust host APIs:**
  ```rust
  fn build_user(user_id: i32) -> LuaTable;
  fn config_set(key: &str, value: LuaValue);
  fn config_get(key: &str) -> LuaValue;
  fn register_event_handler(event: &str, callback: LuaFunction, priority: u32);
  fn get_url_param(name: &str) -> Option<String>;
  ```

#### `ws_invoke_allowed` — API Authorization Gate

- **Type:** `trigger_change`
- **Location:** `inc/PwgServer.php:383`
- **Data:** `bool $allowed, string $methodName, array $params`
- **Risk:** HIGH — controls access to all 84 API methods
- **Lua challenge:** Must be able to return `PwgError` objects
- **Required Rust host API:**
  ```rust
  fn create_pwg_error(code: i32, message: &str) -> PwgError;
  ```

#### `ws_add_methods` — API Method Registration

- **Type:** `trigger_notify`
- **Location:** `inc/ws_init.php`
- **Data:** `&PwgServer` (reference)
- **Risk:** HIGH — plugins can register arbitrary API endpoints
- **Lua challenge:** Needs `PwgServer` API to call `addMethod()`
- **Required Rust host API:**
  ```rust
  fn register_ws_method(
      name: &str, handler: LuaFunction,
      params: LuaTable, options: LuaTable
  );
  ```

#### `load_conf` — Configuration Override

- **Type:** `trigger_notify`
- **Location:** `inc/functions.php:1468`
- **Data:** `string $condition` (WHERE clause)
- **Risk:** HIGH — can override security settings after DB config load
- **Lua-safe:** YES (read/write config via host API)

### 20.1.4 SQL Query Modification Hooks

These 5 hooks pass raw SQL strings through handlers — the handler modifies the SQL:

| Hook | Data Type | Location | What It Modifies |
|---|---|---|---|
| `loc_begin_index_category_thumbnails_query` | `string` (full SQL) | `category_cats.php:75` | Category listing SQL — plugins inject WHERE clauses or JOIN tables |
| `get_tag_name_like_where` | `array` of WHERE clauses | `functions_admin.php:1822` | Tag search conditions |
| `qsearch_get_images_sql_scopes` | `array` of clauses | `functions_search.php:651` | Quick search SQL scopes |
| `qsearch_get_scopes` | `array` of scope definitions | `functions_search.php` | Search scope types |
| `get_category_menu_sql_where` | `string` SQL WHERE | `functions_category.php` | Category menu filtering |

**Rust implication:** Lua plugins that modify SQL create injection risk. The Rust host must either:
1. Validate returned SQL fragments (expensive, fragile)
2. Provide a structured query modification API instead of raw SQL
3. Accept the risk and document it as a plugin author responsibility

### 20.1.5 AdminTools — Proof-of-Concept for Lua Bridge

AdminTools is the most invasive plugin. Its MultiView feature:

1. Hooks `user_init` → reads URL params (`ato_view_as`, `ato_lang`, `ato_theme`, `ato_show_queries`, `ato_debug_l10n`, `ato_debug_template`, `ato_template_combine_files`)
2. Calls `build_user($view_as_id)` to reconstruct user object for impersonated user
3. Overwrites `$user` global with new user data
4. Modifies `$conf` globals (show_queries, debug flags)
5. Dynamically registers handlers for `pwg_log_allowed` → returns `false` (suppress logging)
6. Dynamically registers handlers for `pwg_log_update_last_visit` → returns `false`
7. Saves multiview state to session
8. Hooks `loc_after_page_header` → injects admin toolbar HTML
9. Hooks `loc_begin_picture` and `loc_begin_index` → handles quick-edit form submissions

**Minimum Lua bridge to replicate AdminTools:**
```lua
piwigo.event.register("user_init", function(ctx)
    local view_as = ctx:get_url_param("ato_view_as")
    if view_as then
        local new_user = ctx:build_user(tonumber(view_as))
        ctx:set_user(new_user)
        ctx:config_set("show_queries", ctx:get_url_param("ato_show_queries") == "1")
        ctx:event_register("pwg_log_allowed", function(do_log) return false end)
    end
end, 10) -- priority 10 (early)
```

### 20.1.6 Complete Rust Host API Requirements

**Tier 1 — CRITICAL (blocks basic plugin functionality):**

```rust
// Authentication
fn verify_password(hashed: &str, plaintext: &str) -> bool;
fn build_user(user_id: i32) -> LuaTable;
fn log_user(user_id: i32, remember_me: bool);

// Configuration
fn config_get(key: &str) -> LuaValue;
fn config_set(key: &str, value: LuaValue);

// Event system (dynamic registration from within handlers)
fn register_event_handler(event: &str, callback: LuaFunction, priority: u32);
fn unregister_event_handler(event: &str, handler_id: &str);

// Database (sandboxed)
fn db_query(sql: &str, params: Vec<LuaValue>) -> LuaTable;    // SELECT only for non-admin
fn db_execute(sql: &str, params: Vec<LuaValue>) -> u64;       // Admin plugins only
fn db_insert_id() -> i64;

// Template
fn template_assign(key: &str, value: LuaValue);
fn template_concat(key: &str, value: LuaValue);
fn template_set_prefilter(handle: &str, callback: LuaFunction, weight: u32);
```

**Tier 2 — HIGH (many hooks need these):**

```rust
// Session
fn session_get(key: &str) -> LuaValue;
fn session_set(key: &str, value: LuaValue);

// Request context
fn get_url_param(name: &str) -> Option<String>;
fn get_post_param(name: &str) -> Option<String>;
fn set_user(user_table: LuaTable);

// WS API
fn register_ws_method(name: &str, handler: LuaFunction, params: LuaTable, opts: LuaTable);
fn create_pwg_error(code: i32, message: &str) -> PwgError;

// Block manager
fn block_register(id: &str, name: &str, owner: &str);
fn block_set_data(id: &str, data: LuaTable);
fn block_set_template(id: &str, template: &str);

// Derivative params
fn get_derivative_params(type_name: &str) -> LuaTable;
```

**Tier 3 — MEDIUM (enhancement hooks):**

```rust
// i18n
fn l10n(key: &str) -> String;
fn l10n_dec(singular: &str, plural: &str, count: i32) -> String;

// Utilities
fn get_root_url() -> String;
fn get_csrf_token() -> String;
fn is_admin() -> bool;
fn redirect(url: &str);
```

### 20.1.7 Hooks NOT Safe for Lua Without Host APIs

| Hook | Reason | Required API |
|---|---|---|
| `try_log_user` | Needs password verification | `verify_password()` |
| `user_init` | Needs user object reconstruction + dynamic handler registration | `build_user()`, `register_event_handler()` |
| `ws_invoke_allowed` | Needs PwgError instantiation | `create_pwg_error()` |
| `ws_add_methods` | Needs method registration API | `register_ws_method()` |
| `blockmanager_*` | Needs BlockManager object API | `block_register()`, `block_set_data()` |
| `get_src_image_url` | Needs SrcImage object introspection | `get_src_image_path()` |
| `load_image_library` | Needs ability to inject image library | Not feasible in Lua — native only |
| `qsearch_*` | Needs QToken/QExpression types | Simplified search scope API |
| `combinable_preparse` | Needs Template + FileCombiner objects | `template_assign()` (sufficient) |
| `before_send_mail` | Needs mail object API | `mail_add_header()`, `mail_set_body()` |

**All other hooks (~90+)** are Lua-safe — they only manipulate strings, arrays, and primitive types.

---

## 20.2 Dynamic SQL & Query Safety

**Severity: HIGH — 523 raw queries, 4 confirmed vulnerable LIKE patterns, 0 ORDER BY injection risks.**

### 20.2.1 Query Pattern Taxonomy

| Pattern | Count | Safety | Rust Migration |
|---|---|---|---|
| Simple WHERE with integer IDs | ~250 | SAFE | Direct `sqlx::query!` |
| IN clause with `implode(safe_ids)` | ~80 | SAFE | `sqlx` array binding |
| LIKE with search words | ~12 | **VULNERABLE** | Must escape `%` and `_` before binding |
| String field interpolation (author, path) | ~15 | RISKY | Parameterize |
| Computed permission conditions (FandF) | ~19 | SAFE (pre-validated) | `QueryBuilder` pattern |
| ORDER BY | ~12 | SAFE (all hardcoded/whitelisted) | Enum-based column selection |
| BETWEEN with dates | ~4 | SAFE | Chrono date params |
| REGEXP with addslashes | ~2 | MOSTLY SAFE | Different escaping rules |
| GROUP_CONCAT + GROUP BY | ~2 | SAFE (whitelist) | Custom GROUP BY builder |

### 20.2.2 Confirmed Vulnerable Patterns

#### LIKE Injection (4 sites)

**`inc/functions_search.php:132`** — Search words directly in LIKE:
```php
$local_clauses[] = $field . " LIKE '%" . $word . "%'";
// $word comes from unserialized search data — no LIKE metacharacter escaping
```

**`inc/functions_search.php:181`** — Same pattern in allwords search.

**`inc/functions_search.php:224`** — Tag name search: `"WHERE name LIKE '%{$word}%'"`

**`inc/functions_search.php:340`** — File extension: `"path LIKE '%." . $ext . "'"`

**Rust fix:** All LIKE values must go through an escaping layer:
```rust
fn escape_like(value: &str) -> String {
    value.replace('\\', "\\\\")
         .replace('%', "\\%")
         .replace('_', "\\_")
}

// Then bind as parameter:
qb.push(" AND file LIKE ")
   .push_bind(format!("%{}%", escape_like(&word)));
```

#### IP Address Injection in History Logging

**`inc/functions.php:618`** — `$_SERVER['REMOTE_ADDR']` interpolated without escaping:
```php
$query .= "... '{$ip}' ...";
```

**Rust fix:** Always use bind parameter for IP address.

### 20.2.3 The 10 Most Complex Queries to Port

| # | Location | Complexity | Challenge |
|---|---|---|---|
| 1 | `functions_search.php:430` | 3 JOINs + 4 dynamic clauses | `search_clause` built from tokenized search input with AND/OR groups |
| 2 | `batch_manager.php:399` | GROUP_CONCAT + HAVING | Duplicate detection with dynamic GROUP BY columns (whitelist) |
| 3 | `functions_notification.php` | COUNT(DISTINCT) ×2 + JOIN | Date-grouped statistics with permission filtering |
| 4 | `CalendarBase.php` | SELECT DISTINCT + dynamic sql | Calendar levels with variable inner SQL |
| 5 | `functions_category.php:365` | UNION query | Permalink resolution across 2 tables |
| 6 | `functions_search.php:785` | Categories JOIN + MATCH/REGEXP | Full-text clause list varies by search token |
| 7 | `functions_user.php:563` | Favorites + category filter | Permission-filtered favorites list |
| 8 | `batch_manager.php:470` | Category image filtering | Category string implode in condition |
| 9 | `functions_search.php:206` | Tag/category image search | Multiple IN clauses from tag resolution |
| 10 | `batch_manager.php:507` | Dimension/ratio filtering | Multiple range WHEREs |

### 20.2.4 `get_sql_condition_FandF` — The Permission SQL Generator

Called from **22 files**, this function builds SQL WHERE conditions from cached permission data:

```php
// Generates conditions like:
// (category_id NOT IN (5,7,12) AND i.level <= 2 AND image_id NOT IN (100,205))
```

**Call sites by file:**
- `index.php` (4 calls) — gallery browsing
- `picture.php` (2 calls) — image detail
- `comments.php` (2 calls) — comments listing
- `inc/section_init.php` (3 calls) — section initialization
- `inc/functions_search.php` (1 call) — search results
- `inc/functions_category.php` (2 calls) — category queries
- `inc/ws_functions/pwg_*.php` (4 files) — API methods
- `action.php`, `random.php`, `search.php` — misc pages

**Rust equivalent:** A `PermissionConditions` struct with `apply_to(qb: &mut QueryBuilder)` method. All values go through bind parameters — never string interpolation.

### 20.2.5 MySQL vs PostgreSQL Divergence Beyond Abstraction

| Feature | MySQL | PostgreSQL | In-Code Handling |
|---|---|---|---|
| Regex operator | `REGEXP` | `~` | `DB_REGEX_OPERATOR` constant |
| Random function | `RAND()` | `CAST(random() AS text)` | `DB_RANDOM_FUNCTION` constant |
| Date subtraction | `SUBDATE(NOW(), INTERVAL X DAY)` | `CURRENT_TIMESTAMP - INTERVAL 'X days'` | Abstracted in dblayer functions |
| Upsert | `REPLACE INTO` | `INSERT ... ON CONFLICT` | Separate implementations |
| Transaction start | `START TRANSACTION` | `BEGIN` | Separate implementations |
| Boolean | `ENUM('true','false')` | Native BOOLEAN | Convert `'t'`/`'f'` in PostgreSQL |
| Sequences | Auto-increment | GENERATED BY DEFAULT AS IDENTITY | `sync_sequences()` in pgsql only |
| Full-text | `FULLTEXT INDEX` + `MATCH AGAINST` | `TSVECTOR` + `@@` | PostgreSQL has triggers |

**Rust approach:** `sqlx` handles most divergence automatically. The few manual differences (regex, random, date math) become helper functions that emit the correct SQL per backend.

---

## 20.3 Template System & Asset Pipeline

**Severity: HIGH — NOT a simple template language swap. Requires ~40-50% custom Rust infrastructure.**

### 20.3.1 Smarty Customization Inventory

| Customization | Count | Tera Equivalent | Effort |
|---|---|---|---|
| Registered PHP classes (static method access) | 4 | Custom Tera functions per method | HIGH |
| Modifier compilers (compile-time) | 2 | Runtime filters (loses optimization) | MEDIUM |
| Standard modifiers (PHP wrappers) | 21 | Custom filters | LOW |
| Block plugins (`html_head`, `footer_script`, `html_style`) | 3 | Post-template processing | MEDIUM |
| Function plugins (`combine_script`, `combine_css`, `define_derivative`) | 5 | Custom Tera functions | MEDIUM |
| Filter pipeline (pre/post/output) | 4 levels | Rust pre/post processing | MEDIUM |
| Compiler plugins (`get_combined_css`) | 1 | Runtime function | LOW |

### 20.3.2 Dangerous Modifiers

These Smarty modifiers expose operations that shouldn't happen in templates:

| Modifier | Risk | Tera Decision |
|---|---|---|
| `file_exists` | File I/O in templates | **Remove** — pre-compute in handler |
| `preg_match` | Regex execution in templates | **Remove** — pre-compute in handler |
| `constant` | Access to PHP/Rust constants | **Replace** with Tera globals |
| `md5` | Crypto in templates | Keep as custom filter |

### 20.3.3 ScriptLoader Dependency Resolution Algorithm

The dependency resolver is a **constraint solver**, not a topological sort:

```
REPEAT until no changes:
    FOR each script:
        FOR each dependency:
            IF dependency.load_mode > script.load_mode:
                // Promote dependency to earlier load mode
                dependency.load_mode = script.load_mode
                changed = true
            
            IF both are async AND (dependency is remote OR !combine_files):
                // Can't guarantee execution order for uncombined async scripts
                // Demote dependency to footer
                dependency.load_mode = FOOTER
                changed = true
```

Then scripts are sorted by: `load_mode ASC, topological_depth ASC, remote_first (header only), id ASC`.

**Rust implementation:** Straightforward — use a `Vec<Script>` with a fixed-point iteration loop. No external crate needed.

### 20.3.4 FileCombiner — Template-Parsed Assets

**Critical pattern:** Some CSS and JS files are Smarty templates themselves (`.css.tpl`, `.js.tpl`):

1. Template registered as asset: `{combine_css path='themes/style.css.tpl'}`
2. FileCombiner detects `is_template = true`
3. Triggers `combinable_preparse` hook — plugins inject vars
4. Parses template through Smarty — CSS/JS with variable substitution
5. Minifies result (CSS: `tubalmartin/CssMin`, JS: `JShrink`)
6. Caches with `t` prefix: `var/combined/t{hash}.css`

**Cache key:** `base_convert(crc32(implode('>', [root_url, path, version, mtime])), 16, 36)`

**CSS URL rewriting:** After combining, relative URLs in CSS are rewritten to absolute paths. `@import` statements are inlined recursively.

**Rust replacement:**
- Tera can render `.css.tpl` / `.js.tpl` files
- CSS minification: `lightningcss` crate
- JS minification: `oxc_minifier` or `swc_ecma_minifier`
- URL rewriting: regex replacement on `url(...)` patterns

### 20.3.5 `footer_script` Block System

**106 total `{footer_script}` blocks** across all templates:
- 40 with no dependencies
- 35 requiring `jquery`
- 10 requiring `jquery.ui`
- 15 requiring various jQuery plugins
- 5 using async mode

**Pattern:**
```smarty
{footer_script require='jquery'}<script>
  jQuery(document).ready(function() { ... });
</script>{/footer_script}
```

**Rust approach:** During template rendering, collect `footer_script` blocks into a `Vec<InlineScript>`. After rendering, resolve dependencies and inject into the HTML at the appropriate location (before `</head>` for `html_head`/`html_style`, before `</body>` for `footer_script`).

### 20.3.6 Template Variable Inventory

~200-300 unique template variable names assigned via `$template->assign()`. The most frequently assigned:

- `ADMIN_PAGE_TITLE` (22 files)
- `PWG_TOKEN` (6 files)
- `GRAPHICS_LIBRARY` (8 files)
- `U_HOME`, `U_REGISTER`, `U_LOGOUT` (navigation URLs)
- `TITLE`, `COMMENT`, `NB_IMAGES` (page content)
- `derivative` objects with method calls

### 20.3.7 Object Method Calls in Templates

Templates call methods on objects passed from PHP:

```smarty
{$derivative->get_type()}
{$derivative->get_url()}
{$derivative->get_size_hr()}
{$previous.derivatives.square->get_url()}
```

**Tera limitation:** Tera does NOT support method calls on objects. These must become either:
- Pre-computed properties: `{{ derivative.type }}`, `{{ derivative.url }}`
- Custom Tera functions: `{{ derivative_url(derivative, "square") }}`

### 20.3.8 Theme Inheritance (get_extent)

The `get_extent` modifier enables template overrides:
```smarty
{include file='picture_nav_buttons.tpl'|get_extent:'picture_nav_buttons'}
```

This checks for: `template-extension/{handle}.tpl` → child theme override → parent theme → default.

**Rust approach:** Custom Tera template loader that implements the lookup chain:
1. `templates/extensions/{handle}.html`
2. `themes/{active_theme}/templates/{handle}.html`
3. `themes/{parent_theme}/templates/{handle}.html`
4. `templates/{handle}.html`

### 20.3.9 BlockManager Lifecycle

3-phase lifecycle for dynamic sidebar blocks:

1. **Register:** Plugins call `block_register(id, name, owner)` via `blockmanager_register_blocks` hook
2. **Prepare:** Load block positions from DB config, filter by visibility, trigger `blockmanager_prepare_display`
3. **Apply:** Render each block's template, assign `$blocks` to main template context

**Rust approach:** The BlockManager is pure Rust logic. Tera templates just consume a `blocks` array. Plugin hooks can add/modify blocks via the Lua bridge.

---

## 20.4 Permission System & Caching

**Severity: MEDIUM-HIGH — Architecturally sound but every query depends on it, and string interpolation of permission lists is pervasive.**

### 20.4.1 Complete Permission Computation Algorithm

```
calculate_permissions(user_id, user_status):
    private_cats = SELECT id FROM categories WHERE status = 'private'
    user_auth    = SELECT cat_id FROM user_access WHERE user_id = ?
    group_auth   = SELECT DISTINCT cat_id FROM user_group ug
                   JOIN group_access ga ON ug.group_id = ga.group_id
                   WHERE ug.user_id = ?
    
    authorized = UNION(user_auth, group_auth)
    forbidden  = private_cats - authorized
    
    IF user_status NOT IN ('admin', 'webmaster'):
        locked = SELECT id FROM categories WHERE visible = 'false'
        forbidden = UNION(forbidden, locked)
    
    IF forbidden IS EMPTY:
        forbidden = [0]   // sentinel for NOT IN queries
    
    RETURN comma_separated(forbidden)
```

Then image-level restrictions:
```
    forbidden_images = SELECT DISTINCT id FROM images
                       JOIN image_category ON id = image_id
                       WHERE category_id NOT IN (forbidden_cats)
                       AND level > user_level
```

### 20.4.2 Per-Request Query Count

**Cache warm (typical request):** 2 queries (user + user_infos/cache join)

**Cache cold (after invalidation):** 13-17 queries:
1. User record
2. User infos + cache + theme (JOIN)
3. All private categories
4. User direct access grants
5. Group access grants
6. Forbidden images
7. Accessible image count
8. Computed categories (complex JOIN with GROUP BY)
9-13. DELETE/INSERT for user_cache and user_cache_categories

### 20.4.3 Cache Invalidation Triggers (18 call sites)

Every one of these admin actions triggers `invalidate_user_cache(true)` which TRUNCATES both cache tables:

- Category status change (public/private)
- Category visibility change
- Group permission add/remove
- User permission add/remove
- Image privacy level change
- Batch operations (any)
- Photo metadata change
- Category create/delete
- Upload operations
- Extension updates
- Manual maintenance action

**Rust improvement opportunity:** Selective invalidation. Instead of TRUNCATE ALL, invalidate only affected users' caches. When a category's status changes, only users who had access to that category need recomputation.

### 20.4.4 The Filter System (Separate from Permissions)

The `$filter` system is a temporal filter ("show only recent photos") that is AND-ed with permissions:

```
Final SQL = (permission_conditions) AND (filter_conditions)
```

Filter state is stored in SESSION (ephemeral), while permission cache is in the DB (persistent). Both must be invalidated together when permissions change.

**Rust design:** Two separate cache layers with coordinated invalidation:
```rust
pub struct RequestPermissions {
    pub forbidden_categories: Vec<i32>,    // From permission cache
    pub forbidden_images: Vec<i32>,        // From permission cache
    pub visible_categories: Option<Vec<i32>>,  // From filter (if active)
    pub visible_images: Option<Vec<i32>>,      // From filter (if active)
    pub user_level: i32,
}
```

### 20.4.5 Edge Cases That Could Cause Permission Bypass

1. **Image in multiple categories:** If image is in both an authorized and forbidden category, the image should be accessible (it's in at least one authorized category). Current PHP does this correctly — `NOT IN (forbidden_cats)` only checks `image_category.category_id`.

2. **Empty forbidden list sentinel:** `[0]` is used as sentinel for empty list. `NOT IN (0)` is always true for valid IDs. If Rust accidentally uses NULL or empty array, all categories become forbidden.

3. **Admin visibility bypass:** Admins see categories with `visible='false'`. If the Rust code applies visibility filtering before checking admin status, admins lose access.

4. **Race condition:** Concurrent permission updates could cause Request A to use stale cache while Admin B invalidates. Solution: optimistic locking with `cache_update_time` comparison.

5. **Session/DB cache desync:** Filter is session-based, permissions are DB-based. If session expires but DB cache is stale with different `cache_update_time`, the filter may apply wrong restrictions.

---

## 20.5 Filesystem Sync Complexity

**Severity: HIGH — Most complex single subsystem. 3 sequential phases, SSE streaming, profiling, partial failure risks.**

### 20.5.1 Three-Phase Architecture

```
PHASE 1: DIRECTORIES → CATEGORIES
    Load DB categories → Scan FS dirs → Diff → Insert new / Delete removed
    
PHASE 2: FILES → IMAGES  
    Scan FS files → Load DB images → Diff → Insert new / Delete removed / Update changed
    
PHASE 3: METADATA
    Load candidates → Extract EXIF/IPTC per file → Batch resolve tags → Update DB
```

Phases MUST run sequentially — Phase 2 depends on categories from Phase 1, Phase 3 depends on images from Phase 2.

### 20.5.2 Transaction Safety (or lack thereof)

| Operation | Transaction? | Interrupted State |
|---|---|---|
| Phase 1: Category inserts | NO | Orphaned categories possible |
| Phase 1: Category deletes | YES (explicit) | Safe — atomic |
| Phase 2: Image inserts | NO | Orphaned `image_category` links |
| Phase 2: Format sync | NO | Mixed old/new formats |
| Phase 2: Image deletes | NO (per-element) | Partial deletions |
| Phase 3: Metadata updates | NO | Some images have EXIF, others don't |
| Phase 3: Tag updates | NO | `image_tag` cleared but not repopulated |

**Rust improvement:** Wrap each phase in a transaction. Use savepoints for sub-operations. On interruption, rollback the current phase and mark the sync as incomplete.

### 20.5.3 Progress Reporting (SSE)

When `?sse` query parameter is present:
```
Content-Type: text/event-stream
Cache-Control: no-cache
X-Accel-Buffering: no  // Disable proxy buffering

Events:
  phase_start    → {phase: 'dirs'|'files'|'meta'}
  substep_start  → {phase, id, label, has_progress}
  substep_progress → {phase, id, detail, file}
  phase_progress → {phase, current, total, updated, skipped, file}
  substep_complete → {phase, id, detail, elapsed}
  phase_complete → {phase, elapsed, new, deleted, updated}
  complete       → {simulate, update, metadata, errors}
  error          → {message}
```

**Rust approach:** `axum::response::Sse` with `tokio::sync::mpsc` channels. Each phase sends events through the channel; the SSE handler converts them to `Event` structs.

### 20.5.4 Metadata Extraction Pipeline

Per-file extraction sequence:
1. **Filesize check** — if `fs_filesize == db_filesize && filesize > 0`: SKIP (unchanged)
2. **Image dimensions** — via libvips header read (no full decode)
3. **EXIF data** — date, camera, GPS, orientation, keywords
4. **IPTC data** — title, description, author, keywords, date
5. **Date normalization** — 30+ format variants:
   - UNIX timestamp → `Y-m-d H:i:s`
   - `YYYY:MM:DD HH:MM:SS` → normalized
   - `YYYYMMDD` (IPTC) → `Y-m-d` with fallback to `Y-01-01`
   - SVG viewBox → pixel dimensions

**Parallelization:** Metadata extraction is CPU-bound and fully parallelizable. Use `rayon::par_iter` or `tokio::task::spawn_blocking` with a semaphore to cap concurrency.

### 20.5.5 Profiling Instrumentation

When `$conf->sync_profiling = true`, per-phase timing is tracked:

- Phase 1: dirs scanned, files found, readdir time
- Phase 2: representative lookups (count + time), format lookups (count + time)
- Phase 3: per-file extraction time, aggregate p50/p95/p99 percentiles, per-operation breakdown (filesize, getimagesize, exif, iptc)

**Rust approach:** Use `std::time::Instant` for timing. Collect into a `SyncProfile` struct with `percentile()` method.

### 20.5.6 Everything SDK / MFT Scanner

**Everything SDK** (Windows, optional):
- FFI to `Everything3_x64.dll` via PHP `ext-ffi`
- Queries Everything search index for directories and files matching patterns
- Returns `[path, size_bytes]` pairs
- Falls back to `LocalSiteReader` if Everything not running

**MFT reader** (Rust, planned replacement):
- Direct NTFS Master File Table read via `windows-rs` crate
- `FSCTL_GET_NTFS_FILE_RECORD` or `IOCTL_QUERY_USN_JOURNAL`
- Requires admin privileges
- Target: <100ms for 400k file index

---

## 20.6 Image Pipeline & Derivative System

**Severity: MEDIUM — Well-structured, but the PHP VIPS backend has 4 stubbed operations that libvips-rs must implement.**

### 20.6.1 Derivative URL Format Specification

**Standard types** (2-char codes):
```
sq = IMG_SQUARE    (120×120, crop=1.0)
th = IMG_THUMB     (144×144)
2s = IMG_XXSMALL   (240×240)
xs = IMG_XSMALL    (432×324)
sm = IMG_SMALL     (576×432)
me = IMG_MEDIUM    (792×594)
la = IMG_LARGE     (1008×756)
xl = IMG_XLARGE    (1224×918)
xx = IMG_XXLARGE   (1656×1242)
```

**Custom parameters** (`cu` prefix):
- Scale only: `cus200x150` — `s` = scale, `200x150` = dimensions
- Scale + crop: `cu200x150l100_150` — `l` = crop factor (char a-z → 0.0-1.0), `100_150` = min size
- Exact square crop: `cue120` — `e` = exact, `120` = square size

**Crop factor encoding:** `(fraction × 25).round()` → char `'a' + n`

### 20.6.2 Cache Invalidation Logic

Derivative regenerated if ANY of:
1. Derivative file does not exist
2. Source file mtime > derivative mtime
3. `ImageStdParams.last_mod_time` > derivative mtime (params changed in admin)

**HTTP caching:**
- `Last-Modified` always sent
- `Expires: +10 days` if source stable for 24+ hours
- `304 Not Modified` on `If-Modified-Since` match
- `?b=TIMESTAMP` forces regeneration within 100 seconds (used during COI editing)

### 20.6.3 COI (Center of Interest) Crop Algorithm

**Storage:** 4 chars in `images.coi` column, each encoding a 0.0-1.0 fraction as `'a' + round(fraction × 25)`.

**Algorithm:**
1. Compute aspect ratio mismatch between source and target
2. Determine if horizontal or vertical crop is needed
3. Calculate ideal crop amount (pixels to remove)
4. Cap at `max_crop × dimension`
5. Distribute crop evenly around COI center
6. Shift if COI doesn't have enough space on one side
7. Scale resulting rectangle to target dimensions

### 20.6.4 PHP VIPS Backend — Stubbed Operations

The current PHP VIPS backend (`image_vips.php`) has **4 critical stubs**:

| Operation | Status | Impact |
|---|---|---|
| `strip()` | Returns true, does nothing | Metadata never removed from derivatives |
| `sharpen()` | Returns true, does nothing | Derivatives lack sharpening |
| `compose()` | Returns true, does nothing | **Watermarks never applied** |
| `set_compression_quality()` | Ignored | Always outputs quality 75 instead of configured 95 |

Additionally, `resize()` re-reads the file from disk instead of operating on the loaded image, breaking the operation pipeline.

**libvips-rs capabilities:** All 4 operations ARE supported by the underlying libvips C library:
- `vips_sharpen()` — built-in sharpening
- `vips_composite2()` — alpha compositing for watermarks
- `vips_autorot()` + clear EXIF — metadata stripping
- `vips_jpegsave()` with Q parameter — quality control

### 20.6.5 Animated WebP Detection

Parse first 25 bytes of file:
```
Bytes 0-3:   "RIFF" magic
Bytes 8-11:  "WEBP" fourCC
Byte 15:     Format identifier:
             ' ' (0x20) = VP8 Lossy
             'L' = VP8L Lossless
             'X' = VP8X Extended
If VP8X:
    Byte 20 bit 1 (0x02) = animation flag
    Byte 20 bit 4 (0x10) = alpha/transparency flag
```

Animated WebP quality is capped at 70 (configurable) to prevent oversized thumbnails.

### 20.6.6 Sharpening Matrix

```
amount_range = -48 to 10 (user param 0-1 maps to this range)
center_value = abs(-48 + (amount × 0.38))

matrix = [[-1, -1, -1],
          [-1, center, -1],
          [-1, -1, -1]]
          
normalized: each element / sum(all elements)
```

---

## 20.7 Admin Batch Operations & Upload Pipeline

**Severity: MEDIUM — Complex but well-structured. Main challenge is the session-based filter state and the 15+ action types.**

### 20.7.1 Batch Manager Prefilters (10 types)

| Prefilter | SQL Pattern | Notes |
|---|---|---|
| `caddie` | `SELECT element_id FROM caddie WHERE user_id = ?` | Per-user working set |
| `favorites` | `SELECT image_id FROM favorites WHERE user_id = ?` | Per-user |
| `last_import` | `MAX(date_available)` + same-day filter | Single day window |
| `no_album` | Custom orphan detection function | Set difference |
| `no_tag` | `LEFT JOIN image_tag ... IS NULL` | Negation join |
| `no_virtual_album` | Images not in any virtual category | Multi-step |
| `duplicates` | `GROUP BY {fields} HAVING COUNT(*) > 1` | Up to 4 fields |
| `all_photos` | `SELECT id FROM images` | Full scan |
| `no_sync_md5sum` | `WHERE md5sum IS NULL` | Missing checksums |
| Plugin-registered | Via `perform_batch_manager_prefilters` hook | Extensible |

### 20.7.2 Batch Manager Actions (15 types)

| Action | Operations | Transaction Needed |
|---|---|---|
| `remove_from_caddie` | DELETE from caddie | No |
| `add_tags` | Resolve tag IDs + INSERT image_tag | No |
| `del_tags` | DELETE from image_tag | No |
| `associate` | INSERT image_category + update representatives | Yes |
| `move` | DELETE old links + INSERT new | Yes |
| `dissociate` | DELETE from image_category (virtual only) | No |
| `author` | mass_update images.author | No |
| `title` | mass_update images.name | No |
| `date_creation` | mass_update images.date_creation | No |
| `level` | mass_update images.level + invalidate cache | Yes (cache) |
| `add_to_caddie` | INSERT into caddie | No |
| `delete` | Full cascade delete (see §20.7.4) | Yes |
| `metadata` | Re-extract EXIF/IPTC + update | No |
| `delete_derivatives` | Delete specific derivative size files | No (FS only) |
| `generate_derivatives` | Trigger async derivative generation | No |

### 20.7.3 Upload Pipeline (11 steps)

1. **Duplicate detection** — MD5 check against existing images
2. **Directory preparation** — `var/uploads/{YYYY}/{MM}/{DD}/`
3. **File placement** — `move_uploaded_file()` + `chmod 0644`
4. **Format handling** — Plugin hook for PDF/HEIC/TIFF/video/PSD/EPS → representative generation
5. **Original resize** — Optional, if dimensions exceed configured limits
6. **EXIF rotation** — Apply orientation correction
7. **Metadata extraction** — Dimensions, filesize
8. **Database insert** — `images` table
9. **Category association** — `image_category` table
10. **Metadata sync** — EXIF/IPTC extraction + tag creation
11. **Derivative cache** — Pre-warm medium size

**Chunked upload protocol:** Browser sends chunks → server accumulates → final chunk triggers `uploadCompleted` → chunks concatenated → processed as normal upload.

### 20.7.4 Delete Cascade Order

**`delete_categories(ids)`:**
1. Get all subcategories (recursive)
2. Delete images whose `storage_category_id` is in the set
3. (Wrapped in transaction):
   - DELETE `image_category` links
   - DELETE `user_access` grants
   - DELETE `group_access` grants
   - DELETE `categories` records
   - DELETE `old_permalinks`
   - DELETE `user_cache_categories`
4. Update representatives for affected parent categories
5. Trigger `delete_categories` plugin hook

**`delete_elements(ids)`:**
1. Delete physical files (original, representative, formats, derivatives)
2. DELETE `comments`
3. DELETE `image_category` links
4. DELETE `image_format` records
5. DELETE `image_tag` links
6. DELETE `favorites`
7. DELETE `rate` records
8. DELETE `caddie` entries
9. DELETE `images` records
10. Fix broken `representative_picture_id` references
11. Trigger `delete_elements` plugin hook

### 20.7.5 Category Tree Algorithms

**`update_uppercats()`:** For each category, walk parent chain to root, build comma-separated ancestor path: `"root_id,parent_id,self_id"`. Mass update.

**`update_global_rank()`:**
1. Query all categories ORDER BY `id_uppercat, sort_rank, name`
2. For each parent group, assign consecutive sort_rank (1, 2, 3...)
3. Build global_rank by replacing each ID in uppercats with its sort_rank → `"1.2.3"`
4. Mass update if changed

**`move_categories(ids, new_parent)`:**
1. Validate: can't move into own subtree (check uppercats)
2. UPDATE `id_uppercat`
3. Recalc `uppercats` and `global_rank`
4. If parent is private, children become private
5. Permission cascade

---

## 20.8 Session Serialization & Auth Edge Cases

**Severity: MEDIUM — Mostly straightforward, but the PHP serialization format requires migration and several security issues should be fixed.**

### 20.8.1 Session ID Construction

```
IPv4: first 2 octets → uppercase hex → 4 chars
      192.168.1.1 → "C0A8"
IPv6: empty string (NOT IMPLEMENTED)

Final session ID: "{IP_HASH}{PHP_SESSION_ID}"
Example: "C0A8abc123def456ghi789jkl012mno"
```

### 20.8.2 Complete Session Variable Inventory

| Key | Type | Purpose |
|---|---|---|
| `pwg_uid` | `i32` | Authenticated user ID |
| `image_order` | `i32` | Gallery sort order |
| `index_deriv` | `String` | Thumbnail derivative type |
| `show_metadata` | `bool` | Metadata visibility toggle |
| `picture_deriv` | `String` | Picture view derivative size |
| `device` | `String` | `"mobile"` or `"desktop"` |
| `mobile_theme` | `bool` | Mobile theme override |
| `comments_order` | `String` | Comment sort direction |
| `filter_enabled` | `bool` | Content filter active |
| `filter_check_key` | `Object` | Filter validation data |
| `filter_categories` | `Vec<i32>` | Filtered category IDs |
| `filter_visible_categories` | `String` | Comma-separated category IDs |
| `filter_visible_images` | `String` | Comma-separated image IDs |
| `bulk_manager_filter` | `Object` | Batch manager filter state |
| `plugins_show_details` | `bool` | Admin plugin details view |
| `plugins_new_order` | `String` | Plugin sort order |
| `multiview` | `Object` | AdminTools MultiView state |
| `lang_switch` | `String` | Language switch plugin state |

### 20.8.3 Remember-Me Algorithm

**Generation:**
```
data = "{timestamp}{user_id}{username}"
key  = "{config.secret_key}{user_password_hash}"
hmac = base64(HMAC-SHA1(data, key))
cookie = "{user_id}-{timestamp}-{hmac}"
```

**Validation:**
1. Split cookie by `-` → must be exactly 3 parts
2. `user_id` must be numeric
3. `timestamp` must be numeric
4. `timestamp` must be within `[now - remember_me_length, now]`
5. Recalculate HMAC with same inputs
6. Compare `calculated_hmac === cookie_hmac`
7. If valid → `log_user(user_id, true)` → establish session

**Cookie parameters:**
- Expires: `now + 5,184,000 seconds` (60 days)
- Path: cookie_path (usually `/piwigo/`)
- HttpOnly: yes
- Secure: if HTTPS

### 20.8.4 API Key Algorithm

**Generation:**
- `random_bytes(40)` → base64 → remove `+` and `/` → truncate to 30 chars
- Only `'normal'` and `'generic'` users can have keys (NOT admin/webmaster/guest)
- Default TTL: 259,200 seconds (3 days)
- Stored **plaintext** in `user_auth_keys` table

**Validation:**
- Format check: `/^[a-z0-9]{30}$/i`
- DB lookup by plaintext key value
- Expiry check: `expired_on > NOW()`
- Status check: only `'normal'` or `'generic'`

### 20.8.5 Security Issues to Fix in Rust

| Issue | PHP Behavior | Rust Fix |
|---|---|---|
| **IPv6 session binding** | Returns empty string — all IPv6 users share session space | Hash first 8 bytes of IPv6 address |
| **Non-timing-safe comparison** | `$key === $cookie[2]` in remember-me validation | Use `constant_time_eq` crate |
| **API keys stored plaintext** | `auth_key` column stores raw key | Store `bcrypt(key)` or `SHA-256(key)`, compare hash |
| **IP binding only 2 octets** | Only first 2 octets of IPv4 hashed | Use all 4 octets (or 3 for mobile-friendly) |
| **PHP native serialization** | Session data stored as PHP serialized | JSON — already planned |
| **No remember-me refresh** | Cookie expiry is fixed from creation time | Refresh expiry on each `auto_login()` |
| **Deterministic GC** | Session GC runs on every login | Probabilistic (1% of requests) or background task |
| **Case-insensitive login** | Loads ALL users for case-insensitive search | Use `LOWER(username) = LOWER(?)` in SQL |

---

## 21. Modernization Roadmap

> Post-parity features that take advantage of the Rust rewrite to deliver capabilities PHP Piwigo could never offer. Organized by domain. Each item is classified as **Tier 1** (ship with or soon after v1.0 — low effort, high value), **Tier 2** (v1.x series — medium effort), or **Tier 3** (v2.0 — major new subsystem).

---

### 21.1 Modern Image & Media Pipeline

#### 21.1.1 Next-Gen Format Negotiation — Tier 1

libvips already supports AVIF, WebP, and JPEG XL. Serve the best format the client accepts.

- [ ] Parse `Accept` header for `image/avif`, `image/webp`, `image/jxl`
- [ ] Preference order: AVIF > JXL > WebP > JPEG (configurable)
- [ ] Generate derivatives in negotiated format on first request, cache alongside the JPEG derivative
- [ ] Cache key includes format: `photo-me.avif`, `photo-me.webp`, `photo-me.jpg` coexist in `var/derivatives/`
- [ ] `<picture>` element with `<source>` tags in templates for browsers that don't send proper Accept
- [ ] Admin toggle per format (some admins may not want AVIF due to older browser share)
- [ ] Fallback: if libvips lacks encoder for a format (e.g., JXL on older builds), skip silently

**Size savings estimate:** AVIF is ~30-50% smaller than JPEG at equivalent quality. For a gallery with 100k images at 9 derivative sizes each, this saves significant storage and bandwidth.

#### 21.1.2 BlurHash / LQIP Placeholders — Tier 1

Generate a tiny placeholder that renders instantly while the real image loads.

- [ ] Compute [BlurHash](https://blurha.sh/) string (20-30 chars) during upload/sync metadata extraction
- [ ] Store in `images.blurhash` column (`VARCHAR(32)`)
- [ ] Embed hash in HTML as `data-blurhash` attribute on `<img>` tags
- [ ] Client-side JS decodes hash into a 32×32 canvas, displayed as placeholder
- [ ] Alternative: store a 4×3 pixel JPEG inline as base64 data URI (~100 bytes) — no JS needed
- [ ] **Crate:** `blurhash` (pure Rust encoder/decoder)

#### 21.1.3 Video Support — Tier 3

Basic video hosting with thumbnail extraction and adaptive streaming.

- [ ] **Supported formats:** MP4 (H.264/H.265), WebM (VP9/AV1), MOV
- [ ] **Thumbnail extraction:** Use `ffmpeg` CLI (shelled out via `tokio::process::Command`) to extract frame at configurable timestamp (default: 10% into video, or scene-detect)
- [ ] **Transcoding pipeline:**
  - Original stored as-is
  - Generate HLS segments: `ffmpeg -i input.mp4 -codec: copy -start_number 0 -hls_time 6 -hls_list_size 0 -f hls output.m3u8`
  - Multiple quality tiers: 1080p, 720p, 480p (configurable)
  - Store segments in `storage/videos/{image_id}/`
- [ ] **Streaming:** Serve `.m3u8` playlist + `.ts` segments via standard Axum static file serving
- [ ] **Player:** Embed `hls.js` for HLS playback in browsers without native support
- [ ] **Metadata:** Duration, resolution, codec, framerate — extracted via `ffprobe` JSON output
- [ ] **Schema:** `images.media_type ENUM('photo','video','audio')`, `images.duration_ms INT`, `video_formats` table for transcoded variants
- [ ] **Background processing:** Video transcoding is CPU-heavy — run in a background job queue (§21.9), not inline with upload

#### 21.1.4 RAW File Support — Tier 2

Photographers shoot RAW. Support it as a first-class format.

- [ ] Detect RAW formats: CR2, CR3, NEF, ARW, DNG, ORF, RW2, RAF, PEF
- [ ] **Crate:** `rawloader` for demosaicing, or shell out to `dcraw`/`libraw`
- [ ] On upload: extract embedded JPEG preview for immediate display, generate full-quality JPEG derivative from RAW data as background job
- [ ] Store RAW as original, treat generated JPEG as the representative
- [ ] EXIF extraction works on RAW files via `kamadak-exif` (already reads TIFF-based formats)

#### 21.1.5 Perceptual Hashing — Tier 2

Detect near-duplicate images regardless of resolution, crop, or compression.

- [ ] Compute perceptual hash (pHash or blockhash) during upload/sync
- [ ] Store as `images.phash BIGINT` (64-bit hash)
- [ ] **Similarity:** Hamming distance between hashes — distance < 10 = likely duplicate
- [ ] Admin tool: "Find similar images" page — query all pairs within threshold
- [ ] Batch manager prefilter: `near_duplicates` — groups by phash similarity
- [ ] **Crate:** `image_hasher` (implements aHash, dHash, pHash, blockhash)
- [ ] Index: B-tree on `phash` column enables range scans for Hamming-distance queries, or use BK-tree in memory for large galleries

---

### 21.2 AI-Powered Features

#### 21.2.1 ONNX Runtime Integration — Tier 2

Run ML models locally — no cloud API, no data leaves the server.

- [ ] **Crate:** `ort` (ONNX Runtime Rust bindings)
- [ ] Load models from `models/` directory on startup
- [ ] Inference runs on `tokio::task::spawn_blocking` with a semaphore (default: 2 concurrent inferences)
- [ ] GPU acceleration: ONNX Runtime supports CUDA and DirectML — configure via `config.ml.execution_provider`
- [ ] Models are not bundled with the binary — downloaded on first use or manually placed
- [ ] All ML features are opt-in via config flags

```rust
pub struct MlPipeline {
    clip_vision: Option<ort::Session>,     // CLIP ViT-B/32 (~350MB)
    clip_text: Option<ort::Session>,       // CLIP text encoder (~250MB)
    tagger: Option<ort::Session>,          // Scene/object tagger (~90MB)
    face_detect: Option<ort::Session>,     // RetinaFace (~30MB)
    face_embed: Option<ort::Session>,      // ArcFace (~120MB)
}
```

#### 21.2.2 Auto-Tagging — Tier 2

Automatic scene and object detection on upload.

- [ ] **Model:** Pre-trained tagger (e.g., Recognize Anything Model / RAM, or WD-Tagger for anime/illustration galleries)
- [ ] Run inference on upload (if enabled) — input is resized derivative (224×224 or 384×384)
- [ ] Output: list of `(tag_name, confidence)` pairs
- [ ] Filter by configurable confidence threshold (default: 0.5)
- [ ] Create tags automatically in `tags` table, link via `image_tag` with `source = 'auto'`
- [ ] Admin review page: show auto-tagged images grouped by tag, approve/reject/edit
- [ ] Re-run on existing gallery: `piwigo ml tag --all` CLI command (batch, uses rayon for parallelism)
- [ ] Store confidence score: `image_tag.confidence FLOAT NULL` (NULL = manual tag)

#### 21.2.3 Face Detection & Recognition — Tier 3

Identify and group faces across the gallery.

- [ ] **Detection:** RetinaFace ONNX model — outputs bounding boxes + landmarks for each face
- [ ] **Embedding:** ArcFace ONNX model — 512-dim vector per face
- [ ] **Schema:**
  ```sql
  CREATE TABLE faces (
      id SERIAL PRIMARY KEY,
      image_id INT NOT NULL REFERENCES images(id) ON DELETE CASCADE,
      bbox_x FLOAT, bbox_y FLOAT, bbox_w FLOAT, bbox_h FLOAT,  -- normalized 0-1
      embedding BYTEA,          -- 512×float32 = 2048 bytes
      person_id INT REFERENCES persons(id),
      confirmed BOOLEAN DEFAULT false
  );
  CREATE TABLE persons (
      id SERIAL PRIMARY KEY,
      name VARCHAR(255),
      representative_face_id INT REFERENCES faces(id)
  );
  ```
- [ ] **Clustering:** On-demand DBSCAN or Chinese Whispers clustering of unassigned face embeddings → suggest person groups
- [ ] **Admin UI:** Face review page — show clusters, name them, merge/split, confirm matches
- [ ] **Privacy:** Face detection is off by default. Opt-in per gallery. No external API calls.
- [ ] **Search:** "Photos of [person name]" in search bar

#### 21.2.4 Semantic Search via CLIP — Tier 2

Search photos by natural language description instead of manual tags.

- [ ] **CLIP (Contrastive Language-Image Pre-training):** Embeds images and text into the same 512-dim vector space
- [ ] On upload: run image through CLIP vision encoder → store 512-dim float32 vector (2048 bytes)
- [ ] Store in `images.clip_embedding BYTEA` column
- [ ] On search: encode query text via CLIP text encoder → cosine similarity against all image embeddings
- [ ] **Performance:** Brute-force cosine similarity over 100k 512-dim vectors takes ~5ms on modern CPU (SIMD)
- [ ] For larger galleries (>500k): use `usearch` or `hnsw_rs` crate for approximate nearest-neighbor index
- [ ] Query examples: "sunset over mountains", "birthday cake with candles", "dog playing in snow"
- [ ] Integrate into existing search: `type:semantic sunset over mountains` scope prefix, or automatic fallback when tag/text search returns few results
- [ ] **Crate:** `usearch` (compact ANN index, single-file, no server dependency)

#### 21.2.5 Smart Albums — Tier 2

Albums that auto-populate based on rules and ML signals.

- [ ] **Rule engine:** Combine filters with AND/OR logic
  - Date range: photos from last 30 days
  - Tag: has tag "landscape" (manual or auto)
  - Person: contains face of [person]
  - Location: within radius of GPS coordinate
  - Camera: shot with specific camera model
  - Rating: above threshold
  - Semantic: similar to text query (CLIP)
- [ ] Stored as JSON rule definition in `categories.smart_rules JSONB` column
- [ ] `categories.is_smart BOOLEAN DEFAULT false`
- [ ] Membership computed on access (cached with TTL) — not materialized
- [ ] Admin UI: rule builder with live preview count

---

### 21.3 Modern Authentication & Security

#### 21.3.1 WebAuthn / Passkeys — Tier 2

Passwordless authentication using platform authenticators (fingerprint, face, hardware key).

- [ ] **Crate:** `webauthn-rs` (comprehensive WebAuthn server library)
- [ ] **Schema:**
  ```sql
  CREATE TABLE webauthn_credentials (
      id SERIAL PRIMARY KEY,
      user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      credential_id BYTEA NOT NULL UNIQUE,
      public_key BYTEA NOT NULL,
      counter INT NOT NULL DEFAULT 0,
      transports TEXT[],           -- usb, ble, nfc, internal
      created_at TIMESTAMP NOT NULL DEFAULT NOW(),
      last_used_at TIMESTAMP
  );
  ```
- [ ] **Registration flow:** User logs in with password → navigates to security settings → clicks "Add passkey" → browser WebAuthn ceremony → credential stored
- [ ] **Login flow:** Username field → server sends challenge → browser prompts passkey → server verifies assertion
- [ ] **Discoverable credentials:** Support resident keys so users don't need to type username
- [ ] Passkeys are additive — password login remains available unless explicitly disabled by user

#### 21.3.2 TOTP Two-Factor Authentication — Tier 1

Standard time-based one-time passwords (Google Authenticator, Authy, etc.).

- [ ] **Crate:** `totp-rs`
- [ ] **Setup:** Generate secret → display QR code (via `qrcode` crate rendered to SVG) → user scans → verify one code to confirm
- [ ] Store `users.totp_secret` (encrypted at rest with server secret key)
- [ ] **Login flow:** Password verified → if TOTP enabled → redirect to TOTP entry page → verify code → establish session
- [ ] **Recovery codes:** Generate 10 single-use 8-char codes on TOTP setup, stored hashed. User saves these offline.
- [ ] Admin can enforce 2FA for admin/webmaster accounts via config

#### 21.3.3 OAuth2 / OIDC Single Sign-On — Tier 2

Login with Google, GitHub, Microsoft, or any OIDC provider.

- [ ] **Crate:** `openidconnect` (full OIDC Relying Party implementation)
- [ ] **Schema:**
  ```sql
  CREATE TABLE oauth_providers (
      id VARCHAR(50) PRIMARY KEY,    -- 'google', 'github', 'custom-oidc'
      display_name VARCHAR(100),
      client_id VARCHAR(255) NOT NULL,
      client_secret VARCHAR(255) NOT NULL,
      issuer_url VARCHAR(500),        -- OIDC discovery URL
      authorization_url VARCHAR(500), -- for non-OIDC OAuth2
      token_url VARCHAR(500),
      scopes VARCHAR(255) DEFAULT 'openid profile email',
      enabled BOOLEAN DEFAULT true
  );
  CREATE TABLE user_oauth_links (
      user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      provider_id VARCHAR(50) NOT NULL REFERENCES oauth_providers(id),
      external_id VARCHAR(255) NOT NULL,
      email VARCHAR(255),
      UNIQUE(provider_id, external_id)
  );
  ```
- [ ] **Flow:** Login page shows "Sign in with [provider]" buttons → OAuth2 Authorization Code flow with PKCE → on callback, match `external_id` to existing user or auto-provision
- [ ] Auto-provision configurable: off (must link manually), on for matching email, on always (create new user)
- [ ] Pre-built configurations for Google, GitHub, Microsoft — admin just enters client ID/secret
- [ ] Custom OIDC provider: admin enters discovery URL, client credentials

#### 21.3.4 Scoped API Tokens — Tier 1

Replace the current plaintext 30-char keys with proper scoped tokens.

- [ ] **Schema:**
  ```sql
  CREATE TABLE api_tokens (
      id SERIAL PRIMARY KEY,
      user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      name VARCHAR(100) NOT NULL,      -- "Lightroom sync", "Mobile app"
      token_hash VARCHAR(128) NOT NULL, -- SHA-256 of token (never store plaintext)
      token_prefix VARCHAR(8) NOT NULL, -- first 8 chars for identification: "pwg_a1b2"
      scopes TEXT[] NOT NULL,           -- ['read', 'upload', 'admin']
      expires_at TIMESTAMP,             -- NULL = no expiry
      last_used_at TIMESTAMP,
      last_used_ip INET,
      created_at TIMESTAMP NOT NULL DEFAULT NOW()
  );
  ```
- [ ] **Token format:** `pwg_{random_40_chars}` — prefix makes tokens identifiable in logs/secrets scanning
- [ ] **Scopes:** `read` (browse gallery, download), `upload` (add photos), `write` (edit metadata, manage albums), `admin` (full admin access)
- [ ] API validates scope on each endpoint: `POST /api/v1/photos` requires `upload` scope, `DELETE /api/v1/users/{uuid}` requires `admin` scope
- [ ] Admin UI: token management page — create, list (showing prefix + last used), revoke
- [ ] Backward compat: old-style tokens continue to work with `read+upload` scope

#### 21.3.5 EXIF Privacy Controls — Tier 1

Protect photographer and subject location data.

- [ ] **Config options:**
  - `exif.strip_gps`: `never` | `on_public` | `always` — strip GPS from derivatives served to non-admin users
  - `exif.strip_all_metadata`: strip all EXIF from derivatives (already planned as `strip()`)
  - `exif.geographic_privacy_zones`: list of `{lat, lon, radius_km, label}` — auto-strip GPS from originals within zone
- [ ] **Implementation:** During derivative generation, conditionally call `vips_image_remove("exif-ifd2")` (GPS IFD) before writing
- [ ] **Privacy zones:** During metadata extraction in sync/upload, check if GPS coordinates fall within any zone → if yes, null out `images.latitude`/`images.longitude` and strip GPS from stored original
- [ ] **Admin UI:** Map-based zone editor (Leaflet.js) for defining privacy zones (home, school, workplace)
- [ ] Originals always retain full EXIF on disk — stripping happens at serving time for derivatives and at DB level for coordinates

---

### 21.4 Advanced Search

#### 21.4.1 Tantivy Full-Text Search — Tier 2

Replace SQL `LIKE '%word%'` with a proper inverted index.

- [ ] **Crate:** `tantivy` (Rust-native full-text search engine, Lucene architecture)
- [ ] **Indexed fields:** image name, description, file name, tags, IPTC keywords, author, album name, album description, comments
- [ ] **Index location:** `var/search_index/` directory
- [ ] **Indexing:** On upload/edit/sync, update the Tantivy document for affected images. Batch reindex via `piwigo search reindex` CLI.
- [ ] **Tokenizer:** ICU-aware tokenizer for CJK support, plus stemming for European languages
- [ ] **Query syntax:** Support quoted phrases (`"red car"`), boolean operators (`sunset AND mountains`), field-specific (`tag:landscape`), fuzzy (`sunet~1`)
- [ ] **Ranking:** BM25 with boost on title > tags > description > comments
- [ ] **Performance:** Tantivy searches 1M documents in <10ms
- [ ] **Fallback:** If Tantivy index is unavailable, degrade to SQL LIKE (with warning in logs)

#### 21.4.2 Vector Similarity Search — Tier 2

Powers semantic search (§21.2.4) and "find similar images".

- [ ] **Crate:** `usearch` (single-file ANN index) or `hnsw_rs`
- [ ] **Index:** HNSW (Hierarchical Navigable Small World) graph for approximate nearest neighbors
- [ ] **Index location:** `var/vector_index/` — single memory-mapped file
- [ ] **Operations:**
  - `search_by_text(query, top_k)` → encode text with CLIP → ANN search → return image IDs
  - `search_by_image(image_id, top_k)` → use stored embedding → ANN search → return similar images
- [ ] **"More like this" button** on picture page — returns 12 most visually similar images
- [ ] Index rebuilt incrementally on upload; full rebuild via `piwigo search reindex-vectors`

#### 21.4.3 Faceted Search — Tier 1

Filter search results by multiple dimensions simultaneously.

- [ ] **Facets:** camera make, camera model, lens, year, month, tag, album, author, rating, file type, privacy level, resolution range, orientation (landscape/portrait/square)
- [ ] **UI:** Sidebar with collapsible facet sections, each showing top values with counts
- [ ] Clicking a facet value adds it as a filter — URL reflects all active facets
- [ ] **Implementation:** Aggregate queries alongside main search — `GROUP BY camera_make` etc.
- [ ] If Tantivy is enabled, use its native facet support (more efficient than SQL aggregation)
- [ ] Facets update dynamically as filters are applied (counts reflect filtered result set)

---

### 21.5 Storage Backends

#### 21.5.1 S3-Compatible Object Storage — Tier 2

Store originals and/or derivatives on S3, MinIO, Backblaze B2, Cloudflare R2, or any S3-compatible service.

- [ ] **Crate:** `aws-sdk-s3` (official AWS SDK) or `rust-s3` (lighter, S3-compatible)
- [ ] **Config:**
  ```toml
  [storage]
  backend = "s3"           # "local" | "s3" | "hybrid"
  
  [storage.s3]
  endpoint = "https://s3.amazonaws.com"   # or MinIO/R2/B2 endpoint
  bucket = "my-piwigo-gallery"
  region = "us-east-1"
  access_key_id = "..."
  secret_access_key = "..."
  prefix = "gallery/"                      # optional key prefix
  public_url = "https://cdn.example.com"   # for direct client access (signed URLs or public bucket)
  ```
- [ ] **Storage trait:**
  ```rust
  #[async_trait]
  pub trait StorageBackend: Send + Sync {
      async fn put(&self, key: &str, data: Bytes, content_type: &str) -> Result<()>;
      async fn get(&self, key: &str) -> Result<Bytes>;
      async fn delete(&self, key: &str) -> Result<()>;
      async fn exists(&self, key: &str) -> Result<bool>;
      async fn presigned_url(&self, key: &str, expires_in: Duration) -> Result<String>;
  }
  ```
- [ ] **Hybrid mode:** Originals on S3, derivatives on local disk (fast serving). Or originals local, derivatives on S3 behind CDN.
- [ ] **Direct serving:** For public galleries, generate presigned S3 URLs or use public bucket URL — client fetches directly from S3/CDN, bypassing Piwigo server for image data
- [ ] **Migration CLI:** `piwigo storage migrate --from local --to s3` — copies all files, updates paths, verifiable with `--dry-run`

#### 21.5.2 Content-Addressed Deduplication — Tier 2

If the same photo is uploaded to multiple albums, store only one copy.

- [ ] **Key:** `SHA-256` of original file content → `storage/cas/{ab}/{cd}/{abcdef...full-hash}.{ext}`
- [ ] `images.cas_hash VARCHAR(64)` — the content hash
- [ ] `images.path` becomes a virtual path (album-relative), `images.cas_hash` points to actual storage
- [ ] **Dedup check:** Before writing file, check if hash exists → if yes, skip write, link to existing
- [ ] **Reference counting:** `cas_objects` table tracks `hash → ref_count`. Decrement on image delete, GC when ref_count = 0.
- [ ] **Savings:** For galleries where the same photos appear in multiple albums (events, curated collections), storage drops significantly
- [ ] Compatible with both local and S3 storage backends

#### 21.5.3 Tiered Storage — Tier 3

Hot/cold storage tiers for large archives.

- [ ] **Hot tier:** Local SSD — originals and derivatives for recently accessed images
- [ ] **Cold tier:** S3/S3 Glacier/tape — originals for images not accessed in N days
- [ ] **Policy:** Configurable per album or globally: `archive_after_days = 90`
- [ ] **Access:** When a cold image is requested, fetch from cold tier → cache on hot tier → serve. Display a "loading from archive" placeholder during retrieval.
- [ ] **Background job:** Nightly scan moves images past threshold to cold tier, deletes hot copies (except small derivatives for browsing)
- [ ] **CLI:** `piwigo storage archive --older-than 180d` and `piwigo storage restore --album 42`

---

### 21.6 Modern Frontend

#### 21.6.1 HTMX Progressive Enhancement — Tier 1

Add interactivity to the SSR pages without a JavaScript framework.

- [ ] **Key interactions to enhance:**
  - Infinite scroll / "load more" for image grids: `hx-get="/gallery/42?page=3" hx-trigger="revealed" hx-swap="afterend"`
  - Batch manager: action forms submit via HTMX → partial page update instead of full reload
  - Admin quick-edit: inline editing of image title/tags/description
  - Comments: submit/delete without full page reload
  - Search autocomplete: `hx-get="/qsearch?q=" hx-trigger="keyup changed delay:300ms"`
  - Album tree drag-and-drop reordering (with Sortable.js + HTMX swap)
- [ ] **Response format:** Handlers detect `HX-Request` header → return HTML fragment instead of full page
- [ ] **Progress indicators:** `hx-indicator` for loading spinners on slow operations
- [ ] **Size:** HTMX is ~14KB gzipped — single `<script>` tag, no build pipeline

#### 21.6.2 Responsive Images — Tier 1

Serve optimal image sizes for every viewport.

- [ ] **`srcset` generation:** For each image, emit all available derivative sizes in `srcset`:
  ```html
  <img src="photo-me.jpg"
       srcset="photo-xs.jpg 432w, photo-sm.jpg 576w, photo-me.jpg 792w, photo-la.jpg 1008w, photo-xl.jpg 1224w"
       sizes="(max-width: 600px) 100vw, (max-width: 1200px) 50vw, 33vw"
       loading="lazy"
       decoding="async"
       alt="{{ image.name }}">
  ```
- [ ] **`<picture>` with format sources:** Combine with format negotiation (§21.1.1):
  ```html
  <picture>
      <source srcset="photo-me.avif 792w, photo-la.avif 1008w" type="image/avif">
      <source srcset="photo-me.webp 792w, photo-la.webp 1008w" type="image/webp">
      <img src="photo-me.jpg" srcset="photo-me.jpg 792w, photo-la.jpg 1008w" ...>
  </picture>
  ```
- [ ] **Thumbnail grid:** `sizes` attribute computed from CSS grid column count per breakpoint
- [ ] **Art direction:** Square crop for mobile grid, uncropped for desktop — different `srcset` per breakpoint

#### 21.6.3 Progressive Web App — Tier 2

Offline browsing and native app feel on mobile.

- [ ] **Service worker:** Cache gallery shell (HTML, CSS, JS), cache viewed images for offline browsing
- [ ] **Cache strategy:**
  - App shell: cache-first (CSS, JS, fonts)
  - Gallery pages: network-first with cache fallback
  - Images: cache-first (thumbnails likely don't change)
  - API calls: network-only (real-time data)
- [ ] **Manifest:** `manifest.json` with app name, icons, theme color, `display: standalone`
- [ ] **Install prompt:** "Add to Home Screen" banner on mobile
- [ ] **Offline indicator:** Show banner when offline, indicate which content is cached
- [ ] **Share Target:** Register as a share target — users can share photos from other apps directly to Piwigo for upload
- [ ] **Background sync:** Queue uploads when offline, sync when connection returns

#### 21.6.4 Masonry / Justified Grid Layout — Tier 1

Display thumbnails in a visually appealing layout that respects each image's aspect ratio.

- [ ] **Justified layout:** Rows of images with equal height, variable width — like Flickr/Google Photos
- [ ] **Implementation:** Server-side layout computation using image aspect ratios (known from DB):
  ```rust
  pub struct JustifiedRow {
      pub images: Vec<JustifiedImage>,
      pub height: u32,
  }
  pub struct JustifiedImage {
      pub id: i32,
      pub width: u32,   // computed display width
      pub height: u32,  // same for all images in row
  }
  ```
- [ ] Algorithm: Knuth-Plass line-breaking adapted for images — minimize row height variance
- [ ] Pass computed dimensions to template → CSS `width` and `height` set per image → no layout shift
- [ ] **User toggle:** Grid (uniform squares), Justified (variable width), List (single column with metadata)
- [ ] Persisted in user session/preferences

#### 21.6.5 Dark Mode — Tier 1

System-preference-aware dark theme.

- [ ] CSS custom properties for all colors — single source of truth
- [ ] `@media (prefers-color-scheme: dark)` for automatic detection
- [ ] Manual toggle (stored in session) overrides system preference
- [ ] Three states: Light / Dark / System
- [ ] Admin panel gets dark mode too
- [ ] Theme authors can override dark mode palette via `theme.toml`

#### 21.6.6 Keyboard Navigation — Tier 1

Power-user shortcuts throughout the gallery.

- [ ] **Gallery browsing:** `j`/`k` or arrow keys to move between thumbnails, `Enter` to open
- [ ] **Picture page:** Left/Right arrows for prev/next (already common), `f` for fullscreen, `i` for info panel toggle, `Escape` to return to album
- [ ] **Admin batch manager:** `x` to select/deselect, `Shift+click` for range select, `Ctrl+a` select all
- [ ] **Global:** `/` focuses search, `?` shows shortcut help overlay
- [ ] Implementation: single `keydown` event listener, action map dispatched by current page context
- [ ] Shortcut help modal: `?` key opens overlay showing all available shortcuts for current page

---

### 21.7 API Modernization

#### 21.7.1 OpenAPI Specification — Tier 1

Auto-generate API documentation from the method registry.

- [ ] **Crate:** `utoipa` (derive macros for OpenAPI from Axum handlers)
- [ ] Each API method annotated with `#[utoipa::path(...)]` — parameters, response types, auth requirements
- [ ] Serve `GET /api/openapi.json` with the full OpenAPI 3.1 spec
- [ ] Embed Swagger UI at `GET /api/docs` (served from static assets)
- [ ] Spec includes all 84+ methods with request/response schemas
- [ ] CI check: spec matches implementation (generated spec diffed against committed spec)

#### 21.7.2 Webhooks — Tier 2

Push notifications when gallery events occur.

- [ ] **Schema:**
  ```sql
  CREATE TABLE webhooks (
      id SERIAL PRIMARY KEY,
      user_id INT NOT NULL REFERENCES users(id),
      url VARCHAR(500) NOT NULL,
      secret VARCHAR(128),          -- HMAC signing key
      events TEXT[] NOT NULL,        -- ['image.uploaded', 'album.updated', 'comment.added']
      enabled BOOLEAN DEFAULT true,
      created_at TIMESTAMP NOT NULL DEFAULT NOW(),
      last_triggered_at TIMESTAMP,
      failure_count INT DEFAULT 0
  );
  ```
- [ ] **Events:** `image.uploaded`, `image.deleted`, `image.updated`, `album.created`, `album.deleted`, `comment.added`, `comment.approved`, `user.registered`, `sync.completed`
- [ ] **Delivery:** POST to webhook URL with JSON body + `X-Piwigo-Signature` header (HMAC-SHA256 of body with webhook secret)
- [ ] **Retry:** Exponential backoff — 1s, 10s, 60s, 600s. Disable webhook after 10 consecutive failures.
- [ ] **Delivery queue:** Fire-and-forget via `tokio::spawn` — webhook delivery never blocks the triggering request
- [ ] **Admin UI:** Webhook management page — create, test (sends ping event), view delivery log

#### 21.7.3 GraphQL Endpoint — Tier 3

Optional alternative API for clients that benefit from it (mobile apps, SPAs).

- [ ] **Crate:** `async-graphql` (most mature Rust GraphQL server)
- [ ] Serve at `POST /api/graphql` alongside the existing REST API
- [ ] **Schema mirrors domain:**
  ```graphql
  type Query {
      image(id: ID!): Image
      images(albumId: ID, search: String, first: Int, after: String): ImageConnection
      album(id: ID!): Album
      albums(parentId: ID): [Album!]!
      tags: [Tag!]!
      me: User
  }
  type Mutation {
      uploadImage(input: UploadInput!): Image!
      updateImage(id: ID!, input: ImageInput!): Image!
      deleteImage(id: ID!): Boolean!
      addComment(imageId: ID!, content: String!): Comment!
  }
  type Subscription {
      syncProgress(jobId: ID!): SyncEvent!
  }
  ```
- [ ] **Key advantage:** Clients can request exactly the fields they need — mobile app fetching thumbnail grid doesn't need full EXIF, desktop app fetching image detail doesn't need all derivative URLs
- [ ] **DataLoader:** Use `async-graphql::dataloader` for N+1 query prevention
- [ ] Auth: same session/token auth as REST, scope enforcement on mutations
- [ ] GraphQL Playground at `/api/graphql/playground` (dev mode only)

---

### 21.8 Observability

#### 21.8.1 OpenTelemetry — Tier 1

Distributed tracing and metrics from day one.

- [ ] **Crates:** `tracing-opentelemetry`, `opentelemetry`, `opentelemetry-otlp`
- [ ] **Traces:** Each HTTP request is a trace span. Child spans for:
  - Database queries (query text, duration, rows affected)
  - Image processing (source path, derivative type, dimensions, duration)
  - Plugin hook invocations (event name, handler count, duration)
  - Template rendering (template name, duration)
  - External HTTP calls (webhook delivery, update checks)
- [ ] **Metrics:**
  - `http_requests_total` (method, path, status)
  - `http_request_duration_seconds` (histogram)
  - `db_query_duration_seconds` (histogram, by query type)
  - `derivative_generation_duration_seconds` (histogram, by size)
  - `active_sessions` (gauge)
  - `sync_phase_duration_seconds` (histogram, by phase)
  - `cache_hit_ratio` (by cache: permission, derivative, template)
- [ ] **Export:** OTLP gRPC/HTTP to Jaeger, Grafana Tempo, Datadog, or any OTLP-compatible backend
- [ ] **Config:** `config.telemetry.otlp_endpoint`, `config.telemetry.service_name`
- [ ] **Zero-cost when disabled:** If no OTLP endpoint configured, tracing overhead is near-zero (no-op subscriber)

#### 21.8.2 Prometheus Metrics Endpoint — Tier 1

Standard `/metrics` endpoint for monitoring.

- [ ] **Crate:** `metrics`, `metrics-exporter-prometheus`
- [ ] Serve at `GET /metrics` (optionally behind auth or IP whitelist)
- [ ] All metrics from §21.8.1 exported in Prometheus exposition format
- [ ] **Process metrics:** RSS, CPU seconds, open file descriptors, thread count
- [ ] **Business metrics:** total images, total albums, total users, storage used
- [ ] Pre-built Grafana dashboard JSON shipped in `docs/grafana-dashboard.json`

#### 21.8.3 Structured Logging — Tier 1

JSON logs with correlation IDs for every request.

- [ ] **Format:** Each log line is JSON:
  ```json
  {"timestamp":"2026-04-15T12:00:00Z","level":"INFO","message":"image uploaded",
   "request_id":"a1b2c3","user_id":3,"image_id":42,"duration_ms":150,
   "span":"upload_handler"}
  ```
- [ ] **Request ID:** Generated per request (`uuid::Uuid::new_v4`), propagated via `tracing::Span`, included in all log lines and response header `X-Request-Id`
- [ ] **Log levels:** `ERROR` (action needed), `WARN` (degraded), `INFO` (request lifecycle), `DEBUG` (query details), `TRACE` (framework internals)
- [ ] **Config:** `RUST_LOG=piwigo=info,piwigo_db=debug` for per-module control
- [ ] **Dev mode:** Pretty-printed colored console output instead of JSON

---

### 21.9 Deployment & Operations

#### 21.9.1 Docker — Tier 1

Multi-architecture container images.

- [ ] **Multi-stage Dockerfile:**
  ```dockerfile
  FROM rust:1.82 AS builder
  # ... build with cargo
  FROM debian:bookworm-slim
  RUN apt-get install -y libvips42 ffmpeg  # runtime deps only
  COPY --from=builder /app/gallery /usr/local/bin/gallery
  ENTRYPOINT ["piwigo"]
  CMD ["serve"]
  ```
- [ ] **Architectures:** `linux/amd64`, `linux/arm64` (Raspberry Pi, ARM servers)
- [ ] **Compose file:** `compose.yaml` with Piwigo + PostgreSQL + optional Redis/MinIO
- [ ] **Health probes:** `HEALTHCHECK CMD piwigo health` (checks DB, storage, libvips)
- [ ] **Env-based config (12-factor):** All config options overridable via environment:
  - `PIWIGO_DATABASE_URL=postgres://...`
  - `PIWIGO_SERVER_PORT=8080`
  - `PIWIGO_STORAGE_BACKEND=s3`
  - Config precedence: env vars > config file > DB > code defaults

#### 21.9.2 Background Job Queue — Tier 2

Long-running tasks shouldn't block HTTP requests.

- [ ] **Job types:** Derivative batch generation, video transcoding, ML inference (tagging, face detection, CLIP embedding), S3 migration, search reindexing, email digest sending, storage tier migration
- [ ] **Architecture:** In-process job runner using `tokio::spawn` + bounded channel
  ```rust
  pub enum Job {
      GenerateDerivatives { image_ids: Vec<i32>, sizes: Vec<DerivativeType> },
      TranscodeVideo { image_id: i32, quality_tiers: Vec<VideoQuality> },
      MlInference { image_ids: Vec<i32>, models: Vec<MlModel> },
      ReindexSearch { scope: ReindexScope },
      SendDigestEmails,
  }
  ```
- [ ] **Persistence:** Jobs stored in `job_queue` table — survives server restart
- [ ] **Concurrency:** Configurable per job type (e.g., 4 concurrent derivative generations, 1 video transcode, 2 ML inferences)
- [ ] **Progress:** Job status visible in admin panel, queryable via API
- [ ] **CLI:** `piwigo jobs list`, `piwigo jobs run <id>`, `piwigo jobs cancel <id>`
- [ ] **Scheduling:** Periodic jobs (nightly storage tiering, weekly search reindex) via internal cron table — no external crontab needed

#### 21.9.3 Backup & Restore — Tier 1

First-class backup tooling built into the binary.

- [ ] **`gallery backup`:**
  - Dumps database to SQL file (via `pg_dump` / `mysqldump` shelled out, or sqlx-native for portability)
  - Archives `storage/` (originals, CAS, config) — the part that MUST be preserved
  - Skips `var/` by default (derivatives, caches, indexes — regenerable); `--include-var` option if desired
  - Archives `plugins/` and `themes/` directories
  - Output: single `.tar.zst` file (Zstandard-compressed tar)
  - Options: `--db-only`, `--files-only`, `--exclude-derivatives` (regeneratable), `--output path`
- [ ] **`piwigo restore`:**
  - Validates backup integrity (checksum)
  - Restores database (drop + recreate, or merge into existing)
  - Restores files to correct locations
  - Runs pending migrations after restore
- [ ] **Scheduled backups:** Via job queue (§21.9.2) — `config.backup.schedule = "0 3 * * *"` (daily at 3am)
- [ ] **Remote backup:** Upload backup file to S3 after creation

---

### 21.10 Collaboration & Sharing

#### 21.10.1 Shareable Guest Links — Tier 1

Share albums without requiring recipients to create accounts.

- [ ] **Schema:**
  ```sql
  CREATE TABLE share_links (
      id SERIAL PRIMARY KEY,
      token VARCHAR(64) NOT NULL UNIQUE,    -- random URL-safe string
      category_id INT NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
      created_by INT NOT NULL REFERENCES users(id),
      password_hash VARCHAR(255),            -- optional password protection
      expires_at TIMESTAMP,                  -- NULL = no expiry
      max_views INT,                         -- NULL = unlimited
      view_count INT DEFAULT 0,
      allow_download BOOLEAN DEFAULT true,
      allow_originals BOOLEAN DEFAULT false,  -- only derivatives if false
      include_subcategories BOOLEAN DEFAULT true,
      created_at TIMESTAMP NOT NULL DEFAULT NOW()
  );
  ```
- [ ] **URL:** `https://gallery.example.com/s/{token}`
- [ ] Guest accesses shared link → sees album without login (no user record needed)
- [ ] Permission check: shared link grants temporary read access to the specific album, bypassing normal permission system
- [ ] **Expiry:** Admin/owner can set expiry date and/or max views
- [ ] **Revocation:** Delete the share link → all access immediately revoked
- [ ] **Admin UI:** Per-album "Share" button → generate link, set options, copy to clipboard

#### 21.10.2 Guest Upload Links — Tier 2

Allow anyone with a link to upload photos to a specific album.

- [ ] Similar to share links but with write permission: `share_links.allow_upload BOOLEAN DEFAULT false`
- [ ] Upload page: minimal UI — drag-and-drop area, optional name/comment field
- [ ] Uploads go to moderation queue: `images.upload_status ENUM('approved','pending','rejected')` — admin reviews before they're visible
- [ ] Rate limiting: max 50 uploads per link per hour
- [ ] Use case: wedding guests, event attendees, classroom assignments

#### 21.10.3 Activity Feed — Tier 2

Per-album feed of recent activity.

- [ ] **Events tracked:** Photo added, photo commented on, album updated, person tagged
- [ ] **Implementation:** Query existing `activity` table (already tracks most operations), filter by album, format as timeline
- [ ] **UI:** Activity tab on album page — chronological list with thumbnails
- [ ] **API:** `GET /api/v1/albums/{uuid}/activity?since={iso8601}` — for mobile/external clients
- [ ] **Notifications:** Users can "watch" an album → receive email digest when new activity occurs (ties into existing notification system)

---

### 21.11 Privacy & Compliance

#### 21.11.1 Download Restrictions — Tier 1

Control what visitors can download.

- [ ] **Config per album:**
  - `download_policy`: `original` | `largest_derivative` | `watermarked_only` | `disabled`
  - Applies to download button, right-click save (watermark deters), API download methods
- [ ] **Watermark-only viewing:** Serve watermarked derivatives for non-owner users, strip download button. Original available only to owner/admin.
- [ ] **Hotlink protection:** Check `Referer` header on derivative requests — reject if not from gallery domain (configurable, off by default)

#### 21.11.2 GDPR Tools — Tier 2

Self-service tools for user data management.

- [ ] **Data export:** `GET /profile/export` → generates ZIP of all user data:
  - Profile information (JSON)
  - Comments posted (JSON)
  - Ratings given (JSON)
  - Favorites list (JSON)
  - Uploaded images (if applicable — links or actual files per config)
  - Activity history (JSON)
- [ ] **Account deletion:** `DELETE /profile` → cascade delete all user data:
  - Comments anonymized or deleted (configurable)
  - Ratings removed
  - Favorites cleared
  - Sessions destroyed
  - User record deleted
  - Confirmation email sent
- [ ] **Consent tracking:** Record when user accepted terms, which version
- [ ] **Cookie consent:** Minimal cookies by default (session only). Analytics/tracking cookies require explicit consent banner.

#### 21.11.3 Audit Trail — Tier 1

Comprehensive, tamper-evident log of security-relevant actions.

- [ ] **Events:**
  - Authentication: login success/failure, 2FA success/failure, password change, passkey added/removed
  - Authorization: permission grant/revoke, role change
  - Data access: original image download, bulk export
  - Admin actions: user create/delete, album delete, config change, plugin install
  - Sharing: link created/revoked, guest access
- [ ] **Schema:**
  ```sql
  CREATE TABLE audit_log (
      id BIGSERIAL PRIMARY KEY,
      timestamp TIMESTAMP NOT NULL DEFAULT NOW(),
      event_type VARCHAR(50) NOT NULL,
      actor_id INT REFERENCES users(id),     -- NULL for system events
      actor_ip INET,
      target_type VARCHAR(30),                -- 'user', 'image', 'album', 'config'
      target_id VARCHAR(100),
      details JSONB,
      request_id VARCHAR(36)                  -- correlation with HTTP request
  );
  ```
- [ ] **Retention:** Configurable, default 1 year. `gallery maintenance audit-trim` for manual cleanup.
- [ ] **Admin UI:** Searchable audit log page with filters by event type, actor, date range
- [ ] **Export:** `piwigo audit export --from 2026-01-01 --to 2026-04-01 --format csv`

---

### 21.12 Modernization Dependencies

Additional crates for the modernization features (beyond §3 base dependencies):

```toml
# Image formats (already via libvips — no additional crate)
blurhash             = "0.2"           # BlurHash encoding

# AI/ML
ort                  = { version = "2", features = ["cuda"] }  # ONNX Runtime
usearch              = "2"             # Approximate nearest neighbor
image_hasher         = "2"             # Perceptual hashing

# Authentication
webauthn-rs          = { version = "0.5", features = ["danger-allow-state-serialisation"] }
totp-rs              = { version = "5", features = ["qr"] }
openidconnect        = "4"
qrcode               = "0.14"

# Search
tantivy              = "0.22"

# Storage
aws-sdk-s3           = "1"
aws-config           = "1"

# API
utoipa               = { version = "5", features = ["axum_extras"] }
utoipa-swagger-ui    = { version = "8", features = ["axum"] }
async-graphql        = { version = "7", features = ["dataloader"] }
async-graphql-axum   = "7"

# Observability
opentelemetry        = "0.27"
opentelemetry-otlp   = "0.27"
tracing-opentelemetry = "0.28"
metrics              = "0.24"
metrics-exporter-prometheus = "0.16"

# Deployment
zstd                 = "0.13"          # Backup compression

# Frontend (JS, not Rust — served as static assets)
# htmx: 14KB, loaded from static/
# hls.js: video playback
# leaflet: privacy zone map editor
```

### 21.13 Modernization Milestone Summary

| Milestone | Tier | Features | Est. After v1.0 |
|---|---|---|---|
| **M9: Quick wins** | Tier 1 | AVIF/WebP negotiation, BlurHash, responsive images, dark mode, keyboard shortcuts, HTMX, justified grid, TOTP 2FA, faceted search, scoped API tokens, EXIF privacy, OpenAPI docs, OpenTelemetry, Prometheus, structured logging, Docker multi-arch, backup/restore, guest links, download restrictions, audit trail | +2 months |
| **M10: Intelligence** | Tier 2 | Tantivy search, CLIP semantic search, auto-tagging, perceptual hashing, RAW support, WebAuthn, OAuth2/OIDC, S3 storage, content-addressed dedup, PWA, webhooks, background job queue, vector index, smart albums, guest uploads, activity feed, GDPR tools | +6 months |
| **M11: Full platform** | Tier 3 | Video support (HLS), face detection/recognition, tiered storage, GraphQL endpoint | +12 months |

---
---

# Appendices — Reference Material

> Exhaustive inventories derived from the PHP 14.x codebase. Every table, API method, config option, hook event, template file, and URL pattern is cataloged here so no implementation surprise goes undocumented.

---

## Appendix A: Complete Database Schema

This is the greenfield schema the rewrite starts from. **Not** a port of Piwigo 14's 34-table layout — design decisions are restated in §5. Subject to refinement during Phase 1 but the shape is committed.

**Total: ~22 tables**, FKs on every relationship from day one, JSON for structured columns, UUIDv7 for externally-exposed identifiers.

The SQL below shows MySQL 9.7 / MariaDB 11.8 syntax. Postgres 18 equivalents use `BIGINT GENERATED ALWAYS AS IDENTITY`, native `UUID`, `JSONB`, `CITEXT`, `TIMESTAMPTZ`, `BYTEA`, and `ENUM` via `CREATE TYPE`. See §5 for the dialect-adapter notes.

### A.1 Users + auth

```sql
CREATE TABLE users (
    id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    uuid              BINARY(16) NOT NULL UNIQUE,
    username          VARCHAR(100) NOT NULL UNIQUE,
    email             VARCHAR(255) NOT NULL,
    email_ci          VARCHAR(255) AS (LOWER(email)) STORED UNIQUE,  -- CITEXT on PG
    email_verified_at TIMESTAMP NULL,
    password_hash     VARCHAR(255) NOT NULL,
    access_level      TINYINT UNSIGNED NOT NULL DEFAULT 2,
    locale            VARCHAR(10) NOT NULL DEFAULT 'en',
    timezone          VARCHAR(64) NOT NULL DEFAULT 'UTC',
    theme             VARCHAR(50) NOT NULL DEFAULT 'default',
    last_login_at     TIMESTAMP NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                           ON UPDATE CURRENT_TIMESTAMP,
    deleted_at        TIMESTAMP NULL,                     -- soft delete
    INDEX (access_level),
    INDEX (deleted_at)
);

CREATE TABLE groups (
    id         BIGINT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL UNIQUE,
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE user_groups (
    user_id  BIGINT NOT NULL REFERENCES users(id)  ON DELETE CASCADE,
    group_id BIGINT NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, group_id),
    INDEX (group_id)
);
```

Rust types: `User { id, uuid, username, email, access_level: AccessLevel, ... }`. `AccessLevel` derives `sqlx::Type` with `#[sqlx(type_name = "tinyint unsigned")]` on MySQL, `SMALLINT` on Postgres.

### A.2 Sessions + tokens

```sql
CREATE TABLE sessions (
    id          VARCHAR(128) PRIMARY KEY,                -- {ipv4_hex_4bytes}{random}
    user_id     BIGINT NULL REFERENCES users(id) ON DELETE CASCADE,
    data        JSON NOT NULL,                           -- application state, JSON from day one
    ip_hash     BINARY(32),                              -- SHA-256 of requester IP
    user_agent  VARCHAR(255),
    last_active TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  TIMESTAMP NOT NULL,
    INDEX (user_id),
    INDEX (expires_at)
);

CREATE TABLE remember_tokens (
    selector   CHAR(32) PRIMARY KEY,                     -- unhashed cookie half
    hash       BINARY(32) NOT NULL,                      -- SHA-256 of the secret half
    user_id    BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX (expires_at)
);

CREATE TABLE api_tokens (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name         VARCHAR(100) NOT NULL,                  -- human-readable, like "mobile app"
    token_hash   BINARY(32) NOT NULL UNIQUE,             -- SHA-256 of plaintext token
    scopes       JSON NOT NULL,                          -- ["read"], ["read","write"], etc.
    expires_at   TIMESTAMP NULL,
    last_used_at TIMESTAMP NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id)
);

CREATE TABLE password_reset_tokens (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  BINARY(32) NOT NULL UNIQUE,
    expires_at  TIMESTAMP NOT NULL,
    used_at     TIMESTAMP NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX (expires_at)
);
```

### A.3 Albums

```sql
CREATE TABLE albums (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    uuid            BINARY(16) NOT NULL UNIQUE,
    parent_id       BIGINT NULL REFERENCES albums(id) ON DELETE SET NULL,
    path            VARCHAR(500) NOT NULL,               -- materialized path, e.g. "1/5/12"
    name            VARCHAR(255) NOT NULL,
    slug            VARCHAR(255) NOT NULL,
    description     TEXT,
    cover_image_id  BIGINT NULL,                         -- FK added after images table
    rank            INT NOT NULL DEFAULT 0,
    min_level       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_public       BOOLEAN NOT NULL DEFAULT TRUE,
    image_count     INT NOT NULL DEFAULT 0,              -- denormalized, maintained by listeners
    commentable     BOOLEAN NOT NULL DEFAULT TRUE,
    visible         BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY albums_parent_slug (parent_id, slug),
    INDEX (parent_id, rank),
    INDEX (is_public, min_level),
    INDEX (path(191))                                    -- LIKE '1/5/%' descendant queries
);
```

`path` replaces Piwigo's `uppercats` comma-string. Postgres uses `ltree` with a GIST index; MySQL uses a `VARCHAR` with a prefix index. Rebuilt on album move via a single `UPDATE ... WHERE path LIKE 'old/%'`.

### A.4 Images

```sql
CREATE TABLE images (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    uuid            BINARY(16) NOT NULL UNIQUE,          -- used in derivative URLs
    storage_path    VARCHAR(500) NOT NULL,               -- e.g. storage/originals/2026/04/{uuid}.jpg
    original_name   VARCHAR(255) NOT NULL,               -- user-supplied filename, display only
    mime_type       VARCHAR(100) NOT NULL,
    width           INT NOT NULL,
    height          INT NOT NULL,
    filesize        BIGINT NOT NULL,                     -- bytes
    sha256          BINARY(32) NOT NULL,                 -- content hash for exact-dup detection
    perceptual_hash BINARY(8),                           -- pHash for near-duplicate detection
    author_id       BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    title           VARCHAR(255),
    description     TEXT,
    rating          TINYINT UNSIGNED,                    -- 0–5 Bayesian-adjusted average
    exif            JSON,                                -- typed + queryable
    taken_at        TIMESTAMP NULL,                      -- EXIF DateTimeOriginal
    taken_at_offset SMALLINT NULL,                       -- tz offset minutes preserved from EXIF
    gps_lat         DECIMAL(10, 7) NULL,
    gps_lng         DECIMAL(10, 7) NULL,
    coi             CHAR(4) NULL,                        -- center-of-interest for smart crop
    min_level       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    view_count      BIGINT NOT NULL DEFAULT 0,
    search_fts      TEXT AS (CONCAT_WS(' ', title, description, author_id)) STORED,  -- MySQL
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,
    INDEX (author_id),
    INDEX (taken_at),
    INDEX (min_level, created_at),
    INDEX (sha256),
    INDEX (perceptual_hash),
    FULLTEXT INDEX (search_fts)                           -- Postgres uses tsvector + GIN
);

-- Apply the deferred FK from albums
ALTER TABLE albums
    ADD CONSTRAINT fk_albums_cover FOREIGN KEY (cover_image_id)
    REFERENCES images(id) ON DELETE SET NULL;

CREATE TABLE image_albums (
    image_id BIGINT NOT NULL REFERENCES images(id) ON DELETE CASCADE,
    album_id BIGINT NOT NULL REFERENCES albums(id) ON DELETE CASCADE,
    rank     INT NOT NULL DEFAULT 0,
    added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (image_id, album_id),
    INDEX (album_id, rank)
);

CREATE TABLE image_formats (                             -- alternative file formats (CR2/DNG/HEIC)
    id        BIGINT AUTO_INCREMENT PRIMARY KEY,
    image_id  BIGINT NOT NULL REFERENCES images(id) ON DELETE CASCADE,
    ext       VARCHAR(16) NOT NULL,
    filesize  BIGINT NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    UNIQUE KEY (image_id, ext),
    INDEX (image_id)
);
```

No `representative_ext` column (Piwigo's way of flagging non-image originals). `mime_type` + `image_formats` table replace it cleanly.

### A.5 Tags

```sql
CREATE TABLE tags (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    slug        VARCHAR(100) NOT NULL UNIQUE,
    image_count INT NOT NULL DEFAULT 0,                  -- denormalized
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE image_tags (
    image_id   BIGINT NOT NULL REFERENCES images(id) ON DELETE CASCADE,
    tag_id     BIGINT NOT NULL REFERENCES tags(id)   ON DELETE CASCADE,
    source     ENUM('manual','auto') NOT NULL DEFAULT 'manual',
    confidence FLOAT NULL,                               -- for auto-tagged; NULL for manual
    added_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (image_id, tag_id),
    INDEX (tag_id)
);
```

### A.6 Permissions (album ACL)

```sql
CREATE TABLE album_user_access (
    album_id   BIGINT NOT NULL REFERENCES albums(id) ON DELETE CASCADE,
    user_id    BIGINT NOT NULL REFERENCES users(id)  ON DELETE CASCADE,
    can_view   BOOLEAN NOT NULL DEFAULT TRUE,
    can_upload BOOLEAN NOT NULL DEFAULT FALSE,
    can_manage BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (album_id, user_id),
    INDEX (user_id)
);

CREATE TABLE album_group_access (
    album_id   BIGINT NOT NULL REFERENCES albums(id) ON DELETE CASCADE,
    group_id   BIGINT NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    can_view   BOOLEAN NOT NULL DEFAULT TRUE,
    can_upload BOOLEAN NOT NULL DEFAULT FALSE,
    can_manage BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (album_id, group_id),
    INDEX (group_id)
);
```

Piwigo's `user_cache` + `user_cache_categories` tables are **not** in this schema — permission resolution is cached in-process via `moka` per §6.5.3.

### A.7 Engagement (comments, favorites, ratings)

```sql
CREATE TABLE comments (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    image_id     BIGINT NOT NULL REFERENCES images(id) ON DELETE CASCADE,
    user_id      BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    author_name  VARCHAR(100),                           -- for anonymous comments
    author_email VARCHAR(255),
    body         TEXT NOT NULL,
    status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    ip_hash      BINARY(32),                             -- salted SHA-256
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at  TIMESTAMP NULL,
    INDEX (image_id, status),
    INDEX (status, created_at)
);

CREATE TABLE favorites (
    user_id  BIGINT NOT NULL REFERENCES users(id)  ON DELETE CASCADE,
    image_id BIGINT NOT NULL REFERENCES images(id) ON DELETE CASCADE,
    added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, image_id),
    INDEX (image_id)
);

CREATE TABLE ratings (
    user_id  BIGINT NOT NULL REFERENCES users(id)  ON DELETE CASCADE,
    image_id BIGINT NOT NULL REFERENCES images(id) ON DELETE CASCADE,
    rating   TINYINT UNSIGNED NOT NULL,                  -- 1–5 (0 would mean "not rated")
    rated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, image_id),
    INDEX (image_id)
);
```

No separate `caddie` table — the admin "working set" is a saved-search or session-scoped selection, not persistent state.

No separate `anonymous_id` column (Piwigo's "last 3 octets of IP" scheme) — anonymous commenting + rating is gated behind server-side rate-limits keyed on `ip_hash`, not pseudo-identity.

### A.8 Audit log

```sql
CREATE TABLE audit_log (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    actor_id    BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    actor_ip    BINARY(16),                              -- binary IPv6 or IPv4-in-v6
    event       VARCHAR(100) NOT NULL,                   -- 'user.login', 'album.permission_changed', ...
    target_type VARCHAR(100),                            -- 'album', 'image', 'user', …
    target_id   BIGINT,
    details     JSON,                                    -- event-specific payload
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (actor_id, created_at),
    INDEX (event, created_at),
    INDEX (target_type, target_id, created_at)
);
```

Append-only. Retention is configurable (default 365 days) via `gallery maintenance audit-trim`.

### A.9 Background jobs + operations

```sql
CREATE TABLE background_jobs (                           -- queue storage (polling workers)
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    queue        VARCHAR(64) NOT NULL,                   -- 'default', 'images', 'mail', 'webhooks'
    payload      JSON NOT NULL,
    available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reserved_at  TIMESTAMP NULL,
    attempts     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (queue, available_at),
    INDEX (reserved_at)
);

CREATE TABLE failed_jobs (                                -- DLQ
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    queue        VARCHAR(64) NOT NULL,
    payload      JSON NOT NULL,
    error_class  VARCHAR(255) NOT NULL,
    error_msg    TEXT NOT NULL,
    stack_trace  TEXT NOT NULL,
    failed_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (queue, failed_at)
);

CREATE TABLE operations (                                 -- long-running operation tracking
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    uuid         BINARY(16) NOT NULL UNIQUE,              -- exposed in /api/v1/operations/{uuid}
    kind         VARCHAR(64) NOT NULL,                    -- 'sync', 'reindex', 'batch_tag', ...
    user_id      BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    status       ENUM('queued','running','completed','failed','cancelled') NOT NULL,
    progress     FLOAT NOT NULL DEFAULT 0,                -- 0.0–1.0
    message      TEXT,
    result       JSON,                                    -- populated on completion
    started_at   TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id, created_at),
    INDEX (status, created_at)
);
```

### A.10 Plugins + webhooks

```sql
CREATE TABLE plugins (
    name         VARCHAR(100) PRIMARY KEY,                -- "example/gallery-copyright"
    version      VARCHAR(32) NOT NULL,
    status       ENUM('active','inactive','error') NOT NULL DEFAULT 'inactive',
    capabilities JSON NOT NULL,                           -- from plugin.toml [capabilities]
    settings     JSON NOT NULL,                           -- per-plugin config
    installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    activated_at TIMESTAMP NULL
);

CREATE TABLE webhook_subscriptions (
    id         BIGINT AUTO_INCREMENT PRIMARY KEY,
    uuid       BINARY(16) NOT NULL UNIQUE,
    user_id    BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    event      VARCHAR(100) NOT NULL,                     -- event name pattern; '*' matches all
    target_url TEXT NOT NULL,
    secret     VARCHAR(128) NOT NULL,                     -- HMAC signing key
    is_active  BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (event, is_active)
);

CREATE TABLE webhook_deliveries (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    subscription_id BIGINT NOT NULL REFERENCES webhook_subscriptions(id) ON DELETE CASCADE,
    event           VARCHAR(100) NOT NULL,
    payload         JSON NOT NULL,
    response_status SMALLINT NULL,
    response_body   TEXT,
    attempt         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    delivered_at    TIMESTAMP NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (subscription_id, created_at)
);
```

### A.11 Settings + schema

```sql
CREATE TABLE settings (
    key_name   VARCHAR(100) PRIMARY KEY,
    value      JSON NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE schema_migrations (
    version    VARCHAR(255) PRIMARY KEY,                  -- "20260501120000"
    name       VARCHAR(255) NOT NULL,
    checksum   CHAR(64) NOT NULL,                         -- SHA-256 of the .sql file
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

### A.12 Saved searches + user quotas

```sql
CREATE TABLE saved_searches (
    id         BIGINT AUTO_INCREMENT PRIMARY KEY,
    uuid       BINARY(16) NOT NULL UNIQUE,
    user_id    BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name       VARCHAR(255) NOT NULL,
    rules      JSON NOT NULL,                             -- parsed SearchQuery
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id)
);

CREATE TABLE user_quotas (
    user_id         BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    storage_limit   BIGINT NOT NULL,                      -- bytes; 0 = unlimited
    storage_used    BIGINT NOT NULL DEFAULT 0,
    image_limit     INT NOT NULL,                         -- count; 0 = unlimited
    image_count     INT NOT NULL DEFAULT 0,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                           ON UPDATE CURRENT_TIMESTAMP
);
```

### Key design decisions

- **`BIGINT` surrogate PKs** on every table for internal relations. `AUTO_INCREMENT` on MySQL/MariaDB, `GENERATED ALWAYS AS IDENTITY` on Postgres.
- **UUIDv7 for externally-exposed IDs.** `BINARY(16)` on MySQL, native `UUID` on Postgres + MariaDB 10.7+. Time-ordered so B-tree-friendly. Used in derivative URLs, API responses, webhook payloads.
- **Foreign keys on every relationship.** Unlike Piwigo, which enforced referential integrity at the application layer, every relationship here has an FK with explicit `ON DELETE` behavior. Cascade on owned relationships (image_tags → image); SET NULL on non-owning (album parent_id, album cover_image_id).
- **Soft-delete only on users.** Audit log references past user IDs; hard-deleting a user would break history reads. Images, albums, comments, tags hard-delete; audit entries record the action.
- **JSON for EXIF, settings, ACL scopes, plugin config, webhook payloads.** Native `JSON` on MySQL + Postgres (`JSONB` for Postgres); `LONGTEXT`-with-check-constraint alias on MariaDB accessed via `JSON_VALUE(...)`.
- **Denormalized counts** (`albums.image_count`, `tags.image_count`) maintained via event listeners; reconciled nightly as a belt-and-suspenders check.
- **IPs as salted hashes.** `ip_hash BINARY(32)` preserves rate-limit / audit utility without making the DB a surveillance goldmine. Salt rotates weekly; old hashes stop resolving.
- **Email uniqueness is case-insensitive.** Generated `email_ci` column on MySQL/MariaDB; `CITEXT` on Postgres.
- **Adjacency-list + materialized path** for the album tree. `albums.parent_id` holds the adjacency; `albums.path` ("1/5/12") holds the path. `WHERE path LIKE '1/5/%'` descendant queries are O(log n) with a prefix index. Path rebuilt on move via single-statement UPDATE.
- **FTS:** MySQL uses `FULLTEXT` on the generated `search_fts` column; Postgres uses `tsvector GENERATED ALWAYS AS (...) STORED` + GIN index; MariaDB uses `FULLTEXT` like MySQL.
- **No `is_deleted` flag.** Where soft-delete applies, nullable `deleted_at` is both the flag and the "when".

### Permission resolution algorithm

"Can user U view album A?" resolves in order:

1. If `U.access_level >= Webmaster` → **allow**.
2. If `U.access_level < A.min_level` → **deny**.
3. If `A.is_public` AND no ancestor restricts access → **allow**.
4. Walk from A up to root:
   - At each level, check for an explicit `album_user_access(can_view=1)` row for U.
   - Or a matching row in `album_group_access(can_view=1)` for any group U belongs to.
   - If any level has an explicit permit → **allow**.
   - If any level has an explicit deny (`can_view=0`) → **deny** (short-circuit).
5. Default → **deny**.

Resolution is memoized per-user per-request; changes to permissions dispatch `PermissionChangedEvent`, invalidating the per-user entry in the `moka` cache. In-process cache replaces Piwigo's `user_cache` + `user_cache_categories` tables — those two tables **do not exist** in this schema.

### ERD summary

```
users ──┬── user_groups ────── groups
        │         │                │
        ├── api_tokens              ├── album_group_access ──┐
        ├── remember_tokens         │                         │
        ├── password_reset_tokens   │                         │
        ├── sessions                │                         │
        ├── album_user_access ──────┤                         ├── albums ──┬── image_albums ──┐
        ├── user_quotas             │                         │    │       │                  │
        ├── saved_searches          │                         │    │       │                  │
        ├── favorites               │                         │    └── path (materialized)    │
        ├── ratings                 │                         │                                │
        └── comments ─── images ────┴──── image_tags ─── tags ┘                                │
                         │   │                                                                  │
                         │   ├── exif (JSON)                                                   │
                         │   ├── image_formats (alt formats: CR2/DNG/HEIC)                     │
                         │   └── storage_path → storage/originals/{yyyy}/{mm}/{uuid}.{ext}     │
                         └──────────────────────────────────────────────────────────────────────┘

audit_log  settings  schema_migrations  background_jobs  failed_jobs  operations
plugins  webhook_subscriptions  webhook_deliveries
```

### Indexes summary

Hot-path indexes, per-table:

- `users`: `(access_level)`, `(deleted_at)`, unique `(username)`, unique `(email_ci)`.
- `sessions`: `(user_id)`, `(expires_at)`.
- `albums`: `(parent_id, rank)`, `(is_public, min_level)`, prefix `(path)`.
- `images`: `(author_id)`, `(taken_at)`, `(min_level, created_at)`, `(sha256)`, `(perceptual_hash)`, FULLTEXT `(search_fts)`.
- `image_albums`: `(album_id, rank)`.
- `image_tags`: `(tag_id)`.
- `comments`: `(image_id, status)`, `(status, created_at)`.
- `audit_log`: `(actor_id, created_at)`, `(event, created_at)`, `(target_type, target_id, created_at)`.
- `background_jobs`: `(queue, available_at)`, `(reserved_at)`.
- `operations`: `(user_id, created_at)`, `(status, created_at)`.

Every FK column is indexed. No Piwigo-style "no FK, rely on app code" — schema integrity is DB-enforced.


---

## Appendix B: Complete API Surface

`/api/v1/*` — REST, JSON in and out, OpenAPI-generated from source. No `pwg.*` RPC-style method names. Every endpoint has a `#[Route]` attribute that is the single source of truth for the schema.

**Auth:** session cookie (browsers) OR `Authorization: Bearer {api_token}` (clients). Admin/webmaster endpoints are gated by `#[RequiresLevel(AccessLevel::Admin)]` attribute.

**Versioning:** this is v1. Breaking changes move to `/api/v2/*`; v1 keeps working with a deprecation header for one major version cycle.

**Response envelope:** no `{stat: "ok", result: ...}` wrapping — bodies are the resource directly. Errors are RFC 7807 Problem Details (`application/problem+json`).

**Pagination:** cursor-based. `?cursor={opaque}&limit={n}` in, `{data: [...], next_cursor: "..."}` out. Last page omits `next_cursor`.

**Filtering / sorting:** query-string grammar matches the PHP plan's conventions: `?tags[in]=a,b&min_rating=3&sort=-taken_at&fields=id,title,derivatives`.

### B.1 Session + auth

| Method | Path | Level | Purpose |
|---|---|---|---|
| `POST` | `/api/v1/auth/login` | public | Username + password → session cookie + CSRF token |
| `POST` | `/api/v1/auth/logout` | any | Revoke session |
| `GET`  | `/api/v1/auth/me` | any | Current user profile + effective permissions |
| `POST` | `/api/v1/auth/password-reset/request` | public | Email a reset link |
| `POST` | `/api/v1/auth/password-reset/confirm` | public | Exchange token for new password |
| `GET`  | `/api/v1/auth/sessions` | any | List my active sessions |
| `DELETE` | `/api/v1/auth/sessions/{id}` | any | Revoke one of my sessions |
| `GET`  | `/api/v1/auth/tokens` | any | List my API tokens |
| `POST` | `/api/v1/auth/tokens` | any | Create API token with scopes |
| `DELETE` | `/api/v1/auth/tokens/{id}` | any | Revoke API token |

### B.2 Albums

| Method | Path | Level | Purpose |
|---|---|---|---|
| `GET`    | `/api/v1/albums` | any | List root albums visible to caller |
| `GET`    | `/api/v1/albums/{uuid}` | any | Album detail + cover + counts |
| `GET`    | `/api/v1/albums/{uuid}/children` | any | Direct children |
| `GET`    | `/api/v1/albums/{uuid}/descendants` | any | Full subtree (flat) |
| `GET`    | `/api/v1/albums/{uuid}/images` | any | Images in album (filtered, paginated) |
| `GET`    | `/api/v1/albums/{uuid}/images/ids` | any | Lightweight ID-only list for prev/next navigation |
| `POST`   | `/api/v1/albums` | admin | Create album |
| `PATCH`  | `/api/v1/albums/{uuid}` | admin | Update fields |
| `DELETE` | `/api/v1/albums/{uuid}?photo_action=keep\|delete_orphans\|cascade` | admin | Delete with explicit image-handling mode |
| `POST`   | `/api/v1/albums/{uuid}/move` | admin | Reparent (body: `{new_parent_uuid, rank}`) |
| `POST`   | `/api/v1/albums/{uuid}/cover` | admin | Set cover image (body: `{image_uuid}`) |
| `POST`   | `/api/v1/albums/{uuid}/rank` | admin | Reorder images within album |
| `GET`    | `/api/v1/albums/{uuid}/permissions` | admin | ACL listing |
| `PUT`    | `/api/v1/albums/{uuid}/permissions` | admin | Replace ACL (users + groups, with inherit-to-descendants flag) |
| `GET`    | `/api/v1/albums/orphans` | admin | Images not in any album |

### B.3 Photos (images)

| Method | Path | Level | Purpose |
|---|---|---|---|
| `GET`    | `/api/v1/photos` | any | List with filters (`?album=`, `?tag=`, `?author=`, `?rating=`, `?taken_after=`, `?sort=-taken_at`) |
| `GET`    | `/api/v1/photos/{uuid}` | any | Full detail: EXIF, tags, albums, derivative URLs, comment count |
| `POST`   | `/api/v1/photos` | any (quota'd) | Single-shot multipart upload |
| `POST`   | `/api/v1/uploads` | any (quota'd) | Start a resumable tus upload, returns `Location: /api/v1/uploads/{id}` |
| `PATCH`  | `/api/v1/uploads/{id}` | any | tus chunk PATCH |
| `HEAD`   | `/api/v1/uploads/{id}` | any | tus offset/status |
| `DELETE` | `/api/v1/uploads/{id}` | any | Abort upload (quarantine cleanup) |
| `PATCH`  | `/api/v1/photos/{uuid}` | any (author/admin) | Update metadata, tags, album assignments |
| `DELETE` | `/api/v1/photos/{uuid}` | admin | Hard delete |
| `POST`   | `/api/v1/photos/{uuid}/regenerate-derivatives` | admin | Queue regeneration for all or specified presets |
| `POST`   | `/api/v1/photos/{uuid}/sync-metadata` | admin | Re-extract EXIF from file |
| `POST`   | `/api/v1/photos/actions/batch` | admin | Body: `{photo_uuids: [...], operations: [{tag_add: [...]}, {move_album: ...}, {set_min_level: ...}, ...]}`. Returns `202` + `Operation` pointer. |
| `POST`   | `/api/v1/photos/lookup-duplicates` | any | Body: `{sha256s: [...]}` — returns existing matches |
| `GET`    | `/api/v1/photos/{uuid}/comments` | any (ACL-filtered) | Approved comments |
| `POST`   | `/api/v1/photos/{uuid}/comments` | any | Submit (goes to moderation unless admin) |
| `POST`   | `/api/v1/photos/{uuid}/rate` | any | Body: `{rating: 1..5}` |
| `DELETE` | `/api/v1/photos/{uuid}/rate` | any | Remove own rating |
| `POST`   | `/api/v1/photos/{uuid}/favorite` | authenticated | Toggle favorite |

Media delivery: **not** under `/api/v1`. Served at `/media/{preset}/{uuid}.{ext}` — see Appendix F.

### B.4 Tags

| Method | Path | Level | Purpose |
|---|---|---|---|
| `GET`    | `/api/v1/tags` | any | List with counts; `?sort=name\|count` |
| `GET`    | `/api/v1/tags/{slug}` | any | Tag detail |
| `GET`    | `/api/v1/tags/{slug}/images` | any | Images tagged (permission-filtered) |
| `POST`   | `/api/v1/tags` | admin | Create |
| `PATCH`  | `/api/v1/tags/{slug}` | admin | Rename (slug rewrites preserved in `old_permalinks`-equivalent) |
| `DELETE` | `/api/v1/tags/{slug}` | admin | Delete |
| `POST`   | `/api/v1/tags/merge` | admin | Body: `{destination: slug, sources: [slug, ...]}` |

### B.5 Search

| Method | Path | Level | Purpose |
|---|---|---|---|
| `GET`    | `/api/v1/search` | any | Free-text + scope query (`?q=...&tags=a,b&date_after=...&sort=-relevance`) |
| `GET`    | `/api/v1/search/suggest` | any | Autocomplete (tags, albums, users) |
| `GET`    | `/api/v1/search/saved` | authenticated | My saved searches |
| `POST`   | `/api/v1/search/saved` | authenticated | Save search |
| `DELETE` | `/api/v1/search/saved/{uuid}` | authenticated | Delete saved search |

### B.6 Users + groups

| Method | Path | Level | Purpose |
|---|---|---|---|
| `GET`    | `/api/v1/users` | admin | Paginated list |
| `GET`    | `/api/v1/users/{uuid}` | admin | Detail |
| `POST`   | `/api/v1/users` | admin | Create |
| `PATCH`  | `/api/v1/users/{uuid}` | admin | Update (incl. level, groups) |
| `DELETE` | `/api/v1/users/{uuid}` | admin | Soft-delete |
| `POST`   | `/api/v1/users/{uuid}/force-logout` | admin | Revoke all sessions |
| `GET`    | `/api/v1/groups` | admin | List |
| `POST`   | `/api/v1/groups` | admin | Create |
| `PATCH`  | `/api/v1/groups/{uuid}` | admin | Update |
| `DELETE` | `/api/v1/groups/{uuid}` | admin | Delete |
| `GET`    | `/api/v1/groups/{uuid}/members` | admin | List members |
| `PUT`    | `/api/v1/groups/{uuid}/members` | admin | Replace membership |
| `POST`   | `/api/v1/groups/merge` | admin | Body: `{destination, sources: [...]}` |

### B.7 Comments + moderation

| Method | Path | Level | Purpose |
|---|---|---|---|
| `GET`    | `/api/v1/comments` | admin | Moderation queue (`?status=pending\|approved\|rejected`) |
| `POST`   | `/api/v1/comments/{id}/approve` | admin | Approve |
| `POST`   | `/api/v1/comments/{id}/reject` | admin | Reject |
| `DELETE` | `/api/v1/comments/{id}` | admin | Delete |

### B.8 Operations (long-running tasks)

| Method | Path | Level | Purpose |
|---|---|---|---|
| `GET`    | `/api/v1/operations/{uuid}` | any (owner or admin) | Poll status |
| `GET`    | `/api/v1/operations/{uuid}/events` | same | SSE stream of progress events |
| `POST`   | `/api/v1/operations/{uuid}/cancel` | same | Request cancel |
| `GET`    | `/api/v1/operations` | admin | List recent operations |

Operations returned by: batch photo actions, sync, reindex, export, long derivative regenerations.

### B.9 Sync (filesystem ingest)

| Method | Path | Level | Purpose |
|---|---|---|---|
| `POST`   | `/api/v1/sync/start` | admin | Body: `{root: "...", mode: "files"\|"dirs", simulate: bool, metadata: bool}`. Returns `202` + operation UUID. |
| `GET`    | `/api/v1/sync/status` | admin | Current sync job (if any) |
| `POST`   | `/api/v1/sync/cancel` | admin | Cancel in-progress sync |

### B.10 Admin (maintenance + diagnostics)

| Method | Path | Level | Purpose |
|---|---|---|---|
| `GET`    | `/api/v1/admin/stats` | admin | Dashboard counts |
| `GET`    | `/api/v1/admin/storage` | admin | Originals/derivatives disk usage |
| `GET`    | `/api/v1/admin/queues` | admin | Per-queue depth + oldest age + DLQ count |
| `GET`    | `/api/v1/admin/queues/{name}/failed` | admin | DLQ viewer |
| `POST`   | `/api/v1/admin/queues/{name}/failed/{id}/retry` | admin | Retry failed job |
| `DELETE` | `/api/v1/admin/queues/{name}/failed/{id}` | admin | Drop failed job |
| `GET`    | `/api/v1/admin/audit` | admin | Audit log (filtered) |
| `GET`    | `/api/v1/admin/audit/export.csv` | admin | CSV export |
| `POST`   | `/api/v1/admin/cache/clear` | admin | Body: `{scopes: ["response","permissions","templates","derivatives"]}` |
| `POST`   | `/api/v1/admin/maintenance/prune-orphan-derivatives` | admin | Triggers cleanup job |
| `POST`   | `/api/v1/admin/maintenance/recompute-counters` | admin | Rebuild denormalized counts |

### B.11 Settings + plugins + themes

| Method | Path | Level | Purpose |
|---|---|---|---|
| `GET`    | `/api/v1/settings` | admin | All settings (grouped) |
| `PATCH`  | `/api/v1/settings` | admin | Update subset |
| `GET`    | `/api/v1/plugins` | admin | Installed plugins |
| `POST`   | `/api/v1/plugins/{name}/activate` | admin | Enable |
| `POST`   | `/api/v1/plugins/{name}/deactivate` | admin | Disable |
| `DELETE` | `/api/v1/plugins/{name}` | admin | Uninstall (with `?drop_data=bool`) |
| `GET`    | `/api/v1/themes` | admin | Installed themes |
| `POST`   | `/api/v1/themes/{name}/activate` | admin | Set default |

### B.12 Webhooks

| Method | Path | Level | Purpose |
|---|---|---|---|
| `GET`    | `/api/v1/webhooks` | any | My subscriptions |
| `POST`   | `/api/v1/webhooks` | any | Create (body: `{event, target_url}`, server generates signing secret) |
| `PATCH`  | `/api/v1/webhooks/{uuid}` | any | Update |
| `DELETE` | `/api/v1/webhooks/{uuid}` | any | Delete |
| `GET`    | `/api/v1/webhooks/{uuid}/deliveries` | any | Delivery history |
| `POST`   | `/api/v1/webhooks/{uuid}/deliveries/{id}/retry` | any | Manual retry |

### B.13 Feeds + public reads

| Method | Path | Level | Purpose |
|---|---|---|---|
| `GET` | `/feed.atom?scope=...&token=...` | public (ACL-filtered) | Atom feed (latest, per-album, per-user with signed token) |
| `GET` | `/feed.rss?scope=...&token=...` | same | RSS alternative |
| `GET` | `/sitemap.xml` | public | Public URLs only |
| `GET` | `/.well-known/security.txt` | public | Security contact |

### B.14 Health + version

| Method | Path | Level | Purpose |
|---|---|---|---|
| `GET` | `/healthz` | public | 200 for liveness |
| `GET` | `/readyz` | public | 200 only if DB + storage + Redis reachable |
| `GET` | `/version` | public | `{version, git_sha, build_date}` |
| `GET` | `/metrics` | IP-allowlisted | Prometheus format |

### Authentication gates

Every endpoint declares its access level via `#[RequiresLevel]` attribute:

- **public** — no auth required
- **any** — any authenticated user (including guest if guest access enabled)
- **authenticated** — non-guest user
- **admin** — `AccessLevel::Administrator` or above
- **webmaster** — `AccessLevel::Webmaster` only

Plus ACL gates on per-resource endpoints (`/api/v1/albums/{uuid}/images` returns only what the caller can view, regardless of their level).

---

## Appendix C: Configuration reference

Greenfield configuration tree. All keys are TOML dotted paths — e.g. `mail.smtp.host` is `[mail.smtp] host = "..."` in the file. Loading order (last wins): code defaults (via `Default` + `#[serde(default)]`) → `local/config/config.toml` → `settings` DB table.

The DB `settings` table takes precedence over file config, but only for keys the admin UI exposes; infrastructure keys (DB DSN, secret key, storage paths) are file-only and immutable at runtime.

### `[server]`

| Key | Default | Type | Description |
|---|---|---|---|
| `server.bind` | `"0.0.0.0:8080"` | string | HTTP listen address |
| `server.trusted_proxies` | `[]` | array<CIDR> | Trusted X-Forwarded-For sources |
| `server.secret_key` | file-only, required | string (≥32 bytes) | CSRF + HMAC + cookie-signing key |
| `server.public_url` | auto-detected | string | Canonical URL (HTTPS://example.com) |
| `server.maintenance_mode` | `false` | bool | Serve 503 to non-admins |

### `[database]`

| Key | Default | Type | Description |
|---|---|---|---|
| `database.url` | required | string | `postgres://...` / `mysql://...` |
| `database.max_connections` | `10` | int | Pool size |
| `database.min_connections` | `2` | int | Idle connections kept warm |
| `database.acquire_timeout_ms` | `5000` | int | Pool-acquire timeout |
| `database.idle_timeout_ms` | `600000` | int | 10 minutes |
| `database.max_lifetime_ms` | `1800000` | int | 30 minutes |

### `[storage]`

| Key | Default | Type | Description |
|---|---|---|---|
| `storage.root` | `"storage/"` | path | Persistent data root — back this up |
| `storage.var_root` | `"var/"` | path | Regenerable runtime root — wipe at will |
| `storage.originals_layout` | `"date"` | enum | `"date"` (yyyy/mm) or `"cas"` (SHA-sharded) |
| `storage.backend` | `"local"` | enum | `"local"` / `"s3"` |
| `storage.s3.endpoint` | — | string | For S3-compatible stores |
| `storage.s3.bucket` | — | string | |
| `storage.s3.access_key` | — | string | |
| `storage.s3.secret_key` | — | string | Encrypted at rest via `server.secret_key` |

### `[gallery]`

| Key | Default | Type | Description |
|---|---|---|---|
| `gallery.title` | `"Gallery"` | string | Displayed site title |
| `gallery.banner_html` | `""` | string | Banner HTML; `{title}` substituted |
| `gallery.show_version_footer` | `false` | bool | Show server version in footer |
| `gallery.thumbnail_captions` | `true` | bool | Captions under grid thumbnails |
| `gallery.level_separator` | `" / "` | string | Breadcrumb separator |
| `gallery.pagination_pages_around` | `2` | int | Pages shown around current |
| `gallery.root_redirect` | `none` | enum | `"none"` / `"random_album"` |
| `gallery.default_theme` | `"default"` | string | Must be an active theme slug |

### `[albums]`

| Key | Default | Type | Description |
|---|---|---|---|
| `albums.new.commentable` | `true` | bool | New albums commentable by default |
| `albums.new.visible` | `true` | bool | New albums visible by default |
| `albums.new.status` | `"public"` | enum | `"public"` / `"private"` |
| `albums.new.position` | `"first"` | enum | `"first"` / `"last"` |
| `albums.new.inherit_permissions` | `false` | bool | Copy parent's ACL rows |
| `albums.per_page` | `12` | int | Sub-albums per page |
| `albums.random_cover_on_reload` | `false` | bool | Re-pick cover each page load |

### `[photos]`

| Key | Default | Type | Description |
|---|---|---|---|
| `photos.default_sort` | `"-taken_at"` | string | Whitelisted sort key |
| `photos.allowed_mimes` | `["image/jpeg","image/png","image/gif","image/webp","image/avif"]` | array | MIME allowlist for uploads |
| `photos.alt_format_mimes` | `["image/x-canon-cr2","image/x-nikon-nef","image/x-adobe-dng","image/heic","image/tiff"]` | array | Alternative-format allowlist |
| `photos.duplicate_detection` | `"sha256"` | enum | `"sha256"` / `"phash"` / `"both"` |
| `photos.privacy_levels` | `[0,1,2,4,8]` | array<int> | Allowed min_level values |

### `[derivatives]`

| Key | Default | Type | Description |
|---|---|---|---|
| `derivatives.presets` | see §5.2 table | map | preset → `{max_width, max_height, crop, quality}` |
| `derivatives.default_preset` | `"medium"` | string | Default size when none specified |
| `derivatives.strip_metadata_below_pixels` | `256000` | int | Drop EXIF below this pixel count |
| `derivatives.animated_webp_quality` | `70` | int | Animated WebP quality cap |
| `derivatives.original_resize.enabled` | `false` | bool | Resize originals on upload |
| `derivatives.original_resize.max_width` | `2016` | int | |
| `derivatives.original_resize.max_height` | `2016` | int | |
| `derivatives.original_resize.quality` | `95` | int | |
| `derivatives.formats.preference` | `["avif","jxl","webp","jpeg"]` | array | Format negotiation order |

### `[watermark]`

| Key | Default | Type | Description |
|---|---|---|---|
| `watermark.enabled` | `false` | bool | Apply watermark to derivatives |
| `watermark.file` | `"storage/watermark.png"` | path | Watermark image |
| `watermark.min_output_pixels` | `100000` | int | Skip watermark below this |
| `watermark.x_percent` | `50` | int | Horizontal position 0..100 |
| `watermark.y_percent` | `50` | int | Vertical position 0..100 |
| `watermark.x_repeat` | `0` | int | Tile count (0 = single) |
| `watermark.opacity_percent` | `50` | int | Opacity 0..100 |

### `[uploads]`

| Key | Default | Type | Description |
|---|---|---|---|
| `uploads.max_file_size_mb` | `1000` | int | Per-file cap |
| `uploads.tus.chunk_size_kb` | `500` | int | Preferred chunk size |
| `uploads.tus.staging_dir` | `"var/uploads/tus/"` | path | tus staging |
| `uploads.tus.abandoned_ttl_hours` | `24` | int | Cleanup stale uploads |
| `uploads.auto_rotate_exif` | `true` | bool | Apply EXIF orientation on ingest |
| `uploads.strip_gps_on_upload` | `false` | bool | Privacy: drop GPS EXIF |

### `[sync]`

| Key | Default | Type | Description |
|---|---|---|---|
| `sync.enabled` | `true` | bool | Enable filesystem sync feature |
| `sync.galleries_root` | `"storage/galleries/"` | path | Ingest source tree |
| `sync.exclude_patterns` | `[".git","node_modules",".DS_Store","_data"]` | array | Skip these during walk |
| `sync.profiling` | `false` | bool | Per-file timing + percentiles |
| `sync.mft.enabled_windows` | `false` | bool | Use NTFS MFT reader on Windows (requires admin) |
| `sync.checksum_batch_size` | `50` | int | Files per SHA batch |

### `[metadata]`

| Key | Default | Type | Description |
|---|---|---|---|
| `metadata.exif.show` | `true` | bool | Display EXIF on picture page |
| `metadata.exif.fields` | `["Make","Model","DateTimeOriginal","FNumber","ExposureTime","ISO","FocalLength"]` | array | EXIF fields to display |
| `metadata.exif.use_during_sync` | `true` | bool | Extract during sync |
| `metadata.exif.mapping` | `{date_creation = "DateTimeOriginal"}` | map | EXIF tag → DB column |
| `metadata.iptc.show` | `false` | bool | Display IPTC |
| `metadata.iptc.use_during_sync` | `false` | bool | Extract during sync |
| `metadata.iptc.mapping` | `{title = "ObjectName", description = "Caption-Abstract"}` | map | IPTC tag → DB column |

### `[comments]`

| Key | Default | Type | Description |
|---|---|---|---|
| `comments.enabled` | `true` | bool | Feature toggle |
| `comments.anti_flood_seconds` | `60` | int | Min gap between same-IP posts |
| `comments.spam_max_links` | `3` | int | URLs threshold for spam rejection |
| `comments.require_moderation` | `false` | bool | Default-pending vs default-approved |
| `comments.allow_anonymous` | `false` | bool | Non-authenticated commenting |
| `comments.honeypot_enabled` | `true` | bool | Honeypot website-URL field |
| `comments.user_can_delete_own` | `false` | bool | |
| `comments.user_can_edit_own` | `false` | bool | |

### `[auth]`

| Key | Default | Type | Description |
|---|---|---|---|
| `auth.guest_enabled` | `true` | bool | Allow anonymous browsing |
| `auth.self_registration` | `false` | bool | Open signup |
| `auth.username_case_insensitive` | `false` | bool | |
| `auth.password.hasher` | `"argon2id"` | enum | `"argon2id"` / `"bcrypt"` (bcrypt for compat only) |
| `auth.password.argon2.memory_kb` | `19456` | int | Argon2id m parameter |
| `auth.password.argon2.iterations` | `2` | int | Argon2id t parameter |
| `auth.password.argon2.parallelism` | `1` | int | Argon2id p parameter |
| `auth.password.min_length` | `8` | int | |
| `auth.password.reset_token_ttl_minutes` | `60` | int | |
| `auth.login_rate_limit_per_minute` | `10` | int | Per-IP |

### `[sessions]`

| Key | Default | Type | Description |
|---|---|---|---|
| `sessions.cookie_name` | `"gallery_id"` | string | |
| `sessions.store` | `"database"` | enum | `"database"` / `"redis"` |
| `sessions.lifetime_seconds` | `3600` | int | Idle timeout |
| `sessions.bind_to_ip_class` | `true` | bool | Validate /24 match |
| `sessions.remember_me.enabled` | `true` | bool | |
| `sessions.remember_me.lifetime_seconds` | `5184000` | int | 60 days |
| `sessions.api_tokens.default_ttl_days` | `90` | int | API token default expiry |

### `[mail]`

| Key | Default | Type | Description |
|---|---|---|---|
| `mail.smtp.host` | — | string | `host:port` or `host` (empty = disabled) |
| `mail.smtp.user` | `""` | string | |
| `mail.smtp.password` | `""` | string | Encrypted at rest via `server.secret_key` |
| `mail.smtp.secure` | `"tls"` | enum | `"ssl"` (implicit) / `"tls"` (STARTTLS) / `"none"` |
| `mail.sender.name` | `{gallery.title}` | string | From display name |
| `mail.sender.email` | required | string | From address |
| `mail.template_theme` | `"clear"` | string | `"clear"` / `"dark"` / custom |
| `mail.allow_html` | `true` | bool | Send HTML multipart |
| `mail.debug_dump_eml` | `false` | bool | Also write to `var/tmp/mail_{ts}.eml` |

### `[ratings]`

| Key | Default | Type | Description |
|---|---|---|---|
| `ratings.enabled` | `true` | bool | |
| `ratings.allow_anonymous` | `true` | bool | |
| `ratings.allowed_values` | `[1,2,3,4,5]` | array | |
| `ratings.bayesian_confidence` | `2` | int | Prior strength |

### `[search]`

| Key | Default | Type | Description |
|---|---|---|---|
| `search.engine` | `"native"` | enum | `"native"` (DB FTS) / `"meilisearch"` / `"tantivy"` |
| `search.meilisearch.url` | — | string | When engine = meilisearch |
| `search.meilisearch.api_key` | — | string | |
| `search.semantic.enabled` | `false` | bool | CLIP-based semantic search (§21.2.4) |

### `[api]`

| Key | Default | Type | Description |
|---|---|---|---|
| `api.max_photos_per_page` | `500` | int | Cap on `?limit` |
| `api.max_users_per_page` | `1000` | int | |
| `api.rate_limits.read_per_minute` | `600` | int | Per-user read budget |
| `api.rate_limits.write_per_minute` | `60` | int | Per-user write budget |
| `api.rate_limits.auth_per_minute` | `10` | int | Per-IP login budget |
| `api.cors.allowed_origins` | `[]` | array | Explicit list for credentialed routes |

### `[admin]`

| Key | Default | Type | Description |
|---|---|---|---|
| `admin.batch_manager.photos_per_page_global` | `60` | int | Virtualized grid loads N at a time |
| `admin.batch_manager.photos_per_page_unit` | `1` | int | Unit-mode fetch |
| `admin.audit.retention_days` | `365` | int | Audit log TTL |

### `[performance]`

| Key | Default | Type | Description |
|---|---|---|---|
| `performance.permission_cache_ttl_seconds` | `300` | int | In-process moka TTL |
| `performance.response_cache.enabled` | `true` | bool | Anon page caching |
| `performance.response_cache.ttl_seconds` | `300` | int | |
| `performance.asset_bundling` | `true` | bool | Combined CSS/JS in prod |
| `performance.template_reload` | `false` | bool | Dev-only template hot-reload |

### `[observability]`

| Key | Default | Type | Description |
|---|---|---|---|
| `observability.log_level` | `"info"` | enum | Standard tracing levels |
| `observability.log_format` | `"json"` | enum | `"json"` / `"text"` |
| `observability.log_sql_queries` | `false` | bool | Debug-only |
| `observability.otlp.endpoint` | — | string | OpenTelemetry collector URL |
| `observability.prometheus.enabled` | `true` | bool | `/metrics` endpoint |
| `observability.prometheus.allowed_ips` | `["127.0.0.1/32"]` | array | IP allowlist |
| `observability.sentry.dsn` | — | string | Optional |

### `[privacy]`

| Key | Default | Type | Description |
|---|---|---|---|
| `privacy.retain_audit_days` | `365` | int | |
| `privacy.retain_comment_ip_days` | `90` | int | After window, `ip_hash` → NULL |
| `privacy.allow_data_export` | `true` | bool | User GDPR export via API |
| `privacy.allow_account_deletion` | `true` | bool | User-initiated erasure |

### Dropped vs Piwigo

Keys from Piwigo 14's `Config.php` that are **not carried over** (rationale):

- `gallery_url`: auto-detected from `Host` header + trusted-proxy chain. No explicit override.
- `question_mark_in_urls`, `php_extension_in_urls`, `category_url_style`, `picture_url_style`, `tag_url_style`: one canonical URL shape per resource (see Appendix F); no per-install URL-style choice.
- `session_use_ip_address`: replaced by `sessions.bind_to_ip_class` (same intent, name clarified).
- `newcat_default_*`: namespaced under `albums.new.*`.
- `upload_dir`: uploads always land under `storage.root/originals/`; staging under `var/uploads/tus/`. Paths are derived, not configured individually.
- `graphics_library`: only libvips is supported. No GD/Imagick/ext_imagick fallback.
- `everything_dll_path`: Windows MFT reader uses `windows-rs` bindings, no DLL path.
- `guest_id`, `webmaster_id`: not needed — guest is a sentinel row with access_level = Guest, not an ID-referenced user.
- `show_queries`, `log_sql_queries`: unified under `observability.log_sql_queries`.
- `ws_max_images_per_page` → `api.max_photos_per_page`.

---

## Appendix D: Event Catalog (Piwigo ↔ Rust cross-reference)

**Total: ~144 unique events** — covered by the `GalleryEvent` enum in plan §11.1.

**Purpose of this appendix:** Piwigo plugin authors migrating to Lua need to know which Rust event corresponds to the PHP hook they were subscribing to. Each row maps the old PHP hook name to the equivalent `GalleryEvent` variant that fires at the analogous point in the Rust handler.

**Not all Piwigo hooks carry over.** Some were artifacts of PHP-specific concerns (e.g., `functions_mail_included` — a marker that a PHP require succeeded) and have no Rust counterpart. Others are consolidated when the Rust design collapses multiple PHP fire-points into one event. Conversely, the Rust `GalleryEvent` enum adds events for operations the PHP version never exposed (e.g., `OperationStartedEvent` for long-running tasks, `DerivativeGenerationQueuedEvent` for the messenger pipeline).

**Rust naming convention:** the PHP `snake_case` names become `PascalCase` enum variants under `GalleryEvent::` (e.g., `loc_begin_index` → `GalleryEvent::LocBeginIndex`; `render_tag_name` → `GalleryEvent::RenderTagName`). The full list is in `gallery-plugins/src/events.rs`.

**"Change" vs "notify":** preserved from Piwigo semantics. `trigger_notify` becomes `EventBus::trigger_notify(GalleryEvent::X, ...)` (return value ignored, all handlers fire); `trigger_change` becomes `EventBus::trigger_change(GalleryEvent::X, data)` (handlers form a transforming pipeline, each receives the previous's output).

**Prior art:** grep of `trigger_notify()` and `trigger_change()` call sites in Piwigo 14.x.

### D.1 Lifecycle / Initialization (7 events)

| Event | Type | Location | Data |
|---|---|---|---|
| `init` | notify | `common.php:296` | (none) |
| `loading_lang` | notify | `common.php:192` + 3 others | (none) |
| `load_conf` | notify | `functions.php:1471` | `$condition` (SQL WHERE) |
| `plugins_loaded` | notify | `functions_plugins.php:366` | (none) |
| `user_init` | notify | `user.php:80` | `$user` array |
| `functions_mail_included` | notify | `functions_mail.php:957` | (none) |
| `functions_history_included` | notify | `functions_history.php:452` | (none) |

### D.2 Authentication (10 events)

| Event | Type | Location | Data |
|---|---|---|---|
| `try_log_user` | **change** | `functions_user.php:1090` | `false`, `$username`, `$password`, `$remember_me` |
| `register_user_check` | **change** | `functions_user.php:181` | `$errors` array, registration data |
| `register_user` | notify | `functions_user.php:292` | `['id', 'username', 'email']` |
| `user_login` | notify | `functions_user.php:1017` | `$user_id` |
| `login_success` | notify | `functions_user.php:1043,1165,1502` | `$username` |
| `login_failure` | notify | `functions_user.php:1170` | `$username` |
| `user_logout` | notify | `functions_user.php:1181` | `$_SESSION['pwg_uid']` |
| `delete_user` | notify | `functions_admin.php:432` | `$user_id` |
| `save_profile_from_post` | notify | `functions.php:3467` | `$userdata['id']` |
| `load_profile_in_template` | notify | `functions.php:3537` | `$userdata` |

### D.3 Page Lifecycle — loc_begin/loc_end (27 events)

| Event | Type | Location |
|---|---|---|
| `loc_begin_page_header` | notify | `page_header.php:23` |
| `loc_end_page_header` | notify | `page_header.php:105` |
| `loc_after_page_header` | notify | `page_header.php:110` |
| `loc_begin_page_tail` | notify | `page_tail.php:22` |
| `loc_end_page_tail` | notify | `page_tail.php:103` |
| `loc_begin_index` / `loc_end_index` | notify | `index.php` |
| `loc_begin_picture` / `loc_end_picture` | notify | `picture.php` |
| `loc_begin_identification` / `loc_end_identification` | notify | `identification.php` |
| `loc_begin_register` / `loc_end_register` | notify | `register.php` |
| `loc_begin_password` / `loc_end_password` | notify | `password.php` |
| `loc_begin_profile` / `loc_end_profile` | notify | `profile.php` |
| `loc_begin_search` | notify | `search.php` |
| `loc_begin_tags` / `loc_end_tags` | notify | `tags.php` |
| `loc_begin_comments` / `loc_end_comments` | notify | `comments.php` |
| `loc_begin_about` | notify | `about.php` |
| `loc_begin_notification` / `loc_end_notification` | notify | `notification.php` |
| `loc_end_section_init` | notify | `section_init.php:696` |
| `loc_begin_admin` / `loc_end_admin` | notify | `admin.php` |
| `loc_begin_admin_page` | notify | `admin.php:318` |

### D.4 Data Modification (15 events)

| Event | Type | Location | Data |
|---|---|---|---|
| `create_virtual_category` | notify | `functions_admin.php:1655` | `['id' => ...]` |
| `delete_categories` | notify | `functions_admin.php:175` | `$ids` |
| `begin_delete_elements` | notify | `functions_admin.php:298` | `$ids` |
| `delete_elements` | notify | `functions_admin.php:378` | `$ids` |
| `delete_tags` | notify | `functions_admin.php:1777` | `$tag_ids` |
| `delete_group` | notify | `functions_admin.php:2913` | `$groupids` |
| `empty_lounge` | notify | `functions_admin.php:2246` | `$rows` |
| `invalidate_user_cache` | notify | `functions_admin.php:2516` | `$full` (bool) |
| `element_set_global_action` | notify | `batch_manager_global.php:393` | `$action`, `$collection` |
| `picture_modify_before_update` | **change** | `picture_modify.php:126` | `$data` array |
| `ws_images_uploadCompleted` | notify | `pwg_images.php:2534` | upload info |
| `loc_end_add_uploaded_file` | notify | `functions_upload.php:383` | `$image_infos` |
| `loc_end_add_format` | notify | `functions_upload.php:488` | `$format_infos` |
| `upload_file` | **change** | `functions_upload.php:272` | `null`, `$file_path` |

### D.5 Rendering Filters (16 events)

| Event | Type | Data |
|---|---|---|
| `render_category_name` | **change** | `$name` string |
| `render_category_description` | **change** | `$comment` string |
| `render_category_literal_description` | **change** | `$desc` string |
| `render_element_name` | **change** | `$name`, `$info` |
| `render_element_description` | **change** | `$comment`, `$param` |
| `render_element_content` | **change** | `''`, `$picture['current']` |
| `render_comment_content` | **change** | `$content` string |
| `render_comment_author` | **change** | `$author` string |
| `render_tag_name` | **change** | `$name`, `$row` |
| `render_tag_url` | **change** | `$tag_name` → URL slug |
| `render_page_banner` | **change** | `$banner_html` |
| `render_lost_password_mail_content` | **change** | `$message` |
| `get_thumbnail_title` | **change** | `$title`, `$info` |
| `get_tag_alt_names` | **change** | `[]`, `$raw_name` |
| `get_tag_name_like_where` | **change** | `[]`, `$tag_name` |

### D.6 Search (6 events)

| Event | Type | Data |
|---|---|---|
| `qsearch_pre` | **change** | `$q` query string |
| `qsearch_get_scopes` | **change** | `$scopes` array |
| `qsearch_expression_parsed` | notify | `$expression` |
| `qsearch_before_eval` | notify | `$expression`, `$qsr` |
| `qsearch_get_images_sql_scopes` | **change** | `$clauses`, `$token`, `$expr` |
| `qsearch_results` | **change** | `$search_results`, `$expression`, `$qsr` |

### D.7 Index / Thumbnails (10 events)

| Event | Type | Data |
|---|---|---|
| `loc_begin_index_thumbnails` | notify | `$pictures` |
| `loc_index_thumbnails_selection` | **change** | `$selection` (image IDs) |
| `loc_end_index_thumbnails` | **change** | `$tpl_thumbnails_var`, `$pictures` |
| `get_index_derivative_params` | **change** | DerivativeParams |
| `loc_begin_index_category_thumbnails_query` | **change** | `$query` SQL |
| `loc_begin_index_category_thumbnails` | notify | `$categories` |
| `loc_end_index_category_thumbnails` | **change** | `$tpl_var` |
| `get_index_album_derivative_params` | **change** | DerivativeParams |
| `get_categories_menu_sql_where` | **change** | `$where` SQL |
| `get_category_preferred_image_orders` | **change** | `$orders` array |

### D.8 Picture Page (3 events)

| Event | Type | Data |
|---|---|---|
| `allow_increment_element_hit_count` | **change** | `$inc_hit_count` bool |
| `get_element_metadata_available` | **change** | `$showable` bool |
| `picture_pictures_data` | **change** | `$picture` full data |

### D.9 Comments (4 events)

| Event | Type | Data |
|---|---|---|
| `user_comment_check` | **change** | `$comment_action`, `$comm` |
| `user_comment_insertion` | notify | `$comm + action` |
| `user_comment_deletion` | notify | `$comment_id` |
| `user_comment_validation` | notify | `$comment_id` |

### D.10 API (5 events)

| Event | Type | Data |
|---|---|---|
| `ws_add_methods` | notify | `[&$server]` PwgServer ref |
| `ws_invoke_allowed` | **change** | `true`, `$methodName`, `$params` |
| `sendResponse` | notify | `$encodedResponse` |
| `get_history` | **change** | `[]`, `$search`, `$types` |
| `ws_users_getList` | **change** | `$users` array |

### D.11 Image/Derivative URLs (4 events)

| Event | Type | Data |
|---|---|---|
| `get_derivative_url` | **change** | `$url`, `$params`, `$src_image`, `$rel_url` |
| `get_src_image_url` | **change** | `$url`, `$this` SrcImage |
| `get_element_url` | **change** | (handler-dependent) |
| `get_mimetype_location` | **change** | `$path`, `$ext` |

### D.12 Template / Assets (5 events)

| Event | Type | Data |
|---|---|---|
| `combinable_preparse` | notify | `$template`, `$combinable`, `$this` |
| `combined_css` | **change** | `$href` URL |
| `combined_css_postfilter` | **change** | `$css` string |
| `combined_script` | **change** | `$ret` URL |
| `tabsheet_before_select` | **change** | `$sheets`, `$uniqid` |

### D.13 Block Manager (3 events)

| Event | Type | Data |
|---|---|---|
| `blockmanager_register_blocks` | notify | `[$this]` BlockManager |
| `blockmanager_prepare_display` | notify | `[$this]` BlockManager |
| `blockmanager_apply` | notify | `[$this]` BlockManager |

### D.14 Mail (4 events)

| Event | Type | Data |
|---|---|---|
| `before_send_mail` | **change** | `true`, `$to`, `$args`, `$mail` PHPMailer |
| `before_parse_mail_template` | notify | `$cache_key`, `$content_type` |
| `nbm_render_global_customize_mail_content` | **change** | `$content` |
| `nbm_render_user_customize_mail_content` | **change** | `$content`, `$user` |

### D.15 Themes (5 events)

| Event | Type | Data |
|---|---|---|
| `theme_installed` | notify | `['theme_id' => ...]` |
| `theme_activated` | notify | `['theme_id' => ...]` |
| `theme_deactivated` | notify | `['theme_id' => ...]` |
| `theme_deleted` | notify | `['theme_id' => ...]` |
| `get_pwg_themes` | **change** | `$themes` array |

### D.16 Metadata / Logging / Misc (6 events)

| Event | Type | Data |
|---|---|---|
| `clean_iptc_value` | **change** | `$value` raw IPTC |
| `format_exif_data` | **change** | `$exif`, `$filename`, `$map` |
| `update_rating_score` | **change** | `false`, `$element_id` |
| `pwg_log_allowed` | **change** | `$do_log` bool |
| `pwg_log_update_last_visit` | **change** | `$update_last_visit` bool |
| `set_status_header` | notify | `$code`, `$text` |

---

## Appendix E: Template Inventory

Greenfield Tera template tree. Author-driven by the handler surface in Phases 2–4; not a port of Piwigo's 265 `.tpl` files.

### E.1 Gallery (public SSR)

Files live under `templates/` with theme overrides resolved via the parent chain in §12.4.

```
templates/
├── layout.html                      # Root layout for all public pages
├── error.html                       # 404/403/422/500
├── home.html                        # Root album listing
├── album/
│   ├── show.html                    # Album detail + grid
│   ├── flat.html                    # Flat descendant view
│   └── calendar.html                # Chronological view
├── photo/
│   ├── show.html                    # Detail page (metadata sidebar, prev/next)
│   └── slideshow.html               # Keyboard-driven slideshow
├── tag/
│   ├── index.html                   # Cloud + alphabetical list
│   └── show.html                    # Images for tag
├── search/
│   ├── form.html
│   └── results.html
├── favorites.html
├── highlights/
│   ├── most_viewed.html
│   ├── top_rated.html
│   ├── recent_photos.html
│   └── recent_albums.html
├── auth/
│   ├── login.html
│   ├── register.html
│   ├── password_reset_request.html
│   ├── password_reset_confirm.html
│   └── account/
│       ├── overview.html
│       ├── security.html
│       ├── notifications.html
│       ├── tokens.html
│       └── sessions.html
└── partials/
    ├── nav.html
    ├── footer.html
    ├── pagination.html
    ├── breadcrumb.html
    ├── thumbnail_card.html          # <picture> with AVIF/WebP/JPEG sources
    ├── comment_list.html
    ├── comment_form.html
    ├── rating_widget.html
    └── flash_messages.html
```

### E.2 Admin (SSR)

```
templates/admin/
├── layout.html                      # Sidebar + breadcrumb shell
├── dashboard.html
├── albums/
│   ├── index.html                   # Tree manager
│   ├── new.html
│   ├── edit.html                    # Tabbed: properties/sort/permissions/notification
│   └── permissions_preview.html     # "View as user X"
├── photos/
│   ├── batch.html                   # Global batch manager
│   ├── unit.html                    # Single-photo edit
│   ├── upload.html
│   ├── coi_picker.html              # Center-of-interest interactive tool
│   └── formats.html
├── users/
│   ├── index.html
│   ├── edit.html
│   └── permissions.html
├── groups/
│   ├── index.html
│   └── edit.html
├── tags.html
├── comments.html                    # Moderation queue
├── history.html
├── stats.html
├── ratings.html
├── sync.html                        # SSE-streamed progress
├── maintenance/
│   ├── actions.html
│   └── env.html
├── queues.html                      # Queue monitor + DLQ
├── audit.html
├── settings/
│   ├── general.html
│   ├── storage.html
│   ├── uploads.html
│   ├── derivatives.html
│   ├── mail.html
│   ├── security.html
│   └── search.html
├── plugins/
│   ├── index.html
│   ├── marketplace.html
│   └── detail.html
├── themes.html
└── webhooks.html
```

### E.3 Mail templates

```
templates/mail/
├── layout.html
├── welcome.html                     # + welcome.txt
├── password_reset.html              # + .txt
├── comment_notification.html        # + .txt  (admin moderation alert)
├── digest.html                      # + .txt  (periodic user digest)
├── album_notification.html          # + .txt
└── partials/
    ├── header.html
    └── footer.html
```

Every HTML mail template has a plain-text sibling (`.txt`) rendered via Tera from the same handler context. CSS inlining is applied post-render.

### E.4 Themes

```
themes/
├── default/                         # Parent theme — all other themes inherit
│   └── templates/                   # (overrides of core templates)
├── <theme_2>/                       # Four additional themes shipped at v1.0
├── <theme_3>/                       # Final names TBD during Phase 7
├── <theme_4>/
└── <theme_5>/
```

Theme resolution: `themes/{active}/templates/{path}.html` → `themes/{parent}/templates/{path}.html` → `templates/{path}.html`. Missing files cascade upward automatically via the custom Tera loader (§12.4).

No `.css.tpl` / `.js.tpl` template-assets — Vite handles all CSS/JS build, Tera handles only HTML.

---

## Appendix F: Complete URL Routing Map

All URLs listed here are our own — no preserved Piwigo entry points. No `index.php`, `picture.php`, `ws.php`, `i.php`, `action.php`, `feed.php`, `identification.php`, `admin.php` or any other `*.php` surface. The Axum router matches the clean paths below directly.

### F.1 Public gallery (SSR)

| Path | Handler | Notes |
|---|---|---|
| `/` | `GalleryController::home` | Root album listing; paginated |
| `/albums/{slug}` | `AlbumController::show` | Album detail + photos grid |
| `/albums/{slug}/flat` | `AlbumController::show_flat` | Include descendants |
| `/albums/{slug}/page/{n}` | `AlbumController::show` | Deep-link pagination |
| `/albums/{slug}/calendar/{year}` | `CalendarController::year` | Per-album calendar |
| `/albums/{slug}/calendar/{year}/{month}` | `CalendarController::month` | |
| `/albums/{slug}/calendar/{year}/{month}/{day}` | `CalendarController::day` | |
| `/photos/{uuid}` | `PictureController::show` | Detail view; context-aware prev/next inferred from `?from=album/{slug}` or `?from=tag/{slug}` or `?from=search/{uuid}` |
| `/tags` | `TagController::index` | Tag cloud + list |
| `/tags/{slug}` | `TagController::show` | Images for tag |
| `/tags/{slug}+{slug}+{slug}` | `TagController::show_multi` | Multi-tag AND filter |
| `/search` | `SearchController::form` | Form + result rendering |
| `/search/{uuid}` | `SearchController::show` | Saved-search deep link |
| `/favorites` | `FavoritesController::index` | Authenticated |
| `/highlights/most-viewed` | `HighlightsController::most_viewed` | |
| `/highlights/top-rated` | `HighlightsController::top_rated` | |
| `/highlights/recent-photos` | `HighlightsController::recent_photos` | |
| `/highlights/recent-albums` | `HighlightsController::recent_albums` | |

Clean slugs, no `category/`/`picture/`/`tags/`/`search/` legacy prefixes. Section context for the picture page is passed as `?from=` query param rather than embedded in the path — resolves prev/next without `parse_well_known_params_url()`-style two-phase dispatch.

### F.2 Auth (SSR)

| Path | Handler |
|---|---|
| `/login` | `AuthController::form` |
| `/logout` | `AuthController::submit_logout` |
| `/register` | `RegisterController::form` |
| `/password-reset` | `PasswordResetController::form` |
| `/password-reset/confirm/{token}` | `PasswordResetController::confirm_form` |
| `/account` | `ProfileController::show` |
| `/account/security` | `ProfileController::security` |
| `/account/notifications` | `ProfileController::notifications` |
| `/account/tokens` | `TokenController::index` |
| `/account/sessions` | `SessionController::index` |

### F.3 Media (derivative serving)

| Path | Handler | Auth |
|---|---|---|
| `/media/{preset}/{uuid}.{ext}` | `DerivativeController::serve` | ACL-filtered |
| `/media/{preset}/{uuid}` | `DerivativeController::serve_negotiated` | ACL-filtered; picks format from `Accept` header |
| `/media/custom/{uuid}?w=&h=&crop=&s=&exp=` | `DerivativeController::serve_custom` | Requires valid HMAC signature |
| `/media/originals/{uuid}/{filename}` | `OriginalsController::download` | ACL + `Content-Disposition: attachment` |

Caddy routes `/media/*` to the derivative worker pool (§2); no Caddyfile match for `/i/*`.

### F.4 API (versioned REST)

| Path prefix | Scope |
|---|---|
| `/api/v1/*` | Full REST surface — see Appendix B |
| `/api/v1/docs` | Scalar/Swagger UI |
| `/api/v1/openapi.json` | Spec served from generated source |

### F.5 Admin (SSR, under `/admin/*`)

Single admin namespace. No `?page=X` query-param routing. Each admin page has its own path, its own handler, and its own `#[RequiresLevel(AccessLevel::Administrator)]` attribute.

| Path | Purpose |
|---|---|
| `/admin` | Dashboard |
| `/admin/albums` | Album tree manager |
| `/admin/albums/new` | Create album |
| `/admin/albums/{uuid}` | Edit album (properties tab) |
| `/admin/albums/{uuid}/photos` | Sort photos within |
| `/admin/albums/{uuid}/permissions` | ACL editor |
| `/admin/albums/{uuid}/notify` | Send notification to subscribers |
| `/admin/photos` | Batch manager (global mode) |
| `/admin/photos/unit/{uuid}` | Batch manager (unit mode) |
| `/admin/photos/upload` | Upload UI |
| `/admin/photos/{uuid}` | Edit photo |
| `/admin/photos/{uuid}/coi` | Center-of-interest picker |
| `/admin/photos/{uuid}/formats` | Alternative formats |
| `/admin/users` | User list |
| `/admin/users/{uuid}` | Edit user |
| `/admin/users/{uuid}/permissions` | ACL (reverse view) |
| `/admin/groups` | Group list |
| `/admin/groups/{uuid}` | Edit group |
| `/admin/tags` | Tag manager |
| `/admin/comments` | Moderation queue |
| `/admin/history` | Activity log |
| `/admin/stats` | Usage graphs |
| `/admin/ratings` | Rating overview |
| `/admin/sync` | Filesystem sync (form + SSE progress) |
| `/admin/maintenance` | Cache clear, integrity checks, etc. |
| `/admin/maintenance/env` | Read-only environment info |
| `/admin/queues` | Queue monitor + DLQ |
| `/admin/audit` | Audit log viewer |
| `/admin/settings/general` | General settings |
| `/admin/settings/storage` | Storage config |
| `/admin/settings/uploads` | Upload config |
| `/admin/settings/derivatives` | Derivative presets |
| `/admin/settings/mail` | SMTP + templates |
| `/admin/settings/security` | Security config |
| `/admin/settings/search` | Search engine selection |
| `/admin/plugins` | Installed plugins |
| `/admin/plugins/marketplace` | Browse Lua plugins |
| `/admin/plugins/{name}` | Plugin detail + settings |
| `/admin/themes` | Theme switcher |
| `/admin/webhooks` | Webhook subscriptions |

### F.6 Public utility endpoints

| Path | Purpose |
|---|---|
| `/healthz` | Liveness probe (200 + `ok`) |
| `/readyz` | Readiness probe (200 only if DB + storage reachable) |
| `/version` | JSON `{version, git_sha, build_date}` |
| `/metrics` | Prometheus format (IP-allowlisted) |
| `/feed.atom` | Atom feed |
| `/feed.rss` | RSS feed |
| `/sitemap.xml` | Sitemap (public content only) |
| `/.well-known/security.txt` | Security contact |
| `/robots.txt` | Generated from settings |

### F.7 Routing implementation notes

1. **Attribute-driven routes.** Each handler declares its path with `#[Route("GET", "/albums/{slug}")]`; a build-time scanner compiles the route table for FastRoute-style prefix trie matching. Config file `config/routes.php`-equivalent does not exist — routes live with handlers.
2. **No path-info parsing.** Axum's `Path<T>` extractor deserializes parameters from the matched route. No string splitting, no two-phase dispatch.
3. **No URL-style options.** Piwigo's `config.url.category_style = 'id' | 'id-name' | 'permalink'` configurability is removed — one canonical URL shape per resource.
4. **Permalink history.** When an album or tag is renamed, the old slug is stored in a `slug_redirects` table (not documented as a separate schema entry above — lightweight; fits in `settings` or a dedicated small table if it grows). Old slug → 301 to new.
5. **Localization in URLs.** Paths stay English. Locale selection is via `Accept-Language`, session preference, or `?lang=` override — never in the path.
6. **No `.php` anywhere.** If a user bookmarks `/picture.php?...` on an old Piwigo and lands here, they see a 404. The server does not pretend.

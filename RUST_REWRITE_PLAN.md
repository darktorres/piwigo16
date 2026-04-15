# Piwigo Rust Rewrite

> Full ground-up rewrite of the Piwigo PHP photo gallery in Rust.  
> Target: feature parity with the current PHP 14.x branch, then modernization beyond what PHP could offer.

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
17. [Breaking Changes & Migration Guide](#17-breaking-changes--migration-guide)
18. [Performance Targets](#18-performance-targets)
19. [Milestone Summary](#19-milestone-summary)
20. [Pain Point Deep Dives](#20-pain-point-deep-dives)
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

---

## 1. Project Overview

### What

A complete, ground-up rewrite of the Piwigo photo gallery application in Rust. No PHP code carries forward. The result is a single binary (or small set of binaries) that replaces Apache + PHP-FPM + the current Piwigo PHP stack.

### Why

| Problem (PHP) | Solution (Rust) |
|---|---|
| ~500ms average page load | Target ~75ms (6–7× faster) |
| NTFS MFT access requires Everything SDK via FFI | Native MFT reader via Windows API |
| Sync of 400k+ dirs bottlenecked by PHP/MySQL overhead | Parallel async sync with tokio |
| No compile-time SQL safety (raw addslashes) | sqlx query macros enforce parameterized queries |
| Plugin system requires PHP runtime | Lua (mlua) plugin bridge |
| Memory: ~50MB per PHP request | ~2MB per async task |
| No concurrency within a request | Full async/concurrent request handling |

### Scope

- Full feature parity with PHP 14.x at launch
- MySQL and PostgreSQL support maintained
- All 5 default themes migrated
- All 6 built-in plugins reimplemented as Lua or native
- REST API (`ws.php`) fully compatible — existing API clients must not break
- Admin panel fully functional (SSR, no SPA)
- Filesystem sync with optional native MFT reader (Windows)

### Out of Scope (v1.0)

- Community plugin compatibility (breaking change, documented)
- Support for the PHP serialized response format in the REST API
- XML-RPC protocol support
- Non-UTF-8 language file encoding

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

### ADR-006: SSR Admin UI (Not SPA)

**Decision:** Admin panel uses server-side rendering with Tera templates, not a JS SPA.

**Rationale:**
- Piwigo's admin is form-heavy and navigation-heavy, not interaction-heavy
- SSR requires no frontend build pipeline, no JS bundler, no API contract between admin and backend
- Incremental migration is simpler — each admin page is an isolated Tera template
- HTMX can be added later for selective interactivity without architectural change

### ADR-007: Single Binary, Multiple Modes

**Decision:** Compile to a single binary with subcommands.

```
piwigo serve          # HTTP server
piwigo sync           # Run filesystem sync
piwigo install        # First-time DB setup
piwigo upgrade        # Run DB migrations
piwigo maintenance    # Cache clear, integrity check, etc.
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
bcrypt               = "0.15"
hmac                 = "0.12"
sha1                 = "0.10"
sha2                 = "0.10"
md-5                 = "0.10"
hex                  = "0.4"
rand                 = "0.8"
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
piwigo-rs/
├── Cargo.toml
├── Cargo.lock
│
├── crates/
│   ├── piwigo-core/           # Domain types, traits, error types
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
│   ├── piwigo-db/             # Database layer
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
│   ├── piwigo-image/          # Image processing (libvips wrapper)
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
│   ├── piwigo-metadata/       # EXIF/IPTC extraction
│   │   └── src/
│   │       ├── lib.rs
│   │       ├── exif.rs
│   │       ├── iptc.rs
│   │       └── mapping.rs         # Configurable field mapping (from $conf->use_exif_mapping)
│   │
│   ├── piwigo-search/         # Search query building
│   │   └── src/
│   │       ├── lib.rs
│   │       ├── parser.rs          # Query string tokenizer
│   │       ├── scopes.rs          # Scope types: date, numeric, text, tag
│   │       └── builder.rs         # SQL query builder from parsed scopes
│   │
│   ├── piwigo-plugins/        # Plugin & hook system
│   │   └── src/
│   │       ├── lib.rs
│   │       ├── event_bus.rs       # EventBus: trigger_notify, trigger_change
│   │       ├── events.rs          # All 132 event types as enum
│   │       ├── lua_bridge.rs      # mlua plugin host
│   │       ├── plugin_loader.rs   # Discovery, activation, lifecycle
│   │       ├── host_api.rs        # Lua-callable Rust functions
│   │       └── maintain.rs        # PluginMaintain trait
│   │
│   ├── piwigo-auth/           # Authentication & sessions
│   │   └── src/
│   │       ├── lib.rs
│   │       ├── session.rs         # DB-backed session store
│   │       ├── extractors.rs      # Axum extractors: AuthenticatedUser, AdminUser
│   │       ├── permissions.rs     # Permission computation & caching
│   │       ├── login.rs           # Login flow, bcrypt verify
│   │       ├── remember_me.rs     # HMAC-SHA1 cookie tokens
│   │       ├── api_keys.rs        # user_auth_keys management
│   │       └── csrf.rs
│   │
│   ├── piwigo-sync/           # Filesystem synchronization
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
│   └── piwigo-mail/           # Email (lettre wrapper)
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
├── templates/                 # Tera templates (migrated from .tpl)
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

- **MySQL 8.0+** (and MariaDB 10.6+)
- **PostgreSQL 17+**

### Schema Changes from PHP Version

The schema is preserved as-is for zero-friction migration from existing installations, with the following additions:

| Change | Reason |
|---|---|
| `sessions.data_json TEXT` column added | Migrate from PHP-serialized to JSON session data |
| `search.rules_json JSONB/JSON` column added | Migrate from PHP-serialized search rules |
| `user_infos.preferences_json JSON` column added | Migrate from PHP-serialized preferences |
| All PHP-serialized columns kept during transition | Dual-write period for backward compatibility |

### Migration Strategy

1. `piwigo upgrade` runs sqlx migrations in order
2. Existing PHP session/search/preferences data is migrated in-place by a one-time migration that reads the PHP serialization format and writes JSON
3. PHP columns are dropped in a later migration (configurable, default: 30 days after upgrade)

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
- [ ] Write `Dockerfile` and `compose.yaml` for local dev (MySQL + PostgreSQL + Piwigo binary)
- [ ] Set up `cargo-watch` for hot-reload during development
- [ ] Establish `CHANGELOG.md` and semantic versioning policy

**Acceptance:** `cargo build --release` succeeds, CI passes on empty repo.

---

### 1.2 Configuration System

**Source:** `inc/config_default.php`, `inc/Config.php` (1,266 lines), `local/config/config.php`

- [ ] Define `PiwigoConfig` struct with all ~900 config options, grouped by domain:
  ```rust
  pub struct PiwigoConfig {
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
- [ ] `piwigo install` subcommand writes `local/config/config.toml` and `local/config/database.toml`
- [ ] Expose config to handlers via `axum::extract::State<Arc<AppState>>`
- [ ] Hot-reload of DB-sourced config on SIGHUP (Unix) or admin panel "reload" action

**Acceptance:** Config loads, all 3 tiers merge correctly, unit tests cover all value types.

---

### 1.3 Database Layer (piwigo-db crate)

**Source:** `inc/dblayer/functions_mysqli.php` (890 lines), `inc/dblayer/functions_pgsql.php` (858 lines)

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
- [ ] `piwigo install` runs `sqlx::migrate!()` to apply all migrations
- [ ] `piwigo upgrade` runs pending migrations
- [ ] Connection pool configuration: max connections, acquire timeout, idle timeout
- [ ] Query logging in debug mode (`RUST_LOG=piwigo_db=debug` logs all queries)

**Acceptance:** Both MySQL and PostgreSQL connect and migrate successfully. Integration tests with testcontainers pass for all query modules.

---

### 1.4 Core Domain Types (piwigo-core crate)

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
- [ ] `UserStatus` enum: `Guest`, `Generic`, `Normal`, `Admin`, `Webmaster`
- [ ] `CategoryStatus` enum: `Public`, `Private`
- [ ] `Image` struct with all fields from the `images` table
- [ ] `Category` struct with hierarchy fields (`uppercats`, `global_rank`)
- [ ] `User` struct (from `users` + `user_infos` join)
- [ ] `DerivativeType` enum: `Square`, `Thumb`, `XXSmall`, `XSmall`, `Small`, `Medium`, `Large`, `XLarge`, `XXLarge`, `Custom`
- [ ] `PiwigoError` type hierarchy with `thiserror`
- [ ] All types implement `serde::Serialize` + `serde::Deserialize` where appropriate

---

### 1.5 Authentication & Session Middleware (piwigo-auth crate)

**Source:** `inc/functions_session.php`, `inc/functions_user.php`, `inc/user.php`, `identification.php`

#### 1.5.1 Database-Backed Session Store

- [ ] Define `PiwigoSessionStore` implementing `tower_sessions::SessionStore`
- [ ] Session ID format: `{ipv4_hex_4bytes}{random_session_id}` — matches PHP's IP-binding
- [ ] `create`: INSERT INTO sessions (id, data_json, expiration)
- [ ] `load`: SELECT data_json FROM sessions WHERE id = ? AND expiration > NOW()
- [ ] `save`: INSERT ... ON CONFLICT (id) DO UPDATE SET data_json, expiration
- [ ] `delete`: DELETE FROM sessions WHERE id = ?
- [ ] GC: DELETE FROM sessions WHERE expiration < NOW() — triggered probabilistically (1% of requests) or via `piwigo maintenance sessions`
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

- [ ] `POST /identification` → validate username/password via bcrypt, create session
- [ ] `POST /identification` (logout) → destroy session, clear cookies
- [ ] Remember-me cookie: generate `{user_id}-{timestamp}-{hmac_sha1}`, validate on `auto_login()`
- [ ] Session regeneration on login (delete old session ID, create new)
- [ ] CSRF token: `HMAC-MD5(session_id, secret_key)` — exposed in `GET /ws.php?method=pwg.session.getStatus`
- [ ] Rate limiting on login endpoint: max 10 attempts per IP per minute (tower governor)

**Acceptance:** Login, logout, remember-me, API key auth all work. Permission cache returns correct forbidden categories. Integration tests cover all auth paths.

---

### 1.6 AppState & Server Bootstrap

- [ ] `AppState` struct:
  ```rust
  pub struct AppState {
      pub db: DbPool,
      pub config: Arc<RwLock<PiwigoConfig>>,
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
- [ ] Startup checks: DB connectivity, writable `_data/` directory, libvips version

**Acceptance:** `piwigo serve` starts, health check returns 200, middleware stack processes a request end-to-end.

---

### 1.7 i18n System

**Source:** `inc/functions.php` (l10n, l10n_dec), language loading in `inc/common.php`

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

**Source:** `inc/section_init.php` (648 lines), `inc/functions_url.php`

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
- [ ] Implement all 3 URL style variants per entity type:
  - Categories: `id`, `id-name`
  - Pictures: `id`, `id-file`, `file`
  - Tags: `id-tag`, `id`, `tag`
- [ ] Permalink resolution: query `old_permalinks` table, 301 redirect if slug changed
- [ ] Pagination: extract `start-N` token, validate against item count
- [ ] URL generation helpers: `make_index_url(section, start)`, `make_picture_url(image_id, cat_id)`, etc.
- [ ] Canonical URL header on all pages

---

### 2.2 Gallery Index Handler

**Source:** `index.php` (726 lines), `inc/section_init.php`

- [ ] Category image listing query with permission filtering:
  ```sql
  SELECT DISTINCT image_id, [order_fields]
  FROM image_category
  INNER JOIN images ON id = image_id
  WHERE category_id = ? AND category_id != ALL(?) AND level <= ?
  ORDER BY [config_order]
  ```
- [ ] Persistent query cache (moka, keyed by MD5 of full SQL + user cache key)
- [ ] Image ordering: 7 built-in sort orders (date posted, date created, file name, id, position, rating, visits) + custom via config
- [ ] Pagination: N images per page (from user preference)
- [ ] Sub-categories display: query children of current category with representative images
- [ ] "Flat" view: fetch all descendant categories recursively, merge their image lists
- [ ] Chronology (calendar) view: group by year/month/week/day
- [ ] Breadcrumb: walk `uppercats` string to build ancestor trail
- [ ] `trigger_notify('loc_begin_index')` and `trigger_notify('loc_end_index')`
- [ ] `trigger_change('loc_begin_index_category_thumbnails_query', sql)` — plugin can modify SQL

---

### 2.3 Image Detail Handler

**Source:** `picture.php` (976 lines)

- [ ] Fetch image metadata: all fields from `images` table
- [ ] Category membership: `SELECT category_id FROM image_category WHERE image_id = ?`
- [ ] Comments: fetch approved comments for image with pagination
- [ ] Hit counter increment: `UPDATE images SET hit = hit + 1 WHERE id = ?`
  - Rate-limited: one increment per session per image
  - Configurable: `$conf->count_views`
- [ ] Privacy level check: `image.level <= user.level` — 403 if inaccessible
- [ ] Navigation: previous/next image in category context (using pre-fetched id list)
- [ ] Download link: served via `GET /download/{image_id}` with Content-Disposition header
- [ ] Related tags display
- [ ] Slideshow: JSON data for JS slideshow (image list, derivative URLs)
- [ ] `trigger_notify('loc_begin_picture')` and `trigger_notify('loc_end_picture')`

---

### 2.4 On-Demand Derivative (Thumbnail) Serving

**Source:** `i.php` (350 lines), `inc/ImageStdParams.php`, `inc/DerivativeParams.php`

This is a high-traffic endpoint — every thumbnail request hits it.

- [ ] Parse derivative URL: `GET /i.php?/path/to/photo-sq.jpg` → extract source path, derivative type
- [ ] Custom derivative parsing: `th_cx200y150` format → width=200, height=150, crop=true
- [ ] Cache check: compare `stat(derivative_path).mtime` vs `stat(source_path).mtime` and `params.last_modified`
- [ ] If cache hit: return with `Last-Modified`, `Expires: +10 days`, `ETag`
- [ ] If cache miss: invoke image pipeline (§8)
- [ ] `304 Not Modified` on `If-Modified-Since` match
- [ ] Serve via `tokio::fs::File` with `tower_http::services::ServeFile`
- [ ] Rate limit custom derivatives: max 1 new custom derivative per 5 seconds per IP

---

### 2.5 Search Handler

**Source:** `inc/functions_search.php` (1,254 lines), `search.php`, `qsearch.php`

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

**Source:** `feed.php`

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

### 3.1 libvips-rs Backend (piwigo-image crate)

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

**Source:** `inc/ImageStdParams.php`, `inc/DerivativeParams.php`, `inc/SizingParams.php`, `inc/ImageRect.php`

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

**Source:** `i.php` main generation block (lines 196–290)

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
- [ ] Derivative URL generation: `make_derivative_url(source_path, derivative_type)` → `/_data/i/path/to/photo-sq.jpg`
- [ ] Missing derivatives scan: `GET /admin/maintenance?action=generate_derivatives` triggers background generation for all missing sizes

---

### 3.4 Watermark System

**Source:** `inc/WatermarkParams.php`, watermark block in `i.php`

- [ ] `WatermarkParams`: file path, min output size, x/y position (0–100%), x/y repeat count, opacity (0–100%)
- [ ] Load watermark image once at startup into shared `Arc<VipsImage>`
- [ ] Scale watermark to fit output if output < watermark dimensions
- [ ] Position calculation: `x = (xpos/100) * (output_width - wm_width)`
- [ ] Tiling: if `xrepeat > 0`, tile horizontally at interval
- [ ] Opacity: VIPS composite with alpha premultiplication

---

### 3.5 EXIF/IPTC Metadata Extraction (piwigo-metadata crate)

**Source:** `inc/functions_metadata.php`, `admin/inc/functions_metadata_admin.php` (533 lines)

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

**Source:** `admin/inc/functions_upload.php` (991 lines), `admin/photos_add_direct.php`

- [ ] `POST /admin/photos/upload` (multipart form):
  - Validate file type against allowed extensions
  - Compute MD5 checksum
  - Duplicate detection: `SELECT id FROM images WHERE md5sum = ?`
  - If duplicate: link to existing image, don't store new file
  - Generate destination path: `_data/upload/{YYYY}/{MM}/{DD}/{timestamp}-{random}.{ext}`
  - Async file write via `tokio::io::copy`
  - Trigger `upload_file` plugin event (for special format handlers: PDF, HEIC, video, etc.)
  - Optional original resize: apply if dimensions exceed config limits
  - Apply EXIF rotation
  - Insert into `images` table
  - Insert into `image_category` table (link to target album)
  - Extract and store metadata
- [ ] Chunked upload: `pwg.images.addChunk` + `pwg.images.uploadCompleted`
  - Store chunks in `_data/upload/chunks/{upload_id}/`
  - On completion: concatenate chunks, MD5 verify, process as normal upload
- [ ] Async upload: `pwg.images.uploadAsync` — runs chunked upload accepting username/password in POST body (for batch uploaders)

---

## 9. Phase 4 — Write Paths & Admin

**Duration: 8–12 weeks**  
**Goal:** Full REST API with write operations. Complete admin panel. Users can manage their gallery through both the web UI and API.

---

### 4.1 REST API Method Registry

**Source:** `inc/PwgServer.php`, `inc/ws_init.php`, `inc/ws_functions.php`

- [ ] `MethodRegistry`: `HashMap<String, MethodDef>`
- [ ] `MethodDef`:
  ```rust
  pub struct MethodDef {
      pub handler: Box<dyn MethodHandler>,
      pub params: Vec<ParamDef>,
      pub options: MethodOptions,
  }
  pub struct MethodOptions {
      pub admin_only: bool,
      pub post_only: bool,
      pub hidden: bool,  // not in reflection.getMethodList
  }
  ```
- [ ] `ParamDef`:
  ```rust
  pub struct ParamDef {
      pub name: String,
      pub flags: ParamFlags,   // bitflags: optional, accept_array, force_array
      pub type_: ParamType,    // bitflags: bool, int, float, positive, notnull
      pub default: Option<Value>,
      pub max_value: Option<f64>,
  }
  ```
- [ ] Parameter validation: check presence, coerce arrays, validate type + range
- [ ] Response encoders: `JsonEncoder` and `RestXmlEncoder`
- [ ] Drop: XML-RPC encoder and PHP-serialize encoder (breaking change, documented)
- [ ] Built-in reflection methods: `reflection.getMethodList`, `reflection.getMethodDetails`

---

### 4.2 API Methods Implementation

Implement all 84 methods. Below is the priority order with complexity notes.

#### 4.2.1 Session Methods (3)
- [ ] `pwg.session.getStatus` — user info + CSRF token + available sizes
- [ ] `pwg.session.login` — POST, bcrypt verify, create session
- [ ] `pwg.session.logout` — destroy session

#### 4.2.2 Core Methods (4)
- [ ] `pwg.getVersion`
- [ ] `pwg.getInfos` — admin only, system stats
- [ ] `pwg.getCacheSize` — derivative cache sizes on disk
- [ ] `pwg.activity.getList` — admin only, paginated activity log

#### 4.2.3 Category Methods (12)
- [ ] `pwg.categories.getList` — hierarchical tree with permission filtering, pagination, representative image
- [ ] `pwg.categories.getImages` — image list for category with filters and sort
- [ ] `pwg.categories.getAdminList` — all categories (admin)
- [ ] `pwg.categories.calculateOrphans` — images not linked to any category
- [ ] `pwg.categories.add` — create album, inherit permissions
- [ ] `pwg.categories.delete` — delete album and optionally its images
- [ ] `pwg.categories.move` — reparent album, recompute uppercats/global_rank
- [ ] `pwg.categories.setInfo` — update name, description, status, representative
- [ ] `pwg.categories.setRank` — reorder within parent
- [ ] `pwg.categories.setRepresentative` — set cover image
- [ ] `pwg.categories.deleteRepresentative`
- [ ] `pwg.categories.refreshRepresentative` — auto-select from images

#### 4.2.4 Image Methods (26)

Most complex. Prioritize:
- [ ] `pwg.images.getInfo` — full image detail (metadata, comments, URLs for all derivative sizes)
- [ ] `pwg.images.search` — full-text + structured search with permission filtering
- [ ] `pwg.images.rate` — submit rating, update `rating_score`
- [ ] `pwg.images.addComment` — validated, optionally moderated
- [ ] `pwg.images.setInfo` — update title, description, date, tags, level, etc.
- [ ] `pwg.images.setCategory` — associate/dissociate/move image between albums
- [ ] `pwg.images.addSimple` — single file upload (multipart POST)
- [ ] `pwg.images.addChunk`, `pwg.images.uploadCompleted` — chunked upload
- [ ] `pwg.images.uploadAsync` — username+password in POST body
- [ ] `pwg.images.delete` — delete image + files + DB records
- [ ] `pwg.images.exist` — check by md5sum or file path
- [ ] `pwg.images.syncMetadata` — re-extract EXIF/IPTC from file
- [ ] `pwg.images.checkFiles` — compare file checksums to DB
- [ ] `pwg.images.deleteOrphans` — delete images not linked to any category
- [ ] Remaining 11 methods

#### 4.2.5 Tags Methods (8)
- [ ] `pwg.tags.getList`, `pwg.tags.getImages`
- [ ] `pwg.tags.add`, `pwg.tags.delete`, `pwg.tags.rename`, `pwg.tags.duplicate`, `pwg.tags.merge`
- [ ] `pwg.tags.getAdminList`

#### 4.2.6 User/Group/Permission Methods (21)
- [ ] All user CRUD methods + auth key management + favorites
- [ ] All group CRUD methods
- [ ] All permission methods (add/remove/list for user and group access)

#### 4.2.7 Plugin/Extension Methods (6)
- [ ] `pwg.plugins.getList`, `pwg.plugins.performAction`
- [ ] `pwg.extensions.checkUpdates`, `pwg.extensions.update`, `pwg.extensions.ignoreUpdate`
- [ ] `pwg.themes.performAction`

#### 4.2.8 Utility Methods (6)
- [ ] `pwg.caddie.add`, `pwg.rates.delete`
- [ ] `pwg.getMissingDerivatives`, `pwg.history.log`, `pwg.history.search`
- [ ] `pwg.images.filteredSearch.create`

---

### 4.3 Admin Panel (57 pages)

All admin pages use SSR with Tera templates. Each page is an isolated handler.

#### 4.3.1 Infrastructure
- [ ] Admin base template (`admin/base.html`): sidebar navigation, breadcrumb, flash messages, CSRF token in all forms
- [ ] Admin auth middleware: redirect to login if not Administrator
- [ ] Tab system: `TabSheet` struct for multi-tab pages (maintenance, config, user edit)
- [ ] Flash messages: one-shot session messages for success/error feedback
- [ ] HTMX integration for partial page updates (optional enhancement, not required for v1)

#### 4.3.2 High Priority Pages
- [ ] **Dashboard** (`/admin`) — pending comments, orphan images, update notifications, activity summary
- [ ] **Album management** (`/admin/albums`) — tree view, drag-and-drop ordering (or form-based), create/edit/delete
- [ ] **Album edit** (`/admin/album/{id}`) — name, description, status, representative, permissions
- [ ] **Photo upload** (`/admin/photos/add`) — drag-and-drop upload interface, progress, album assignment
- [ ] **Photo edit** (`/admin/photo/{id}`) — metadata, tags, album links, privacy level, COI tool
- [ ] **Batch manager** (`/admin/batch`) — filter by any criteria, bulk tag/category/privacy/delete operations
- [ ] **Configuration** (`/admin/configuration`) — all ~900 config options, tabbed by domain
- [ ] **User management** (`/admin/users`) — list, create, edit, delete, group assignment
- [ ] **User permissions** (`/admin/user/{id}/permissions`) — category access grants
- [ ] **Group management** (`/admin/groups`) — create, edit, delete, member management
- [ ] **Group permissions** (`/admin/group/{id}/permissions`)

#### 4.3.3 Medium Priority Pages
- [ ] **Sync** (`/admin/sync`) — trigger sync, view progress via SSE, profiling stats
- [ ] **Maintenance** (`/admin/maintenance`) — DB optimize, cache clear, session purge, integrity check
- [ ] **Tags** (`/admin/tags`) — list, rename, merge, delete
- [ ] **Comments** (`/admin/comments`) — moderation queue, approve/reject/delete
- [ ] **History** (`/admin/history`) — activity log with filters
- [ ] **Stats** (`/admin/stats`) — visit statistics, most viewed
- [ ] **Rating** (`/admin/rating`) — manage ratings, delete spam

#### 4.3.4 Lower Priority Pages
- [ ] **Plugins** (`/admin/plugins`) — installed list, activate/deactivate, install from file
- [ ] **Themes** (`/admin/themes`) — installed list, activate, upload new
- [ ] **Languages** (`/admin/languages`) — installed list, install new
- [ ] **Permalinks** (`/admin/permalinks`) — URL structure config
- [ ] **Photo formats** (`/admin/photo/{id}/formats`) — manage alternative file formats
- [ ] **Menubar** (`/admin/menubar`) — sidebar block ordering
- [ ] **Notification by mail** (`/admin/notification`) — subscription management, send digest
- [ ] **FTP import** (`/admin/photos/ftp`) — import from FTP-uploaded files
- [ ] **Updates** (`/admin/updates`) — check for core/plugin/theme updates
- [ ] Remaining pages

#### 4.3.5 Category & Permission Operations (Admin)
- [ ] `category.uppercats` recomputation after move/create/delete
- [ ] `global_rank` recomputation (order across full tree)
- [ ] Permission inheritance: when creating a new category, optionally inherit parent's user_access and group_access
- [ ] Batch permission assignment: apply same permissions to all categories in a subtree

---

### 4.4 User-Facing Write Operations

- [ ] Comment submission: `POST /comments` — validate, optionally queue for moderation, trigger notification
- [ ] Comment validation/deletion (admin)
- [ ] User registration: `POST /register` — validate, create user, send activation email
- [ ] User profile update: `POST /profile` — password change, email, language, theme, notification prefs
- [ ] Image rating: `POST /rate/{image_id}` — validate score, upsert in `rate` table, recompute `rating_score`
- [ ] Favorite add/remove: `POST /favorites/{image_id}`
- [ ] Caddie (working set) management

---

### 4.5 Email System (piwigo-mail crate)

**Source:** `inc/functions_mail.php` (1,047 lines), `inc/functions_notification.php`

- [ ] `PiwigoMailer` wrapping `lettre::AsyncSmtpTransport`
- [ ] SMTP configuration: host, port, TLS mode, username, password (from config)
- [ ] `send_mail(to, subject, template, context)` — render Tera template, send HTML + plain text
- [ ] Email templates: Tera templates in `templates/mail/`
- [ ] Notification types: new comments, digest for subscribed albums, user activation, password reset
- [ ] Subscription management: per-user per-album opt-in/out
- [ ] Batch notification digest: collect new images since last check, send one email

---

## 10. Phase 5 — Filesystem Sync

**Duration: 4–6 weeks**  
**Goal:** Full 3-phase sync with streaming progress, profiling, and optional Windows MFT reader.

---

### 5.1 Sync Orchestrator

**Source:** `admin/site_update.php` (1,389 lines)

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

## 11. Phase 6 — Plugin System

**Duration: 8–10 weeks**  
**Goal:** Full event hook system with Lua plugin support. All 6 built-in PHP plugins reimplemented.

---

### 6.1 Event Bus (piwigo-plugins crate)

**Source:** `inc/functions_plugins.php` (369 lines), 132 event names

- [ ] Define all 132 event names as a Rust enum:
  ```rust
  #[derive(Debug, Clone, PartialEq, Eq, Hash)]
  pub enum PiwigoEvent {
      Init,
      PluginsLoaded,
      UserInit,
      UserLogin,
      UserLogout,
      LoginSuccess,
      LoginFailure,
      TryLogUser,
      LocBeginIndex,
      LocEndIndex,
      // ... all 132
  }
  ```
- [ ] `EventBus`:
  ```rust
  pub struct EventBus {
      handlers: DashMap<PiwigoEvent, BTreeMap<u32, Vec<HandlerFn>>>,
  }
  impl EventBus {
      pub fn add_handler(&self, event: PiwigoEvent, handler: HandlerFn, priority: u32);
      pub fn remove_handler(&self, event: PiwigoEvent, handler_id: HandlerId);
      pub async fn trigger_notify(&self, event: PiwigoEvent, ctx: &RequestContext);
      pub async fn trigger_change<T: Serialize + DeserializeOwned>(
          &self, event: PiwigoEvent, data: T, ctx: &RequestContext
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
      lua: Lua,
      plugins: Vec<LoadedPlugin>,
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
  piwigo.conf.set("key", value)  -- admin plugins only
  piwigo.db.query(sql, params)   -- sandboxed: only SELECT allowed for non-admin plugins
  piwigo.db.execute(sql, params) -- admin plugins only
  piwigo.l10n("key")
  piwigo.log(level, message)
  ```
- [ ] Capability model: plugin declares required capabilities in `plugin.toml`, user approves on install
- [ ] Lua error isolation: errors in plugin handlers are caught, logged, and execution continues

---

### 6.3 Plugin Lifecycle (PluginMaintain)

**Source:** `inc/PluginMaintain.php`

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

**Source:** `inc/ScriptLoader.php` (373 lines), `inc/CssLoader.php` (89 lines), `inc/FileCombiner.php` (300+ lines)

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
- [ ] Write combined file to `_data/combined/{hash}.css` or `.js`
- [ ] Configurable: `config.template_combine_files` — disable for development

---

### 7.3 BlockManager (Rust equivalent)

**Source:** `inc/BlockManager.php` (184 lines)

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

**Source:** `themes/*/themeconf.php`

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

### 7.5 Template Migration Execution

- [ ] Write a Smarty→Tera transpiler script (PHP or Python) to handle mechanical conversions:
  - `{$var}` → `{{ var }}`
  - `{'key'|translate}` → `{{ 'key' | translate }}`
  - `{if $cond}...{/if}` → `{% if cond %}...{% endif %}`
  - `{foreach from=$arr item=x}...{/foreach}` → `{% for x in arr %}...{% endfor %}`
  - `{include file='name.tpl'}` → `{% include 'name.html' %}`
  - `{combine_script ...}` → `{{ combine_script(...) }}`
- [ ] Manually review and fix each migrated template (~277 files)
- [ ] Build visual regression test suite: screenshot comparison between PHP and Rust renders
- [ ] Priority migration order:
  1. `header.html` + `footer.html` (affects all pages)
  2. `index.html` (gallery browsing)
  3. `picture.html` (image detail)
  4. `identification.html` (login)
  5. All admin templates
  6. Remaining gallery templates
  7. All child-theme overrides

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

- [ ] SQL injection: verify all 523 ported queries use bind parameters — automated check via `cargo clippy` lint or grep for string interpolation in query strings
- [ ] XSS: verify all template output is escaped by default (Tera auto-escapes by default)
- [ ] CSRF: verify all state-mutating POST endpoints check CSRF token
- [ ] Session fixation: verify session ID is regenerated on login
- [ ] Path traversal: verify file serving endpoints reject `../` patterns
- [ ] Upload validation: verify file type checked by magic bytes, not extension
- [ ] Rate limiting: verify login endpoint and derivative generation are rate-limited

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
- [ ] `docs/upgrade.md` — upgrade from Piwigo PHP 14.x
- [ ] `docs/configuration.md` — all config options with defaults
- [ ] `docs/plugins.md` — Lua plugin API reference with examples
- [ ] `docs/api.md` — REST API reference (auto-generated from method registry)
- [ ] `docs/themes.md` — theme development guide (Tera templates, asset pipeline)
- [ ] `docs/sync.md` — filesystem sync guide (MFT requirements, performance tuning)
- [ ] In-code: `///` doc comments on all public API surfaces
- [ ] `cargo doc --no-deps` generates complete API docs

---

## 14. Subsystem Specifications

### 14.1 Session Format Migration

PHP sessions are stored as `s:7:"pwg_uid";i:3;` in the `sessions.data` column. The Rust version uses JSON.

**Migration steps:**
1. Add `sessions.data_json` column (nullable) in an early migration
2. `piwigo upgrade` reads all existing sessions, deserializes PHP serialization format, writes JSON to `data_json`
3. Rust server reads `data_json` if non-null, else `data` (for sessions created by a mixed-mode deployment)
4. Write always goes to `data_json`
5. After cutover period (configurable, default 30 days), a second migration drops `data` and `data_json` becomes `data`

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

```
_data/i/
├── {site_relative_path}/
│   ├── photo-sq.jpg          (SQUARE 120px)
│   ├── photo-th.jpg          (THUMB 144px)
│   ├── photo-xs.jpg          (XSMALL 432px)
│   ├── photo-me.jpg          (MEDIUM 792px)
│   └── photo-cu_cx200y150.jpg (CUSTOM 200x150 crop)
```

File naming convention matches current PHP format exactly — existing derivative caches are compatible.

### 14.5 Plugin Event Context

Every hook invocation receives a `RequestContext` giving the plugin access to request-scoped data:

```rust
pub struct RequestContext {
    pub user: AuthenticatedUser,
    pub config: Arc<PiwigoConfig>,
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
| `piwigo-auth/permissions` | Permission calculation for users with various group/direct access combinations |
| `piwigo-auth/session` | Session ID generation, IP binding, expiry |
| `piwigo-auth/remember_me` | HMAC generation and validation, expiry |
| `piwigo-image/sizing` | COI crop rectangle calculation against known-good values from PHP |
| `piwigo-image/derivatives` | Mtime-based cache invalidation logic |
| `piwigo-search/parser` | Tokenization of search queries |
| `piwigo-search/builder` | Generated SQL matches expected parameterized form |
| `piwigo-db/bulk` | `mass_inserts` correct with various batch sizes |
| `piwigo-sync/diff` | Directory diff, file diff — set operations |
| `piwigo-plugins/event_bus` | Priority ordering, chaining in `trigger_change` |
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

### API Tests

Each of the 84 REST methods has at least one test covering:
- Happy path
- Authentication required (returns 403)
- Missing required parameter (returns 1002)
- Invalid parameter type (returns 1003)

### Benchmark Suite

```
benches/
├── derivative_generation.rs   # Time to generate each of 9 sizes
├── sync_scan.rs               # Time to scan directory tree of 10k/100k/400k files
├── permission_computation.rs  # Permission calculation for users with 100/1000/10000 categories
├── api_gallery.rs             # Latency for pwg.categories.getList on large galleries
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
| R-06 | Existing user data in PHP serialization format not migrated correctly | Medium | High | Write comprehensive PHP deserialization unit tests; dry-run migration before applying |
| R-07 | REST API breaking changes break third-party clients | Low | High | Maintain strict compatibility testing against documented PHP behavior |
| R-08 | Template visual regression too large to automate | Medium | Medium | Prioritize high-traffic pages; accept manual review for others |
| R-09 | Memory usage higher than expected at scale | Low | Medium | Profile early with realistic dataset; use `jemalloc` for reduced fragmentation |
| R-10 | Community plugin ecosystem resists Lua migration | High | Low | Accepted breakage; document clearly; provide PHP→Lua migration guide |

---

## 17. Breaking Changes & Migration Guide

### From Piwigo PHP 14.x

#### Database

The existing database schema is preserved. Run `piwigo upgrade` to apply additive migrations (new columns for JSON session data, etc.). No destructive schema changes in the upgrade path.

#### Session Compatibility

Sessions from the PHP version are invalidated on upgrade (PHP serialized format → JSON). Users will need to log in again after upgrade. This is expected and documented.

#### Plugin Ecosystem

**All PHP plugins are incompatible.** This is a hard break.

- Built-in plugins (AdminTools, GDThumb, etc.) are reimplemented as Lua plugins
- Community plugin authors must rewrite in Lua using the documented plugin API
- A migration guide with code examples will be provided
- A compatibility shim is NOT planned — it would add significant complexity for temporary benefit

#### API

The REST API (`ws.php` / `/api/v1/ws`) maintains full JSON response compatibility. The following are dropped:
- PHP serialized response format (`format=php`)
- XML-RPC format (`format=xmlrpc`)

Applications using these formats must migrate to JSON.

#### URL Structure

All URL formats (id, id-name, file) are preserved. Existing bookmarks and permalinks continue to work.

#### Templates & Themes

PHP themes (`.tpl` files) are incompatible. The 5 built-in themes are migrated. Third-party themes must be rewritten using Tera syntax.

#### Configuration

Config values from `config` table are preserved. The `local/config/config.php` format is read during migration for backward compatibility and converted to `local/config/config.toml`.

---

## 18. Performance Targets

| Metric | PHP 14.x (Baseline) | Rust Target | Method |
|---|---|---|---|
| Gallery page (cold, no cache) | ~500ms | <80ms | Benchmark |
| Gallery page (warm, DB cached) | ~150ms | <30ms | Benchmark |
| Derivative generation (MEDIUM, cold) | ~800ms | <150ms | Benchmark |
| Derivative serving (cache hit) | ~20ms | <5ms | Benchmark |
| API `pwg.categories.getList` | ~200ms | <40ms | Benchmark |
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

## 20. Pain Point Deep Dives

> Detailed technical investigation of every identified pain point.

---

## 20.1 Plugin & Hook System

**Severity: CRITICAL — This is the single hardest architectural problem in the rewrite.**

### 20.1.1 Hook Inventory

The codebase has **222 hook call sites** across 52 files, invoking **132 unique event names**.

| Metric | Count |
|---|---|
| `trigger_notify()` call sites | 105 |
| `trigger_change()` call sites | 117 |
| Unique notify events | 71 |
| Unique change events | 61 |
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
fn get_pwg_token() -> String;
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
6. Caches with `t` prefix: `_data/combined/t{hash}.css`

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
2. **Directory preparation** — `_data/upload/{YYYY}/{MM}/{DD}/`
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
- [ ] Cache key includes format: `photo-me.avif`, `photo-me.webp`, `photo-me.jpg` coexist in `_data/i/`
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
  - Store segments in `_data/v/{image_id}/`
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
- [ ] API validates scope on each method: `pwg.images.addSimple` requires `upload`, `pwg.users.delete` requires `admin`
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
- [ ] **Index location:** `_data/search_index/` directory
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
- [ ] **Index location:** `_data/vector_index/` — single memory-mapped file
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

- [ ] **Key:** `SHA-256` of original file content → `_data/cas/{ab}/{cd}/{abcdef...full-hash}.{ext}`
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
  COPY --from=builder /app/piwigo /usr/local/bin/piwigo
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

- [ ] **`piwigo backup`:**
  - Dumps database to SQL file (via `pg_dump` / `mysqldump` shelled out, or sqlx-native for portability)
  - Archives `_data/` directory (originals, derivatives, config)
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
- [ ] **API:** `pwg.activity.getAlbumFeed(album_id, since)` — for mobile/external clients
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
- [ ] **Retention:** Configurable, default 1 year. `piwigo maintenance audit-trim` for manual cleanup.
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

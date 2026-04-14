# Gallery Rewrite — Architecture & Technology Plan

## Goal

Rewrite the Piwigo fork into a high-performance photo gallery application that can handle millions of files. Strip unnecessary features, focus on speed, parallelism, and clean architecture.

## Core Workloads

1. **Sync engine** — scan filesystem, extract metadata, diff with DB (CPU + I/O parallel)
2. **Image processing** — thumbnail/derivative generation (CPU heavy)
3. **Web API** — serve gallery pages, search, admin (I/O bound, concurrent)
4. **Image serving** — serve derivatives fast (static file serving)

## Language Comparison

| | **Go** | **Rust** | **C#/.NET** | **TypeScript (Bun)** |
|---|---|---|---|---|
| Parallel file scanning | Goroutines — trivial | rayon — trivial | Task Parallel Library — easy | Worker threads — awkward |
| EXIF/metadata extraction | goexif, goimage | kamadak-exif, image | MetadataExtractor | exifreader (pure JS, slow) |
| Image processing | libvips bindings | libvips/image crate | ImageSharp | sharp (libvips wrapper) |
| Web server | stdlib / Echo | Axum / Actix | ASP.NET (very fast) | Hono / Fastify |
| WebSocket | stdlib | tokio-tungstenite | SignalR | built-in |
| Deployment | Single binary | Single binary | Single binary (AOT) | Node runtime |
| Dev speed | Fast | Slower | Fast | Fastest |
| Raw performance | Excellent | Best | Excellent | Good |

## Recommended Stack

### Backend: Go

- **Goroutines** are perfect for parallel scanning — `go scanDir(path)` across thousands of directories with zero overhead. Channels for collecting results.
- **Single binary deployment** — No PHP, no Apache, no runtime. Just one executable.
- **Built-in HTTP server** — Fast enough to serve production traffic directly, with WebSocket support in the stdlib.
- **libvips bindings** (govips) — Derivative generation stays fast.
- Simple language, fast compile times, fast development velocity.

### Database: PostgreSQL or SQLite

- **PostgreSQL** — Better indexing, JSONB for flexible metadata, full-text search, handles concurrent writes well.
- **SQLite** — Surprisingly fast for read-heavy workloads, zero deployment overhead, WAL mode handles concurrent reads. Good for single-user/small-team galleries.

### Frontend: SvelteKit or HTMX

- **SvelteKit** — Rich SPA with virtual scrolling for million-image grids, fast reactivity.
- **HTMX** — Simpler, server-rendered HTML with progressive enhancement. Less code, less complexity.

## Architecture

```
┌─────────────────────────────────────┐
│           Go binary                 │
│                                     │
│  ┌───────────┐    ┌──────────────┐  │
│  │ Web Server │    │ Sync Engine  │  │
│  │ (HTTP+WS)  │    │ (background) │  │
│  └─────┬─────┘    └──────┬───────┘  │
│        │                 │          │
│        │   ┌─────────┐   │          │
│        └──►│   DB    │◄──┘          │
│            │(PG/SQLite)│            │
│            └─────────┘              │
└─────────────────────────────────────┘
```

### Sync Engine (Pipeline Architecture)

Instead of sequential batch processing, use a streaming pipeline where steps overlap:

```
Pool of N goroutines:

  Scanner goroutines (parallel per top-level dir)
       │
       ▼ channel
  Differ (compare fs entries vs DB)
       │
       ▼ channel
  Metadata extractors (N worker goroutines)
       │
       ▼ channel
  DB batch writer (collects results, bulk inserts)
```

- Scanner goroutines walk directories in parallel, emit file entries into a channel
- Differ consumes entries, checks DB, emits new/modified files into next channel
- Metadata extractors run N workers pulling from the channel, extract EXIF/IPTC/size
- DB writer batches results and does bulk inserts/updates

This means metadata extraction starts before the scan finishes, and DB writes happen while extraction is still running.

### Web Server

- **API (JSON)** — Gallery browsing, search, admin operations
- **WebSocket** — Bidirectional sync progress (start/pause/abort + real-time updates)
- **Static file serving** — Derivatives served directly, with cache headers
- **Frontend** — Embedded SPA (SvelteKit build) or server-rendered templates

### Image Processing

- **govips** (libvips Go bindings) for thumbnail/derivative generation
- Background worker pool for generation on first access or during sync
- Derivative cache on filesystem with path-based lookup

## Key Differences from Piwigo

| Aspect | Piwigo (PHP) | Rewrite (Go) |
|---|---|---|
| Request model | New process per request, full bootstrap | Persistent process, everything in memory |
| DB connections | Connect/disconnect per request | Connection pool, persistent |
| Parallelism | None (sequential loops) | Goroutine workers, pipeline streaming |
| Sync 328k files | ~5-10 minutes | Target: 30-60 seconds |
| Image serving | PHP bootstrap + file read | Direct static file serve |
| Sync control | SSE (one-way) + file IPC hacks | WebSocket (bidirectional) |
| Deployment | Apache + PHP + MySQL | Single binary + DB |
| File limit | Struggles past 100k | Designed for millions |

## Performance Targets (328k files baseline)

| Operation | Current (PHP) | Target (Go) |
|---|---|---|
| Directory scan | 1-2 min | 5-10s (parallel goroutines) |
| File scan | 2 min | 15-20s (parallel goroutines) |
| DB diff + insert | 30s-5min | 5-10s (batch inserts, prepared statements) |
| Metadata extraction (first sync) | 30-45s | 10-15s (N workers) |
| Metadata re-sync (no changes) | 0.2s | 0.1s (filesize skip) |
| Total first sync | 5-10 min | 30-60s |
| Total re-sync | 8s | 2-3s |

## Migration Path

1. **Phase 1**: Build the sync engine as a standalone Go CLI tool. Can run alongside existing Piwigo, writing to the same MySQL database.
2. **Phase 2**: Build the API server in Go. Run in parallel with Piwigo, gradually migrate endpoints.
3. **Phase 3**: Build the frontend (SvelteKit). Replace Piwigo's Smarty templates.
4. **Phase 4**: Drop PHP/Apache entirely. Single Go binary serves everything.

Phase 1 is the highest-value starting point — it exercises the core performance-critical path (filesystem + metadata + DB) without needing to rewrite the entire web UI.

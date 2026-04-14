# Sync Performance Analysis

## Overview

The sync process (`admin/site_update.php`) synchronizes the filesystem with the database across three phases: directory/category sync, file/element sync, and metadata sync. All three phases contain performance bottlenecks, but metadata sync is by far the most expensive.

---

## Profiling results

### Run 1: 782 images (quick sync, before optimization)

#### Timeline

| Phase | Duration | Notes |
|-------|----------|-------|
| Directory sync | 0.13s | 2 new categories |
| Filesystem scan | 0.06s | 3 dirs, 782 files matched |
| DB insert (images+links) | 0.13s | 782 new images |
| Update attributes | 0.47s | mass_updates 0.45s |
| **Metadata sync** | **7.78s** | **dominates everything** |

#### Metadata sync breakdown (7.78s total)

| Operation | Total | Avg/image | % of phase |
|-----------|-------|-----------|------------|
| `finfo` (MIME detect) | 0.61s | 0.78ms | 7.8% |
| `exif` (exif_read_data) | 0.31s | 0.40ms | 4.0% |
| `filesize` | 0.13s | 0.16ms | 1.7% |
| `getimagesize` (dimensions) | 0.08s | 0.10ms | 1.0% |
| **Extraction subtotal** | **1.20s** | 1.53ms | **15.4%** |
| **mass_updates** | **6.57s** | 8.4ms/row | **84.4%** |

#### Per-image extraction stats

| avg | p50 | p95 | p99 | max |
|-----|-----|-----|-----|-----|
| 1.5ms | 1.4ms | 2.4ms | 3.4ms | 17.1ms |

---

### Run 2: 30,302 images (quick sync, before optimization)

#### Timeline

| Phase | Duration | Notes |
|-------|----------|-------|
| Directory sync | 5.43s | 1 new category |
| Filesystem scan | 4.47s | 4 dirs, 33,652 files found, 31,084 matched |
| mass_inserts (images+links) | 31.26s | 30,302 new rows |
| Update attributes | 18.40s | mass_updates 18.12s for 31,084 rows |
| **Metadata sync** | **345.33s (5m 45s)** | **dominates everything** |
| **Total** | **~6m 40s** | |

#### Metadata sync breakdown (345s total)

| Operation | Total | Avg/image | % of phase |
|-----------|-------|-----------|------------|
| `finfo` (MIME detect) | **51.25s** | 1.69ms | **14.8%** |
| `exif` (exif_read_data) | 16.22s | 0.54ms | 4.7% |
| `filesize` | 7.93s | 0.26ms | 2.3% |
| `getimagesize` (dimensions) | 5.85s | 0.19ms | 1.7% |
| **Extraction subtotal** | **84.03s** | 2.77ms | **24.3%** |
| **mass_updates** | **261.04s** | 8.6ms/row | **75.6%** |

#### Per-image extraction stats

| avg | p50 | p95 | p99 | max |
|-----|-----|-----|-----|-----|
| 2.8ms | 2.6ms | 4.5ms | 6.3ms | 67.7ms |

---

### Scaling analysis (782 vs 30,302 images, before optimization)

| Metric | 782 images | 30,302 images | Scale factor (38.7x more images) |
|--------|-----------|---------------|----------------------------------|
| Extraction total | 1.20s | 84.03s | 70x (superlinear) |
| finfo total | 0.61s | 51.25s | 84x (superlinear) |
| finfo avg/image | 0.78ms | 1.69ms | **2.2x worse at scale** |
| exif total | 0.31s | 16.22s | 52x (linear) |
| exif avg/image | 0.40ms | 0.54ms | 1.35x (slight degradation) |
| mass_updates total | 6.57s | 261.04s | 40x (linear) |
| mass_updates avg/row | 8.4ms | 8.6ms | stable |
| Filesystem scan | 0.06s | 4.47s | 75x (superlinear, readdir dominates) |

Key observations:
- **`finfo` degrades superlinearly** — avg cost per image doubles at 30k. Likely OS-level file handle or cache pressure. ExifTool would eliminate this entirely.
- **`mass_updates` scales linearly** at ~8.5ms/row regardless of batch size — confirmed doing per-row UPDATE statements with per-row auto-commit.
- **Filesystem scan** jumps from 0.06s to 4.47s. The `readdir` calls account for 4.45s of that — expected for 33k files.
- **Extraction loop at 30k is 84s** — no longer negligible. ExifTool collapsing 4 file operations into 1 becomes meaningful at this scale.

---

## `mass_updates` optimization — implemented

### Root cause

**Location**: `inc/dblayer/functions_mysqli.php:271-331`

The original `mass_updates` executed one `UPDATE ... SET ... WHERE id = N` per row via `pwg_query()`. Each call went through:
1. TCP round-trip to MySQL on `127.0.0.1:3306` (~0.1ms)
2. SQL query logging to `_data/sql/mysqli.sql` via `file_put_contents` with `FILE_APPEND | LOCK_EX`
3. Query parsing and execution in MySQL

For 31,084 rows = 31,084 round-trips + 31,084 file writes = 18.12s.

### Approaches evaluated

| Approach | Problem |
|----------|---------|
| `INSERT ... ON DUPLICATE KEY UPDATE` with grouping | Callers only pass primary key + update columns in `$datas`. Tables with NOT NULL columns (e.g. `images.file`) reject the INSERT because missing columns have no default. Would require changing all 25+ call sites to pass full row data. |
| Temp table + JOIN UPDATE | DDL overhead (CREATE, ALTER, DROP per group). Workaround, not a fix. |
| Transaction wrapping only | No improvement (18.06s). The bottleneck is per-query round-trips and SQL file logging, not InnoDB commit flushing. |
| **`mysqli->multi_query()` batching** | **Correct fix. Concatenates UPDATE statements into batches respecting `max_allowed_packet`, sends each batch in a single network round-trip via `multi_query()`. Bypasses per-query SQL file logging overhead.** |

### Result (update attributes phase, 31,084 rows)

| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| mass_updates | 18.12s | **3.41s** | **5.3x faster** |
| per-row cost | 0.58ms | 0.11ms | 5.3x |

Additional fix: added `pwg_db_real_escape_string` on all interpolated values (the original had no escaping).

### Changes made

- `inc/dblayer/functions_mysqli.php`: Rewrote `mass_updates` to batch UPDATE statements via `mysqli->multi_query()`, respecting `max_allowed_packet`. Wrapped in `START TRANSACTION` / `COMMIT`. Added value escaping.
- `inc/dblayer/functions_pgsql.php`: Added `BEGIN` / `COMMIT` transaction wrapping and value escaping (PostgreSQL multi-statement batching not yet implemented).

### Run 3: 31,084 images (full sync with metadata, after mass_updates fix)

#### Timeline

| Phase | Duration | Notes |
|-------|----------|-------|
| Directory sync | 4.54s | 0 new categories |
| Filesystem scan | 3.38s | 4 dirs, 33,652 files found, 31,084 matched |
| Update attributes | 4.12s | mass_updates **3.84s** (was 18.12s) |
| **Metadata sync** | **114.29s** | extraction 109.54s + mass_updates 4.47s |
| **Total** | **~2m 6s** | **was ~6m 40s (3.2x faster)** |

#### Metadata sync breakdown (114.3s total)

| Operation | Total | Avg/image | % of phase |
|-----------|-------|-----------|------------|
| `finfo` (MIME detect) | **59.69s** | 1.92ms | **52.2%** |
| `exif` (exif_read_data) | 26.97s | 0.87ms | 23.6% |
| `getimagesize` (dimensions) | 10.21s | 0.33ms | 8.9% |
| `filesize` | 9.81s | 0.32ms | 8.6% |
| **Extraction subtotal** | **109.54s** | 3.52ms | **95.8%** |
| **mass_updates** | **4.47s** | 0.14ms/row | **3.9%** |

#### Per-image extraction stats

| avg | p50 | p95 | p99 | max |
|-----|-----|-----|-----|-----|
| 3.5ms | 3.4ms | 5.2ms | 6.9ms | 22.0ms |

#### mass_updates improvement (metadata phase)

| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| mass_updates (31,084 rows) | 261.04s | **4.47s** | **58x faster** |
| per-row cost | 8.6ms | 0.14ms | 61x |

#### Combined improvement

| Phase | Before | After |
|-------|--------|-------|
| Update attributes mass_updates | 18.12s | 3.84s |
| Metadata mass_updates | 261.04s | 4.47s |
| **Total mass_updates** | **279.16s** | **8.31s** |
| **Total sync** | **~6m 40s** | **~2m 6s** |

### Run 4: 31,084 images (full sync, after mass_updates + finfo fix)

#### Timeline

| Phase | Duration | Notes |
|-------|----------|-------|
| Directory sync | 4.16s | 0 new categories |
| Filesystem scan | 3.07s | 4 dirs, 33,652 files found, 31,084 matched |
| Update attributes | 3.98s | mass_updates 3.71s |
| **Metadata sync** | **50.82s** | extraction 46.22s + mass_updates 4.35s |
| **Total** | **~1m 2s** | **was ~6m 40s (6.5x faster)** |

#### Metadata sync breakdown (50.8s total)

| Operation | Total | Avg/image | % of phase |
|-----------|-------|-----------|------------|
| `exif` (exif_read_data) | **26.35s** | 0.85ms | **51.8%** |
| `getimagesize` (dimensions) | 8.77s | 0.28ms | 17.3% |
| `filesize` | 8.60s | 0.28ms | 16.9% |
| **Extraction subtotal** | **46.22s** | 1.49ms | **90.9%** |
| **mass_updates** | **4.35s** | 0.14ms/row | **8.6%** |

#### Per-image extraction stats

| avg | p50 | p95 | p99 | max |
|-----|-----|-----|-----|-----|
| 1.5ms | 1.4ms | — | — | 22.5ms |

#### finfo elimination impact

| Metric | Run 3 (with finfo) | Run 4 (no finfo) | Improvement |
|--------|--------------------|--------------------|-------------|
| Metadata sync total | 114.29s | **50.82s** | **2.2x** |
| Extraction loop | 109.54s | **46.22s** | **2.4x** |
| finfo | 59.69s | **0s** | **eliminated** |
| Avg per image | 3.5ms | **1.5ms** | **2.3x** |

---

## `finfo` optimization — implemented

### Root cause

**Location**: `admin/inc/functions_metadata_admin.php:196`

The code created `new \finfo(FILEINFO_MIME_TYPE)` and called `$finfo->file($file)` on every image during metadata sync, solely to check if the file is an SVG. This read file headers for MIME detection — 1.92ms per image, 59.69s total for 31k images, over half the extraction time.

### Fix

Replaced MIME detection with a file extension check (`pathinfo($file, PATHINFO_EXTENSION)`). SVG files have `.svg`/`.svgz` extensions — no need to read file headers to determine this.

### Changes made

- `admin/inc/functions_metadata_admin.php`: Replaced `new \finfo(FILEINFO_MIME_TYPE)` + `$finfo->file()` with `pathinfo()` extension check.

---

## `getimagesize` consolidation — implemented

### Root cause

For each image, `getimagesize()` was called multiple times:

1. In `get_sync_metadata()` — for width/height dimensions
2. In `get_exif_data()` — to check APP1 segment before calling `exif_read_data()`
3. In `get_iptc_data()` (if IPTC enabled) — to extract APP13 segment for `iptcparse()`

Each call re-opens and re-reads the file. The second call was hidden inside the "exif" profiling bucket, making `exif` appear to cost 26s when `exif_read_data()` itself was only ~15s.

### Fix

Call `getimagesize($file, $imginfo)` once in `get_sync_metadata()`. The `$imginfo` array (containing APP1 and APP13 segments) is passed to `get_exif_data()` and `get_iptc_data()` via new optional `$imginfo` parameters. When provided, they skip their internal `getimagesize()` calls. The display path (no `$imginfo` passed) falls back to calling `getimagesize()` internally — no breakage.

### Changes made

- `admin/inc/functions_metadata_admin.php`: Single `getimagesize($file, $imginfo)` call, pass `$imginfo` to `get_sync_exif_data()` and `get_sync_iptc_data()`.
- `inc/functions_metadata.php`: Added optional `$imginfo` parameter to `get_exif_data()` and `get_iptc_data()`.

### Run 7: 31,084 images (after getimagesize consolidation)

| Operation | Run 6 (before) | Run 7 (after) | Improvement |
|-----------|---------------|---------------|-------------|
| getimagesize | 9.10s | 8.53s | (single call) |
| exif | 26.36s | **14.77s** | **44% faster** |
| filesize | 8.59s | 8.23s | — |
| **Extraction total** | **46.60s** | **34.55s** | **26% faster** |
| mass_updates | 4.09s | 4.05s | — |
| **Metadata sync total** | **50.95s** | **38.84s** | **24% faster** |
| avg/image | 1.5ms | **1.1ms** | 27% faster |

---

## Remaining bottlenecks

### N+1 tag lookup queries

**Location**: `admin/inc/functions_admin.php:1772-1839`

Each tag name extracted from EXIF/IPTC metadata triggers `tag_id_from_tag_name()`, which runs **up to 4 sequential SQL queries** (exact name, url_name, plugin sub-name, INSERT). There's an in-memory cache (line 1780), but it only helps when the same tag appears on multiple images.

**Impact**: Not triggered in any test (no tags in EXIF, IPTC disabled). Would add significant DB load when enabled.

### Representative/format file probing

**Location**: `admin/LocalSiteReader.php:176-213`

For every non-picture file, `get_representative_ext()` loops through all `$conf->picture_ext` calling `is_file()` on each. Similarly, `get_formats()` loops through `$conf->format_ext`.

**Impact**: Not triggered in any test (all JPEGs). Would matter for mixed collections with RAW/video files.

### Recursive `array_merge` during directory scan

**Location**: `admin/LocalSiteReader.php:122-125`

The recursive scan uses `array_merge($fs, $tmp_fs)` at each recursion level, which copies the entire accumulated array each time. With deep directory hierarchies, this is O(n^2) in total elements.

---

## Current EXIF/IPTC implementation

### PHP built-in functions

All metadata extraction uses PHP built-ins. Three functions do the work:

| Function | Where | What it extracts |
|----------|-------|-----------------|
| `exif_read_data()` | `inc/functions_metadata.php:130`, `admin/inc/pwg_image.php:284` | EXIF tags (DateTimeOriginal, GPS, Orientation, Make, Model, etc.) |
| `getimagesize()` | 10+ call sites | Width, height, image type, and APP1/APP13 binary segments |
| `iptcparse()` | `inc/functions_metadata.php:35` | IPTC keywords, caption, author, date from APP13 segment |

PHP's `exif` extension is a C wrapper around libexif. It's fast per-call but there's no batch mode — each call opens, reads, and closes the file independently.

### Two distinct use cases

1. **Sync pipeline** (`site_update.php:830`) — bulk metadata extraction for all images. Per image it calls `getimagesize()` 2-3x, `exif_read_data()` once, and `iptcparse()` once (if IPTC enabled). All serial.

2. **Single-image** — rotation detection during upload (`pwg_image.php:284`) and live EXIF display on picture page (`inc/functions_metadata.php:130`). One-shot, not a bottleneck.

### Default config

Only `DateTimeOriginal` is mapped for EXIF sync (`Config.php:394-396`). IPTC is disabled by default. GPS coordinates are always extracted when EXIF is enabled. The display path (`show_exif_fields`) reads Make, Model, DateTimeOriginal, and ApertureFNumber live from files.

### Plugin hooks to preserve

- **`format_exif_data`** (`inc/functions_metadata.php:133,142`) — plugins can override/supplement EXIF data after `exif_read_data()` or provide it when it fails.
- **`clean_iptc_value`** (`inc/functions_metadata.php:88`) — plugins can handle non-standard IPTC encodings (e.g. MacRoman).

---

## ExifTool in stay_open mode — evaluated and rejected

### Hypothesis

ExifTool in stay_open mode would collapse `filesize` + `getimagesize` + `exif_read_data` (3 file accesses) into 1 ExifTool call per image, reducing the 46s extraction loop.

### Implementation

Created `inc/ExifTool.php` — a stay_open wrapper using `proc_open`. ExifTool launches once, filenames are written to stdin, JSON results are read from stdout via sentinel markers. Integrated into `get_sync_metadata()` with automatic fallback to PHP built-ins when ExifTool is unavailable.

### Run 5: 31,084 images (with ExifTool stay_open)

| Operation | PHP built-ins (Run 4) | ExifTool stay_open (Run 5) |
|-----------|----------------------|---------------------------|
| Extraction total | **46.2s** (1.5ms/img) | **480.8s** (15.5ms/img) |
| mass_updates | 4.4s | 4.2s |
| **Metadata sync total** | **50.8s** | **485.4s** |

**ExifTool is 10x slower** — 15.5ms per image vs 1.5ms with PHP built-ins.

### Why ExifTool is slower

The overhead is **inter-process communication**, not metadata reading:

- Each file requires: write args to stdin → flush → read stdout line-by-line until sentinel → JSON decode
- The PHP↔ExifTool round-trip via pipes costs ~15ms per file on Windows
- PHP's `exif_read_data()` and `getimagesize()` are direct C function calls with zero IPC overhead — ~0.85ms and ~0.28ms respectively

ExifTool stay_open is designed for scenarios where the alternative is launching a new process per file (~100ms startup). PHP's built-in EXIF functions have no process overhead, so stay_open provides no benefit.

### Conclusion

The PHP built-in extraction at 46s (1.5ms/image) for 31k images is already near-optimal. The three remaining operations (`exif_read_data` 26s, `getimagesize` 9s, `filesize` 9s) are direct C function calls — there is no faster way to read this data from PHP without a custom C extension.

The ExifTool wrapper (`inc/ExifTool.php`) and integration remain in the codebase but disabled by default (`$conf->exiftool_path = ''`). It could still be useful for formats that PHP's EXIF extension doesn't handle well.

---

## Filesize-based skip for unchanged files — implemented

### Root cause

On re-sync, the metadata loop extracted `getimagesize()` + `exif_read_data()` from every file even when nothing changed. The update attributes phase ran `mass_updates` on all 31k rows even when `representative_ext` was unchanged.

### Fix

1. **`get_filelist()` now fetches `filesize` from the DB** along with id/path/representative_ext.
2. **`get_sync_metadata()` compares `filesize()` on disk with stored filesize.** If they match, returns `null` immediately — skipping `getimagesize()` and `exif_read_data()` entirely.
3. **Update attributes phase compares `representative_ext`** before/after and only includes changed rows in the `mass_updates` batch.

### Run 9: 31,084 images (re-sync, filesize skip without cache)

| Phase | Run 8 (before) | Run 9 (with skip) | Improvement |
|-------|---------------|-------------------|-------------|
| Directory sync | 4.28s | 4.36s | — |
| Filesystem scan | 3.19s | 3.14s | — |
| Update attributes | **3.67s** | **0.25s** | **14.7x** (0 rows changed) |
| Metadata sync | **36.08s** | **7.79s** | **4.6x** (31k files skipped) |
| **Total** | **~44s** | **~15.6s** | **2.8x** |

The metadata phase still spent 7.7s calling `filesize()` on 31k files. All files skipped, but the stat calls were redundant — the scan phase had already stat'd every file via `is_file()`.

---

## Scan-phase filesize cache — implemented

### Root cause

The filesystem scan calls `is_file()` on every file, which internally does a `stat()`. Then the metadata phase calls `filesize()` on the same files — a second `stat()` per file. On Windows, each stat costs ~0.25ms, so 31k redundant stats = 7.8s.

### Fix

1. **Capture `filesize()` during the scan** — right after `is_file()`, PHP's stat cache makes this nearly free.
2. **Store in `$fs` array** as `fs_filesize` alongside `representative_ext`.
3. **Build `$fs_sizes` map** after the scan, keyed by path.
4. **Inject cached filesize** into `$element_infos` before calling `get_sync_metadata()`.
5. **`get_sync_metadata()` uses cached value** — skips the `filesize()` syscall entirely when `fs_filesize` is present.

Also fixed `get_fs_directories()` `array_merge` → `array_push(...spread)` for the same O(n²) recursion issue.

### Run 10: 31,084 images (re-sync, with filesize cache)

| Phase | Run 9 (no cache) | Run 10 (with cache) | Improvement |
|-------|-----------------|---------------------|-------------|
| Directory sync | 4.36s | 4.16s | — |
| Filesystem scan | 3.14s | 3.07s | — |
| Update attributes | 0.25s | 0.23s | — |
| **Metadata sync** | **7.79s** | **0.18s** | **43x** |
| **Total** | **~15.6s** | **~7.7s** | **2x** |

The metadata phase now does zero filesystem calls — all 31k filesize comparisons use the cached values from the scan phase.

### Changes made

- `admin/LocalSiteReader.php`: `get_elements()` captures `filesize()` per file (free via stat cache from `is_file()`).
- `admin/site_update.php`: Builds `$fs_sizes` map from scan results, injects into metadata loop.
- `admin/inc/functions_metadata_admin.php`: `get_sync_metadata()` uses `fs_filesize` when available, skips `filesize()` call.
- `admin/inc/functions_admin.php`: Fixed `get_fs_directories()` `array_merge` → `array_push(...spread)`.

---

## Summary of optimizations

| # | Status | Fix | Savings |
|---|--------|-----|---------|
| ~~1~~ | Done | ~~Optimize `mass_updates` (multi_query batching)~~ | ~~279s -> 8.3s (34x)~~ |
| ~~2~~ | Done | ~~Eliminate `finfo` MIME detection~~ | ~~59.7s -> 0s~~ |
| ~~3~~ | Done | ~~Consolidate `getimagesize` calls~~ | ~~46.6s -> 34.5s (26%)~~ |
| ~~4~~ | Done | ~~Gate SQL logging, batch tags, scandir cache, conditional profiling~~ | ~~47s -> 44s~~ |
| ~~5~~ | Done | ~~Skip unchanged files via filesize comparison~~ | ~~44s -> 15.6s (re-sync)~~ |
| ~~6~~ | Done | ~~Cache filesizes from scan phase~~ | ~~15.6s -> 7.7s (re-sync)~~ |
| ~~7~~ | Rejected | ~~ExifTool stay_open~~ | ~~10x slower than PHP built-ins~~ |

### Final sync times (31k images)

| Phase | Original | First sync | Re-sync (no changes) |
|-------|----------|------------|----------------------|
| Directory sync | 5.4s | 4.3s | 4.2s |
| Filesystem scan | 4.5s | 3.1s | 3.1s |
| Update attributes | 18.4s | **3.7s** | **0.23s** |
| Metadata extraction | 84-110s | **31.9s** | **0.08s** (cached filesize check) |
| Metadata mass_updates | 261.0s | **4.1s** | **0s** (skipped) |
| **Total** | **~6m 40s** | **~44s** | **~7.7s** |
| **Improvement** | | **9.1x** | **52x** |

---

## `mass_inserts` optimization — implemented

### Root cause

**Location**: `inc/dblayer/functions_mysqli.php:447-513`

Two problems with the original `mass_inserts`:

1. **O(n²) memory allocation** — Line 494 did `strlen($queryBase . $query . ', ' . $queryTemp)` which created a temporary concatenation of the entire query string on every iteration just to check the length. For 297k rows, this built progressively larger temporary strings (up to ~60MB), causing massive memory churn.

2. **No batch size cap** — The only limit was `max_allowed_packet` (typically 64MB). This meant 297k rows were crammed into one or two enormous INSERT statements. MySQL had to parse a 60MB+ query, and PHP spent minutes building the string.

3. **No transaction wrapping** — Each batch query triggered an InnoDB commit with fsync.

4. **Missing value escaping** — Values were interpolated without `pwg_db_real_escape_string()`.

### Fix

- **Incremental length tracking** — track `$currentLen` as an integer instead of recomputing via string concatenation
- **Batch size cap at 1000 rows** — each INSERT sends ~200KB, MySQL parses instantly. For 297k rows = 297 fast queries instead of 1-2 enormous ones
- **Transaction wrapping** — `START TRANSACTION` / `COMMIT` around all batches (one fsync at the end)
- **Value escaping** — added `pwg_db_real_escape_string()` with `(string)` cast to handle int/float values
- **Progress callback** — optional `$on_progress` closure parameter, called after each batch flush. Used to emit SSE events during long inserts, keeping the connection alive

### Bug found: TypeError crash

**Root cause**: `pwg_db_real_escape_string()` is typed as `?string` but `mass_inserts` passes integer values (e.g., category `id`, `sort_rank`). The original code didn't escape and relied on PHP's implicit int-to-string cast in string interpolation.

**Fix**: Cast to `(string)` before escaping: `pwg_db_real_escape_string((string) $insert[$dbfield])`.

**Symptom**: PHP fatal error killed the sync process silently mid-run. The SSE stream ended without a `complete` event, leaving the UI stuck with a spinning phase and frozen timer.

### Changes made

- `inc/dblayer/functions_mysqli.php`: Rewrote `mass_inserts` with batch size cap, incremental length tracking, transaction wrapping, value escaping with string cast, and optional progress callback.

---

## Real-time sync progress UI — implemented

### Problem

The sync page used a synchronous form POST. The user clicked "Synchronize", the page hung for seconds to minutes with no feedback, then results appeared.

### Architecture: Server-Sent Events (SSE)

The form submission is intercepted by JavaScript, which opens a `fetch()` stream to the same page with `?sse=1`. The PHP backend streams events as the sync progresses, and the frontend updates a progress panel in real-time.

**SSE event types:**

| Event | Purpose | Data |
|-------|---------|------|
| `phase_start` | Top-level phase begins | `{phase: "dirs"\|"files"\|"meta"}` |
| `phase_progress` | Real-time update within a phase | `{phase, dir, files_so_far, ...}` |
| `phase_complete` | Phase finished | `{phase, elapsed, new, deleted, ...}` |
| `substep_start` | Sub-operation begins | `{phase, id, label}` |
| `substep_progress` | Sub-operation update | `{phase, id, detail}` |
| `substep_complete` | Sub-operation finished | `{phase, id, detail, elapsed}` |
| `complete` | Sync finished | `{simulate, update: {...}, metadata: {...}}` |
| `error` | Fatal error | `{message}` |

**PHP side** (`admin/site_update.php`):
- `sync_emit()` function outputs SSE events (no-op when not in SSE mode)
- SSE headers set, output buffering disabled, session released
- `ignore_user_abort(false)` so PHP stops on connection close
- Exits before template rendering with final results

**JavaScript** (`site_update.tpl`):
- Intercepts form submit, opens `fetch()` stream with `ReadableStream` reader
- Parses SSE events from chunked text response
- Dynamically builds phase and substep elements with spinners/checkmarks
- Progress bar for metadata extraction with percentage and file count
- Live elapsed timer (global + per active substep)
- Pause button (stops reading stream, server blocks on full buffer)
- Abort button (cancels fetch via `AbortController`)
- Falls back to normal POST if JavaScript disabled

**Progress callbacks in filesystem operations:**
- `get_fs_directories()` and `get_elements()` accept optional `?\Closure $on_dir` callback
- Called when entering each directory during recursive scan
- Emits full directory path to SSE stream for real-time display

### UI structure

Phases show as top-level items with substeps indented below, each with individual elapsed time:

```
✓ Scanning directories  no changes                              4.2s
✓ Scanning and syncing files  no changes                        4.7s
    ✓ Scanning filesystem  328,752 files found                 119.8s
    ✓ Loading database records  31,084 records                   0.1s
    ✓ Comparing filesystem vs database  297,668 new              0.1s
    ✓ Inserting 297,668 new photos  297,668 photos inserted     12.3s
    ✓ Checking for deleted files  no deletions                   0.1s
    ✓ Building filesize cache  328,752 entries                   0.2s
    ✓ Updating album metadata  done                              0.3s
    ✓ Checking file attributes  no changes                       1.1s
✓ Syncing metadata  0 updated, 31,084 skipped                   0.2s
    ✓ Loading file list  31,084 candidates                       0.1s
    ✓ Extracting metadata  0 updated, 31,084 skipped             0.1s
      ████████████████████████████████  100%
    ✓ Updating database  0 rows, 0 tagged                        0.0s
```

### SSE connection stability fixes

- **Connection drop during long DB operations**: Browser's TCP stack times out if no SSE events arrive for ~2 minutes. Previously this cleared the timer and hid controls silently. Now shows "waiting for server..." and keeps timers running.
- **`mass_inserts` progress callback**: Emits SSE events every 1000 rows inserted, keeping the connection alive during large inserts.
- **Stream end without `complete` event**: If PHP crashes (e.g., fatal error), the stream ends cleanly but without a `complete` event. Now detected and shown as an error: "Server process ended unexpectedly."
- **Tab visibility**: Browser throttles `setInterval` in background tabs. Added `visibilitychange` handler to force-update elapsed timers when tab becomes visible.
- **Live substep timer**: Running substeps show a ticking elapsed timer, updated on each 100ms tick alongside the global timer.

### Bug found: files phase elapsed time

The `$t_fs_scan` variable was used as both the start timestamp and the elapsed duration (overwritten on line 508). The phase complete event computed `microtime(true) - $t_fs_scan` which produced `current_timestamp - 3_seconds` ≈ 1.7 billion seconds. Fixed by introducing separate `$t_files_phase` variable.

### Changes made

- `admin/site_update.php`: SSE mode detection, `sync_emit()` function, progress events throughout all sync phases and substeps, SSE exit before template rendering
- `admin/themes/default/template/site_update.tpl`: Progress panel HTML/CSS (dark theme), JavaScript SSE stream handler, phase/substep rendering, pause/abort controls, elapsed timers
- `admin/LocalSiteReader.php`: `$on_dir` callback parameter on `get_elements()` and `get_full_directories()`
- `admin/inc/functions_admin.php`: `$on_dir` callback parameter on `get_fs_directories()`
- `inc/dblayer/functions_mysqli.php`: `$on_progress` callback parameter on `mass_inserts()`

---

## Large collection profiling (328k files)

### Run 11: 328,752 files (first sync, partial — before mass_inserts fix)

Tested with a larger collection to identify scaling bottlenecks beyond 31k images.

| Substep | Duration | Notes |
|---------|----------|-------|
| Scanning filesystem | **119.8s** | 328,752 files across many directories |
| Loading database records | 0.1s | 31,084 existing records |
| Comparing fs vs db | 0.1s | 297,668 new, 31,084 existing |
| Inserting 297,668 new photos | **crashed** | TypeError: `pwg_db_real_escape_string()` received int |

### Scaling observations (31k → 328k)

| Metric | 31k files | 328k files | Scale factor (10.6x more) |
|--------|-----------|------------|--------------------------|
| Filesystem scan | 3.1s | **119.8s** | **38.6x** (superlinear) |
| DB record load | 0.1s | 0.1s | 1x (same 31k DB rows) |
| Diff | instant | 0.1s | — |

The filesystem scan scales superlinearly — 38.6x slower for 10.6x more files. This is expected on Windows where `readdir` + `is_file` + `stat` per file has high per-call overhead. This is now the dominant bottleneck at scale.

### Bottleneck analysis at 328k+ scale

| Bottleneck | Current time | Root cause | Potential fix |
|------------|-------------|------------|---------------|
| Filesystem scan | ~120s | Sequential `readdir` + `stat` per file, Windows FS overhead | Parallel workers scanning different top-level directories |
| mass_inserts (297k rows) | TBD (was crashing) | Fixed: batch size + escaping | Should be ~30s with 1000-row batches |
| Metadata extraction (297k new files) | TBD | Sequential `exif_read_data` + `getimagesize` per file | Parallel worker processes |
| Directory scan | ~60s+ | Sequential `readdir` + `is_dir` across many directories | Parallel workers |

### Path forward: parallelism

For collections beyond 100k files, the bottleneck shifts from database operations (now optimized) to filesystem I/O. PHP's single-threaded model limits throughput. Two approaches:

1. **`proc_open()` workers** — Spawn N PHP processes, each scanning a subset of directories. Communicate via temp files. Works on Windows, no extensions needed.
2. **Language rewrite** — Go with goroutines would make parallel scanning trivial. See `docs/rewrite-architecture.md`.

---

## Summary of all optimizations

| # | Status | Fix | Savings |
|---|--------|-----|---------|
| ~~1~~ | Done | ~~Optimize `mass_updates` (multi_query batching)~~ | ~~279s → 8.3s (34x)~~ |
| ~~2~~ | Done | ~~Eliminate `finfo` MIME detection~~ | ~~59.7s → 0s~~ |
| ~~3~~ | Done | ~~Consolidate `getimagesize` calls~~ | ~~46.6s → 34.5s (26%)~~ |
| ~~4~~ | Done | ~~Gate SQL logging, batch tags, scandir cache, conditional profiling~~ | ~~47s → 44s~~ |
| ~~5~~ | Done | ~~Skip unchanged files via filesize comparison~~ | ~~44s → 15.6s (re-sync)~~ |
| ~~6~~ | Done | ~~Cache filesizes from scan phase~~ | ~~15.6s → 7.7s (re-sync)~~ |
| ~~7~~ | Rejected | ~~ExifTool stay_open~~ | ~~10x slower than PHP built-ins~~ |
| ~~8~~ | Done | ~~Optimize `mass_inserts` (batch size cap, transaction, escaping)~~ | ~~minutes → seconds for 297k rows~~ |
| ~~9~~ | Done | ~~Real-time sync progress UI (SSE streaming)~~ | ~~UX: live progress instead of blank page~~ |

### Final sync times (31k images)

| Phase | Original | First sync | Re-sync (no changes) |
|-------|----------|------------|----------------------|
| Directory sync | 5.4s | 4.3s | 4.2s |
| Filesystem scan | 4.5s | 3.1s | 3.1s |
| Update attributes | 18.4s | **3.7s** | **0.23s** |
| Metadata extraction | 84-110s | **31.9s** | **0.08s** (cached filesize check) |
| Metadata mass_updates | 261.0s | **4.1s** | **0s** (skipped) |
| **Total** | **~6m 40s** | **~44s** | **~7.7s** |
| **Improvement** | | **9.1x** | **52x** |

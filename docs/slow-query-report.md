# Slow Query Report

**Log analyzed:** `_data/sql/show_queries.log`  
**Total queries in log:** 12,973  
**Queries ≥ 50ms (excluding dim UPDATEs):** 478  
**Dimension UPDATE queries:** 12,231 (94% of all queries in log)

---

## Issue 1 — Lazy dimension UPDATEs ⚠️ CRITICAL

**Total cost:** 57s SQL + ~450s file I/O = **~500s per page load**  
**Query count:** 12,231 individual queries

```sql
UPDATE images SET width = 1280, height = 853 WHERE id = 1388424;
```

`SrcImage::get_size()` calls `getimagesize()` on disk for every image with no stored
dimensions, then fires an individual UPDATE per image. 10.38M images have NULL
width/height (synced without metadata). At ~0.005s each (file I/O + SQL), this
triggers per page load until every image has been displayed at least once.

**Root cause:** `inc/SrcImage.php:149` — lazy fill on display.  
**Images affected:** 10,383,121 out of 10,402,220.

**Fixes:**
1. Remove `getimagesize()` + UPDATE from `SrcImage::get_size()` — return null when
   dimensions are absent; all callers already handle null.
2. Populate `width`/`height` during sync from Everything SDK properties
   `PROPERTY_ID_WIDTH (13)` and `PROPERTY_ID_HEIGHT (14)`.
3. Add a maintenance action to batch-populate dimensions for existing images.

---

## Issue 2 — `representative_picture_id` LIKE queries

**Total cost:** 15.6s across **164 queries** per page (0.065–0.200s each)

```sql
SELECT representative_picture_id
FROM categories
INNER JOIN user_cache_categories ON id = cat_id AND user_id = 1
WHERE uppercats LIKE '5,389112,389116,389127,%'
  AND representative_picture_id IS NOT NULL
ORDER BY CAST(random() AS text) LIMIT 1;
```

**EXPLAIN:** `Parallel Seq Scan on categories` — full table scan on every call.

**Why:** The column is `varchar(255)` but queried with an explicit `::text` cast
(`(uppercats)::text ~~ '...'`), which prevents the planner from using any btree
index. Additionally, `ORDER BY CAST(random() AS text)` forces evaluating all
matching rows before sorting, even with LIMIT 1.

**`uppercats` max length is only 43 chars** — the data fits comfortably in an
indexed text column.

**Fixes:**
1. Add `text_pattern_ops` index so prefix LIKE queries can use it:
   ```sql
   CREATE INDEX idx_categories_uppercats ON categories (uppercats text_pattern_ops);
   ```
2. Replace `ORDER BY CAST(random() AS text)` with `ORDER BY random()` — the cast
   to text is unnecessary and forces a different sort path.
3. Consider caching the representative image per category rather than selecting
   randomly on every page load.

---

## Issue 3 — `SELECT DISTINCT` on large album (category_id = 6)

**Cost:** 30.361s for a single page load (80 thumbnails)

```sql
SELECT DISTINCT image_id, date_creation, file, id
FROM image_category
INNER JOIN images ON id = image_id
WHERE category_id = 6
ORDER BY date_creation DESC, file ASC, id ASC
LIMIT 80 OFFSET 0;
```

**Category 6 row count:** 30,302 images.

**EXPLAIN:** `Parallel Index Scan Backward using idx_images_date_creation on images`
— the planner scans ALL 10M images in date_creation order (using the index to
avoid a sort), then does a nested-loop lookup per image to check if it belongs to
category 6. Cost is O(total_images) instead of O(category_images).

**Why:** The `DISTINCT` causes the planner to prefer the sorted index scan over
scanning image_category by category_id first. The query effectively visits all
4M images in date order before finding 30k matches.

**Fixes:**
1. Remove `DISTINCT` — `(image_id, category_id)` is the primary key of
   `image_category`, so duplicates are impossible. This is the same pattern as
   issue 4 and 5 below.
2. After removing DISTINCT the planner should use `idx_image_category_category_id`
   → scan 30k rows → join → sort 30k → LIMIT 80. Expected time: <100ms.

---

## Issue 4 — `SELECT DISTINCT` on "Recently Added" (no LIMIT)

**Cost:** 61.121s — the second most expensive single query

```sql
SELECT DISTINCT id, date_available, date_creation, file
FROM images
INNER JOIN image_category AS ic ON id = ic.image_id
WHERE date_available >= LEAST(CURRENT_DATE - INTERVAL '7 days', CURRENT_DATE - INTERVAL '1 days')
ORDER BY date_available DESC, date_creation DESC, file ASC, id ASC;
```

**Problems:**
- No LIMIT — returns all images added in the past 7 days; on a 10M collection
  this could be tens of thousands of rows.
- `DISTINCT` on a join that can produce duplicates (one image in multiple
  categories) forces a full dedup of the result set.
- The join to `image_category` exists only to filter out images with no category,
  which inflates the result set before dedup.

**Fixes:**
1. Remove `DISTINCT` and rewrite the join as `EXISTS`:
   ```sql
   SELECT id, date_available, date_creation, file
   FROM images
   WHERE date_available >= ...
     AND EXISTS (SELECT 1 FROM image_category WHERE image_id = images.id)
   ORDER BY ...
   LIMIT 200;  -- add a sane limit
   ```
2. `idx_images_date_available` already exists — with no DISTINCT the index scan
   can return sorted results and stop at LIMIT without scanning all matching rows.

---

## Issue 5 — Search with `LIKE '%...%'`

**Cost:** 81.675s — the single most expensive query in the log

```sql
SELECT DISTINCT id, date_creation, file, id
FROM images i
INNER JOIN image_category AS ic ON id = ic.image_id
LEFT JOIN image_tag AS it ON id = it.image_id
WHERE (
  file   LIKE '%a%' OR
  name   LIKE '%a%' OR
  comment LIKE '%a%' OR
  author  LIKE '%a%' OR
  id IN (1, 2, 3, …)  -- tag match: huge list
)
ORDER BY date_creation DESC, file ASC, id ASC;
```

**Problems:**
- Leading `%` in all LIKE patterns = full seq scan on `images` (10M rows).
- DISTINCT + multi-join further multiplies the cost.
- The `id IN (huge list)` is a tag match injected as literals — for a
  single-char query like `'a'` this list is enormous.
- The `image_ft` GIN index exists (`tsvector` on file/name/comment/author) but
  this query does not use it.

**Fixes:**
1. Replace `LIKE '%q%'` with the existing FTS index:
   ```sql
   WHERE image_fts @@ plainto_tsquery('english', 'search_term')
   ```
   Note: FTS doesn't support single-character or substring searches.
2. For substring search (where FTS is insufficient), add a `pg_trgm` GIN index:
   ```sql
   CREATE EXTENSION IF NOT EXISTS pg_trgm;
   CREATE INDEX idx_images_name_trgm   ON images USING GIN (name   gin_trgm_ops);
   CREATE INDEX idx_images_file_trgm   ON images USING GIN (file   gin_trgm_ops);
   CREATE INDEX idx_images_comment_trgm ON images USING GIN (comment gin_trgm_ops);
   ```
   With `pg_trgm`, `LIKE '%a%'` can use the GIN index.
3. Remove DISTINCT; apply LIMIT.

---

## Issue 6 — Large `IN (...)` on `image_category` / `categories`

**Cost:** 2.191s + 0.993s

```sql
-- 2.191s
SELECT image_id FROM image_category
WHERE category_id IN (1, 15, 36, …hundreds of IDs…);

-- 0.993s
SELECT c.* FROM categories AS c
INNER JOIN user_cache_categories ON c.id = cat_id AND user_id = 1
WHERE id IN (1, 15, 36, …hundreds of IDs…);
```

Both have the right indexes (`idx_image_category_category_id`, `categories_pkey`).
The cost is proportional to the number of matching rows — not an index miss.

**Fix:** These queries are generating IDs from a recursive `get_subcat_ids()` call
that returns all descendant albums for the root. Result should be cached in
`user_cache_categories` (which already exists) or in the PHP session for the
duration of the request.

---

## Issue 7 — `categories` flat-list queries (repeated per expand)

**Total cost:** 1.255s across 53 queries (0.142–0.318s each)

```sql
SELECT …
FROM categories INNER JOIN user_cache_categories ON id = cat_id AND user_id = 1
WHERE (id_uppercat IS NULL OR id_uppercat IN (5, 268, 504));
```

**EXPLAIN:** Uses `idx_categories_id_uppercat` — this is the right plan.  
The cost comes from the size of `user_cache_categories` and the join. These queries
are fired once per level of the album tree being expanded. 53 occurrences in one
session suggests the tree is being rebuilt repeatedly rather than cached.

**Fix:** Cache the assembled category tree in the PHP session or in
`user_cache_categories` for the duration of the request rather than rebuilding it
on every page.

---

## Issue 8 — `SELECT id, name FROM languages` (0.145s)

```sql
SELECT id, name FROM languages ORDER BY name ASC;
```

Full scan on `languages` table. This is a small, static table that never changes
at runtime. The cost is just I/O and no index on `name`.

**Fix:** Cache the result in `$conf` or in a PHP static variable — this can be
called once per process.

---

## Issue 9 — `SELECT id, name, permalink FROM categories` (0.174s)

```sql
SELECT id, name, permalink FROM categories;
```

Full table scan, no filter. Used to build a flat map of all categories (for
breadcrumb / permalink resolution). With ~390k categories this returns a large
result set.

**Fix:** Cache in `$conf` or session for the request lifetime. Same data is
read multiple times per page load.

---

## Issue 10 — `INSERT INTO sessions` (0.067–0.168s, multiple per request)

Session writes hit 100–170ms on the large session blob. The `data` column stores
a serialized PHP session as text including large arrays (`cache_activity_last_weeks`).

**Fix:** Remove large transient data from the session (move `cache_activity_last_weeks`
to a short-lived DB cache or compute it on demand). Smaller session blobs write faster.

---

## Summary Table

| # | Pattern | Occurrences | Total time | Priority | Fix type |
|---|---------|-------------|------------|----------|----------|
| 1 | Lazy dimension UPDATE | 12,231 | ~500s | **Critical** | Remove lazy load; sync from Everything |
| 2 | `uppercats LIKE` seq scan | 164 | 15.6s | High | Add `text_pattern_ops` index |
| 3 | DISTINCT album browse (cat 6) | 1 | 30.4s | High | Remove DISTINCT |
| 4 | DISTINCT recently-added (no LIMIT) | 1 | 61.1s | High | EXISTS + LIMIT |
| 5 | LIKE '%q%' search | 1 | 81.7s | High | pg_trgm indexes |
| 6 | Large IN on image_category | 2 | 3.2s | Medium | Cache subcategory IDs |
| 7 | Category tree rebuild | 53 | 1.3s | Medium | Cache tree per request |
| 8 | Languages full scan | recurring | 0.15s | Low | Static cache |
| 9 | Categories full scan | recurring | 0.17s | Low | Request-level cache |
| 10 | Large session writes | recurring | 0.1–0.17s | Low | Trim session data |

---

## Indexes to create now

```sql
-- Issue 2: enables prefix LIKE on uppercats
CREATE INDEX idx_categories_uppercats ON categories (uppercats text_pattern_ops);

-- Issue 5: substring search on images (requires pg_trgm extension)
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE INDEX idx_images_name_trgm    ON images USING GIN (name    gin_trgm_ops);
CREATE INDEX idx_images_file_trgm    ON images USING GIN (file    gin_trgm_ops);
CREATE INDEX idx_images_comment_trgm ON images USING GIN (comment gin_trgm_ops);
CREATE INDEX idx_images_author_trgm  ON images USING GIN (author  gin_trgm_ops);
```

Issues 3 and 4 (DISTINCT) and Issue 1 (SrcImage) require code changes, not indexes.

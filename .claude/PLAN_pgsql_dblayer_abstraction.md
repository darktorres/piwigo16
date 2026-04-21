# Abstract MySQL-isms into dblayer + Add PostgreSQL COPY Support

## Context

The codebase has two DB backends (`functions_mysqli.php`, `functions_pgsql.php`) but ~11 MySQL-specific SQL constructs are hardcoded in business logic files, breaking PostgreSQL. Additionally, the MySQL backend recently gained `LOAD DATA LOCAL INFILE` bulk insert support; the PostgreSQL backend needs an equivalent via `COPY FROM STDIN`. These changes make the pgsql backend actually functional for normal operations and add fast bulk inserts.

---

## Phase 1: New dblayer methods (both `functions_mysqli.php` and `functions_pgsql.php`)

Add these public static methods with identical signatures in both backends:

### 1. `index_exists(string $table, string $index_name): bool`
- **MySQL:** `SHOW INDEX FROM $table WHERE Key_name = '$index_name'` + num_rows > 0
- **PgSQL:** `SELECT 1 FROM pg_indexes WHERE tablename = '$table' AND indexname = '$index_name'` + num_rows > 0
- Replaces: `site_update.php:713`

### 2. `drop_index(string $table, string $index_name): void`
- **MySQL:** `ALTER TABLE $table DROP INDEX $index_name`
- **PgSQL:** `DROP INDEX IF EXISTS $index_name`
- Replaces: `site_update.php:716`

### 3. `create_fulltext_index(string $table, string $index_name, array $columns): void`
- **MySQL:** `ALTER TABLE $table ADD FULLTEXT INDEX $index_name (col1, col2)`
- **PgSQL:** Lookup tsvector column from static map, `CREATE INDEX $index_name ON $table USING GIN($fts_col)`
- PgSQL needs a private `$FTS_COLUMN_MAP`: `['images' => 'image_fts', 'categories' => 'category_fts', 'tags' => 'tag_fts']`
- Replaces: `site_update.php:896`

### 4. `set_bulk_insert_mode(bool $enable): void`
- **MySQL:** `SET unique_checks=$v, foreign_key_checks=$v`
- **PgSQL:** No-op (no equivalent session variables; constraint checking is already efficient within transactions)
- Replaces: `site_update.php:656,889`

### 5. `add_enum_value(string $table, string $column, string $new_value): void`
- **MySQL:** Read existing values via `get_enums()`, rebuild full enum definition with `ALTER TABLE CHANGE`
- **PgSQL:** `ALTER TYPE {$table}_{$column} ADD VALUE IF NOT EXISTS '$new_value'`
- Replaces: `functions.php:601`

### 6. `sql_group_concat(string $column): string`
- **MySQL:** returns `GROUP_CONCAT($column)`
- **PgSQL:** returns `STRING_AGG($column::text, ',')`
- Replaces: `batch_manager.php:400`, `pwg_tags.php:153`

### 7. `get_table_names(): array`
- **MySQL:** `SHOW TABLES`
- **PgSQL:** `SELECT tablename FROM pg_tables WHERE schemaname = 'public'`
- Replaces: `functions.php:3237`

### 8. `get_table_columns(string $table): array`
- **MySQL:** `DESC $table` → collect `Field` column
- **PgSQL:** `SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='$table' ORDER BY ordinal_position`
- Replaces: `functions.php:3262` (`get_columns_of`), `functions_history.php:443`

### 9. `sql_fulltext_clause(array $fields, array $fts_terms, string $table): string`
- **MySQL:** `MATCH(name, comment) AGAINST('term1 term2' IN BOOLEAN MODE)` — ignores `$table`
- **PgSQL:** Resolve tsvector column from `$FTS_COLUMN_MAP[$table]`, translate terms: `"phrase"` → `word1 <-> word2`, `term*` → `term:*`, join with `|`, produce `$fts_col @@ to_tsquery('english', '...')`
- Replaces: `functions_search.php:560`

### 10. `sql_regex_word_boundary(): array`
- **MySQL:** Cache version check; `\\\\b` for MySQL 8.0.4+ (ICU), `[[:<:]]`/`[[:>:]]` for older/MariaDB
- **PgSQL:** `['begin' => '\\y', 'end' => '\\y']` (POSIX word boundary)
- Replaces: `functions_search.php:525-539`

### 11. Make `sync_sequences()` public
- **MySQL:** Add as public no-op
- **PgSQL:** Change existing private method to public
- Callers managing their own transactions (site_update.php) call this after COMMIT

### 12. Uncomment `pwg_db_cast_to_text(string $string): string`
- **MySQL:** `return "CAST({$string} AS CHAR)"`
- **PgSQL:** `return $string` (VARCHAR already text-compatible; `CAST(x AS CHAR)` would truncate to 1 char in PgSQL)
- Replaces: `functions_search.php:589`

---

## Phase 2: Update pgsql `mass_inserts()` for parity

File: `inc/dblayer/functions_pgsql.php` — method at line 431

1. Add `?\Closure $on_progress = null` parameter (match mysqli signature)
2. Add `BEGIN`/`COMMIT` transaction wrapping (currently auto-commits each statement)
3. Support `$options['no_transaction']` — skip `BEGIN`/`COMMIT`, skip `sync_sequences()`
4. Support `$options['bulk']` — accept but no-op (no equivalent optimization)
5. When `no_transaction` is NOT set: call `sync_sequences()` after COMMIT (current behavior)
6. When `no_transaction` IS set: skip `sync_sequences()` — caller handles it

---

## Phase 3: Add COPY FROM STDIN bulk load to pgsql

File: `inc/dblayer/functions_pgsql.php`

### `write_tsv_row($fp, array $row, array $dbfields): void`
Copy verbatim from `functions_mysqli.php` — identical TSV format (tab-delimited, `\N` for NULL, escaped special chars).

### `load_data_local(string $tsv_path, string $table_name, array $dbfields): bool`
Use `COPY ... FROM STDIN` + `pg_put_line()` for streaming:
```
1. Open $tsv_path for reading
2. pg_query($pg, "COPY $table ($cols) FROM STDIN WITH (FORMAT text, NULL '\\N')")
3. Stream lines via pg_put_line()
4. pg_put_line($pg, "\\.\n") + pg_end_copy($pg)
5. Return success/failure
```
Empty file (probe) → `COPY FROM STDIN` with immediate `\.` terminator → returns true, enabling the fast path. The existing site_update.php code works unchanged since it already probes via `method_exists('load_data_local')`.

---

## Phase 4: Update business logic callers

### `admin/site_update.php`
- Line 656: `pwg_query('SET unique_checks=0...')` → `set_bulk_insert_mode(true)`
- Line 713: `pwg_query("SHOW INDEX...")` → `index_exists('images', 'image_ft')`
- Line 716: `pwg_query('ALTER TABLE images DROP INDEX image_ft')` → `drop_index('images', 'image_ft')`
- Line 889: `pwg_query('SET unique_checks=1...')` → `set_bulk_insert_mode(false)`
- Line 896: `pwg_query('ALTER TABLE images ADD FULLTEXT INDEX...')` → `create_fulltext_index('images', 'image_ft', ['name', 'comment'])`
- After final COMMIT (both LOAD DATA and mass_inserts paths): add `$conf->sql_backend::sync_sequences()`

### `inc/functions.php`
- Line 601: Raw `ALTER TABLE CHANGE` → `add_enum_value('history', 'section', $page['section'])`
- Lines 3236-3243 (`get_tables()`): `SHOW TABLES` → `return $conf->sql_backend::get_table_names()`
- Lines 3260-3271 (`get_columns_of()`): `DESC $table` → `$conf->sql_backend::get_table_columns($table)`

### `admin/batch_manager.php`
- Line 400: `GROUP_CONCAT(id)` → `$conf->sql_backend::sql_group_concat('id')`

### `inc/ws_functions/pwg_tags.php`
- Line 153: `GROUP_CONCAT(tag_id)` → `$conf->sql_backend::sql_group_concat('tag_id')`

### `admin/inc/functions_history.php`
- Line 443: `SHOW COLUMNS FROM history LIKE "summarized"` → `in_array('summarized', $conf->sql_backend::get_table_columns('history'))`

### `inc/functions_search.php`
- Lines 525-539: MySQL ICU version check → `$conf->sql_backend::sql_regex_word_boundary()`
- Line 560: `MATCH(...) AGAINST(...)` → `$conf->sql_backend::sql_fulltext_clause($fields, $fts, $table)`
- Add `string $table = ''` parameter to `qsearch_get_text_token_search_sql()` — thread from callers (line 594: `'images'`, line 603: `'images'`, line 686: `'tags'`, line 782: `'categories'`)
- Line 589: `CAST(file AS CHAR)` → `$conf->sql_backend::pwg_db_cast_to_text('file')`

---

## Phase 5: PostgreSQL tsvector triggers

File: `install/piwigo_structure-pgsql.sql`

The pgsql schema has `tsvector` columns and GIN indexes but **no triggers** to populate them. Without triggers, FTS columns stay NULL and search never returns results. Add `BEFORE INSERT OR UPDATE` triggers:

- `images`: `to_tsvector('english', coalesce(NEW.name,'') || ' ' || coalesce(NEW.comment,''))` → `image_fts`
- `categories`: same pattern → `category_fts`
- `tags`: `to_tsvector('english', coalesce(NEW.name,''))` → `tag_fts`

These triggers fire during COPY FROM STDIN, so the `load_data_local()` path populates tsvector automatically. The GIN index is dropped before bulk inserts and rebuilt after — exactly the right pattern (tsvector data written, index deferred).

---

## Files modified

| File | Changes |
|------|---------|
| `inc/dblayer/functions_mysqli.php` | 12 new methods |
| `inc/dblayer/functions_pgsql.php` | 12 new methods + mass_inserts parity + load_data_local + write_tsv_row + sync_sequences public |
| `admin/site_update.php` | 5 MySQL-isms → dblayer calls + sync_sequences after COMMIT |
| `inc/functions.php` | 3 MySQL-isms → dblayer calls |
| `inc/functions_search.php` | MATCH AGAINST + word boundary + CAST → dblayer calls |
| `admin/batch_manager.php` | GROUP_CONCAT → dblayer call |
| `inc/ws_functions/pwg_tags.php` | GROUP_CONCAT → dblayer call |
| `admin/inc/functions_history.php` | SHOW COLUMNS → dblayer call |
| `install/piwigo_structure-pgsql.sql` | Add tsvector population triggers |

---

## Verification

1. `php -l` syntax check on all modified PHP files
2. MySQL sync: run a sync and verify LOAD DATA path still works (check log for "LOAD DATA LOCAL INFILE available")
3. MySQL search: verify quick search returns results (MATCH AGAINST path)
4. PostgreSQL: connect with pgsql backend, verify `load_data_local()` probe passes and COPY path activates
5. PostgreSQL search: verify `sql_fulltext_clause` generates valid tsquery and returns search results
6. Check that `sync_sequences()` leaves IDENTITY sequences correct after bulk inserts
7. Run `phpstan` to catch type/signature mismatches

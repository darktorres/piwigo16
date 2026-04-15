# Everything SDK Properties for Piwigo Sync

Configure Everything (voidtools) to index these properties so that sync can read
file metadata from the index instead of opening every file.

Enable via: **Tools → Options → Properties → Index Properties**

---

## Property Reference

Property IDs are from `admin/inc/Everything3.h` (SDK v3, stable — will not change).

### Already in use

| ID | Name | Piwigo field | Type |
|----|------|--------------|------|
| 2 | `SIZE` | `filesize` | UINT64 (bytes) |

### Enable for zero-I/O sync

#### Dimensions (eliminates `getimagesize()` on every page load and during sync)

| ID | Name | Piwigo field | Type |
|----|------|--------------|------|
| 13 | `WIDTH` | `width` | DWORD (pixels) |
| 14 | `HEIGHT` | `height` | DWORD (pixels) |

#### Date & GPS (eliminates EXIF parsing for the most commonly used fields)

| ID | Name | Piwigo field | Type | Notes |
|----|------|--------------|------|-------|
| 52 | `DATE_TAKEN` | `date_creation` | UINT64 | Windows FILETIME; from EXIF DateTimeOriginal |
| 91 | `LATITUDE` | `latitude` | INT32_FIXED_Q1M | Divide by 1,000,000 to get decimal degrees |
| 92 | `LONGITUDE` | `longitude` | INT32_FIXED_Q1M | Divide by 1,000,000 to get decimal degrees |
| 201 | `ORIENTATION` | `rotation` | WORD | EXIF orientation 1–8 |

#### Camera EXIF (if `use_exif` + `use_exif_mapping` is configured)

| ID | Name | Piwigo EXIF key | Type |
|----|------|-----------------|------|
| 63 | `CAMERA_MAKER` | `make` | PSTRING |
| 64 | `CAMERA_MODEL` | `model` | PSTRING |
| 65 | `F_STOP` | `fnumber` | INT32_FIXED_Q1K |
| 66 | `EXPOSURE_TIME` | `exposure` | INT32_FIXED_Q1K |
| 67 | `ISO_SPEED` | `iso` | WORD |
| 69 | `FOCAL_LENGTH` | `focal_length` | INT32_FIXED_Q1K |

#### IPTC / content metadata (if `use_iptc` is configured)

| ID | Name | Piwigo IPTC key | Type |
|----|------|-----------------|------|
| 25 | `TITLE` | `ObjectName` (name) | PSTRING |
| 42 | `DESCRIPTION` | `Caption-Abstract` (comment) | PSTRING |
| 36 | `TAGS` | `Keywords` | PSTRING_MULTISTRING (semicolon-separated) |
| 51 | `AUTHORS` | `By-line` | PSTRING_MULTISTRING |
| 55 | `COPYRIGHT` | `CopyrightNotice` | PSTRING |

#### Video

| ID | Name | Purpose | Type |
|----|------|---------|------|
| 18 | `LENGTH` | Duration | UINT64 (100 ns units; divide by 10,000,000 for seconds) |

---

## Implementation Plan

Everything indexes these properties once in the background when files are
added or modified. After that, all SDK queries are instant — no file I/O
during sync.

### Changes needed in code

1. **`admin/EverythingSDK.php`**
   - Add `PROPERTY_*` constants for each new ID
   - Extend `executeSearch()` / add a new `queryPathsWithMeta()` method that
     requests and returns the additional properties

2. **`admin/EverythingSiteReader.php`**
   - Call the new SDK method instead of `queryPathsWithSize()`
   - Include `fs_width`, `fs_height`, `fs_date_taken`, `fs_latitude`,
     `fs_longitude`, `fs_orientation` in the yielded `$entry` array

3. **`admin/site_update.php`** (basic sync)
   - Add `width` and `height` to `$image_fields`
   - Populate from `$meta['fs_width']` / `$meta['fs_height']` in `$insert`
   - No `getimagesize()` call needed

4. **`admin/inc/functions_metadata_admin.php`** (metadata sync)
   - When `fs_date_taken`, `fs_latitude`, etc. are present in `$infos`,
     use them directly instead of parsing EXIF from the file
   - `getimagesize()` call can be skipped when `fs_width`/`fs_height` present

5. **`inc/SrcImage.php`**
   - Remove the lazy `getimagesize()` + UPDATE in `get_size()` — once sync
     populates dimensions, this fallback is never needed
   - Return `null` when dimensions are absent; all callers already handle `null`

---

## Priority Order

| Priority | Properties | Reason |
|----------|-----------|--------|
| 1 (critical) | WIDTH, HEIGHT | Eliminates 527 s page loads from lazy `getimagesize()` on 10 M images |
| 2 | DATE_TAKEN | The one EXIF field everyone uses; saves EXIF parse per metadata sync |
| 3 | LATITUDE, LONGITUDE, ORIENTATION | Rest of the useful per-photo EXIF |
| 4 | Camera EXIF | Only if `use_exif_mapping` is configured |
| 5 | IPTC fields | Only if `use_iptc` is on |
| 6 | LENGTH | Only relevant for video files |

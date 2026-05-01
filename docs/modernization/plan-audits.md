# Modernization plan status audits

Living record of per-plan completion checks. Each entry is a snapshot of how much of a planned migration actually landed.

Audit date: 2026-05-01. Branch: `16.x-rewrite`.

---

## `move-php-libs-to-cached-iverson.md` — Eliminate `window.*` globals via ES module static imports

**Status: ~95% complete.** All three waves landed in substance — every `window.*` global the plan targets is gone from live code (remaining mentions are only in `docs/modernization/globals-removal.md` and `MODERNIZATION_PLAN.md` retrospectives).

### What's done

**Wave 1 — TS→TS globals (`AlbumSelector` + `pwgDoubleSlider`)** ✓

- `window.AlbumSelector` removed from `album_selector.ts`; all 6 consumers (`batchManagerFilter`, `batchManagerGlobal`, `batchManagerUnit`, `cat_modify`, `photos_add_direct`, `picture_modify`) use ES imports
- `mcs.ts` AlbumSelector guard block removed
- `doubleSlider.ts` moved from `admin/themes/default/js/` → `themes/default/js/`, window assignment dropped, both consumers (`batchManagerFilter`, `mcs`) import statically
- `combine_script id='album_selector'` and `combine_script id='doubleSlider'` purged from templates
- `album_selector` Vite entry removed from `vite.config.ts`

**Wave 2 — Template-instantiated `LocalStorageCache` classes** ✓ (mostly)

- All 5 `window.{LocalStorageCache,CategoriesCache,TagsCache,GroupsCache,UsersCache} = …` assignments deleted from `LocalStorageCache.ts`
- All 7 template+TS pairs migrated (`batch_manager_global`, `batch_manager_unit`, `group_list`, `picture_modify`, `user_activity`, `cat_perm`, `rating`)
- New entries created: `cat_perm.ts` (vite.config.ts:44) and `rating_admin` (vite.config.ts:63 — wired to `admin/themes/default/js/rating.ts`)

**Wave 3 — Piecon (legacy JS library)** ✓

- `themes/default/js/plugins/piecon.js` converted to ES module (`const Piecon = (() => {...})(); export default Piecon;`)
- `photos_add_direct.ts:8` does `import Piecon from '../../../../themes/default/js/plugins/piecon';` — no `declare var`, no `typeof` guard
- `combine_script id='piecon'` removed from `photos_add_direct.tpl`

### What's still pending

Three small loose ends:

1. **`admin/themes/default/template/photos_add_direct.tpl:9`** still has stale `{combine_script id='LocalStorageCache' load='footer' path='admin/themes/default/js/LocalStorageCache.js'}` — Wave 2 cleaned this from every other template but missed this one. The `.js` path no longer resolves and the import is now redundant (whatever the page's TS imports gets auto-loaded as a Vite shared chunk).
2. **`vite.config.ts:36`** still lists `'LocalStorageCache': r('admin/themes/default/js/LocalStorageCache.ts')` as a standalone entry. Plan implied removing it (parallel to the `album_selector` entry removal that did happen) so it becomes an auto-shared chunk. Keeping it just produces an unused bundle output; not breakage.
3. **`vite.config.ts:27`** still lists `'doubleSlider': r('themes/default/js/doubleSlider.ts')` as a standalone entry. Plan said "remove from admin entries — let it become a shared chunk" — admin entry was indeed removed during the file move, but a new frontend entry was added in its place. Same dead-bundle concern as #2.

---

## `i-added-a-few-zesty-adleman.md` — Test coverage expansion

**Status: ~100% complete.** Every planned file exists, the unit suite runs green at 218 tests, and only minor distribution differences from the plan text.

### What's done

| Phase | Status |
|---|---|
| 1 — Bootstrap stubs (`char_to_fraction`, `get_pwg_charset`, `trigger_notify`, `trigger_change`) | ✓ Present in `tests/bootstrap.php` lines 91, 99, 107, 113 |
| 2 — Auth (`PwgBase32Test`, `PwgTOTPTest`) | ✓ Both files in `tests/Unit/Auth/` |
| 3 — Search (5 files) | ✓ `QSingleTokenTest`, `QSearchScopeTest`, `QNumericRangeScopeTest`, `QDateRangeScopeTest`, `QExpressionTest` all in `tests/Unit/Search/` |
| 4 — Image geometry (3 files) | ✓ `ImageRectTest`, `SizingParamsTest`, `DerivativeParamsTest` in `tests/Unit/Image/` |
| 5 — Users / Menu / WS encoders (10 files) | ✓ All planned files plus 2 bonus (`PwgErrorTest`, `PwgServerTest`) under `tests/Unit/Ws/` |
| 6 — Integration `WsApiTest` | ✓ 9 tests (plan said ~8) covering version, session login + status, categories list, tags list, image search, users list with/without auth, full categories add/delete lifecycle. `IntegrationTestCase.php` base class also created. |
| 7 — Photo lifecycle E2E | ✓ `09-photo-upload.spec.ts` (3 tests), `10-photo-album-lifecycle.spec.ts` (1 bundled CRUD test, intentional per plan) |
| 8 — Content management E2E | ✓ `11-search.spec.ts` (3 tests), `12-tags.spec.ts` (2 tests, bundled CRUD + admin-page smoke), `13-user-management.spec.ts` (3 tests) |
| 9 — Extended admin smoke | ✓ `14-admin-extended-smoke.spec.ts` (6 tests) |
| Helpers | ✓ `tests/e2e/helpers/upload-photo.ts` exists |

### Test counts vs estimates

- Plan estimated **~140 new unit tests** on top of an 84-test baseline → 224 expected
- Actual: **218 unit tests, all passing** (`vendor/bin/phpunit --testsuite Unit`)

### Minor distribution variations from plan text

Two cosmetic differences, neither a gap:

1. **Page-smoke distribution between specs 12/13/14.** Plan put `user_list`, `group_list`, `tags` admin-page smoke under spec 14. Actual: those moved into the matching feature specs (12-tags, 13-user-management) where they're more cohesive. Spec 14 picked up `photo`, `comments`, `batch_manager`, `stats`, `rating`, `permalinks` instead. Same total page coverage; more readable groupings.
2. **CRUD bundling in specs 10 and 12.** Plan was clear about bundling for spec 10 ("Full CRUD in one spec to keep ordering safe"). Spec 12 followed the same pattern (bundling `tags.add` + `images.setInfo` + `tags.delete` into one `'create tag, assign to photo, delete tag'` test). Plan said ~4 tests; actual is 2 tests covering the same operations.

### What I couldn't verify here

- **Integration suite** — needs `docker compose up -d --wait db web`; Docker is not currently up in this environment. Tests exist and parse; have not confirmed they pass.
- **E2E suite** — needs Playwright + DB reset; same constraint.

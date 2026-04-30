# Plan: Eliminate `window.*` globals via ES module static imports

## Context

The codebase already uses Vite entry points with code splitting and manifest-driven `<script type="module">` tags. The only reason globals exist is that separate `<script type="module">` entries can't `import` from each other at runtime — so `window.*` became the workaround. Since the browser's module loader automatically fetches all `import`-ed chunks when a parent entry loads, static imports eliminate globals entirely with no PHP/ScriptLoader changes.

**Critical infrastructure fact:** ScriptLoader emits `<script type="module" src="dist/entry.js">` tags. The browser fetches all `import`-ed chunks automatically from the module graph — ScriptLoader never needs to know about chunks. Removing a `combine_script` call from a template is safe as long as another loaded entry already imports the needed code.

---

## Globals inventory

| Global | Set in | Consumed by | Type |
|--------|--------|-------------|------|
| `window.AlbumSelector` | `album_selector.ts:604` | `batchManagerFilter.ts`, `batchManagerGlobal.ts`, `batchManagerUnit.ts`, `cat_modify.ts`, `photos_add_direct.ts`, `picture_modify.ts` (all via `(window as any).AlbumSelector`) | TS→TS |
| `window.pwgDoubleSlider` | `doubleSlider.ts:62` | `batchManagerFilter.ts:76`, `mcs.ts` (6 usages) | TS→TS, cross-theme |
| `window.CategoriesCache` | `LocalStorageCache.ts:273` | `batch_manager_global.tpl`, `batch_manager_unit.tpl`, `picture_modify.tpl`, `rating.tpl` footer scripts | TS→template |
| `window.TagsCache` | `LocalStorageCache.ts:274` | `batch_manager_global.tpl`, `batch_manager_unit.tpl`, `picture_modify.tpl` footer scripts | TS→template |
| `window.GroupsCache` | `LocalStorageCache.ts:275` | `cat_perm.tpl` footer script | TS→template |
| `window.UsersCache` | `LocalStorageCache.ts:276` | `group_list.ts:5` (`declare var`), `group_list.tpl`, `cat_perm.tpl`, `user_activity.tpl` footer scripts | TS→TS + template |
| `window.LocalStorageCache` | `LocalStorageCache.ts:272` | No usage found (plugin safety net) | unused |
| `window.Piecon` | `themes/default/js/plugins/piecon.js` | `photos_add_direct.ts:8` (`declare var`, guarded) | TS→legacy JS |

---

## Wave 1: TS→TS globals (AlbumSelector + pwgDoubleSlider)

No page-data migration needed. Pure import/export wiring.

### 1a. `window.AlbumSelector`

**`admin/themes/default/js/album_selector.ts`**
- Remove line 604: `(window as any).AlbumSelector = AlbumSelector;`
- `AlbumSelector` is already `export class` — no other change needed here

**6 consumer TS files** — same pattern in each:
- Add: `import { AlbumSelector } from './album_selector';`
- Replace: `new (window as any).AlbumSelector({...})` → `new AlbumSelector({...})`
- Files: `batchManagerFilter.ts`, `batchManagerGlobal.ts`, `batchManagerUnit.ts`, `cat_modify.ts`, `photos_add_direct.ts`, `picture_modify.ts`

**`themes/default/js/mcs.ts`**
- The `if ((window as any).AlbumSelector)` guard was added as a workaround — remove the entire AlbumSelector block since the frontend search page never has an album selector UI

**Templates** — remove now-redundant combine_script calls:
- `admin/themes/default/template/batch_manager_global.tpl` line 7: remove `{combine_script id='album_selector' ...}`
- `admin/themes/default/template/include/album_selector.inc.tpl`: remove `{combine_script id='album_selector' ...}` line (HTML template + page-data element stay)

**`vite.config.ts`**
- Remove `album_selector` from `rollupOptions.input` — it becomes a Vite shared chunk automatically since 6 entries import it

### 1b. `window.pwgDoubleSlider`

`doubleSlider.ts` is in `admin/` but `mcs.ts` (frontend) also needs it. Move to the frontend layer.

**Move file:** `admin/themes/default/js/doubleSlider.ts` → `themes/default/js/doubleSlider.ts`

**`themes/default/js/doubleSlider.ts`** (after move)
- Remove line 62: `(window as any).pwgDoubleSlider = pwgDoubleSlider;`
- Add `export` keyword to the function declaration

**`admin/themes/default/js/batchManagerFilter.ts`**
- Add: `import { pwgDoubleSlider } from '../../../themes/default/js/doubleSlider';`
- Remove: `const doubleSlider = (window as any).pwgDoubleSlider as ...;`
- Replace all `doubleSlider(...)` calls → `pwgDoubleSlider(...)`

**`themes/default/js/mcs.ts`**
- Add: `import { pwgDoubleSlider } from './doubleSlider';`
- Replace all 6 `(window as any).pwgDoubleSlider(...)` calls → `pwgDoubleSlider(...)`

**Template:** `admin/themes/default/template/include/batch_manager_filter.inc.tpl` line 1: remove `{combine_script id='doubleSlider' ...}`

**`vite.config.ts`**
- Remove `doubleSlider` from admin entries in `rollupOptions.input`
- No need to add a new entry — it becomes a chunk shared by `batchManagerFilter` and `mcs`

---

## Wave 2: Template-instantiated LocalStorageCache classes

Pattern for each template+TS pair:
1. PHP controller already has `CACHE_KEYS` and `ROOT_URL` — add them to the page-data JSON if not present
2. In the TS file: `import { CategoriesCache, TagsCache, ... } from './LocalStorageCache';`
3. In TS `DOMContentLoaded`: read config from `getPageData()`, instantiate caches, call `.selectize()` on the right DOM elements
4. Remove the `{footer_script}` cache block from the template
5. Remove `{combine_script id='LocalStorageCache' ...}` from the template

**Affected pairs:**
- `batch_manager_global.tpl` → `batchManagerGlobal.ts` (TagsCache, CategoriesCache)
- `batch_manager_unit.tpl` → `batchManagerUnit.ts` (TagsCache, CategoriesCache)
- `group_list.tpl` → `group_list.ts` (UsersCache — `declare var UsersCache` already exists at line 5; change to static import; remove template footer block)
- `picture_modify.tpl` → `picture_modify.ts` (CategoriesCache, TagsCache)
- `user_activity.tpl` → `user_activity.ts` (UsersCache)
- `cat_perm.tpl` → **create new** `admin/themes/default/js/cat_perm.ts` entry + add to `vite.config.ts` (GroupsCache, UsersCache)
- `rating.tpl` → **create new** `admin/themes/default/js/rating.ts` entry + add to `vite.config.ts` (CategoriesCache) — distinct from `rating_user.ts`

After all 7 pairs are migrated, remove lines 272–276 from `LocalStorageCache.ts`:
```typescript
// DELETE:
window.LocalStorageCache = LocalStorageCache as any;
window.CategoriesCache = CategoriesCache as any;
window.TagsCache = TagsCache as any;
window.GroupsCache = GroupsCache as any;
window.UsersCache = UsersCache as any;
```

---

## Wave 3: Piecon (legacy JS library)

`photos_add_direct.ts:8` has `declare var Piecon: any` with a `typeof Piecon !== 'undefined'` guard. The library is at `themes/default/js/plugins/piecon.js`.

Convert `piecon.js` to an ES module (add `export default Piecon;` at the end), then:
- `photos_add_direct.ts`: replace `declare var Piecon` with `import Piecon from '../../../themes/default/js/plugins/piecon';`
- Remove `typeof Piecon !== 'undefined'` guards (import guarantees presence)
- Remove `{combine_script id='piecon' ...}` from `photos_add_direct.tpl`

---

## Critical files

**Wave 1:**
- `admin/themes/default/js/album_selector.ts` — remove window assignment
- `admin/themes/default/js/batchManagerFilter.ts`, `batchManagerGlobal.ts`, `batchManagerUnit.ts`, `cat_modify.ts`, `photos_add_direct.ts`, `picture_modify.ts` — add imports
- `admin/themes/default/js/doubleSlider.ts` → move to `themes/default/js/doubleSlider.ts`
- `themes/default/js/mcs.ts` — add import, remove guard block
- `admin/themes/default/template/batch_manager_global.tpl` — remove album_selector combine_script
- `admin/themes/default/template/include/album_selector.inc.tpl` — remove album_selector combine_script
- `admin/themes/default/template/include/batch_manager_filter.inc.tpl` — remove doubleSlider combine_script
- `vite.config.ts` — remove `album_selector` and `doubleSlider` entries

**Wave 2:** all of the above + 7 template+TS pairs + `LocalStorageCache.ts` lines 272–276

**Wave 3:** `themes/default/js/plugins/piecon.js`, `photos_add_direct.ts`, `photos_add_direct.tpl`

---

## Verification

After Wave 1:
1. `npm run build` — zero errors
2. `npm run typecheck` — zero errors
3. Browser: `admin.php?page=batch_manager` — double slider works in filter tab, album selector works in all three tabs (global/unit/filter)
4. Browser: `admin.php?page=cat_modify&cat_id=1` — album selector opens
5. Browser: `admin.php?page=photos_add` — album selector works
6. Browser: frontend search page (`index.php?/search`) — sliders work (height, width, filesize)
7. Zero `window.*` assignments remaining in admin JS (verify with grep)

After Wave 2:
8. Browser: `admin.php?page=batch_manager` — tags and category Tom Select selects load
9. Browser: `admin.php?page=cat_perm&cat_id=1` — groups and users selects load
10. Browser: `admin.php?page=group_list` — user search select loads
11. No `combine_script id='LocalStorageCache'` remaining in any template

After Wave 3:
12. Browser: `admin.php?page=photos_add` — upload progress shows in favicon (Piecon)

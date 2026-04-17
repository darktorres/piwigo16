# Plan: Real CRUD validation + test quality overhaul

## Status: Implementation complete — linting / quality hardening done

---

## Context

The original Playwright suite (4 flat test files) was mostly UI-state testing with no DB
verification, ~20 `waitForTimeout` calls, silent `test.skip()` everywhere, and hardcoded IDs.
All of that has been replaced.

---

## What was done

### 1. Test infrastructure (`tests/src/helpers/`) ✅

Split `helpers.ts` into focused modules:

- **`helpers/auth.ts`** — `loginAsAdmin()`, reads `PIWIGO_USER`/`PIWIGO_PASS` from `.env`.
- **`helpers/console.ts`** — `collectConsoleIssues()`, `assertNoErrors()`. Uses `??` not `||` on nullable failure text.
- **`helpers/fixtures.ts`** — `ensureTestPhotoExists()`, `uniqueName(prefix)` → `test_${prefix}_${Date.now()}_${rand}`. Uses `/regex/.exec()` not `.match()`.
- **`helpers/db.ts`** — Synchronous `psql` wrapper via `execSync`. Exports: `dbCount`, `dbFindGroup`, `dbFindUser`, `dbFindTag`, `dbFindAlbum`, `dbFindComment`, `dbGetPhotoAuthor`, `dbGetFirstPhotoId`, `dbGetFirstPhysicalAlbumId`, `dbGetFirstGroupId`, `dbGetAdminUserId`, `dbCleanupTestEntities`. Cleanup throws on SQL failure (no silent swallowing).
- **`helpers/index.ts`** — re-exports everything.
- **`helpers/global-teardown.ts`** — calls `dbCleanupTestEntities()` (non-async, no await needed).
- **`helpers.ts`** — backward-compat shim for `install.test.ts` only.

### 2. Domain reorganization ✅

```
tests/src/
├── helpers/
├── install.test.ts
├── smoke/
│   ├── admin.test.ts       (smokeCheck helper, dynamic IDs from DB)
│   └── gallery.test.ts     (smokeCheck helper, dynamic IDs from DB)
├── admin/
│   ├── groups.test.ts
│   ├── users.test.ts
│   ├── tags.test.ts
│   ├── albums.test.ts
│   ├── batch-manager.test.ts
│   ├── rating.test.ts
│   └── interactions.test.ts
└── gallery/
    ├── rating.test.ts
    ├── comments.test.ts
    ├── navigation.test.ts
    └── interactions.test.ts
```

Old flat files (`admin-interactions.test.ts`, `gallery-interactions.test.ts`,
`admin-smoke.test.ts`, `gallery-smoke.test.ts`) deleted.

### 3. CRUD tests with DB verification ✅

| Domain | Tests | DB check |
|---|---|---|
| **Groups** | create → verify → delete → verify gone | `SELECT id FROM user_groups WHERE name=?` |
| **Users** | create → verify → delete → verify gone | `SELECT id FROM users WHERE username=?` |
| **Tags** | Selectize widget init; create → verify → delete → verify gone | `SELECT id FROM tags WHERE name=?` |
| **Albums** | create virtual → verify → delete → verify gone | `SELECT id FROM categories WHERE name=? AND dir IS NULL` |
| **Comments** | form visible + submit → DB verify (validated flag checked) | `SELECT id, validated FROM comments WHERE image_id=? AND author=?` |
| **Batch-manager** | 12 UI-state tests + apply author → DB verify → restore | `SELECT author FROM images WHERE id=?` |
| **Gallery rating** | click star as admin → verify DB → cleanup | `SELECT rate FROM rate WHERE element_id=? AND user_id=?` |
| **User permissions** | create test user + private category, grant via UI → verify → revoke → verify gone | `SELECT 1 FROM user_access WHERE user_id=? AND cat_id=?` |

### 4. Quality fixes ✅

- All `test.skip()` and `if (condition) { test.skip() }` patterns replaced with hard `await expect(locator).toBeVisible()`.
- All `{ force: true }` removed; hidden toggle checkbox replaced with `label.switch span.slider` click.
- All `waitForTimeout()` replaced: navigation uses `waitForLoadState('load')`, infinite-scroll uses `waitForResponse`.
- All tautological `if (x.count() > 0) { expect(x).toBeVisible() }` unwrapped to unconditional assertions.
- Hardcoded IDs replaced with `dbGetFirstPhotoId()`, `dbGetFirstPhysicalAlbumId()`, `dbGetAdminUserId()`, `dbGetFirstGroupId()`.
- Hook order fixed: `beforeEach` before `afterAll` in every describe block.
- `async` removed from hooks that have no `await`.

### 5. Cleanup & isolation ✅

- `playwright.config.ts` has `globalTeardown: './src/helpers/global-teardown.ts'`.
- Each CRUD domain has `test.afterAll(() => dbCleanupTestEntities())`.
- All created entities named `test_${domain}_${Date.now()}_${rand}` — swept by LIKE `test\_%\_%\_%`.

### 6. Linting / type-checking ✅

- `tests/tsconfig.json` — strict TypeScript, `NodeNext` modules, `noEmit`.
- `tests/eslint.config.mjs` — full rule set:
  - `tseslint.configs.strictTypeChecked` + `stylisticTypeChecked`
  - `eslint-plugin-playwright` recommended + additional rules:
    - `playwright/prefer-to-be`, `prefer-strict-equal`, `prefer-comparison-matcher` — assertion quality
    - `playwright/no-commented-out-tests` — no dead test code
    - `playwright/require-top-level-describe` — enforced structure
  - `@typescript-eslint/no-explicit-any`: error
  - `@typescript-eslint/restrict-template-expressions`: error, `allowNumber: true`
  - `@typescript-eslint/consistent-type-imports`: error, inline style
  - `no-console`: warn (one intentional `console.warn` in db.ts suppressed with eslint-disable)
- **Current state: 0 errors, 0 warnings.**

### 8. DB helper — MySQL ✅

`db.ts` uses the MySQL 8.4 CLI (`mysql.exe`) via `execSync`. Fixed from an incorrect
PostgreSQL implementation. New helpers added: `dbGetRating`, `dbDeleteRating`,
`dbCreateTestPrivateCategory`, `dbGetUserAccess`.

### 7. Semantic locator migration (partial) ✅

Migrated raw `page.locator()` CSS selectors to Playwright semantic APIs where the underlying
HTML has proper label associations:

- `getByLabel('Group name')`, `getByLabel('Album name')`, `getByLabel('Album')`
- `getByLabel('Username')`, `getByLabel('Password')`, `getByLabel('Email')`
- `getByRole('button', { name: 'Add' })`, `getByRole('button', { name: 'Create' })`
- `getByText('Add a user')`
- `getByPlaceholder('Type here the author name')`

Remaining raw locators (not migrated): dynamic `href` attribute matchers, external library
components (selectize, datepicker, PhotoSwipe), elements with no accessible name (Piwigo
JS-driven divs acting as buttons), and structural queries like
`#thumbnails li:not(.album) a[data-pswp-src]`.

`playwright/no-raw-locators` was evaluated but not enabled: 160 violations would remain after
migrating the approachable ones, because most Piwigo admin selectors target elements with no
ARIA labels. Enabling it would require adding `aria-label` / `data-testid` to PHP templates —
a separate project.

---

## What remains

- [ ] Run the full suite against the live server and confirm pass/fail counts.
- [ ] Consider enabling `playwright/no-raw-locators` after adding `aria-label`/`data-testid` to Piwigo templates for remaining elements.

---

## Running the suite

```bash
bun test                              # full suite
bun test smoke/                       # fast smoke only
bun test admin/groups                 # single domain
bun test:headed admin/tags            # debug with browser
bun test:report                       # open HTML report
```

## Commits (branch `tests2`)

| Hash | Description |
|---|---|
| `d8d2c4b90` | Restructure into domain layout with CRUD + DB verification |
| `499601532` | Add eslint-plugin-playwright, fix all lint errors |
| `1d3d9954b` | Add TypeScript type-checking and ESLint |
| `79e0548a7` | Remove all conditional skips, fix 158 ESLint warnings |
| `5a7ce2471` | Upgrade to strictTypeChecked + stylisticTypeChecked |
| `674197f71` | Add stricter playwright + TypeScript lint rules |
| `62952ccc4` | Let dbCleanupTestEntities throw on SQL failure |
| `2fac5786c` | Migrate raw locators to semantic equivalents |
| `b81699fbb` | Fix DB helper: switch from PostgreSQL to MySQL |
| `e0c206671` | Add DB verification for gallery rating and user permissions |

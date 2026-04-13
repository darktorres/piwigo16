# Coverage Collection Report

This document explains how code coverage collection is implemented in the Piwigo codebase.

## Overview

The codebase implements a **two-tier coverage system**:

1. **PHP Backend Coverage** - Uses Sebastian Bergmann's php-code-coverage library with request-level instrumentation
2. **JavaScript/Browser Testing** - Uses Puppeteer for E2E testing with planned Monocart Reporter integration

---

## PHP Backend Coverage

**STATUS: Partially Implemented - Infrastructure Exists But Not Integrated with Tests**

The coverage infrastructure is built but **not currently connected to the test runner**.

### Current Implementation Gap

**What exists:**
- ✅ `coverage.php` - Full coverage collection implementation
- ✅ Xdebug 3.4.2 installed with `xdebug.mode=coverage` enabled
- ✅ Puppeteer test runner with header/cookie manipulation

**What's missing:**
- ❌ `coverage.php` has **no conditional logic** to check for test headers
- ❌ Puppeteer does **NOT send any coverage-enable header**
- ❌ Coverage requires manual code modification (uncomment line 55)

### How Coverage SHOULD Work (Test-Driven)

**Intended design:**

1. **coverage.php** should check for an HTTP header before starting collection:
   ```php
   // coverage.php - currently missing this logic:
   if (isset($_SERVER['HTTP_X_COVERAGE_ENABLE'])) {
       $coverage_->start($_SERVER['REQUEST_URI']);
       register_shutdown_function('save_coverage');
   }
   ```

2. **Puppeteer** should send the header in requests:
   ```typescript
   // In run-tests.mts handleRequest() - currently missing:
   await interceptedRequest.setExtraHTTPHeaders({
       'X-Coverage-Enable': 'true'
   });
   ```

3. **Result:** Coverage would be automatically enabled **only during test runs**, not for manual browsing

### Current Workaround

To manually enable coverage, uncomment line 55 in `inc/config_default.php`:

```php
// Line 55 - uncomment this:
require_once __DIR__ . '/../coverage.php';
```

**WARNING:** This enables coverage for **ALL requests** (production, manual browsing, etc.), not just tests. This is why it's commented out by default.

### Dependencies

The coverage system relies on the following Composer dev dependencies (`composer.json:22-23`):

```json
{
  "phpunit/php-code-coverage": "^12.3.0",
  "phpunit/phpcov": "^11.0.1"
}
```

### Core Implementation: `coverage.php`

The entire PHP coverage collection is handled by a single file at the project root.

#### Key Components

**1. Filter Configuration (lines 10-280)**

A `Filter` object is created with an explicit whitelist of 278+ PHP files to track:

```php
$filter_ = new Filter();
$filter_->includeFiles([
    __DIR__ . '/about.php',
    __DIR__ . '/action.php',
    __DIR__ . '/admin.php',
    // ... 275+ more files
]);
```

**Included file categories:**
- Core application files (`/about.php`, `/index.php`, `/picture.php`, etc.)
- Admin functionality (`/admin/*.php`, `/admin/inc/*.php`)
- Library/framework code (`/inc/*.php`)
- Web services (`/inc/ws_functions/*.php`, `/inc/ws_protocols/*.php`)
- Plugins (`/plugins/**/*.php`)
- Themes (`/themes/**/*.php`)

**Notable exclusions:**
- Database layer files (except `functions_mysqli.php` and `functions_pgsql.php`)
- Vendor code
- Cache files

**2. Coverage Driver Selection (lines 282-286)**

```php
$coverage_ = new CodeCoverage(
    new Selector()
        ->forLineCoverage($filter_),
    $filter_
);
$coverage_->excludeUncoveredFiles();
```

The `Selector` class automatically detects and uses the best available coverage driver:
- **Xdebug** (preferred) - Most reliable, full feature support
- **PCOV** - Fast, lightweight alternative
- **phpdbg** - Fallback option

The system uses **line coverage** mode (as opposed to branch or path coverage).

**3. Coverage Start (line 289)**

```php
$coverage_->start($_SERVER['REQUEST_URI']);
```

Coverage tracking starts automatically when the file is included, using the current request URI as the test identifier.

**4. Shutdown Handler (lines 291-299)**

```php
function save_coverage(): void
{
    global $coverage_;
    $coverage_->stop();
    new PhpReport()
        ->process($coverage_, 'C:/Apache24/logs/xdebug/coverage/' . bin2hex(random_bytes(16)) . '.cov');
}

register_shutdown_function('save_coverage');
```

Key behaviors:
- Uses PHP's shutdown function to ensure coverage is always saved
- Stops coverage collection
- Saves data as serialized PHP format (`.cov` files)
- Uses random 32-character hex filenames to prevent collisions
- Outputs to `C:/Apache24/logs/xdebug/coverage/`

### Data Flow

```
HTTP Request
    ↓
coverage.php included (start tracking)
    ↓
Application code executes (all lines tracked)
    ↓
PHP shutdown triggered
    ↓
save_coverage() called
    ↓
Coverage data serialized to .cov file
    ↓
Ready for analysis/merging
```

### Output Format

Each request generates a unique `.cov` file containing:
- Serialized `CodeCoverage` object
- Line execution counts per file
- Request URI as test identifier
- Timestamp information

These files can be merged using `phpcov merge` for aggregate reports.

---

## JavaScript/Browser Testing

### Test Runner: `tests/src/run-tests.mts`

The E2E test infrastructure uses Puppeteer (`tests/package.json:14`):

```json
{
  "puppeteer": "^24.10.0"
}
```

#### Test Execution Flow

1. **Browser Launch** (lines 29-34):
   ```typescript
   const browser: Browser = await puppeteer.launch({
       args: ['--no-sandbox'],
       headless: true,
       defaultViewport: { width: 1280, height: 1024 },
       devtools: true,
   });
   ```

2. **Debug Cookie Setup** (lines 38-42):
   ```typescript
   await browserContext.setCookie({
       name: "XDEBUG_SESSION",
       value: "VSCODE",
       domain: "localhost",
   });
   ```
   This enables Xdebug session tracking, integrating with PHP backend coverage.

3. **Page Navigation** - The test runner navigates through:
   - Installation wizard
   - Admin panel sections (Photos, Albums, Users, Plugins, Tools, Configuration)
   - Gallery pages

### Planned: Monocart Reporter Integration

Documentation exists at `tests/MONOCART_README.md` for integrating Monocart Reporter with Playwright. Key planned features:

**V8 Coverage Collection** (from Puppeteer):
```javascript
await page.coverage.startJSCoverage({ resetOnNavigation: false });
await page.coverage.startCSSCoverage({ resetOnNavigation: false });
// ... tests run ...
const jsCoverage = await page.coverage.stopJSCoverage();
const cssCoverage = await page.coverage.stopCSSCoverage();
```

**Report Capabilities:**
- HTML interactive reports with tree-grid display
- JSON data export for CI/CD
- Coverage threshold enforcement
- Trend charts for historical tracking

---

## Coverage Report Generation

### PHP Coverage Reports

Using the collected `.cov` files, reports can be generated via:

```bash
# Merge all coverage files
vendor/bin/phpcov merge --html=coverage/html coverage/

# Or individual formats:
vendor/bin/phpcov merge --clover=coverage.xml coverage/
vendor/bin/phpcov merge --text=coverage.txt coverage/
```

**Available formats:**
| Format | Purpose |
|--------|---------|
| HTML | Interactive visual dashboard |
| Clover XML | CI/CD integration (Codecov, etc.) |
| Text | Command-line summary |
| PHP | Serialized data for further processing |

### JavaScript Coverage Reports

Via Monocart (when implemented):
- HTML reports with drill-down navigation
- JSON data export
- Combined coverage from multiple test runs

---

## Architecture Summary

```
┌─────────────────────────────────────────────────────────────────┐
│                     Coverage Collection                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  PHP Backend                    │  JavaScript/Browser             │
│  ─────────────                  │  ───────────────────            │
│                                 │                                 │
│  coverage.php                   │  run-tests.mts                  │
│       │                         │       │                         │
│       ▼                         │       ▼                         │
│  php-code-coverage              │  Puppeteer                      │
│  (Xdebug/PCOV)                  │  (V8 Coverage API)              │
│       │                         │       │                         │
│       ▼                         │       ▼                         │
│  .cov files                     │  Coverage data                  │
│  (C:/Apache24/logs/             │  (planned: Monocart)            │
│   xdebug/coverage/)             │                                 │
│       │                         │       │                         │
│       ▼                         │       ▼                         │
│  phpcov merge                   │  Monocart Reporter              │
│       │                         │       │                         │
│       ▼                         │       ▼                         │
│  HTML/XML/Text                  │  HTML/JSON Reports              │
│  Reports                        │                                 │
│                                 │                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Important: `_data` Directory and Smarty Templates

A common question is how coverage works given that Piwigo uses compiled templates in `_data/`.

### What `_data` Actually Contains

The `_data` directory does **NOT** contain compiled PHP source code. It contains:

1. **`_data/templates_c/`** - Smarty compiled templates (`.tpl` → `.php`)
2. **`_data/cache/`** - Application cache files
3. **`_data/i/`** - Derivative images

### How Smarty Template Compilation Works

Piwigo uses **Smarty 5.x** as its template engine (`composer.json:17`):

```json
"smarty/smarty": "^5.5.1"
```

**Template Compilation Flow:**

```
themes/default/template/index.tpl
           ↓
     Smarty compiles
           ↓
_data/templates_c/w1q0uw^...file_index.tpl.php
           ↓
     Executed at runtime
```

Example compiled template (`_data/templates_c/*.php`):

```php
/* Smarty version 5.5.1, created on 2025-11-22 12:34:42
  from 'file:index.tpl' */
function content_6921ade27720b1_98193649 (\Smarty\Template $_smarty_tpl) {
    // ... generated PHP code from template ...
}
```

### Why Compiled Templates Are NOT in Coverage

The `coverage.php` filter **explicitly lists only source PHP files**, not compiled templates:

```php
$filter_->includeFiles([
    __DIR__ . '/about.php',
    __DIR__ . '/inc/Template.php',
    // ... 278+ source files
    // NO _data/ or templates_c/ files
]);
```

**Reason:** Coverage is about testing application **logic**, not generated template rendering code. The compiled templates are:
- Auto-generated (not hand-written code)
- Derivative of `.tpl` files (which are presentation, not logic)
- Highly variable (regenerated when templates change)

### What IS Covered vs. What Is NOT

| Covered | Not Covered |
|---------|-------------|
| `/inc/Template.php` (template engine wrapper) | `_data/templates_c/*.php` (compiled templates) |
| `/inc/functions.php` (business logic) | `_data/cache/*.php` (cache files) |
| `/admin/*.php` (admin controllers) | Smarty library itself (`vendor/`) |
| Web service handlers | Language files |

### PHP Source Files Are NOT "Compiled"

PHP is an **interpreted language**. The source `.php` files are:
- Read directly by the PHP interpreter
- Optionally cached by OPcache (bytecode cache)
- Executed as-is without "compilation" to `_data`

The OPcache bytecode is stored in memory (or shared memory), not in `_data`. Coverage tools like Xdebug/PCOV instrument the source files directly.

---

## Key Files Reference

| File | Purpose |
|------|---------|
| `/coverage.php` | PHP coverage instrumentation and collection |
| `/composer.json` | PHP coverage dependencies |
| `/tests/src/run-tests.mts` | TypeScript E2E test runner |
| `/tests/package.json` | JS test dependencies (Puppeteer) |
| `/tests/MONOCART_README.md` | Monocart reporter documentation |

---

## Usage

### Running PHP Coverage Collection

1. **Enable coverage** by uncommenting line 55 in `inc/config_default.php`:
   ```php
   require_once __DIR__ . '/../coverage.php';
   ```

2. **Ensure Xdebug is installed and enabled** (✓ Already configured on your system)
   - PHP 8.4.14 with Xdebug 3.4.2
   - `xdebug.mode=coverage` is enabled

3. **Use the application** - each HTTP request generates coverage data:
   ```
   C:/Apache24/logs/xdebug/coverage/*.cov files
   ```

4. **Merge and generate reports** with phpcov:
   ```bash
   vendor/bin/phpcov merge --html=coverage/html C:/Apache24/logs/xdebug/coverage/
   ```

### Running E2E Tests

```bash
cd tests
bun run tests
```

This executes Puppeteer-based tests while the PHP backend collects coverage data for each request made during the test run.

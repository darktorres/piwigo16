# Roadmap: Piwigo PHP Modernization

**Overall Goal:** To refactor the Piwigo PHP codebase towards modern standards (PHP 8.1+), improving type safety, maintainability, testability, security, and leveraging modern language features, while maintaining compatibility with existing plugins/themes where feasible or clearly documenting necessary updates for them.

---

## [Phase 0: Preparation & Setup (Foundation)](#phase-0-php-details)

*   **Goal:** Establish a controlled environment and tooling for safe and consistent refactoring.
*   **Tasks:**
    *   [ ] **Version Control:** Ensure robust Git usage; create a dedicated branch (e.g., `feature/php-modernization`).
    *   [ ] **PHP Version:** Confirm the target environment consistently runs PHP 8.4 as specified in `composer.json`. Ensure local development matches this.
    *   [ ] **Testing Strategy:** Define manual testing checklists covering core functionalities, admin sections, plugin/theme interactions, and API endpoints (`ws.php`). Consider PHPUnit for unit/integration tests on refactored classes.
    *   [ ] **Static Analysis & Rector/ECS:**
        *   [ ] Configure PHPStan and/or Psalm for deep static analysis (start at a low level, gradually increase). Generate a baseline.
        *   [ ] Configure Rector for automated refactoring tasks (e.g., applying type hints, syntax upgrades).
        *   [ ] Ensure ECS (Easy Coding Standard) is configured and applied for consistent coding style.
    *   [ ] **Documentation (`MODERNIZATION_PHP.md`):** Create a dedicated file to track PHP-specific goals, decisions, progress, and challenges.
    *   [ ] **Composer Dependencies:** Run `composer update` to ensure dependencies are reasonably up-to-date (check for breaking changes).

---

## [Phase 1: Syntax, Strict Types, and Basic Typing (Automated & Low-Risk)](#phase-1-php-details)

*   **Goal:** Apply modern syntax and basic type safety across the codebase, leveraging automated tools where possible.
*   **Tasks:**
    *   [ ] **Enable Strict Types:** Add `declare(strict_types=1);` to the top of all PHP files. Use Rector or scripting to automate this. Address resulting type errors (likely requires careful casting or type adjustments).
    *   [ ] **PHP 8.x Syntax Upgrades:** Use Rector to automatically apply safe upgrades:
        *   [ ] Nullsafe operator (`?->`).
        *   [ ] `match` expressions where applicable.
        *   [ ] Constructor property promotion.
        *   [ ] `str_contains()`, `str_starts_with()`, `str_ends_with()`.
        *   [ ] Potentially readonly properties/classes (apply cautiously).
        *   [ ] Potentially Enums for sets of related constants (e.g., access levels, status codes).
    *   [ ] **Basic Scalar Type Hinting:** Use Rector or manual effort to add scalar type hints (`int`, `string`, `bool`, `float`, `array`) to function/method parameters and return types where obvious and safe. Start with private/protected methods.
    *   [ ] **Basic Return Type Hinting:** Add return types (including `void` and `never`) where the return value is clear and consistent. Use Rector for initial suggestions.
    *   [ ] **Nullable Types:** Use nullable types (`?string`, `?int`) where parameters or return values can legitimately be `null`.
    *   [ ] **Coding Standards Pass:** Run ECS (`vendor/bin/ecs check --fix`) to ensure consistency after syntax changes.
    *   [ ] **Static Analysis Baseline:** Run PHPStan/Psalm at a low level (`level: 0` or `1`) and update the baseline file.

---

## [Phase 2: Core Refactoring - Globals, Error Handling, Database](#phase-2-php-details)

*   **Goal:** Address major architectural issues like reliance on globals, inconsistent error handling, and direct SQL query building.
*   **Tasks:**
    *   [ ] **Reduce Global Dependency (`$conf`, `$user`, `$page`, `$template`):**
        *   [ ] **Dependency Injection:** Identify core classes or functions heavily using globals. Refactor them to accept dependencies (like `$conf` data, user object, template object) via constructor or method parameters. Start with key areas like database functions, user functions, template setup.
        *   [ ] **Configuration Access:** Create a dedicated Configuration service/class to access `$conf` values instead of direct global access, passing this service where needed.
        *   [ ] **User Data:** Pass the `$user` array or a dedicated User object instead of relying on the global.
    *   [ ] **Error Handling:**
        *   [ ] Replace manual error array building (`$page['errors'][] = ...`) in core functions/classes with throwing specific, custom Exceptions (e.g., `DatabaseException`, `PermissionDeniedException`, `NotFoundException`).
        *   [ ] Implement global exception handlers (using `set_exception_handler`) at the entry points (e.g., `index.php`, `admin.php`, `picture.php`, `ws.php`) to catch these exceptions and translate them into appropriate user messages or error pages/responses.
        *   [ ] Phase out `trigger_error` for recoverable errors where exceptions are more suitable. Keep `trigger_error` for actual notices/warnings/deprecations.
    *   [ ] **Database Interaction:**
        *   [ ] **Prepared Statements:** Identify all direct SQL query construction involving user input or variables (e.g., `pwg_query("SELECT ... WHERE id = ".$_GET['id'])`). Rewrite these using prepared statements with placeholders (using PDO or MySQLi object-oriented methods, adapting the existing `dblayer`). This is critical for preventing SQL injection.
        *   [ ] **Data Fetching:** Prefer fetching data into associative arrays (`fetch_assoc`) or potentially dedicated Data Transfer Objects (DTOs) rather than numeric arrays (`fetch_row`) for better code clarity.
        *   [ ] **Abstraction:** Consider strengthening the database layer abstraction (`functions_mysqli.php`, `functions_pgsql.php`) to ensure consistency and potentially ease future ORM adoption.

---

## [Phase 3: OOP & Structural Improvements](#phase-3-php-details)

*   **Goal:** Improve code organization and encapsulation by refactoring procedural code into classes and utilizing OOP principles.
*   **Tasks:**
    *   [ ] **Refactor Procedural Code to Classes:**
        *   [ ] Identify large `functions_*.php` files with groups of related functions (e.g., `functions_user.php`, `functions_category.php`, `functions_metadata.php`).
        *   [ ] Create service classes (e.g., `UserService`, `CategoryService`, `MetadataService`) and move the related functions into these classes as static or instance methods (prefer instance methods if they rely on dependencies passed via constructor).
        *   [ ] Update call sites to use the new class methods (e.g., `functions_user::is_admin()` becomes `$userService->isAdmin($user)` or `UserService::isAdmin($user)`).
    *   [ ] **Introduce Interfaces:**
        *   [ ] Define interfaces for key components where multiple implementations might exist or for ensuring contracts (e.g., `CacheInterface`, `ImageHandlerInterface` for GD/Imagick/Vips, `AuthenticationProviderInterface`).
        *   [ ] Refactor existing classes to implement these interfaces.
    *   [ ] **Improve Namespace Usage:**
        *   [ ] Review the current namespace structure (`Piwigo\inc`, `Piwigo\admin\inc`, etc.).
        *   [ ] Ensure consistency and potentially create more granular sub-namespaces (e.g., `Piwigo\Database`, `Piwigo\Service`, `Piwigo\Http`).
        *   [ ] Update `use` statements accordingly.
    *   [ ] **Class Visibility:** Review method/property visibility (`public`, `protected`, `private`). Use `private` or `protected` where possible to improve encapsulation. Use `readonly` where applicable (PHP 8.1+).
    *   [ ] **Final/Abstract Keywords:** Use `final` for classes not intended for extension and `abstract` for base classes where appropriate.

---

## [Phase 4: Dependencies, Security, Performance & Polish](#phase-4-php-details)

*   **Goal:** Ensure external dependencies are up-to-date, conduct thorough security and performance reviews, and finalize documentation.
*   **Tasks:**
    *   [ ] **Dependency Updates:**
        *   [ ] Regularly run `composer update` and review changes in dependencies (Smarty, PHPMailer, etc.). Address any deprecations or breaking changes.
        *   [ ] Evaluate if any old dependencies can be removed or replaced with standard PHP features or more modern libraries.
    *   [ ] **Security Review:**
        *   [ ] **Input Sanitization:** Double-check that *all* external input (`$_GET`, `$_POST`, `$_FILES`, `$_COOKIE`) is properly validated and sanitized before use, especially before database queries or outputting to HTML. Use `filter_input` or framework-provided methods if applicable.
        *   [ ] **Output Escaping:** Ensure *all* data outputted in HTML templates is properly escaped using Smarty's `|escape:'html'` modifier (or equivalent like `{$variable|escape}` which defaults to html) to prevent XSS, unless HTML is explicitly allowed and sanitized (e.g., descriptions using a safe HTML filter). Check JSON encoding (`json_encode` flags).
        *   [ ] **SQL Injection:** Confirm all database queries involving external data use prepared statements (verified in Phase 2).
        *   [ ] **CSRF Protection:** Verify that all state-changing actions (POST requests, sensitive GET actions) are protected by `pwg_token` checks.
        *   [ ] **File Uploads:** Review file upload handling (`add_uploaded_file`, `add_format`) for security vulnerabilities (e.g., checking file types, preventing path traversal, handling errors securely).
        *   [ ] **Permissions:** Review core permission checks (`check_status`, `is_admin`, `calculate_permissions`) to ensure they are consistently applied.
    *   [ ] **Performance Profiling:**
        *   [ ] Use Xdebug's profiler or a tool like Blackfire.io to profile key application areas (e.g., index page generation with many albums, picture page load, batch manager operations, API calls).
        *   [ ] Identify bottlenecks (slow database queries, inefficient loops, heavy computations).
        *   [ ] Optimize critical code paths and database queries (add indexes, rewrite queries).
    *   [ ] **Refine Type Hinting:** Add more specific type hints, including object types, union types (`string|int`), potentially `iterable` for arrays/Traversables, and `mixed` only when truly necessary. Aim for maximum type coverage reported by PHPStan/Psalm.
    *   [ ] **Final Static Analysis & Baseline:** Run PHPStan/Psalm at the target level (aim for level 5+) and resolve reported issues or update the baseline with justifications.
    *   [ ] **Documentation Update:** Finalize `MODERNIZATION_PHP.md` documenting the final state, key changes, and any remaining technical debt. Update relevant code comments.
    *   [ ] **Cleanup:** Remove deprecated functions, unused variables, and commented-out legacy code.

---
---

## Detailed Roadmap Explanations & Examples (PHP)

*(This section contains the more detailed explanations and code examples corresponding to the PHP modernization phases outlined above.)*

<a name="phase-0-php-details"></a>
### Phase 0: Preparation & Setup (Foundation) - Details

*   **Goal:** Establish a controlled environment and tooling for safe and consistent refactoring.
*   **Tasks:**
    1.  **Version Control:** As crucial as for JS. Use a dedicated branch (`feature/php-modernization`). Commit small, logical changes frequently.
    2.  **PHP Version:** Confirm PHP 8.4 availability across development, testing, and deployment environments. Update local PHP CLI and web server configurations if necessary.
    3.  **Testing Strategy:** Define PHP-specific testing needs.
        *   **Manual:** Cover admin actions, API calls (`ws.php` tested via frontend or tools like Postman), frontend rendering under different conditions (logged in/out, different permissions).
        *   **Automated (PHPUnit):** *Highly recommended* for refactored classes. Start by writing tests for new service classes created in Phase 3 *before* or *during* refactoring (Test-Driven Development or Test-After). Cover core logic, edge cases, and expected outputs/exceptions.
    4.  **Static Analysis & Rector/ECS:**
        *   **PHPStan/Psalm:** Install (`composer require --dev phpstan/phpstan` or `vimeo/psalm`). Configure (`phpstan.neon` / `psalm.xml`). Start with `level: 0` or `1`. Run `vendor/bin/phpstan analyse --generate-baseline` (or psalm equivalent) to establish an initial baseline, ignoring existing issues to focus on new/changed code first. Increment the level gradually as issues are fixed.
        *   **Rector:** Install (`composer require --dev rector/rector`). Configure (`rector.php`). Define sets for PHP 8.1, 8.2, 8.3, 8.4 features, strict types, and basic type hinting rules. Use `--dry-run` extensively before applying changes (`--clear-cache` might be needed).
        *   **ECS:** Install (`composer require --dev symplify/easy-coding-standard`). Configure (`ecs.php`). Use PSR-12 or PER Coding Style as a base. Run `vendor/bin/ecs check --fix`.
    5.  **Documentation (`MODERNIZATION_PHP.md`):** Create this file. Document PHP version target, chosen static analysis tools/levels, key refactoring decisions (e.g., DI strategy, exception hierarchy), and progress.
    6.  **Composer Dependencies:** Run `composer outdated` to see available updates. Use `composer update [package-name]` cautiously, checking the package's changelog for breaking changes before updating major versions. Ensure `composer.lock` is committed.

---

<a name="phase-1-php-details"></a>
### Phase 1: Syntax, Strict Types, and Basic Typing (Automated & Low-Risk) - Details

*   **Goal:** Apply modern syntax and basic type safety across the codebase, leveraging automated tools where possible. This phase focuses on improving code correctness and clarity with relatively low risk of breaking logic if done carefully.
*   **Tasks:**

    1.  **Enable Strict Types:**
        *   **How:** Use a script or Rector rule to add `declare(strict_types=1);` as the *very first* line after `<?php` in all `.php` files.
        *   **Before:** `<?php namespace Piwigo\inc; function add($a, $b) { return $a + $b; }`
        *   **After:** `<?php declare(strict_types=1); namespace Piwigo\inc; function add($a, $b) { return $a + $b; }`
        *   **Why:** Enforces stricter type checking at runtime. Passing a string to a function expecting an int will now throw a `TypeError`, catching bugs earlier.
        *   **Challenge:** This is the most likely step in this phase to cause errors if code implicitly relied on type juggling (e.g., passing `"5"` to a function expecting `int`). Fix these by adding explicit casts (`(int)$var`, `(string)$var`) or correcting the calling code *before* adding strict types, or address the `TypeError`s reported after adding it.
        *   **Testing:** Requires thorough testing, as type juggling errors might only appear in specific code paths. PHPStan/Psalm will help identify many potential issues beforehand.

    2.  **PHP 8.x Syntax Upgrades (via Rector):**
        *   **Nullsafe Operator (`?->`):**
            *   **Before:** `$user ? ($user->getAddress() ? $user->getAddress()->getStreet() : null) : null;`
            *   **After:** `$user?->getAddress()?->getStreet();`
            *   **Why:** Significantly reduces verbosity when dealing with potentially null objects in a chain.
        *   **`match` Expressions:**
            *   **Before:**
                ```php
                switch ($statusCode) {
                    case 200: $message = 'OK'; break;
                    case 404: $message = 'Not Found'; break;
                    default: $message = 'Error'; break;
                }
                ```
            *   **After:**
                ```php
                $message = match ($statusCode) {
                    200 => 'OK',
                    404 => 'Not Found',
                    default => 'Error',
                };
                ```
            *   **Why:** More concise and often safer than `switch` (requires exhaustive checks or `default`, returns a value, strict comparison). Suitable for simple value mapping.
        *   **Constructor Property Promotion:**
            *   **Before:**
                ```php
                class Logger {
                    private string $logPath;
                    public function __construct(string $logPath) {
                        $this->logPath = $logPath;
                    }
                }
                ```
            *   **After:**
                ```php
                class Logger {
                    public function __construct(private string $logPath) {}
                }
                ```
            *   **Why:** Reduces boilerplate code for simple constructor assignments.
        *   **`str_contains()`, etc.:**
            *   **Before:** `if (strpos($haystack, $needle) !== false) { ... }`
            *   **After:** `if (str_contains($haystack, $needle)) { ... }`
            *   **Why:** More readable and intention-revealing.
        *   **Readonly Properties/Classes (PHP 8.1/8.2):** Apply where immutability is desired and feasible.
            *   **After:** `public readonly string $id;` or `readonly class Config { ... }`
            *   **Why:** Enforces immutability, making state easier to reason about. Apply thoughtfully, especially to existing classes.
        *   **Enums (PHP 8.1):** Replace sets of related constants.
            *   **Before:** `define('ACCESS_GUEST', 0); define('ACCESS_CLASSIC', 1); ...`
            *   **After:** `enum UserAccessLevel: int { case GUEST = 0; case CLASSIC = 1; ... }`
            *   **Why:** Provides type safety and clear definition for related constant values.
        *   **Testing:** Rector previews (`--dry-run`) are essential. After applying, run PHPStan/Psalm and perform manual testing, as automated refactoring isn't infallible.

    3.  **Basic Scalar Type Hinting:**
        *   **How:** Use Rector's type declaration rulesets first. Manually add hints where Rector cannot infer them or where complex logic exists. Focus on `int`, `string`, `bool`, `float`, `array`.
        *   **Before:** `function get_username($userId) { /* ... */ return $name; }`
        *   **After:** `function get_username(int $userId): string { /* ... */ return $name; }`
        *   **Why:** Improves code understanding, enables static analysis tools to find errors, helps prevent type-related bugs (especially with `strict_types=1`).
        *   **Testing:** Run PHPStan/Psalm to catch type mismatches introduced. Test affected code paths manually.

    4.  **Basic Return Type Hinting:**
        *   **How:** Similar to parameter hinting. Add `: type` after the parameter list. Use `: void` for functions that explicitly don't return anything. Use `: never` (PHP 8.1) for functions that *always* `exit`, `die`, or `throw`.
        *   **Before:** `function process_data($data) { if (!$data) { return; } /* ... */ }`
        *   **After:** `function process_data(array $data): void { if (!$data) { return; } /* ... */ }`
        *   **Before:** `function fatal_error($msg) { echo $msg; exit; }`
        *   **After:** `function fatal_error(string $msg): never { echo $msg; exit; }`
        *   **Why:** Same benefits as parameter hinting – clarity, static analysis, bug prevention.
        *   **Testing:** PHPStan/Psalm are key here.

    5.  **Nullable Types (`?`):**
        *   **How:** If a parameter can be `null` or a specific type, add `?` before the type (e.g., `?string`). Same for return types.
        *   **Before:** `function find_user($id) { /* returns user array or null */ }`
        *   **After:** `function find_user(int $id): ?array { /* returns user array or null */ }`
        *   **Why:** Explicitly documents nullability, allowing static analysis to catch potential `null` access errors.
        *   **Testing:** Static analysis and manual testing of paths where `null` might be passed or returned.

    6.  **Coding Standards Pass:**
        *   **How:** Run `vendor/bin/ecs check --fix` after making manual or Rector changes.
        *   **Why:** Ensures consistency across the codebase, making it easier to read and maintain.

    7.  **Static Analysis Baseline:**
        *   **How:** Run `vendor/bin/phpstan analyse -l 1 --generate-baseline` (or Psalm equivalent). Commit the generated baseline file.
        *   **Why:** Acknowledges existing issues while allowing the CI/local checks to focus on *new* issues introduced in the modernized code. Gradually reduce the baseline over time.

---

<a name="phase-2-php-details"></a>
### Phase 2: Core Refactoring - Globals, Error Handling, Database - Details

*   **Goal:** Tackle fundamental architectural weaknesses: reduce global state, standardize error handling with exceptions, and improve database query security and clarity. These are higher-risk changes requiring careful planning and testing.
*   **Tasks:**

    1.  **Reduce Global Dependency (`$conf`, `$user`, `$page`, `$template`):**
        *   **How:** This is a gradual process.
            *   **Configuration:** Create a `ConfigService` class. Inject it (or specific config values) into constructors or methods needing them. Replace `global $conf; $value = $conf->some_param;` with `$value = $this->configService->getParam('some_param');`.
            *   **User Data:** Pass the `$user` array (or better, a `User` object/DTO) as a parameter to functions/methods needing user context, instead of `global $user;`. For classes needing user context frequently, inject a `CurrentUserProvider` service.
            *   **Page Data (`$page`):** This array often acts as a mixed bag of request data and data destined for the template. Refactor functions to *return* data instead of setting `$page['some_key']`. Pass necessary request parameters explicitly. Consolidate template assignments closer to where `$template->assign()` is called.
            *   **Template Object (`$template`):** Pass the `$template` object explicitly to functions that *need* to assign variables (aim to minimize these). Ideally, only the main controller/script should interact directly with `$template->assign()`.
        *   **Before (example in a function):**
            ```php
            function check_user_permission() {
                global $user, $conf;
                if ($user['level'] < $conf->min_level) {
                    return false;
                }
                return true;
            }
            ```
        *   **After (example method in a service class):**
            ```php
            class PermissionService {
                private Config $config; // Injected via constructor

                public function __construct(Config $config) {
                    $this->config = $config;
                }

                public function hasSufficientLevel(array $user, int $requiredLevel): bool {
                    // Or better, use a User object: public function hasSufficientLevel(User $user, ...)
                    return $user['level'] >= $requiredLevel;
                }

                public function checkMinLevel(array $user): bool {
                     $minLevel = $this->config->getInt('min_level'); // Assuming Config class handles types
                     return $this->hasSufficientLevel($user, $minLevel);
                }
            }
            // Usage: $permissionService->checkMinLevel($user);
            ```
        *   **Why:** Reduces hidden dependencies, makes code *much* easier to understand and test, improves encapsulation, and facilitates replacing components later. This is fundamental to modern PHP application architecture.
        *   **Challenge:** This is a significant refactoring effort requiring changes across many files. Start with the most heavily used globals in core functions/classes.

    2.  **Error Handling (Exceptions):**
        *   **How:** Define custom exception classes (e.g., `DatabaseException`, `NotFoundException`, `PermissionDeniedException`, `ValidationException`) extending `\Exception` or `\RuntimeException`/`\LogicException`. Replace code that returns `false` on error or manually adds to `$page['errors']` with `throw new CustomException(...)`. Use `try...catch` blocks at higher levels (controllers, API entry points) to handle these exceptions gracefully (log the error, show a user-friendly message or error page).
        *   **Before:**
            ```php
            function get_user_data($userId) {
                global $conf;
                $result = $conf->sql_backend::pwg_query("SELECT ... WHERE id = $userId");
                if ($conf->sql_backend::pwg_db_num_rows($result) == 0) {
                    $page['errors'][] = 'User not found';
                    return false;
                }
                return $conf->sql_backend::pwg_db_fetch_assoc($result);
            }
            // Usage: $userData = get_user_data(123); if ($userData === false) { /* handle error */ }
            ```
        *   **After:**
            ```php
            class UserNotFoundException extends \Exception {}

            function get_user_data(int $userId): array { // Added type hints
                global $conf; // Still global here, address in DI step
                $query = sprintf("SELECT ... WHERE id = %d", $userId); // Still unsafe, address in DB step
                $result = $conf->sql_backend::pwg_query($query);
                if ($conf->sql_backend::pwg_db_num_rows($result) == 0) {
                    throw new UserNotFoundException("User with ID {$userId} not found.");
                }
                return $conf->sql_backend::pwg_db_fetch_assoc($result);
            }
            // Usage:
            try {
                $userData = get_user_data(123);
                // process $userData
            } catch (UserNotFoundException $e) {
                // Log error $e->getMessage()
                functions_html::page_not_found('User not found'); // Or render an error template
            } catch (\Exception $e) { // Catch other potential DB errors
                // Log generic error
                functions_html::fatal_error('An unexpected error occurred');
            }
            ```
        *   **Why:** Provides a standard, robust way to handle errors. Separates error handling logic from core business logic. Allows for more specific error catching and reporting. Prevents scripts from continuing after critical failures.
        *   **Testing:** Ensure expected errors correctly throw exceptions and that these are caught and handled appropriately (user message, logging, correct HTTP status code for API).

    3.  **Database Interaction (Prepared Statements):**
        *   **How:** Locate *all* instances where variables are concatenated or interpolated directly into SQL query strings passed to `pwg_query`. Rewrite these using prepared statements. The exact syntax depends on the underlying driver (`mysqli` or `pgsql`) and how the `dblayer` functions wrap it.
            *   **For MySQLi (Conceptual - adapt to `dblayer`):**
                *   **Before:** `$query = "SELECT * FROM users WHERE username = '" . $conf->sql_backend::pwg_db_real_escape_string($username) . "'"; $result = $conf->sql_backend::pwg_query($query);`
                *   **After:**
                    ```php
                    global $mysqli; // Assuming $mysqli is the connection object used by dblayer
                    $stmt = $mysqli->prepare("SELECT * FROM users WHERE username = ?");
                    $stmt->bind_param("s", $username); // 's' for string
                    $stmt->execute();
                    $result = $stmt->get_result(); // Get mysqli_result object
                    // Now use $result with pwg_db_fetch_assoc etc.
                    // $userData = $conf->sql_backend::pwg_db_fetch_assoc($result);
                    $stmt->close();
                    ```
            *   **For PostgreSQL (Conceptual - adapt to `dblayer`):**
                *   **Before:** `$query = "SELECT * FROM users WHERE username = '" . $conf->sql_backend::pwg_db_real_escape_string($username) . "'"; $result = $conf->sql_backend::pwg_query($query);`
                *   **After:**
                    ```php
                    global $pg; // Assuming $pg is the connection resource
                    $result = pg_query_params($pg, "SELECT * FROM users WHERE username = $1", [$username]);
                    if (!$result) { /* handle error */ }
                    // Now use $result with pwg_db_fetch_assoc etc.
                    // $userData = $conf->sql_backend::pwg_db_fetch_assoc($result);
                    ```
        *   **Why:** **Crucial for security.** Prevents SQL injection vulnerabilities, which are often introduced by improper escaping or direct variable concatenation. Also often improves performance for repeated queries.
        *   **Challenge:** Requires modifying the `dblayer` functions (`pwg_query`, fetch functions) or creating new ones to support prepared statements and parameter binding seamlessly. This is a significant but vital task.
        *   **Testing:** Verify that all pages involving database interactions still work correctly. Test with inputs containing special SQL characters (quotes, semicolons) to ensure they are handled safely and do not cause errors or unexpected behavior.

---

<a name="phase-3-php-details"></a>
### Phase 3: OOP & Structural Improvements - Details

*   **Goal:** Improve code organization, encapsulation, and testability by applying Object-Oriented Programming principles and refining the project structure.
*   **Tasks:**

    1.  **Refactor Procedural Code to Classes:**
        *   **How:** Analyze `functions_*.php` files. Identify groups of functions operating on the same domain (e.g., user authentication, category tree manipulation, image processing). Create corresponding service classes (e.g., `AuthService`, `CategoryTreeService`, `ImageProcessor`). Move the functions into these classes as methods. Decide if methods should be `static` (if they don't rely on instance state) or instance methods (if they need configuration or other dependencies injected via the constructor).
        *   **Before (`functions_category.php`):**
            ```php
            function get_cat_info(int $cat_id): ?array { /* ... */ }
            function get_subcat_ids(array $parent_ids): array { /* ... */ }
            function check_restrictions(int $cat_id): void { /* ... */ }
            ```
        *   **After (`src/Service/CategoryService.php` - conceptual):**
            ```php
            namespace Piwigo\Service;
            // Add necessary use statements for DB access, user context, etc.

            class CategoryService {
                // Dependencies injected via constructor
                private \Piwigo\Database\Connection $db;
                private \Piwigo\Service\PermissionService $permissionService; // Example dependency

                public function __construct(\Piwigo\Database\Connection $db, \Piwigo\Service\PermissionService $permissionService) {
                    $this->db = $db;
                    $this->permissionService = $permissionService;
                }

                public function getCategoryInfo(int $catId): ?array {
                   // Use $this->db to query...
                }
                public function getSubcategoryIds(array $parentIds): array { /* ... */ }
                public function checkRestrictions(int $catId, User $currentUser): void { // Pass user explicitly
                    if (!$this->permissionService->canUserViewCategory($currentUser, $catId)) {
                        throw new \Piwigo\Exception\PermissionDeniedException(...);
                    }
                }
            }
            ```
        *   **Why:** Groups related logic together, improves encapsulation, allows for dependency injection (making code testable), reduces the need for globals, and provides a clearer structure.
        *   **Challenge:** Requires careful planning to identify logical service boundaries. Updating all call sites can be time-consuming.

    2.  **Introduce Interfaces:**
        *   **How:** Identify areas where behavior might vary or where you want to enforce a contract. Examples: Image manipulation (define `ImageProcessorInterface` with `resize()`, `crop()`, `watermark()` methods, implement it with `GdProcessor`, `ImagickProcessor`, `VipsProcessor`), Caching (`CacheInterface` with `get()`, `set()`, `delete()`), Authentication (`AuthProviderInterface`).
        *   **Example (`ImageProcessorInterface.php`):**
            ```php
            namespace Piwigo\Image;
            interface ImageProcessorInterface {
                public function load(string $sourcePath): bool;
                public function resize(int $width, int $height): bool;
                public function save(string $destinationPath, int $quality): bool;
                // ... other methods: crop, rotate, watermark, getDimensions ...
            }
            ```
        *   **Example (`GdProcessor.php`):**
            ```php
            namespace Piwigo\Image;
            class GdProcessor implements ImageProcessorInterface {
                private $imageResource;
                // ... implement interface methods using GD functions ...
            }
            ```
        *   **Why:** Promotes loose coupling, allows swapping implementations easily (e.g., changing image library), improves testability (using mock objects), and defines clear contracts for components.

    3.  **Improve Namespace Usage:**
        *   **How:** Review existing `namespace` declarations. Create more specific sub-namespaces under `Piwigo\` (e.g., `Piwigo\Service`, `Piwigo\Repository`, `Piwigo\Controller\Admin`, `Piwigo\Entity`, `Piwigo\Database`, `Piwigo\Http`). Move classes into the appropriate namespaces and update their `namespace` declaration and all `use` statements referencing them across the project. Tools like PHPStorm can assist with refactoring namespaces.
        *   **Why:** Better organization, avoids class name collisions, aligns with modern PHP standards (PSR-4 autoloading).

    4.  **Class Visibility:**
        *   **How:** Examine `public` methods and properties in classes. If a method/property is only used *internally* within the class, change it to `private`. If it's needed by child classes but not external code, change it to `protected`. Use `readonly` (PHP 8.1+) for properties initialized in the constructor and never changed.
        *   **Why:** Enhances encapsulation, hiding implementation details and making classes easier to understand and maintain. Prevents accidental misuse of internal methods/properties.

    5.  **Final/Abstract Keywords:**
        *   **How:** Add `final` before `class` for classes that are not designed to be extended. Add `abstract` before `class` for base classes that require subclasses to implement certain methods (often used with interfaces).
        *   **Why:** Clearly signals the intended usage of a class. Prevents unintended inheritance (`final`). Enforces necessary implementation details in subclasses (`abstract`).

---

<a name="phase-4-php-details"></a>
### Phase 4: Dependencies, Security, Performance & Polish - Details

*   **Goal:** Ensure the application is secure, performant, uses up-to-date dependencies, and the modernization effort is well-documented.
*   **Tasks:**

    1.  **Dependency Updates:**
        *   **How:** Regularly run `composer outdated`. Review the list. For important updates (especially major versions or security fixes), check the library's `CHANGELOG.md` or release notes for breaking changes. Update cautiously: `composer update smarty/smarty phpmailer/phpmailer`. Test thoroughly after updates.
        *   **Why:** Incorporates bug fixes, security patches, and potentially performance improvements from third-party libraries. Keeps the application ecosystem healthy.

    2.  **Security Review:**
        *   **How:** Systematically review code focusing on common web vulnerabilities:
            *   **Input Sanitization:** Search for direct usage of `$_GET`, `$_POST`, `$_REQUEST`, `$_FILES`, `$_COOKIE`. Ensure data is validated (e.g., checking if an ID is numeric using `filter_var` or type hints) and sanitized appropriately *before* using it (e.g., using specific sanitization filters if needed, though often validation + prepared statements + proper output escaping is sufficient).
            *   **Output Escaping:** Search TPL files for `{$variable}` where the variable might contain user-supplied or database-derived content. Ensure it's escaped: `{$variable|escape:'html'}` (or just `{$variable|escape}`). For attributes: `<a href="{$url|escape:'url'}" title="{$title|escape:'html'}">`. For JS contexts: `var data = {$php_array|json_encode|escape:'html'}`; (encoding for JS, then escaping for HTML attribute/content).
            *   **SQL Injection:** Double-check that *no* variables are directly embedded in SQL strings. Confirm prepared statements (or rigorous escaping via the `dblayer` if absolutely necessary) are used everywhere (verified in Phase 2).
            *   **CSRF Protection:** Ensure all forms submitting data (especially those causing state changes like updates or deletes) include `<input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">` and that the receiving PHP script calls `functions::check_pwg_token();` before processing the request. Verify for sensitive GET actions too.
            *   **File Uploads:** Review `admin/inc/functions_upload.php`. Ensure robust checks on file extensions (`$conf->file_ext`, `$conf->picture_ext`), MIME types (using `finfo`), file size limits, and that uploaded files are moved (`move_uploaded_file`) to a secure location *outside* the web root if possible, or at least with restricted permissions and careful path handling to prevent path traversal (`../`). Ensure generated filenames don't allow overwriting critical files.
            *   **Permissions:** Review `check_status()` usage and the logic within `calculate_permissions()` and `get_sql_condition_FandF()` to ensure access controls are correctly applied.
        *   **Why:** Critical for protecting user data, preventing unauthorized access, and maintaining the integrity of the application.

    3.  **Performance Profiling:**
        *   **How:**
            *   **Xdebug Profiler:** Enable Xdebug profiling (`xdebug.mode=profile`), run requests for slow pages/actions, and analyze the generated cachegrind files with tools like KCachegrind/QCachegrind or Webgrind.
            *   **Blackfire.io (Recommended if available):** Install the agent and browser extension for detailed, user-friendly performance profiles and comparisons.
        *   **Analyze:** Look for functions/methods with high "Self Cost" (time spent *in* the function itself) and "Inclusive Cost" (time spent in the function and its children). Identify slow database queries (check query times in Piwigo debug mode or DB logs).
        *   **Optimize:** Add necessary database indexes (`CREATE INDEX ...`), rewrite inefficient SQL queries, optimize PHP loops, cache computationally expensive results (using `$conf->persistent_cache` or other mechanisms).
        *   **Why:** Ensures the modernized application performs well, especially under load.

    4.  **Refine Type Hinting:**
        *   **How:** Go beyond basic scalar types. Use specific object types (`CategoryService $service`, `User $user`), union types (`int|string`, `array|null`), `iterable` for parameters accepting arrays or Traversables, and potentially generics (using PHPStan/Psalm docblocks `@template`, `@param T`) for collection classes or functions. Aim to reduce or eliminate `mixed`.
        *   **Before:** `function process($data) : array`
        *   **After (Example):** `function process(User|Guest $user, array $options): ProcessResult` (assuming `User`, `Guest`, `ProcessResult` are defined classes/interfaces).
        *   **Why:** Maximizes the benefits of static analysis, makes code self-documenting, and improves type safety.

    5.  **Final Static Analysis & Baseline:**
        *   **How:** Increase the PHPStan/Psalm level (e.g., `level: 5` or higher). Run analysis: `vendor/bin/phpstan analyse -l 5`. Address all reported issues. If some issues cannot be reasonably fixed immediately, add them to the baseline file (`phpstan-baseline.neon` / `psalm-baseline.xml`) *with a comment explaining why*.
        *   **Why:** Catches a wider range of potential bugs, dead code, and inconsistencies. Ensures a high level of code quality.

    6.  **Documentation Update:**
        *   **How:** Review and update `MODERNIZATION_PHP.md`. Add PHPDoc blocks (`/** ... */`) to new classes and methods, and update existing ones, especially explaining non-obvious logic or parameter types if full type hinting wasn't possible.
        *   **Why:** Makes the code understandable for future developers (including yourself). PHPDoc aids IDEs and static analysis.

    7.  **Cleanup:**
        *   **How:** Search for functions/classes marked as `@deprecated` that are no longer used internally. Remove old procedural files if all their functionality has been moved to classes. Delete commented-out code blocks unless they serve as important historical context.
        *   **Why:** Reduces clutter and improves maintainability.

---

**Key Considerations Throughout:**

*   **Iterative Approach:** Modernize incrementally. Don't try to rewrite everything simultaneously. Focus on one module, one feature, or one architectural concern at a time.
*   **Testing:** Essential after *every* significant refactoring step. Combine manual testing with automated tests (unit, integration, end-to-end) where possible.
*   **Backward Compatibility (Plugins/Themes):** Be mindful of changes that might break existing third-party plugins or themes.
    *   Mark old functions/methods as `@deprecated` before removing them.
    *   Provide clear upgrade paths or documentation for extension developers if core APIs change significantly.
    *   Use the event system (`trigger_change`, `trigger_notify`) to allow extensions to adapt or modify behavior where possible.
*   **Database Schema:** This roadmap focuses on PHP code. Database schema changes are a separate, significant undertaking requiring careful planning and migration scripts (usually handled in `maintain.inc.php` or upgrade scripts).
*   **Focus:** Prioritize changes offering the best return: security (prepared statements), maintainability (reducing globals, OOP), and type safety (strict types, hinting).
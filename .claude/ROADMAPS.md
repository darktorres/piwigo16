Okay, absolutely. Since you're focused on modernizing your Piwigo fork beyond just the language syntax, here are several other relevant roadmaps you could consider adopting, presented in a similar format:

---

## Roadmap: Security Hardening

**Goal:** Systematically review and improve the security posture of the application, addressing common web vulnerabilities and ensuring best practices are followed.

*   **Phase 0: Assessment & Tooling**
    *   [ ] **Threat Modeling:** Briefly identify potential attack vectors and sensitive areas (authentication, uploads, user input handling, permissions).
    *   [ ] **Dependency Scan:** Use `composer audit` and potentially `npm audit` (if using Node.js build steps) to identify known vulnerabilities in third-party libraries.
    *   [ ] **SAST (Static Analysis Security Testing):** Configure PHPStan/Psalm with security-focused rulesets if available, or consider dedicated SAST tools (some free options exist, commercial ones offer more).
    *   [ ] **Review Security Headers:** Check current HTTP security headers (CSP, HSTS, X-Frame-Options, etc.) using browser dev tools or online scanners.

*   **Phase 1: Input Validation & Output Escaping (XSS Prevention)**
    *   [ ] **Review Input Handling:** Audit all points where external data (`$_GET`, `$_POST`, `$_FILES`, `$_COOKIE`, API parameters) enters the application. Ensure data is validated against expected types and formats (e.g., using `filter_var`, type hints, regular expressions).
    *   [ ] **Sanitize Input:** Where validation isn't sufficient (e.g., free text fields allowing some markup), ensure appropriate sanitization occurs *before* storage or processing (though output escaping is the primary defense).
    *   [ ] **Audit Output Escaping:** Critically review all `.tpl` files. Ensure *every* variable outputted (`{$variable}`) uses the `|escape:'html'` modifier (or just `|escape`) unless it *intentionally* contains pre-sanitized safe HTML. Pay special attention to variables used within `<script>` tags or HTML attributes (`<a href="{$url|escape:'url'}" title="{$title|escape:'html'}" onclick="{$js|escape:'javascript'}">`).
    *   [ ] **JSON Encoding:** Ensure `json_encode` uses appropriate flags (like `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`) when embedding JSON in HTML `<script>` tags.

*   **Phase 2: Authentication, Authorization & Session Management**
    *   [ ] **Password Hashing:** Confirm modern, strong hashing is used (`password_hash`/`password_verify` via `pwg_password_hash`/`pwg_password_verify`). Ensure no legacy MD5 hashes remain without an upgrade path.
    *   [ ] **Session Security:** Review session configuration (`inc/config_default.php` & `functions_session.php`). Ensure secure flags on cookies (`HttpOnly`, `Secure` if HTTPS is enforced), `session.use_only_cookies = true`, and consider `session.cookie_samesite = Lax` or `Strict`. Review session fixation prevention (session ID regeneration on login).
    *   [ ] **Permission Checks:** Audit critical actions (admin functions, deletions, modifications) to ensure robust permission checks (`check_status`, checking user roles/groups) are performed *before* the action is executed. Verify checks in both PHP controllers and relevant API methods (`ws_*.php`).
    *   [ ] **Authorization Logic:** Review `calculate_permissions` and `get_sql_condition_FandF` for correctness and potential bypasses.

*   **Phase 3: SQL Injection Prevention & File Handling**
    *   [ ] **Prepared Statements:** Confirm *all* database queries involving external/variable data use prepared statements (via the dblayer or direct PDO/MySQLi calls if refactored). Remove any remaining instances of direct variable concatenation/interpolation into SQL strings.
    *   [ ] **File Upload Security:** Review `functions_upload.php`. Ensure strict validation of file types (check MIME type server-side, don't rely solely on extension), file size limits, and filename sanitization. Ensure uploaded files are stored securely (ideally outside web root or with restricted execution permissions) and path traversal is prevented.
    *   [ ] **File Inclusion/Path Manipulation:** Audit any code using file paths derived from user input (e.g., `include`, `require`, `file_get_contents`) to prevent path traversal vulnerabilities.

*   **Phase 4: CSRF Protection & Dependency Management**
    *   [ ] **CSRF Token Validation:** Ensure *all* state-changing actions (POST requests, sensitive GET requests like delete actions) generate and validate a PWG token (`get_pwg_token()`, `check_pwg_token()`).
    *   [ ] **Dependency Updates:** Regularly run `composer audit` / `npm audit` and update libraries with known vulnerabilities.
    *   [ ] **Configure Security Headers:** Implement or strengthen Content Security Policy (CSP), HTTP Strict Transport Security (HSTS), X-Frame-Options, X-Content-Type-Options, Referrer-Policy headers via web server configuration or PHP headers.

---

## Roadmap: Testing Strategy Implementation

**Goal:** Establish a comprehensive testing suite to improve code quality, catch regressions early during modernization and future development, and increase confidence in releases.

*   **Phase 0: Setup & Basic Unit Tests**
    *   [ ] **Install PHPUnit:** Add PHPUnit as a dev dependency via Composer (`composer require --dev phpunit/phpunit`).
    *   [ ] **Configure `phpunit.xml`:** Set up test suite configurations, bootstrap file (if needed), code coverage options, etc.
    *   [ ] **Identify Core Utilities:** Select simple, pure utility functions (e.g., in `inc/functions.php`, string manipulation, date formatting) that have minimal dependencies.
    *   [ ] **Write First Unit Tests:** Create corresponding test classes (e.g., `tests/Unit/FunctionsTest.php`). Write simple tests asserting the output of these utility functions for various inputs. Get familiar with PHPUnit assertions.
    *   [ ] **CI Integration (Basic):** Set up GitHub Actions (or similar) to automatically run PHPUnit tests on pushes/pull requests.

*   **Phase 1: Testing Refactored Services**
    *   [ ] **Target Service Classes:** As procedural code is refactored into classes (Phase 3 of PHP Roadmap), write unit tests for the *new* classes.
    *   [ ] **Mock Dependencies:** Use PHPUnit's mocking capabilities (or a library like Mockery) to isolate the class under test from its dependencies (like database connections or other services). Test the logic *within* the class.
    *   [ ] **Focus:** Test public methods, edge cases, expected exceptions, and different logical paths within the methods.
    *   [ ] **Example:** Test `CategoryService::getSubcategoryIds()` with various parent category scenarios, including non-existent parents or categories with no children. Mock the database interaction.

*   **Phase 2: Integration Testing**
    *   [ ] **Identify Key Interactions:** Focus on interactions between major components, such as:
        *   Authentication service verifying credentials against the database layer.
        *   API endpoint calling a service method and interacting with the database.
        *   Category service interacting with image service.
    *   [ ] **Write Integration Tests:** Create tests that involve multiple real components (but potentially still mock external systems like email). Use a dedicated testing database seeded with specific data.
    *   [ ] **Testing Database:** Set up a script to create/populate/tear down a separate test database for these tests.
    *   [ ] **Focus:** Verify that components work together correctly, data flows as expected, and database state changes are accurate.

*   **Phase 3: End-to-End (E2E) Testing**
    *   [ ] **Choose Framework:** Select a browser automation framework (Puppeteer, Playwright, Cypress).
    *   [ ] **Identify Critical User Flows:** Define key user journeys (e.g., User registers -> logs in -> uploads photo -> views photo -> adds comment -> logs out; Admin logs in -> changes setting -> verifies change).
    *   [ ] **Write E2E Tests:** Script these user flows using the chosen framework. Assert that expected elements are visible, forms submit correctly, and key data appears on the page.
    *   [ ] **CI Integration (Advanced):** Configure CI to run E2E tests, potentially requiring setting up a test instance of Piwigo with a browser environment.
    *   [ ] **Focus:** Validates the application from the user's perspective, catching regressions in UI or interactions missed by unit/integration tests.

*   **Phase 4: Code Coverage & Refinement**
    *   [ ] **Generate Coverage Reports:** Configure PHPUnit and Xdebug (or PCOV) to generate code coverage reports during test runs.
    *   [ ] **Analyze Reports:** Identify critical areas with low test coverage.
    *   [ ] **Increase Coverage:** Write additional unit and integration tests to cover important untested logic, focusing on complex or high-risk areas first.
    *   [ ] **Refine Tests:** Improve existing tests for clarity, robustness, and speed. Refactor test setup code.

---

## Roadmap: Performance Optimization

**Goal:** Identify and resolve performance bottlenecks in both the frontend and backend to improve page load times, responsiveness, and server resource utilization.

*   **Phase 0: Baselining & Tooling**
    *   [ ] **Identify Key Pages/Actions:** List critical pages (homepage with many albums, category with many thumbnails, picture page, admin batch manager) and actions (search, login, upload) to benchmark.
    *   [ ] **Establish Baselines:** Use browser developer tools (Network, Performance, Lighthouse tabs) and backend profiling tools (Xdebug profiler, Blackfire.io, or New Relic APM if available) to measure current performance metrics for the key pages/actions. Document these baselines.
    *   [ ] **Server Monitoring:** Ensure basic server monitoring (CPU, memory, disk I/O, database load) is in place.

*   **Phase 1: Frontend Optimization (Low-hanging Fruit)**
    *   [ ] **Image Optimization:**
        *   Ensure appropriate derivative sizes are configured and used. Avoid loading excessively large images for thumbnails.
        *   Implement lazy loading for images below the fold (using `loading="lazy"` attribute or a JS library).
        *   Consider modern image formats like WebP or AVIF (requires server/GD/Imagick support) for better compression.
    *   [ ] **Asset Delivery:**
        *   Ensure CSS/JS are minified and bundled (via build process from JS roadmap or Piwigo's combiner).
        *   Leverage browser caching effectively using appropriate HTTP headers (Cache-Control, Expires, ETags - configure on the webserver).
        *   Consider using a CDN for static assets (JS, CSS, core images) if traffic warrants it.
    *   [ ] **Critical Rendering Path:** Ensure necessary CSS is loaded in the `<head>` and defer non-essential JavaScript loading (`defer` or `async` attributes, dynamic imports if using modules).

*   **Phase 2: Backend Optimization (Database & PHP)**
    *   [ ] **Database Query Analysis:**
        *   Enable MySQL Slow Query Log or PostgreSQL equivalent (`log_min_duration_statement`).
        *   Analyze slow queries using `EXPLAIN`. Identify missing indexes, inefficient joins, or full table scans.
        *   Add necessary database indexes carefully (test impact).
        *   Rewrite inefficient queries.
    *   [ ] **PHP Profiling:**
        *   Use Xdebug/Blackfire to profile slow backend operations (e.g., complex page generation, API calls, batch actions).
        *   Identify bottlenecks in PHP code (inefficient loops, heavy computations, excessive function calls).
        *   Optimize identified PHP bottlenecks.
    *   [ ] **Caching Strategies:**
        *   Review existing Piwigo caching (`$conf->persistent_cache`, template cache). Ensure it's effective.
        *   Consider adding application-level caching (e.g., using Redis or Memcached via `$conf->persistent_cache` drivers if available/appropriate, or custom file caching) for frequently accessed, expensive-to-compute data (e.g., complex permission lookups, rendered blocks).

*   **Phase 3: Image Processing Optimization**
    *   [ ] **Library Choice:** Benchmark GD vs. Imagick vs. Vips (if available) for derivative generation speed and quality on your server. Configure Piwigo to use the best performer (`$conf->graphics_library`).
    *   [ ] **Derivative Generation:** Analyze if derivatives are being generated unnecessarily. Ensure on-the-fly generation (`i.php`) is performant or consider pre-generating derivatives during upload/sync if initial page loads are too slow.
    *   [ ] **Watermarking:** Assess the performance impact of watermarking if enabled.

*   **Phase 4: Continuous Monitoring & Tuning**
    *   [ ] **Regular Benchmarking:** Re-run performance tests periodically, especially after significant changes or Piwigo core updates.
    *   [ ] **Monitor Live Performance:** Use APM tools or server monitoring to watch for performance regressions in production.
    *   [ ] **Refine Caching:** Adjust cache lifetimes and strategies based on monitoring and usage patterns.

---

## Roadmap: UI/UX Refresh

**Goal:** Modernize the visual appearance and user interaction patterns of the frontend theme(s) and potentially the admin interface, improving usability, responsiveness, and aesthetic appeal.

*   **Phase 0: Analysis & Planning**
    *   [ ] **Identify Target Theme(s):** Decide which theme(s) (e.g., Bootstrap Darkroom, Modus, Default, Admin) are the focus.
    *   [ ] **Gather Feedback:** Collect user feedback (if possible) or perform a heuristic evaluation to identify usability pain points and areas for improvement.
    *   [ ] **Review Competitors/Inspiration:** Look at modern photo gallery interfaces and general web design trends.
    *   [ ] **Define Scope:** Decide on the extent of the refresh (e.g., minor CSS tweaks, component redesign, full layout overhaul).
    *   [ ] **Choose Framework/Tools (Optional):** Decide if adopting a CSS framework (like newer Bootstrap/Tailwind) or methodology (like BEM) is desired for the frontend theme.

*   **Phase 1: CSS Modernization & Cleanup**
    *   [ ] **Refactor CSS:**
        *   Replace outdated techniques (floats for layout) with Flexbox and CSS Grid.
        *   Introduce CSS Custom Properties (Variables) for colors, spacing, fonts to simplify theming and consistency.
        *   Organize CSS into a logical structure (e.g., base styles, layout, components, utilities). Consider using a preprocessor like SASS/SCSS if not already used (Bootstrap Darkroom uses SASS).
        *   Remove unused CSS rules (use browser dev tools coverage feature).
    *   [ ] **Responsiveness:** Ensure layouts adapt fluidly to different screen sizes (mobile, tablet, desktop). Test thoroughly.
    *   [ ] **Visual Polish:** Update basic styling (typography, spacing, borders, shadows) for a cleaner, more modern look.

*   **Phase 2: Component Redesign**
    *   [ ] **Identify Key Components:** Focus on high-impact UI elements (e.g., menubar, thumbnail grid/list, image info display, buttons, forms, modals/popups).
    *   [ ] **Redesign:** Update the visual design and interaction patterns of selected components based on Phase 0 analysis and modern UX principles.
    *   [ ] **Implement:** Rebuild the HTML (TPL) and CSS for these components. Ensure JavaScript interactions (from the JS roadmap) integrate cleanly.
    *   [ ] **Example:** Redesign the menubar to be more touch-friendly on mobile, or improve the layout of the picture information section.

*   **Phase 3: Accessibility Improvements (Overlap with a11y Roadmap)**
    *   [ ] **Integrate Accessibility:** Ensure UI changes from previous phases follow accessibility best practices (semantic HTML, contrast, keyboard navigation).
    *   [ ] **Focus:** Pay specific attention to forms, navigation, and interactive elements.

*   **Phase 4: User Testing & Iteration**
    *   [ ] **Gather Feedback:** Test the refreshed UI with target users (if possible).
    *   [ ] **Iterate:** Refine the design based on feedback and usability testing results.

---

## Roadmap: Accessibility (a11y) Enhancement

**Goal:** Ensure the Piwigo gallery is usable by people with disabilities, adhering to WCAG (Web Content Accessibility Guidelines) standards.

*   **Phase 0: Audit & Tooling**
    *   [ ] **Learn WCAG:** Familiarize the team with WCAG principles (Perceivable, Operable, Understandable, Robust) and success criteria (Levels A, AA). Target Level AA compliance.
    *   [ ] **Automated Audit:** Use browser extensions (like axe DevTools, WAVE) and online checkers to perform an initial automated scan of key pages (frontend and admin). Document findings.
    *   [ ] **Manual Audit Plan:** Prepare checklists for manual testing covering keyboard navigation, screen reader compatibility, color contrast, form labels, image alt text, etc.

*   **Phase 1: Keyboard Navigation & Focus Management**
    *   [ ] **Test Keyboard Navigation:** Navigate through all interactive elements (links, buttons, form fields, menus, modals) using only the Tab key (and Shift+Tab). Ensure all elements are reachable and the focus order is logical.
    *   [ ] **Visible Focus Indicators:** Ensure a clear visual focus indicator (outline) is present for *all* focusable elements. Enhance or fix default browser outlines if needed using CSS (`:focus-visible`).
    *   [ ] **Fix Traps:** Ensure keyboard focus doesn't get trapped within components like modals or carousels. Implement focus trapping/restoration for modals.

*   **Phase 2: Semantic HTML & ARIA Roles**
    *   [ ] **Review HTML Structure:** Audit TPL files. Use appropriate semantic HTML5 elements (`<nav>`, `<main>`, `<article>`, `<aside>`, `<button>`, etc.) instead of generic `<div>`s and `<span>`s where applicable.
    *   [ ] **Image Alt Text:** Ensure all meaningful `<img>` tags have descriptive `alt` attributes. Decorative images should have `alt=""`. Review how alt text is generated/stored for gallery images.
    *   [ ] **Form Labels:** Verify all form inputs (`<input>`, `<textarea>`, `<select>`) have correctly associated `<label>` elements using the `for` attribute.
    *   [ ] **ARIA Attributes:** Add ARIA roles, states, and properties where semantic HTML is insufficient to convey purpose or state, especially for custom JavaScript widgets (e.g., `role="dialog"`, `aria-modal="true"`, `aria-expanded`, `aria-label`, `aria-labelledby`).

*   **Phase 3: Color Contrast & Content Accessibility**
    *   [ ] **Check Color Contrast:** Use browser dev tools or online contrast checkers to ensure text has sufficient contrast against its background (WCAG AA requires 4.5:1 for normal text, 3:1 for large text). Adjust theme colors if necessary.
    *   [ ] **Information Conveyed by Color:** Ensure color is not the *only* way information is conveyed (e.g., use icons or text alongside color for status indicators).
    *   [ ] **Content Readability:** Ensure text is resizable without loss of content or functionality. Check line spacing and font choices.

*   **Phase 4: Screen Reader Testing & Refinement**
    *   [ ] **Basic Screen Reader Tests:** Use a screen reader (NVDA for Windows, VoiceOver for Mac/iOS, TalkBack for Android) to navigate key pages and interactions.
    *   [ ] **Check Announcements:** Ensure dynamic content changes (e.g., AJAX updates, error messages) are announced to screen readers (using ARIA live regions: `aria-live="polite"` or `aria-live="assertive"`).
    *   [ ] **Refine ARIA & Semantics:** Address issues found during screen reader testing by adjusting HTML structure or ARIA attributes.

---

## Roadmap: API Modernization

**Goal:** Refactor the existing web services (`ws.php`) towards a more modern, potentially RESTful architecture with better documentation and consistency.

*   **Phase 0: Analysis & Design**
    *   [ ] **Audit Existing API:** List all current `pwg.*` methods. Analyze their parameters, return structures, usage patterns (which are heavily used by core/plugins/apps?). Identify inconsistencies.
    *   [ ] **Define API Style:** Decide on the target style: Stick with RPC-style over HTTP, or move towards RESTful principles (using HTTP verbs, resource-based URLs, standard status codes)? REST is generally preferred for modern APIs.
    *   [ ] **Design Resource Structure (if RESTful):** Identify core resources (e.g., `/images`, `/albums`, `/tags`, `/users`, `/comments`). Define standard endpoints and HTTP methods (GET, POST, PUT, DELETE) for CRUD operations.
    *   [ ] **Authentication Strategy:** Review current session/token-based auth. Consider modern alternatives like API keys, JWT, or OAuth2 if needed for third-party integrations or stateless operation.
    *   [ ] **Error Handling Standard:** Define a consistent JSON structure for error responses (e.g., `{ "error": { "code": 404, "message": "Resource not found" } }`). Use appropriate HTTP status codes (400, 401, 403, 404, 500).

*   **Phase 1: Infrastructure & Routing**
    *   [ ] **Introduce Router:** Implement a proper HTTP router (e.g., Symfony Routing, FastRoute, or a simple custom one) instead of the single `ws.php` entry point with a `method` parameter. Map URL paths and HTTP verbs to specific controller actions or service methods.
    *   [ ] **Request/Response Objects:** Use dedicated Request and Response objects (e.g., from Symfony HttpFoundation or PSR-7 implementations) to handle input and output in a structured way.

*   **Phase 2: Refactor Existing Methods**
    *   [ ] **Group Functionality:** Group related API logic into dedicated controller classes or service classes.
    *   [ ] **Refactor Method by Method:** Gradually migrate existing `ws_*` functions:
        *   Define a new route (e.g., `GET /api/v2/tags`).
        *   Create a controller action or service method to handle the request.
        *   Use the Request object to get parameters.
        *   Call underlying business logic (ideally refactored services from PHP roadmap).
        *   Return data using the Response object (setting correct status code and JSON content).
        *   Handle exceptions and format error responses consistently.
    *   [ ] **Input Validation:** Implement robust validation for all API input parameters within the controller/service layer.

*   **Phase 3: Documentation & Versioning**
    *   [ ] **API Documentation:** Use a standard like OpenAPI (Swagger) to document the new API endpoints, parameters, request/response formats, and authentication methods. Generate interactive documentation.
    *   [ ] **Versioning:** Introduce API versioning (e.g., `/api/v2/...`) to allow backward compatibility if significant changes are made later.

*   **Phase 4: Deprecation & Client Migration**
    *   [ ] **Update Internal Clients:** Modify Piwigo's own frontend JS and potentially admin areas to use the new API endpoints.
    *   [ ] **Deprecate Old API:** Mark the old `ws.php` methods as deprecated. Provide a timeline for removal if external applications rely on them.

---

**General Advice:**

*   **Prioritize:** You can't do everything at once. Prioritize based on impact (security, performance, user experience) and feasibility. Security and testing should ideally be integrated *during* the JS/PHP modernization.
*   **Iterate:** Apply changes incrementally. Test frequently.
*   **Document:** Keep the `MODERNIZATION*.md` files updated.
*   **Consider the Ecosystem:** Changes, especially to the API or core PHP structure, may impact third-party themes and plugins. Communicate significant changes if applicable.
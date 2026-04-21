# Roadmap: Piwigo JavaScript Modernization

**Overall Goal:** To systematically transition the existing Piwigo JavaScript codebase from its current state (heavily reliant on jQuery, older ES5 syntax, and often inline within templates) to a modern, maintainable, and performant standard using Vanilla JavaScript (ES6+ features). This involves improving code structure, reducing external dependencies (especially jQuery), enhancing readability, and preparing the codebase for future evolution, all while ensuring the continued functionality and stability of the Piwigo gallery.

---

## [Phase 0: Preparation & Setup (Foundation)](#phase-0-preparation--setup-foundation---details)

*   **Goal:** Establish a robust and safe environment to facilitate the modernization process without introducing regressions.
*   **Tasks:**
    *   [ ] **Version Control Strategy:**
        *   [ ] Ensure the entire project is under Git.
        *   [ ] Create a dedicated feature branch (e.g., `feature/js-modernization`).
        *   [ ] Commit frequently with clear messages.
    *   [ ] **Testing Strategy Definition:**
        *   [ ] Define manual testing checklists for core admin and frontend areas.
        *   [ ] *Optional:* Implement basic end-to-end tests (e.g., Puppeteer).
    *   [ ] **Linting & Formatting Integration:**
        *   [ ] Install ESLint & Prettier (or Biome JS).
        *   [ ] Configure rules (ES6+, style guide).
        *   [ ] Integrate tools into editor/Git hooks (optional).
        *   [ ] Run initial formatting/linting pass.
    *   [ ] **Project Documentation (`MODERNIZATION.md`):**
        *   [ ] Create `MODERNIZATION.md`.
        *   [ ] Outline roadmap, decisions, conventions.
        *   [ ] Plan to update throughout.
    *   [ ] **Team Communication & Conventions (if applicable):**
        *   [ ] Agree on goals, phases, tools, conventions.
        *   [ ] Define code review process.

---

## [Phase 1: Foundational Cleanup & Syntax Modernization (Low-Risk Wins)](#phase-1-foundational-cleanup--syntax-modernization-low-risk-wins---details)

*   **Goal:** Improve code readability and consistency using modern syntax without major refactoring.
*   **Tasks:**

    *   [ ] **ES6 Syntax Adoption:**
        *   [ ] Replace `var` with `let`/`const`.
        *   [ ] Convert `function()` to arrow functions (`=>`) where appropriate.
        *   [ ] Use template literals (`` ` ``).
    *   [ ] **Basic jQuery Reduction:**
        *   [ ] Replace basic selectors (`$('#id')`, `$('.class')`) with native equivalents.
        *   [ ] Replace `.html()`, `.text()`, `.val()` with native equivalents.
        *   [ ] Replace `.attr()`, `.data()` with native equivalents.
        *   [ ] Replace `.addClass()`, `.removeClass()`, `.toggleClass()` with `element.classList`.
        *   [ ] Replace `.show()`, `.hide()` with CSS class toggling.
    *   [ ] **Scope Improvement (IIFEs):**
        *   [ ] Wrap major `.js` files in IIFEs `(function() { ... })();`.

---

## [Phase 2: Core Logic Modernization & Decoupling](#phase-2-core-logic-modernization--decoupling---details)

*   **Goal:** Refactor core functionalities like AJAX and separate JavaScript logic from templates.
*   **Tasks:**

    *   [ ] **[AJAX Refactoring (`fetch`, `async/await`)]:**
        *   [ ] Identify all `$.ajax`, `$.get`, `$.post`, `$.getJSON` calls.
        *   [ ] Create reusable `fetch`/`async/await` helper(s) for Piwigo API calls.
        *   [ ] Replace jQuery AJAX calls with helpers.
        *   [ ] Update error handling for Promises/`try...catch`.
    *   [ ] **[Extract Inline JS from TPLs]:**
        *   [ ] Identify inline `<script>` and `{footer_script}` blocks.
        *   [ ] Move logic to separate `.js` files.
        *   [ ] Replace inline blocks with `{combine_script}` calls.
        *   [ ] Pass data from PHP/Smarty using `data-*` attributes or a global JS config object.
        *   [ ] Update `.js` files to read data after DOM load.
    *   [ ] **[Event Handling]:**
        *   [ ] Replace direct jQuery bindings (`.click()`, `.change()`) with `element.addEventListener()`.
        *   [ ] Replace jQuery event delegation (`.on()`) with native delegation.
    *   [ ] **[jQuery UI / Plugin Interactions]:**
        *   [ ] Refactor plugin initializations to use native selectors first, then apply the jQuery plugin method.

---

## [Phase 3: Structural Improvements (Optional / Advanced)](#phase-3-structural-improvements-optional--advanced---details)

*   **Goal:** Implement a more organized and scalable code structure. Choose **either** Option A **or** Option B.
*   **Tasks:**

    *   [ ] **(Option A - Simpler) Namespace Pattern:**
        *   [ ] Define global namespace objects (e.g., `window.PiwigoAdminUtils`).
        *   [ ] Attach functions/variables to these namespaces within IIFEs.
        *   [ ] Update calls to use namespaces.
    *   [ ] **(Option B - Full Modernization) ES Modules & Build Process:**
        *   [ ] Set up a build tool (Vite, Webpack, etc.).
        *   [ ] Configure entry points and output directory.
        *   [ ] Refactor `.js` files into ES Modules (`import`/`export`).
        *   [ ] Run build process.
        *   [ ] **Crucial:** Adapt Piwigo TPLs to load the *bundled* JS file(s) instead of individual sources via `{combine_script}`.

---

## [Phase 4: Dependency Management & Final Polish](#phase-4-dependency-management--final-polish---details)

*   **Goal:** Clean up external dependencies, particularly jQuery and its plugins, and ensure the final codebase is robust and performant.
*   **Tasks:**

    *   [ ] **Evaluate jQuery Plugins:**
        *   [ ] List all jQuery plugins used.
        *   [ ] For each: check maintenance status, research Vanilla JS alternatives.
        *   [ ] Decide: Keep, Update, Replace, or Remove. Document decision.
        *   [ ] Implement replacements/updates.
    *   [ ] **Final jQuery Removal:**
        *   [ ] Search for and replace any remaining `$` or `jQuery` usage.
        *   [ ] *Goal:* Remove the jQuery library include entirely if possible.
        *   [ ] *Testing:* Perform extensive regression testing.
    *   [ ] **Performance Review:**
        *   [ ] Use browser developer tools (Performance/Lighthouse) on key pages.
        *   [ ] Identify and optimize bottlenecks.
    *   [ ] **Code Review & Documentation:**
        *   [ ] Conduct code reviews.
        *   [ ] Finalize `MODERNIZATION.md`.
    *   [ ] **Cleanup:**
        *   [ ] Remove unused/obsolete `.js` files.
        *   [ ] Remove dead code within refactored files.

---
---

## Detailed Roadmap Explanations & Examples

*(This section contains the more detailed explanations and code examples corresponding to the phases outlined above.)*

### Phase 0: Preparation & Setup (Foundation) - Details

*   **Goal:** Establish a robust and safe environment to facilitate the modernization process without introducing regressions. This phase is crucial for minimizing risks and ensuring consistency.
*   **Tasks:**
    1.  **Version Control Strategy:**
        *   **Action:** Ensure the entire project, including all theme and plugin files containing JavaScript, is managed under Git. Create a long-lived feature branch specifically for these modernization efforts (e.g., `feature/js-modernization` or `refactor/modernize-js`). Avoid committing modernization changes directly to `main` or `develop` until thoroughly tested and reviewed.
        *   **Rationale:** Provides a safety net, allows easy rollback, facilitates collaboration, and keeps the main development line stable. Frequent, small, atomic commits on the feature branch are highly recommended.
        *   **Example Command:** `git checkout -b feature/js-modernization`

    2.  **Testing Strategy Definition:**
        *   **Action:** Explicitly define *how* changes will be validated. This likely involves significant manual testing initially. Create checklists covering:
            *   **Admin Areas:** Batch Manager (filters, actions), User Management (list, edit pop-in, add, selection actions), Group Management, Tag Management, Plugin/Theme Management (activation, deactivation, configuration), Configuration Pages (options saving), Upload Forms (web, applications), History/Stats pages.
            *   **Frontend Areas:** Thumbnail display (different views if applicable), Picture page interactions (navigation, metadata display, comments, rating, slideshows - including PhotoSwipe/Slick if used), Menubar functionality (dropdowns, links), Search functionality (quick search, advanced search page), Login/Registration/Profile pages, Plugin integrations (e.g., mapping plugins, contact forms).
        *   **Considerations:** Test across major browsers (Chrome, Firefox, Safari, Edge). Test different user roles (guest, logged-in user, admin, webmaster). Test with different themes if JS interactions vary.
        *   **Optional Automation:** If resources allow, implement basic end-to-end tests using a tool like Puppeteer to cover critical user flows (login, upload a photo, view a photo, search). This provides a baseline regression check.

    3.  **Linting & Formatting Integration:**
        *   **Action:**
            *   Install ESLint (`npm install eslint --save-dev` or `bun add -d eslint`). Initialize its configuration (`npx eslint --init`), choosing options that enforce modern JavaScript (ES6+), potentially using a standard style guide like `eslint:recommended` or Airbnb/Standard as a base. Consider adding plugins like `eslint-plugin-no-jquery` later to help identify jQuery usage.
            *   Install Prettier (`npm install prettier --save-dev --save-exact` or `bun add -d --exact prettier`). Configure it (e.g., via `.prettierrc.json`) for consistent code style (indentation, quotes, spacing, etc.). Or use Biome JS (`npm install --save-dev --save-exact @biomejs/biome` or `bun add -d --exact @biomejs/biome`) for combined linting/formatting.
            *   Integrate these tools into your editor and potentially into Git hooks (using something like Husky or built into CaptainHook if configured) to automate checks.
        *   **Rationale:** Enforces code quality and consistency from the start, catches syntax errors early, makes code reviews easier, and reduces stylistic debates.
        *   **Initial Pass:** Run the formatter (`npx prettier --write .` or `npx biome format --write .`) and linter (`npx eslint --fix .` or `npx biome check --apply .`) across the existing `.js` files to establish a clean, consistent baseline before making logical changes. Address any initial errors reported.

    4.  **Project Documentation (`MODERNIZATION.md`):**
        *   **Action:** Create this file at the project root.
        *   **Content:** Outline the roadmap, document key decisions (e.g., "We will replace jQuery AJAX with Fetch API", "We are keeping Selectize for now"), list conventions (e.g., "Use `const` by default", "Prefix private helper functions with `_`"), track progress section by section, note down any tricky parts or workarounds encountered.
        *   **Rationale:** Serves as a central reference point for the modernization effort, ensuring consistency and knowledge sharing, especially if multiple people are involved or if the work spans a long period.

    5.  **Team Communication & Conventions (if applicable):**
        *   **Action:** Hold a kickoff meeting or discussion to ensure alignment on the goals, phases, chosen tools, and coding conventions. Define how changes will be reviewed (e.g., pull requests against the feature branch).
        *   **Rationale:** Prevents duplicated effort, inconsistent approaches, and ensures everyone is working towards the same standard.

---

### Phase 1: Foundational Cleanup & Syntax Modernization (Low-Risk Wins) - Details

*   **Goal:** Improve the general quality, readability, and basic structure of the JavaScript code using modern syntax features, without altering core logic significantly. These are generally safe changes with immediate benefits.
*   **Tasks:**

    1.  **ES6 Syntax Adoption:**
        *   **`var` to `let`/`const`:**
            *   **How:** Systematically go through `.js` files. If a variable's value never changes after initialization, use `const`. If it might be reassigned (e.g., loop counters, state variables), use `let`.
            *   **Before:** `var userId = 10; var username = "guest"; if (condition) { var count = 5; }`
            *   **After:** `const userId = 10; let username = "guest"; if (condition) { let count = 5; } // 'let' respects block scope`
            *   **Why:** Improves variable scoping (block scope for `let`/`const` vs. function scope for `var`), prevents accidental redeclaration, makes intent clearer (`const` for non-reassigned variables).
            *   **Challenge:** Be careful inside loops; ensure `let` is used if the loop variable itself isn't reassigned but variables declared *inside* the loop need block scoping.
            *   **Testing:** Mostly syntax checks, but verify loops and conditional logic still behave as expected.
        *   **Arrow Functions:**
            *   **How:** Identify anonymous functions used as callbacks (e.g., in event listeners, `setTimeout`, `forEach`). Convert `function(arg) { return arg * 2; }` to `(arg) => arg * 2;` or `arg => arg * 2;` (if one argument). For multi-line functions: `(arg1, arg2) => { /* code */; return result; };`.
            *   **Before:** `items.forEach(function(item) { console.log(item.name); });`
            *   **After:** `items.forEach(item => { console.log(item.name); });`
            *   **Challenge:** Arrow functions do *not* have their own `this` binding; they inherit it from the enclosing lexical scope. If a callback relies on `this` referring to the element triggering an event or a specific object context set by jQuery, you might need to stick with `function()` or use `event.currentTarget`.
            *   **Testing:** Verify event handlers and callbacks still operate correctly, especially those manipulating `this`.
        *   **Template Literals:**
            *   **How:** Find string concatenations using `+`, especially complex ones involving variables. Rewrite using backticks.
            *   **Before:** `var msg = 'User "' + username + '" (ID: ' + userId + ') updated.';`
            *   **After:** ``const msg = `User "${username}" (ID: ${userId}) updated.`;``
            *   **Why:** Much easier to read and write complex strings with embedded expressions. Supports multi-line strings without needing `\n`.
            *   **Testing:** Ensure output strings are identical to the original, paying attention to spacing and quotes.

    2.  **Basic jQuery Reduction (Selectors & Simple DOM):**
        *   **How:** Use find/replace or manual editing. Start with the most frequent and simple patterns.
        *   **Selectors:**
            *   **Before:** `var $container = $('#userList'); var $buttons = $('.action-button');`
            *   **After:** `const container = document.getElementById('userList'); const buttons = document.querySelectorAll('.action-button'); // NodeList`
            *   **Why:** Removes jQuery dependency for basic selection, often faster. `querySelectorAll` returns a static NodeList, not a live HTMLCollection like `getElementsByClassName`.
        *   **Text/HTML/Value Manipulation:**
            *   **Before:** `$('#message').html('<b>Success!</b>'); $('.username').text(user.name); $('#inputField').val(user.value);`
            *   **After:** `document.getElementById('message').innerHTML = '<b>Success!</b>'; document.querySelectorAll('.username').forEach(el => el.textContent = user.name); document.getElementById('inputField').value = user.value;`
            *   **Why:** Direct native equivalents. Use `textContent` generally unless you specifically need to set HTML markup.
        *   **Attribute/Data Manipulation:**
            *   **Before:** `$('a.preview').attr('href', url); var userId = $('.user-info').data('userid');`
            *   **After:** `document.querySelector('a.preview').setAttribute('href', url); // or .href = url const userId = document.querySelector('.user-info').dataset.userid;`
            *   **Why:** Standard DOM APIs. `data-*` attributes are accessed via the `dataset` property (camelCased).
        *   **Class Manipulation:**
            *   **Before:** `$('#myElement').addClass('active').removeClass('hidden');`
            *   **After:** `const element = document.getElementById('myElement'); element.classList.add('active'); element.classList.remove('hidden');`
            *   **Why:** Native, efficient way to manage CSS classes.
        *   **Show/Hide:**
            *   **Before:** `$('#loadingSpinner').show(); $('#mainContent').hide();`
            *   **After (CSS Class approach - Recommended):** `document.getElementById('loadingSpinner').classList.remove('hidden'); document.getElementById('mainContent').classList.add('hidden'); /* CSS: .hidden { display: none !important; } */`
            *   **After (Direct Style - Less Recommended):** `document.getElementById('loadingSpinner').style.display = 'block'; /* or 'flex', etc. */ document.getElementById('mainContent').style.display = 'none';`
            *   **Why:** Using CSS classes (`.hidden` or similar) is better for separation of concerns and allows for transitions/animations.
        *   **Focus:** Target admin files like `common.js`, `batchManagerGlobal.js`, `user_list.js` for common patterns.
        *   **Testing:** Verify that elements are correctly selected, text/values/attributes are updated, and visibility toggles function as expected.

    3.  **Scope Improvement (IIFEs):**
        *   **How:** Add `(function() {` at the very beginning of the file and `})();` at the very end. Indent the existing code one level.
        *   **Before (example.js):**
            ```javascript
            var config = { setting: true };
            function initPage() { /* uses config */ }
            initPage();
            ```
        *   **After (example.js):**
            ```javascript
            (function() {
                const config = { setting: true };
                function initPage() { /* uses config */ }
                initPage();
            })();
            ```
        *   **Why:** Immediately isolates the file's scope, preventing `config` and `initPage` from becoming global variables accessible (and potentially overwritten) by other scripts. It's a crucial step before considering modules.
        *   **Testing:** Ensure the script still functions correctly. Check the browser's console for errors related to undefined variables (which might indicate accidental reliance on globals from *other* files).

---

### Phase 2: Core Logic Modernization & Decoupling - Details

*   **Goal:** Refactor key functionalities (AJAX, event handling) and decouple JavaScript logic from the HTML templates for better maintainability and testability.
*   **Tasks:**

    1.  **AJAX Refactoring (`fetch`, `async/await`):**
        *   **How:** Identify all `$.ajax`, `$.get`, `$.post`, `$.getJSON` calls. Create helper functions using `fetch` and `async/await` to encapsulate the common logic for interacting with `ws.php`. This helper should handle setting the method, format, parameters (including PWG token for POST), sending the request, checking the response status, parsing JSON, and checking the Piwigo-specific `stat` property (`ok` vs. `fail`).
        *   **Before (jQuery with callbacks):**
            ```javascript
            jQuery.ajax({
                url: "ws.php?format=json&method=pwg.tags.getList",
                type: "GET", // Or POST with data: { pwg_token: token, ... }
                dataType: "json",
                success: function(data) {
                    if (data.stat === 'ok') {
                        console.log('Tags:', data.result.tags);
                        updateTagUI(data.result.tags); // Application specific logic
                    } else {
                        console.error('API Error:', data.message);
                        showErrorMessage(data.message); // Application specific error handling
                    }
                },
                error: function(xhr, status, error) {
                    console.error("AJAX Error:", status, error);
                    showNetworkError(); // Application specific network error handling
                }
            });
            ```
        *   **After (Fetch + async/await helper):**
            ```javascript
            // Helper function (potentially in a shared utils file/module)
            async function callPiwigoApi(method, params = {}, options = {}) {
                const defaultOptions = {
                    method: 'GET', // Default to GET
                    headers: {}, // Default headers
                };
                const fetchOptions = { ...defaultOptions, ...options };

                let url = `ws.php?format=json&method=${method}`;
                let body = null;

                // Prepare URLSearchParams for body or query string
                const urlParams = new URLSearchParams();
                for (const key in params) {
                    if (Object.hasOwnProperty.call(params, key)) {
                        if (Array.isArray(params[key])) { // Handle array params like tag_id[]=1&tag_id[]=2
                            params[key].forEach(value => urlParams.append(`${key}[]`, value));
                        } else {
                            urlParams.append(key, params[key]);
                        }
                    }
                }

                // Include PWG token for POST requests (assuming it's available, e.g., window.piwigoConfig.token)
                if (fetchOptions.method.toUpperCase() === 'POST' && window.piwigoConfig && window.piwigoConfig.token) {
                   urlParams.append('pwg_token', window.piwigoConfig.token);
                }

                // Append params to URL for GET, set body for POST
                if (fetchOptions.method.toUpperCase() === 'POST') {
                    fetchOptions.headers['Content-Type'] = 'application/x-www-form-urlencoded';
                    fetchOptions.body = urlParams.toString();
                } else { // GET
                    const queryString = urlParams.toString();
                    if (queryString) {
                        url += `&${queryString}`;
                    }
                }

                try {
                    const response = await fetch(url, fetchOptions);
                    if (!response.ok) { // Checks for network errors (4xx, 5xx)
                        throw new Error(`HTTP error! Status: ${response.status} ${response.statusText}`);
                    }
                    const data = await response.json(); // Parse the JSON response
                    if (data.stat === 'ok') {
                        return data.result; // Success: return the actual result data
                    } else {
                        // Piwigo API specific error
                        throw new Error(`API Error (${data.err}): ${data.message}`);
                    }
                } catch (error) {
                    console.error(`API call failed for ${method}:`, error);
                    // Re-throw the error so the calling function knows something went wrong
                    throw error;
                }
            }

            // Example Usage
            async function loadAndDisplayTags() {
                try {
                    const tagsResult = await callPiwigoApi('pwg.tags.getList'); // Default is GET
                    if (tagsResult && tagsResult.tags) {
                         console.log('Tags:', tagsResult.tags);
                         updateTagUI(tagsResult.tags); // Your application logic
                    }
                } catch (error) {
                    // Handle both network and API errors here
                    showErrorMessage(error.message); // Your application error display logic
                }
            }

            loadAndDisplayTags();
            ```
        *   **Why:** Creates a standard, reusable way to interact with the Piwigo API. Simplifies asynchronous code flow dramatically. Centralizes common error checking (network vs. API `stat`). Removes jQuery's AJAX module dependency.
        *   **Testing:** Verify that GET and POST requests work. Ensure parameters (including arrays and the `pwg_token` for POST) are correctly sent. Check that both successful responses (`stat: 'ok'`) and Piwigo API errors (`stat: 'fail'`) are handled appropriately. Test network failure scenarios (e.g., server down, 404).

    2.  **Extract Inline JS from TPLs:**
        *   **How:**
            1.  **Identify:** Locate all `<script>` tags and `{footer_script}` blocks within `.tpl` files.
            2.  **Create JS Files:** For each TPL with significant JS, create a corresponding `.js` file (e.g., `admin/themes/default/js/user_list.js` for `admin/themes/default/template/user_list.tpl`).
            3.  **Move Code:** Cut the JS code from the TPL and paste it into the new `.js` file. Wrap it in a `DOMContentLoaded` listener or an IIFE if not already done.
            4.  **Replace in TPL:** Remove the original `<script>`/`{footer_script}` block and add a `{combine_script}` directive pointing to the new file (e.g., `{combine_script id='userList' path='admin/themes/default/js/user_list.js' load='footer'}`).
            5.  **Data Transfer:**
                *   Find all instances where PHP/Smarty variables are directly embedded (e.g., `var userId = {$user.id};`, `var config = {json_encode($options)};`).
                *   **Method 1 (Data Attributes - Preferred for element-specific data):** Add `data-*` attributes to the relevant HTML element(s) in the TPL.
                    ```smarty
                    {* In user_list.tpl *}
                    <div id="user-management-area" data-token="{$PWG_TOKEN}" data-confirm-msg="{'Are you sure?'|translate|escape:javascript}">
                      {* ... table or list of users ... *}
                    </div>
                    ```
                    In `user_list.js`, access it:
                    ```javascript
                    document.addEventListener('DOMContentLoaded', () => {
                      const area = document.getElementById('user-management-area');
                      const token = area.dataset.token;
                      const confirmMsg = area.dataset.confirmMsg;
                      // ... use token and confirmMsg
                    });
                    ```
                *   **Method 2 (Global Config Object - Good for page-wide settings):** In a base layout template (`header.tpl` or `footer.tpl`), output a single script block:
                    ```smarty
                    {* In footer.tpl *}
                    <script>
                      window.piwigoConfig = window.piwigoConfig || {};
                      window.piwigoConfig.token = '{$PWG_TOKEN|escape:javascript}';
                      window.piwigoConfig.rootUrl = '{$ROOT_URL|escape:javascript}';
                      // Add other frequently needed global settings
                      {if $ADMIN_PAGE_TITLE}
                      window.piwigoConfig.pageTitle = '{$ADMIN_PAGE_TITLE|escape:javascript}';
                      {/if}
                    </script>
                    ```
                    In any `.js` file, access it: `const token = window.piwigoConfig.token;`.
            6.  **JS Update:** Modify the `.js` file to read the necessary data from the chosen method (`dataset` or global config object) *inside* the `DOMContentLoaded` listener or IIFE.
        *   **Why:** Enforces separation of concerns (HTML vs. JS). Makes TPL files much cleaner and focused on structure. Allows JavaScript to be independently linted, tested, formatted, and potentially bundled. Standardizes data passing from server to client.
        *   **Testing:** This is a critical refactoring step. Verify that all functionality previously handled by inline scripts still works. Check the browser console for errors. Ensure data passed via attributes or config object is correct.

    3.  **Event Handling:**
        *   **How:** Replace `.click(fn)`, `.change(fn)`, `.submit(fn)`, `.hover(fnIn, fnOut)`, `.on('event', fn)` with `element.addEventListener('event', fn)`. For delegation, attach the listener to a static parent and check the `event.target`.
        *   **Direct Binding Before:** `$('#saveButton').click(saveSettings);`
        *   **Direct Binding After:** `document.getElementById('saveButton')?.addEventListener('click', saveSettings);` (Use optional chaining `?.` if the element might not exist on all pages where the script runs).
        *   **Delegation Before:** `$('#userList').on('click', '.delete-icon', handleDeleteClick);`
        *   **Delegation After:**
            ```javascript
            const userList = document.getElementById('userList');
            if (userList) {
                userList.addEventListener('click', (event) => {
                    // Use closest() to handle clicks on icons inside the button, etc.
                    const deleteIcon = event.target.closest('.delete-icon');
                    if (deleteIcon) {
                        handleDeleteClick(event, deleteIcon); // Pass element if needed
                    }
                });
            }
            ```
        *   **Hover Before:** `$('.thumbnail').hover(showTooltip, hideTooltip);`
        *   **Hover After:**
            ```javascript
            document.querySelectorAll('.thumbnail').forEach(thumb => {
                thumb.addEventListener('mouseenter', showTooltip);
                thumb.addEventListener('mouseleave', hideTooltip);
            });
            ```
        *   **Why:** Uses the standard W3C event model. Often more performant, especially with delegation. Reduces jQuery dependency.
        *   **Testing:** Thoroughly test all user interactions – clicks, form submissions, input changes, mouseovers/mouseouts – to ensure they trigger the correct actions.

    4.  **jQuery UI / Plugin Interactions:**
        *   **How:** Locate the jQuery selectors used to initialize plugins (`$('.selector').pluginName(...)`). Replace the selector part with Vanilla JS (`document.querySelectorAll`), then iterate (if needed) and apply the jQuery plugin method to the jQuery-wrapped element `$(element)`.
        *   **Before:** `$('.selectize-me').selectize({ /* options */ });`
        *   **After:**
            ```javascript
            document.querySelectorAll('.selectize-me').forEach(selectElement => {
                $(selectElement).selectize({ /* options */ });
            });
            ```
        *   **Why:** Minimizes the scope where jQuery is strictly needed, making the surrounding code more native JS. Clarifies that jQuery is being used *specifically* for the plugin.
        *   **Testing:** Ensure all third-party UI components (datepickers, sliders, select boxes, colorboxes, tooltips) are still initialized correctly and function as expected.

---

### Phase 3: Structural Improvements (Optional / Advanced) - Details

*   **Goal:** Implement a more organized and scalable code structure, essential for larger applications or easier long-term maintenance. Choose **either** Option A (simpler, no build tool) **or** Option B (more complex, requires build tool).
*   **Tasks:**

    1.  **(Option A - Simpler) Namespace Pattern:**
        *   **How:** Define one or more global objects (e.g., `window.PiwigoAdmin = {}; window.PiwigoUtils = {};`). Inside each file's IIFE, attach functions and properties to these namespaces.
        *   **Before (multiple files with globals):**
            ```javascript
            // file1.js
            function validateEmail(email) { /*...*/ }
            // file2.js
            function setupUserForm() { var isValid = validateEmail(emailInput.value); /*...*/ }
            ```
        *   **After (using namespaces within IIFEs):**
            ```javascript
            // utils.js
            window.PiwigoUtils = window.PiwigoUtils || {};
            (function(Utils) {
                'use strict';
                Utils.validateEmail = function(email) { /*...*/ return true; };
            })(window.PiwigoUtils);

            // userForm.js
            window.PiwigoAdmin = window.PiwigoAdmin || {};
            window.PiwigoAdmin.UserForm = window.PiwigoAdmin.UserForm || {};
            (function(UserForm, Utils) {
                'use strict';
                UserForm.setup = function() {
                    const emailInput = document.getElementById('email');
                    const isValid = Utils.validateEmail(emailInput.value);
                    // ...
                };
                document.addEventListener('DOMContentLoaded', UserForm.setup);
            })(window.PiwigoAdmin.UserForm, window.PiwigoUtils);
            ```
        *   **Why:** Provides a structured way to group related functions and avoid global name collisions without requiring a build step. Improves organization over loose functions.
        *   **Challenge:** Still relies on global objects (`window.PiwigoAdmin`, `window.PiwigoUtils`). Requires careful manual management of the order in which scripts are loaded (`{combine_script}` order) to ensure dependencies are met (e.g., `utils.js` must load before `userForm.js`).

    2.  **(Option B - Full Modernization) ES Modules & Build Process:**
        *   **How:**
            1.  **Setup Build Tool:** Install Node.js/npm (or Bun). Install Vite: `npm install vite --save-dev`. Create a `vite.config.js` file.
            2.  **Configure Vite:** Define entry points (e.g., `admin-main.js`, `public-main.js`) and the output directory (e.g., `dist/`). Configure it to output modern JS without transpiling unless needed for browser compatibility.
                ```javascript
                // vite.config.js (simplified example)
                import { resolve } from 'path';
                import { defineConfig } from 'vite';

                export default defineConfig({
                  build: {
                    outDir: resolve(__dirname, 'admin/themes/default/dist'), // Example output
                    emptyOutDir: true, // Clean the output directory before build
                    rollupOptions: {
                      input: {
                        admin: resolve(__dirname, 'admin/themes/default/js/admin-main.js'), // Your admin entry point
                      },
                      output: {
                        entryFileNames: `assets/[name].js`, // Output structure
                        chunkFileNames: `assets/[name].js`,
                        assetFileNames: `assets/[name].[ext]`
                      }
                    },
                    target: 'esnext' // Output modern JavaScript
                  }
                });
                ```
            3.  **Refactor to Modules:** Go through your `.js` files. Use `export` for functions/variables needed by other files. Use `import` to bring in dependencies.
                ```javascript
                // admin/themes/default/js/utils/validation.js
                export function validateEmail(email) { /* ... */ return true; }

                // admin/themes/default/js/admin-main.js (Entry Point)
                import { validateEmail } from './utils/validation.js';
                // import other modules...

                document.addEventListener('DOMContentLoaded', () => {
                  console.log('Admin scripts loaded.');
                  const emailInput = document.getElementById('admin_mail');
                  if (emailInput) {
                    emailInput.addEventListener('blur', () => {
                      if (!validateEmail(emailInput.value)) {
                        // show error
                      }
                    });
                  }
                  // Initialize other admin features...
                });
                ```
            4.  **Run Build:** Execute `npx vite build` from your project root (or add it to `package.json` scripts).
            5.  **Integrate with Piwigo:** Modify the base TPL file (e.g., `admin/themes/default/template/footer.tpl`) to load the *single* bundled file generated by Vite. Remove all the `{combine_script}` calls for the JS files included in the bundle. Add a `<script type="module">` tag.
                ```smarty
                {* In admin/themes/default/template/footer.tpl (example) *}
                {* Remove combine_script calls for files included in the bundle *}
                {* {combine_script id='common' ...} *}
                {* {combine_script id='userList' ...} *}

                {* Add the bundled output *}
                <script type="module" src="{$ROOT_URL}admin/themes/default/dist/assets/admin.js?v={$smarty.const.PHPWG_VERSION}"></script>

                {* Keep combine_script only for truly external libraries NOT included in your bundle *}
                {get_combined_scripts load='footer'}
                ```
        *   **Why:** Provides the standard, modern way of handling JavaScript dependencies and organization. Enables advanced features like tree-shaking (removing unused code from the final bundle), code splitting (for performance), and easy integration of libraries from npm. Static analysis catches dependency errors early.
        *   **Challenge:** Setting up the build process requires initial effort. The most significant challenge is integrating the output bundle with Piwigo's templating system, potentially bypassing or modifying how `combine_script` and `get_combined_scripts` work for your bundled assets. Careful path configuration in Vite and the TPL is crucial.

---

### Phase 4: Dependency Management & Final Polish - Details

*   **Goal:** Clean up external dependencies, particularly jQuery and its associated plugins, conduct final performance checks, and ensure the modernized codebase is well-documented and polished.
*   **Tasks:**

    1.  **Evaluate jQuery Plugins:**
        *   **How:** Systematically review each jQuery plugin identified in the codebase (e.g., `jquery.plupload.queue`, `jquery.colorbox`, `jquery.selectize`, `jquery.ui`, `jquery.confirm`, `jquery.tipTip`, `jquery.Jcrop`, `mousetrap`, `slick-carousel`, `photoswipe`, `jquery.cookie`, `jquery.equalheights`, etc.).
        *   **Assessment Criteria:**
            *   **Necessity:** Is the functionality provided by the plugin critical? Can it be achieved reasonably with modern CSS (e.g., tooltips, simple modals) or a small amount of Vanilla JS?
            *   **Maintenance:** When was the plugin last updated? Is it actively maintained? Are there known compatibility issues with modern browsers or potential security vulnerabilities?
            *   **Alternatives:** Search for "Vanilla JS [plugin purpose]" (e.g., "Vanilla JS modal library", "Vanilla JS select box"). Popular choices exist for many common UI patterns (e.g., Flatpickr for date pickers, Tippy.js for tooltips, Choices.js for select boxes, Swiper.js for carousels).
            *   **Replacement Effort:** How complex would it be to replace the plugin? Does it require significant changes to the surrounding UI logic?
        *   **Action:**
            *   **Replace:** If a good Vanilla JS alternative exists and the effort is reasonable, migrate to it. This often involves updating initialization code, event handling, and potentially CSS.
            *   **Update:** If keeping the jQuery plugin, update it to the latest version compatible with your (potentially removed) jQuery version or modern browsers.
            *   **Keep:** If replacement is too complex or no suitable alternative exists, keep the plugin but ensure its initialization (Phase 2) and usage are as clean as possible. You might need to keep jQuery *just* for these plugins.
            *   **Remove:** If the functionality is no longer deemed essential or can be easily removed, eliminate the plugin and its associated code.
        *   **Documentation:** Record the decision for each plugin in `MODERNIZATION.md`.

    2.  **Final jQuery Removal:**
        *   **How:** After addressing plugins, perform a final, thorough search across all `.js` files for any remaining `$` or `jQuery` identifiers. Common leftovers include utility functions like `$.extend`, `$.each`, `$.grep`, `$.proxy`, `$.isEmptyObject`, or complex selectors/traversals not caught earlier. Replace these with native equivalents (`Object.assign`, `array.forEach`, `array.filter`, `function.bind`, checking `Object.keys(obj).length === 0`, etc.).
        *   **Goal:** If all direct jQuery calls and jQuery-dependent plugins have been removed or replaced, you can attempt to remove the jQuery library itself from being loaded (likely via `{combine_script id='jquery' ...}`).
        *   **Before:** `var mergedOptions = $.extend({}, defaults, userOptions);`
        *   **After:** `const mergedOptions = Object.assign({}, defaults, userOptions);`
        *   **Testing:** Removing the core jQuery library is a major step. Test *all* aspects of the application thoroughly, as subtle dependencies might exist. Watch the browser console closely for errors like "`$` is not defined" or "`jQuery` is not defined".

    3.  **Performance Review:**
        *   **How:** Use the Performance and Lighthouse tabs in your browser's developer tools. Record performance metrics for key pages/actions *before* and *after* modernization phases (especially after major refactors like AJAX changes or module bundling).
        *   **Analyze:** Look for:
            *   **Long Tasks:** JavaScript execution taking >50ms.
            *   **Main Thread Work:** Excessive JS calculation blocking rendering.
            *   **Layout Shifts (CLS):** Elements moving unexpectedly during/after load.
            *   **Network Requests:** Ensure bundling (if used) reduced the number of JS files. Check total JS size.
        *   **Optimize:** Address identified bottlenecks. Common optimizations include:
            *   Debouncing or throttling frequent event handlers (scroll, resize).
            *   Lazy loading images or components not immediately visible.
            *   Optimizing complex loops or DOM manipulations.
            *   Ensuring efficient selectors.

    4.  **Code Review & Documentation:**
        *   **How:** Conduct peer reviews (if possible) focusing on adherence to modern standards, readability, correctness, and consistency with the project's conventions documented in `MODERNIZATION.md`.
        *   **Action:** Update `MODERNIZATION.md` with final implementation details, decisions made during Phase 4 (especially regarding plugins), any remaining challenges or areas for future improvement, and instructions for developers unfamiliar with the new structure (e.g., how to use the build process if implemented).

    5.  **Cleanup:**
        *   **How:** Systematically review the `js` directories within themes and admin themes. Identify files that are no longer referenced by `{combine_script}` or `import` statements. Search the codebase for any remaining references to be sure.
        *   **Action:** Delete confirmed unused `.js` files. Remove commented-out legacy code blocks unless they serve a specific documentary purpose.
        *   **Why:** Reduces codebase size and clutter, making navigation and future maintenance easier.

---

**Key Considerations Throughout:**

*   **Iterative & Incremental:** Apply changes section by section or file by file. Don't attempt to rewrite everything at once.
*   **Constant Testing:** After each logical step (e.g., refactoring AJAX in one module, extracting inline JS from one TPL), perform targeted manual testing. Full regression testing is needed after major milestones (like removing jQuery).
*   **Piwigo Context:** Always consider how the JS integrates with the Piwigo environment: Smarty variables, `ws.php` API, PWG tokens, user permissions, theme/plugin hooks, and the `combine_script` mechanism (or its replacement if using a build tool).
*   **Prioritization:** Focus efforts where they have the most impact – often this means cleaning up heavily used admin pages, refactoring complex AJAX interactions, and separating logic from TPLs.
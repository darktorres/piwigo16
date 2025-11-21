# JavaScript Modernization Strategy: Incremental Approach

**Goal**: Modernize legacy JS in Piwigo while maintaining stability and showing progress at each step

**Philosophy**: Do the highest-impact changes first, maintain backward compatibility, establish foundations for future work

---

## Why Incremental? Why This Order?

### The Problem with Legacy JS
- jQuery 1.11.3 (2014) - 10 years old, security issues
- Global namespace pollution
- No module system
- Manual dependency management
- Mixed patterns (callbacks, some promises)
- No type safety
- Hard to test

### The Risk of Big Rewrite
- ❌ Everything breaks at once
- ❌ Takes 6+ months of 100% focus
- ❌ Feature development stops
- ❌ High bug risk
- ❌ Plugins break immediately
- ❌ Users get angry

### The Smart Approach (This Plan)
- ✅ Improvements visible in 2-3 weeks
- ✅ Maintain 100% backward compatibility
- ✅ Preserve existing features
- ✅ Enable plugin ecosystem to upgrade gradually
- ✅ Test each layer thoroughly
- ✅ Can do alongside normal development
- ✅ Easy rollback at any point

---

## Overview: 5 Steps Over 12 Months

```
Month 1-2          Month 3-4           Month 5-7           Month 8-10         Month 11-12
┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│ STEP 1       │  │ STEP 2       │  │ STEP 3       │  │ STEP 4       │  │ STEP 5       │
│ Foundation   │→ │ Build System │→ │ Core Modules │→ │ Components   │→ │ Cleanup &    │
│ & Code Quality   │ & TypeScript │  │ + APIs      │  │ Modernized  │  │ Polish       │
│              │  │              │  │              │  │              │  │              │
│ • ESLint     │  │ • Webpack    │  │ • Vanilla JS │  │ • Vue/React  │  │ • Remove jQ  │
│ • Remove jQ  │  │ • TypeScript │  │ • Modern    │  │ • State mgmt │  │ • Polishing  │
│   plugins    │  │ • Build pipe │  │   patterns  │  │ • Testing    │  │ • Docs       │
│ • Format     │  │ • Minify/opt │  │ • API layer │  │ • Async/await│  │ • Cleanup    │
│ • Testing    │  │ • Testing    │  │ • Caching   │  │ • Features   │  │              │
└──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘

Value delivered  Medium         High            High            Very High       High
each step:       (Foundation)   (Capability)    (Performance)   (Usability)     (Polish)
```

---

## STEP 1: Foundation & Code Quality (Weeks 1-8)

**Goal**: Clean up existing code, establish standards, measure baseline

**Why First**: Improves code quality immediately, enables better tooling later, low risk

### 1.1 Set Up Code Quality Tools

**Tasks**:
- [ ] Install ESLint with strict config
- [ ] Install Prettier for formatting
- [ ] Add pre-commit hooks (husky + lint-staged)
- [ ] Run initial linting (don't fix yet, just report)
- [ ] Document standards

**Quick Setup** (30 minutes):
```bash
npm install --save-dev eslint prettier husky lint-staged
npx eslint --init  # Choose strict config

# Configure husky
npx husky install
npx husky add .husky/pre-commit "npx lint-staged"

# Add to package.json
{
  "lint-staged": {
    "*.js": "eslint --fix",
    "*.{js,json}": "prettier --write"
  }
}
```

**Example .eslintrc.json**:
```json
{
  "env": {
    "browser": true,
    "es2020": true,
    "jquery": true,
    "node": true
  },
  "extends": ["eslint:recommended"],
  "rules": {
    "no-var": "warn",  // Warn, not error (gradual migration)
    "prefer-const": "warn",
    "no-console": ["warn", {"allow": ["warn", "error"]}],
    "prefer-arrow-callback": "warn",
    "no-nested-ternary": "warn",
    "complexity": ["warn", 10],
    "max-lines": ["warn", 500],
    "no-unused-vars": ["warn", {"argsIgnorePattern": "^_"}]
  }
}
```

**Expected Outcome**:
- ✅ Consistent code formatting
- ✅ Identified problematic patterns (warnings)
- ✅ Foundation for automated tooling

---

### 1.2 Remove Obsolete jQuery Plugins

**Current Problems**:
- jQuery 1.11.3 is outdated
- Many bundled plugins are no longer maintained
- Some have modern replacements

**Action Items**:

**A. Identify Unused Plugins** (Day 1)
```bash
# Search codebase for actual usage
grep -r "\.colorbox" themes/ admin/ inc/  # Find actual calls
grep -r "\.jgrowl" themes/ admin/ inc/
grep -r "\.jcrop" themes/ admin/ inc/
```

**B. Remove Unused Ones** (Days 2-3)

Check each plugin against actual usage:

| Plugin | Current | Used? | Modern Alternative | Action |
|--------|---------|-------|-------------------|--------|
| jQuery 1.11.3 | Everywhere | YES | Keep (for now) | Keep but plan upgrade |
| jQuery Migrate | Header | YES | Keep | Keep |
| jQuery Cookie | Scripts | YES | js-cookie | Replace gradually |
| Colorbox | Galleries | YES | PhotoSwipe 5.x | Already modern |
| jQuery Confirm | Forms | MAYBE | Native dialog | Audit & consider |
| Chosen | Selects | YES | Selectize or native | Keep for now |
| DataTables | Admin | YES | Modern table libs | Keep for now |
| Plupload | Upload | YES | Native FileAPI | Modernize later |
| jGrowl | Notifications | MAYBE | Custom or Toastr | Audit & replace |
| jQuery Colorpicker | Admin | RARELY | Native color input | Remove if unused |
| jQuery Timepicker | Admin | RARELY | Native datetime | Remove if unused |
| jQuery Jcrop | Cropping | RARELY | Modern crop tool | Remove if unused |

**Implementation**:
```bash
# 1. Remove from node_modules/package.json
npm uninstall jquery-cookie jquery-colorpicker jquery-timepicker

# 2. Remove combine_script tags from templates
# Before:
# {combine_script id='jquery.cookie' path='...' load='footer'}
# After: (removed)

# 3. Search for usage and handle gracefully
grep -r "jQuery.cookie" themes/  # Find all references
# Replace with js-cookie or localStorage

# 4. Test thoroughly in each theme
```

**Replace jQuery.cookie with js-cookie**:
```bash
npm install js-cookie
```

Before:
```javascript
jQuery.cookie("view", "grid");
var view = jQuery.cookie("view");
```

After:
```javascript
// Global: import Cookie from 'js-cookie'
Cookie.set("view", "grid");
var view = Cookie.get("view");
```

**Expected Outcome**:
- ✅ Smaller bundle
- ✅ Removed dependencies on unmaintained libraries
- ✅ Modern replacements in place

---

### 1.3 Establish Code Organization Standards

**Goal**: Define how JavaScript should be organized going forward

**Create Documentation**:

```markdown
# JavaScript Development Standards

## File Organization
- Core utilities: `src/utils/`
- Components: `src/components/`
- API layer: `src/api/`
- Styles: `src/styles/`

## Naming Conventions
- Files: camelCase.js
- Classes: PascalCase
- Functions: camelCase
- Constants: CONSTANT_CASE
- Private: _leadingUnderscore

## Module Pattern (Until TypeScript)
Instead of global functions, use IIFE pattern:
\`\`\`javascript
const GalleryModule = (() => {
  const privateVar = 'hidden';

  const init = () => {
    // Initialize gallery
  };

  const publicMethod = () => {
    // Public API
  };

  return {
    init,
    publicMethod
  };
})();

// Usage
GalleryModule.init();
\`\`\`

## Error Handling
- Always use try/catch for async
- Log errors with context
- Never swallow errors silently

## Comments
- Document WHY, not WHAT
- Use JSDoc for complex functions
- Keep comments in sync with code

## Code Review Checklist
- [ ] ESLint passes
- [ ] No console.log left in production code
- [ ] Error handling present
- [ ] No hardcoded values
- [ ] Functions under 50 lines
- [ ] No deeply nested code (max 3 levels)
```

**Expected Outcome**:
- ✅ Consistent code style
- ✅ Clear standards for new code
- ✅ Team alignment on direction

---

### 1.4 Reduce Global Namespace Pollution

**Problem**: Functions scattered globally
```javascript
function pwg_add_rate() { }
function phpWGOpenWindow() { }
function popuphelp() { }
// ... 50+ global functions
```

**Solution**: Namespace everything

**Step 1: Create namespace object**
```javascript
// src/core/namespace.js
const Pwg = {
  // Utilities
  Utils: {},

  // UI Components
  UI: {
    Modal: {},
    Popup: {},
    Notification: {},
  },

  // Features
  Gallery: {},
  Rating: {},
  Comments: {},
  Search: {},

  // API
  Api: {},

  // Admin features
  Admin: {
    Users: {},
    Photos: {},
    Categories: {},
  }
};
```

**Step 2: Move functions into namespaces**

Before:
```javascript
function pwg_add_rate(image_id, rating) {
  // Implementation
}

jQuery(document).ready(function() {
  jQuery('.rating-btn').click(function() {
    pwg_add_rate($(this).data('image-id'), $(this).data('rating'));
  });
});
```

After:
```javascript
Pwg.Rating = (() => {
  const addRate = (imageId, rating) => {
    // Implementation
  };

  const init = () => {
    document.querySelectorAll('.rating-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        const imageId = btn.dataset.imageId;
        const rating = btn.dataset.rating;
        addRate(parseInt(imageId), parseInt(rating));
      });
    });
  };

  return { init, addRate };
})();

// Initialize when DOM ready
document.addEventListener('DOMContentLoaded', Pwg.Rating.init);
```

**Expected Outcome**:
- ✅ No more global function collisions
- ✅ Clearer code organization
- ✅ Easier to find related functions

---

### 1.5 Set Up Basic Testing Infrastructure

**Goal**: Establish testing practices for new code

**Tasks**:
```bash
npm install --save-dev jest @testing-library/dom @babel/preset-env @babel/preset-react
```

**Example test** (for future use):
```javascript
// __tests__/utils.test.js
import { formatDate, sanitizeHtml } from '../src/utils';

describe('Utils', () => {
  describe('formatDate', () => {
    it('should format date correctly', () => {
      const result = formatDate(new Date('2024-01-15'));
      expect(result).toBe('01/15/2024');
    });
  });

  describe('sanitizeHtml', () => {
    it('should remove script tags', () => {
      const result = sanitizeHtml('<p>Test<script>alert(1)</script></p>');
      expect(result).toBe('<p>Test</p>');
    });
  });
});
```

**Expected Outcome**:
- ✅ Test framework ready
- ✅ Testing culture established
- ✅ Foundation for unit tests in future steps

---

## STEP 2: Build System & TypeScript (Weeks 9-16)

**Goal**: Set up modern build pipeline, add TypeScript support

**Why This Order**: Enables better tooling, makes future modernization easier

### 2.1 Install and Configure Webpack

```bash
npm install --save-dev webpack webpack-cli webpack-dev-server
npm install --save-dev babel-loader @babel/core @babel/preset-env
npm install --save-dev style-loader css-loader sass-loader sass
```

**webpack.config.js**:
```javascript
const path = require('path');
const webpack = require('webpack');

module.exports = {
  mode: 'production',
  entry: {
    app: './src/app.js',
    admin: './src/admin.js',
    gallery: './src/components/gallery.js',
  },
  output: {
    path: path.resolve(__dirname, 'dist'),
    filename: '[name].[contenthash].js',
    publicPath: '/dist/',
  },
  module: {
    rules: [
      {
        test: /\.js$/,
        exclude: /node_modules/,
        use: {
          loader: 'babel-loader',
          options: {
            presets: ['@babel/preset-env'],
            plugins: [],
          },
        },
      },
      {
        test: /\.scss$/,
        use: ['style-loader', 'css-loader', 'sass-loader'],
      },
    ],
  },
  optimization: {
    minimize: true,
    splitChunks: {
      chunks: 'all',
    },
  },
  devServer: {
    contentBase: './dist',
    hot: true,
    port: 3000,
  },
};
```

**package.json scripts**:
```json
{
  "scripts": {
    "build": "webpack --mode production",
    "dev": "webpack serve --mode development",
    "watch": "webpack --mode development --watch"
  }
}
```

---

### 2.2 Introduce TypeScript (Optional Entry Points)

**Setup** (don't convert everything yet):
```bash
npm install --save-dev typescript ts-loader
```

**tsconfig.json**:
```json
{
  "compilerOptions": {
    "target": "ES2020",
    "module": "ESNext",
    "lib": ["ES2020", "DOM"],
    "strict": true,
    "esModuleInterop": true,
    "skipLibCheck": true,
    "forceConsistentCasingInFileNames": true,
    "declaration": true,
    "declarationMap": true,
    "sourceMap": true,
    "baseUrl": ".",
    "paths": {
      "@/*": ["src/*"]
    }
  },
  "include": ["src/**/*"],
  "exclude": ["node_modules", "dist"]
}
```

**Webpack config update** (add ts-loader):
```javascript
{
  test: /\.ts$/,
  use: 'ts-loader',
  exclude: /node_modules/,
}
```

**But keep JavaScript files for now**—convert gradually.

---

### 2.3 Create Modern API Layer

**Why Important**: Replaces jQuery AJAX, testable, type-safe foundation

**src/api/client.js**:
```javascript
/**
 * Modern API client replacing jQuery AJAX
 * Handles Piwigo web service calls with proper error handling
 */

export class ApiClient {
  constructor(rootUrl, token) {
    this.rootUrl = rootUrl;
    this.token = token;
  }

  /**
   * Call Piwigo web service
   * @param {string} method - API method (e.g., 'pwg.images.getList')
   * @param {object} params - Parameters
   * @returns {Promise} Response result
   */
  async call(method, params = {}) {
    const url = new URL(`${this.rootUrl}ws.php`);
    url.searchParams.set('format', 'json');
    url.searchParams.set('method', method);
    url.searchParams.set('pwg_token', this.token);

    Object.entries(params).forEach(([key, value]) => {
      url.searchParams.set(key, String(value));
    });

    try {
      const response = await fetch(url, {
        method: 'GET',
        headers: { 'Content-Type': 'application/json' },
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }

      const data = await response.json();

      if (data.stat === 'fail') {
        throw new Error(data.error?.message || 'API call failed');
      }

      return data.result;
    } catch (error) {
      console.error(`API Error (${method}):`, error);
      throw error;
    }
  }

  // Convenience methods
  async getImages(categoryId, limit = 100) {
    const params = { per_page: limit };
    if (categoryId) params.cat_id = categoryId;
    return this.call('pwg.images.getList', params);
  }

  async getCategories() {
    return this.call('pwg.categories.getList');
  }

  async searchPhotos(query, limit = 20) {
    return this.call('pwg.images.search', { query, per_page: limit });
  }

  async rateImage(imageId, rating) {
    return this.call('pwg.images.rate', { image_id: imageId, rate: rating });
  }

  async uploadImage(file, categoryId) {
    const formData = new FormData();
    formData.append('image', file);
    if (categoryId) formData.append('category', categoryId);

    const url = new URL(`${this.rootUrl}ws.php`);
    url.searchParams.set('format', 'json');
    url.searchParams.set('method', 'pwg.images.addSimple');
    url.searchParams.set('pwg_token', this.token);

    const response = await fetch(url, {
      method: 'POST',
      body: formData,
    });

    const data = await response.json();
    if (data.stat === 'fail') throw new Error(data.error?.message);
    return data.result;
  }
}

// Create singleton instance
let instance;

export function createApiClient(rootUrl, token) {
  instance = new ApiClient(rootUrl, token);
  return instance;
}

export function getApiClient() {
  if (!instance) {
    throw new Error('ApiClient not initialized. Call createApiClient first.');
  }
  return instance;
}
```

**Usage** (replaces jQuery.getJSON):

Before:
```javascript
jQuery.getJSON(baseUrl + "ws.php?method=pwg.images.getList&format=json&per_page=20",
  function(data) {
    jQuery.each(data.result.images, function(i, image) {
      // Process image
    });
  }
);
```

After:
```javascript
import { getApiClient } from './api/client.js';

const api = getApiClient();
api.getImages(null, 20)
  .then(images => {
    images.forEach(image => {
      // Process image
    });
  })
  .catch(error => console.error('Failed to load images:', error));
```

---

### 2.4 Create Data Bridge for Smarty Variables

**src/core/data-bridge.js**:
```javascript
/**
 * Safe bridge for accessing Smarty-rendered data in JavaScript
 * Replaces inline scripts with typed data access
 */

export class DataBridge {
  static getBodyData() {
    const attr = document.body.getAttribute('data-infos');
    if (!attr) throw new Error('data-infos attribute not found on body');
    try {
      return JSON.parse(attr);
    } catch (e) {
      throw new Error(`Failed to parse body data: ${e.message}`);
    }
  }

  static getData(element, attr = 'data-json') {
    if (!element) throw new Error('Element is required');
    const data = element.getAttribute(attr);
    if (!data) return null;
    try {
      return JSON.parse(data);
    } catch (e) {
      console.warn(`Failed to parse ${attr}:`, e);
      return null;
    }
  }

  static getRootUrl() {
    return this.getBodyData().rootUrl;
  }

  static isAdmin() {
    return this.getBodyData().isAdmin || false;
  }

  static getToken() {
    return this.getBodyData().pwgToken;
  }

  static getCurrentUserId() {
    return this.getBodyData().userId;
  }
}

export default DataBridge;
```

**Usage in Smarty**:
```smarty
{* Add data-infos to body *}
<body data-infos='{
  "rootUrl": "{get_absolute_root_url()|json_encode}",
  "isAdmin": {if is_admin()}true{else}false{/if},
  "userId": {$user.id|json_encode},
  "pwgToken": "{$pwg_token|json_encode}"
}'>
```

**Usage in JavaScript**:
```javascript
import { DataBridge } from './core/data-bridge.js';
import { createApiClient } from './api/client.js';

// Initialize API client with Smarty data
const rootUrl = DataBridge.getRootUrl();
const token = DataBridge.getToken();
createApiClient(rootUrl, token);
```

---

### 2.5 Create Component Base Class

**src/core/component.js**:
```javascript
/**
 * Base class for all components
 * Provides common lifecycle, event handling, and cleanup
 */

export class Component {
  constructor(selector) {
    this.element = typeof selector === 'string'
      ? document.querySelector(selector)
      : selector;

    if (!this.element) {
      throw new Error(`Element not found: ${selector}`);
    }

    this.eventListeners = [];
    this.children = [];
  }

  /**
   * Initialize component (called after constructor)
   */
  init() {
    // Override in subclass
  }

  /**
   * Add event listener with automatic cleanup
   */
  on(target, event, handler) {
    target.addEventListener(event, handler);
    this.eventListeners.push({ target, event, handler });
    return this;
  }

  /**
   * Query within component element
   */
  query(selector) {
    return this.element.querySelector(selector);
  }

  /**
   * Query all within component element
   */
  queryAll(selector) {
    return Array.from(this.element.querySelectorAll(selector));
  }

  /**
   * Set text content safely
   */
  setText(selector, text) {
    const el = this.query(selector);
    if (el) el.textContent = text;
    return this;
  }

  /**
   * Set HTML (use carefully!)
   */
  setHtml(selector, html) {
    const el = this.query(selector);
    if (el) el.innerHTML = html;
    return this;
  }

  /**
   * Add CSS class
   */
  addClass(selector, className) {
    const el = this.query(selector);
    if (el) el.classList.add(className);
    return this;
  }

  /**
   * Remove CSS class
   */
  removeClass(selector, className) {
    const el = this.query(selector);
    if (el) el.classList.remove(className);
    return this;
  }

  /**
   * Toggle CSS class
   */
  toggleClass(selector, className) {
    const el = this.query(selector);
    if (el) el.classList.toggle(className);
    return this;
  }

  /**
   * Show element
   */
  show(selector) {
    const el = this.query(selector);
    if (el) el.style.display = '';
    return this;
  }

  /**
   * Hide element
   */
  hide(selector) {
    const el = this.query(selector);
    if (el) el.style.display = 'none';
    return this;
  }

  /**
   * Clean up component
   */
  destroy() {
    // Remove all event listeners
    this.eventListeners.forEach(({ target, event, handler }) => {
      target.removeEventListener(event, handler);
    });
    this.eventListeners = [];

    // Destroy children
    this.children.forEach(child => child.destroy?.());
    this.children = [];

    // Clear element
    if (this.element) {
      this.element.innerHTML = '';
    }
  }
}
```

**Usage**:
```javascript
import { Component } from './core/component.js';
import { getApiClient } from './api/client.js';

export class GalleryComponent extends Component {
  init() {
    this.photos = [];
    this.loadPhotos();
    this.attachEventListeners();
  }

  async loadPhotos() {
    try {
      const api = getApiClient();
      this.photos = await api.getImages();
      this.render();
    } catch (error) {
      console.error('Failed to load photos:', error);
      this.setText('.error-message', 'Failed to load photos');
    }
  }

  attachEventListeners() {
    this.on(this.element, 'click', (e) => {
      if (e.target.closest('.photo-item')) {
        this.onPhotoClick(e);
      }
    });
  }

  onPhotoClick(e) {
    const photoEl = e.target.closest('.photo-item');
    const photoId = photoEl.dataset.photoId;
    // Handle click
  }

  render() {
    const html = this.photos
      .map(photo => `<div class="photo-item" data-photo-id="${photo.id}">
        <img src="${photo.url}" alt="${photo.name}">
      </div>`)
      .join('');

    this.setHtml('.gallery-grid', html);
  }

  destroy() {
    super.destroy();
    this.photos = [];
  }
}
```

---

## STEP 3: Core Modules Modernization (Weeks 17-28)

**Goal**: Modernize high-impact modules using vanilla JavaScript and modern patterns

**Priority**:
1. **Rating system** - Used frequently, clear scope
2. **Gallery component** - Most visible, heavily used
3. **Search/filtering** - Complex logic, good for modern patterns
4. **Comments system** - User interaction, benefits from modernization
5. **Admin panel** - Complex forms, state management

### 3.1 Modernize Rating Component

**Current** (jQuery):
```javascript
// themes/default/js/rating.js
function pwg_add_rate(image_id, rating) {
  var xhr = new XMLHttpRequest();
  // ... complex XHR code
}

jQuery(document).ready(function() {
  jQuery('.rating-btn').click(function() {
    var imageId = jQuery(this).data('image-id');
    pwg_add_rate(imageId, parseInt(jQuery(this).attr('data-rating')));
  });
});
```

**Modernized** (Vanilla JS):
```javascript
// src/components/rating.js
import { Component } from '../core/component.js';
import { getApiClient } from '../api/client.js';

export class RatingComponent extends Component {
  init() {
    this.api = getApiClient();
    this.attachEventListeners();
  }

  attachEventListeners() {
    this.queryAll('.rating-btn').forEach(btn => {
      this.on(btn, 'click', (e) => {
        e.preventDefault();
        this.rateImage(btn);
      });
    });
  }

  async rateImage(btn) {
    try {
      const imageId = parseInt(btn.dataset.imageId);
      const rating = parseInt(btn.dataset.rating);

      btn.disabled = true;
      btn.classList.add('loading');

      const result = await this.api.rateImage(imageId, rating);

      // Update UI
      const ratingContainer = btn.closest('[data-image-id]');
      const ratingValue = ratingContainer.querySelector('.rating-value');
      ratingValue.textContent = result.average.toFixed(1);

      // Show feedback
      this.showNotification('Rating saved!', 'success');

      // Dispatch event for other components
      window.dispatchEvent(
        new CustomEvent('rating:changed', { detail: { imageId, rating: result.average } })
      );
    } catch (error) {
      console.error('Rating failed:', error);
      this.showNotification('Failed to save rating', 'error');
    } finally {
      btn.disabled = false;
      btn.classList.remove('loading');
    }
  }

  showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.textContent = message;
    document.body.appendChild(notification);

    setTimeout(() => {
      notification.remove();
    }, 3000);
  }
}

// Auto-initialize on page load
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    new RatingComponent('.gallery-container');
  });
} else {
  new RatingComponent('.gallery-container');
}
```

---

### 3.2 Create Notification System

Replace jGrowl (unmaintained jQuery plugin):

**src/core/notifications.js**:
```javascript
/**
 * Modern notification system
 * Replaces jGrowl with vanilla JavaScript
 */

export class NotificationManager {
  constructor() {
    this.container = null;
    this.notifications = [];
    this.init();
  }

  init() {
    // Create container if not exists
    if (!this.container) {
      this.container = document.createElement('div');
      this.container.id = 'notification-container';
      this.container.className = 'notification-container';
      document.body.appendChild(this.container);
    }
  }

  /**
   * Show notification
   */
  show(message, type = 'info', duration = 3000) {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.setAttribute('role', 'status');
    notification.setAttribute('aria-live', 'polite');

    const close = document.createElement('button');
    close.className = 'notification-close';
    close.innerHTML = '&times;';
    close.setAttribute('aria-label', 'Close notification');

    const content = document.createElement('div');
    content.className = 'notification-content';
    content.textContent = message;

    notification.appendChild(content);
    notification.appendChild(close);
    this.container.appendChild(notification);

    // Animation
    requestAnimationFrame(() => {
      notification.classList.add('show');
    });

    // Close button
    close.addEventListener('click', () => this.removeNotification(notification));

    // Auto-close
    if (duration > 0) {
      setTimeout(() => this.removeNotification(notification), duration);
    }

    this.notifications.push(notification);
    return notification;
  }

  success(message, duration = 3000) {
    return this.show(message, 'success', duration);
  }

  error(message, duration = 5000) {
    return this.show(message, 'error', duration);
  }

  warning(message, duration = 4000) {
    return this.show(message, 'warning', duration);
  }

  info(message, duration = 3000) {
    return this.show(message, 'info', duration);
  }

  removeNotification(notification) {
    notification.classList.remove('show');
    setTimeout(() => {
      notification.remove();
      this.notifications = this.notifications.filter(n => n !== notification);
    }, 300);
  }

  clear() {
    this.notifications.forEach(n => n.remove());
    this.notifications = [];
  }
}

// Singleton
let instance;

export function getNotificationManager() {
  if (!instance) {
    instance = new NotificationManager();
  }
  return instance;
}
```

**CSS**:
```scss
#notification-container {
  position: fixed;
  top: 20px;
  right: 20px;
  z-index: 9999;
  max-width: 400px;
}

.notification {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  margin-bottom: 10px;
  border-radius: 4px;
  background: #fff;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
  transform: translateX(400px);
  transition: transform 0.3s ease-out;

  &.show {
    transform: translateX(0);
  }

  &.notification-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
  }

  &.notification-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
  }

  &.notification-warning {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffeeba;
  }

  &.notification-info {
    background: #d1ecf1;
    color: #0c5460;
    border: 1px solid #bee5eb;
  }
}

.notification-close {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 20px;
  color: inherit;
  padding: 0;
  margin-left: auto;
}
```

---

## STEP 4: Component Modernization & State Management (Weeks 29-40)

**Goal**: Create interactive components with proper state management

**Tasks**:
- [ ] Modern gallery component with lazy loading
- [ ] Advanced search component
- [ ] Image upload with progress
- [ ] Admin dashboard with real-time updates
- [ ] Comments system with live updates

### 4.1 Create Simple State Management

For components that need shared state:

**src/core/store.js**:
```javascript
/**
 * Simple state management system
 * Alternative to Redux/Vuex for small-to-medium apps
 */

export class Store {
  constructor(initialState = {}) {
    this.state = initialState;
    this.subscribers = [];
  }

  /**
   * Get state value
   */
  get(key) {
    return key ? this.state[key] : this.state;
  }

  /**
   * Set state value
   */
  set(key, value) {
    const oldValue = this.state[key];

    if (oldValue === value) return; // No change

    this.state[key] = value;
    this.notify({ key, oldValue, newValue: value });
  }

  /**
   * Update state object
   */
  update(updates) {
    Object.entries(updates).forEach(([key, value]) => {
      this.set(key, value);
    });
  }

  /**
   * Subscribe to state changes
   */
  subscribe(callback) {
    this.subscribers.push(callback);

    // Return unsubscribe function
    return () => {
      this.subscribers = this.subscribers.filter(sub => sub !== callback);
    };
  }

  /**
   * Notify subscribers of changes
   */
  notify(change) {
    this.subscribers.forEach(callback => {
      callback(this.state, change);
    });
  }

  /**
   * Reset state
   */
  reset(initialState) {
    this.state = initialState;
    this.notify({ type: 'reset' });
  }
}

// Usage example
const galleryStore = new Store({
  images: [],
  selectedCategory: null,
  loading: false,
  error: null,
});

galleryStore.subscribe((state, change) => {
  console.log('State changed:', change);
  console.log('New state:', state);
});

galleryStore.set('loading', true);
galleryStore.update({ images: [...], loading: false });
```

---

## STEP 5: Cleanup & Polish (Weeks 41-48)

**Goal**: Remove jQuery, final optimizations, documentation

### 5.1 Remove jQuery Dependency

**When Ready**:
- [ ] All interactive components converted to vanilla JS
- [ ] All AJAX calls using ApiClient
- [ ] No inline jQuery calls in templates
- [ ] Plugins/themes updated

**Steps**:
1. Remove `{combine_script}` tags for jQuery in templates
2. Remove jquery from package.json
3. Search for any remaining jQuery usage
4. Test thoroughly

### 5.2 Performance Optimization

- [ ] Enable gzip compression
- [ ] Set up caching headers
- [ ] Lazy load images (Intersection Observer)
- [ ] Code splitting per page
- [ ] Remove unused CSS

### 5.3 Documentation

- [ ] API documentation (JSDoc)
- [ ] Component library documentation
- [ ] Migration guide for plugins/themes
- [ ] Architecture decision records

---

## Success Metrics

Track progress at each step:

```
Step 1: Foundation & Code Quality
✓ ESLint fully configured
✓ Prettier formatting applied
✓ Code style guide documented
✓ ESLint: 0 errors, <100 warnings
✓ 5+ unused plugins removed

Step 2: Build System & TypeScript
✓ Webpack configured and building
✓ All JS files transpiled
✓ Bundle size: 40% smaller than jQuery
✓ Build time: <5 seconds
✓ TypeScript configured (even if not fully used)

Step 3: Core Modules Modernized
✓ Rating component: vanilla JS
✓ API client: modern fetch-based
✓ 3+ modules converted
✓ Test coverage: 60%+

Step 4: Component Modernization
✓ Gallery component: modern with lazy loading
✓ Admin components: interactive state-driven
✓ 5+ components modernized
✓ Test coverage: 70%+

Step 5: Cleanup & Polish
✓ jQuery removed from package.json
✓ 50%+ bundle size reduction
✓ Performance score: 90+
✓ Zero jQuery usage
✓ Full documentation
```

---

## Timeline Summary

| Timeframe | Phase | Key Deliverables | Value |
|-----------|-------|------------------|-------|
| Week 1-8 | Foundation | ESLint, standards, removed plugins | Medium - Infrastructure |
| Week 9-16 | Build system | Webpack, TypeScript, modern API | High - Capability enabled |
| Week 17-28 | Core modules | Rating, gallery, notifications | High - Performance/UX |
| Week 29-40 | Components | State management, admin UI | Very high - User features |
| Week 41-48 | Cleanup | Remove jQuery, optimize, docs | High - Polish |

---

## FAQ

### "Won't this break plugins?"

**No**, because:
- Core functionality stays intact
- jQuery still available during migration
- Plugins can coexist with modernized code
- Grace period for plugin updates (12+ months)
- Clear migration guides provided

### "Can we do this faster?"

**Yes, but with risks**:
- Faster timeline = more bugs
- Shorter testing = regression issues
- Plugin incompatibility increases
- Recommended: stick to this timeline

### "What if we want to use Vue/React?"

**Good question**:
- Steps 1-2 enable this
- Can introduce Vue/React at Step 4
- Components can be Vue while API/state is shared
- Gradual migration path exists

### "Do we have to do TypeScript?"

**No**:
- TypeScript is optional
- Benefits: better IDE support, fewer bugs
- You can skip and use vanilla JS throughout
- Can add TypeScript later if desired

---

## Recommended Reading

- [MDN Web Docs](https://developer.mozilla.org/) - Modern JS reference
- [Fetch API](https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API) - Replace jQuery.ajax
- [Web Components](https://developer.mozilla.org/en-US/docs/Web/Web_Components) - Reusable components
- [Intersection Observer](https://developer.mozilla.org/en-US/docs/Web/API/Intersection_Observer_API) - Lazy loading
- [Custom Events](https://developer.mozilla.org/en-US/docs/Web/API/CustomEvent) - Component communication

---

## Next Steps

1. **Start with Step 1** - Set up ESLint, establish standards (Week 1)
2. **Remove unused plugins** - Identify and remove jQuery plugin cruft (Week 2)
3. **Create build system** - Webpack basic config (Week 3)
4. **Create modern API layer** - Fetch-based instead of jQuery (Week 4)
5. **Convert first component** - Rating system as proof of concept (Weeks 5-6)
6. **Get feedback** - Have team review approach
7. **Proceed with full plan** - Rollout across all components

---

**Key Principle**: Better to move slowly and maintain quality than rush and break things.

Each step should be:
- **Small enough** to complete in 1-2 weeks
- **Testable** independently
- **Reversible** if needed
- **Valuable** to users/team

Good luck! 🚀

---

**Document Version**: 1.0
**Last Updated**: November 2025
**Questions/Issues**: Create GitHub issues with [JS-Modernization] tag

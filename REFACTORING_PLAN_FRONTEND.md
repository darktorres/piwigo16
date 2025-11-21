# Frontend Refactoring Plan

**Target**: Piwigo 14.x JavaScript/CSS/HTML
**Focus**: Modernization, performance, maintainability, user experience
**Timeline**: Phased approach over multiple releases
**Compatibility**: Progressive enhancement, graceful degradation

---

## Executive Summary

The Piwigo frontend is based on jQuery 1.11.3 (from 2014) and has accumulated technical debt. This plan outlines a strategic approach to modernize the frontend stack while improving performance, maintainability, and user experience.

**Key Goals**:
- Replace jQuery with modern vanilla JavaScript/framework
- Improve performance and accessibility
- Enhance responsive design and mobile experience
- Modernize CSS (CSS Grid, Flexbox, custom properties)
- Add TypeScript for type safety
- Improve developer experience with better tooling
- Maintain backward compatibility for plugins/themes

---

## Current State Analysis

### Frontend Stack Issues

| Issue | Impact | Priority |
|-------|--------|----------|
| jQuery 1.11.3 (2014) | Security vulnerabilities, performance | Critical |
| jQuery plugins (old) | Limited maintenance, memory leaks | High |
| No build system | Can't use modern tools, bundling | High |
| No TypeScript | Type safety, IDE support | High |
| CSS not modular | Maintenance difficult, duplication | High |
| Bootstrap Tour outdated | UX issues, compatibility | Medium |
| No lazy loading | Performance hit | Medium |
| Moment.js (heavy) | Bundle size (67KB minified) | Medium |
| DataTables 1.10.11 | Outdated, unmaintained branch | Low |
| IE support | No longer needed, dead weight | Low |

### Current Bundle Analysis (Estimated)
```
jQuery 1.11.3:         85KB
jQuery plugins:        150KB
Bootstrap Tour:        45KB
Chart.js 2.9.3:        65KB
DataTables 1.10.11:    140KB
Moment.js:             67KB
Underscore.js:         16KB
Other plugins:         100KB
Custom code:           200KB
Total:                 ~868KB minified, ~250KB gzipped
```

### Performance Issues
- No code splitting
- All JS loaded on every page
- No lazy loading for images
- CSS not optimized
- No service workers
- No caching strategy
- Large assets on slow networks

---

## Phase 1: Foundation & Preparation (Releases 14.5 - 14.7)

### 1.1 Establish Modern Build System

**Current State**: Bun as package manager, no real build process
**Goal**: Production-grade build pipeline

**Tasks**:
- [ ] Set up Webpack/Vite as bundler
  - [ ] Code splitting per page/feature
  - [ ] Tree shaking
  - [ ] Asset optimization
  - [ ] Source maps for development
- [ ] Configure transpilation (Babel)
  - [ ] ES2020+ syntax support
  - [ ] Class properties, optional chaining, nullish coalescing
  - [ ] Decorators (for future use)
- [ ] Set up CSS processing
  - [ ] PostCSS with plugins
  - [ ] SASS/SCSS support
  - [ ] CSS minification
  - [ ] Autoprefixer
  - [ ] CSS variables support
- [ ] Set up asset optimization
  - [ ] Image optimization (sharp)
  - [ ] WebP conversion
  - [ ] SVG optimization
  - [ ] Font optimization
- [ ] Create development server with hot reload
- [ ] Create production build process

**Webpack/Vite Configuration**:
```javascript
// webpack.config.js or vite.config.ts
export default {
  entry: {
    'app': './themes/bootstrap_darkroom/js/app.ts',
    'admin': './admin/js/admin.ts',
    'gallery': './themes/bootstrap_darkroom/js/gallery.ts',
  },
  output: {
    path: './dist',
    filename: '[name].[contenthash].js',
    chunkFilename: '[name].[contenthash].chunk.js',
  },
  optimization: {
    splitChunks: {
      chunks: 'all',
      minSize: 20000,
    },
    minimize: true,
  },
};
```

### 1.2 Introduce TypeScript

**Current State**: Plain JavaScript, no type checking
**Goal**: Full TypeScript migration (gradual)

**Tasks**:
- [ ] Set up TypeScript compiler configuration
  - [ ] Strict mode
  - [ ] Target ES2020
  - [ ] No implicit any
  - [ ] Strict null checks
- [ ] Create tsconfig.json with lib options
- [ ] Add TypeScript to build pipeline
- [ ] Convert high-value files first
  - [ ] Image gallery component
  - [ ] API communication
  - [ ] Form handlers
- [ ] Create shared type definitions
- [ ] Set up type checking in CI/CD

**tsconfig.json**:
```json
{
  "compilerOptions": {
    "target": "ES2020",
    "module": "ESNext",
    "lib": ["ES2020", "DOM", "DOM.Iterable"],
    "strict": true,
    "noImplicitAny": true,
    "strictNullChecks": true,
    "strictFunctionTypes": true,
    "strictBindCallApply": true,
    "strictPropertyInitialization": true,
    "noImplicitThis": true,
    "alwaysStrict": true,
    "noUnusedLocals": true,
    "noUnusedParameters": true,
    "noImplicitReturns": true,
    "declaration": true,
    "declarationMap": true,
    "sourceMap": true
  }
}
```

### 1.3 Replace jQuery with Vanilla JS/Lightweight Framework

**Current State**: jQuery 1.11.3 throughout
**Goal**: Modern JavaScript with optional lightweight framework (Alpine.js or Htmx)

**Strategy**: Two-pronged approach
1. For complex SPAs: Modern framework (Vue 3, React, or Svelte)
2. For progressive enhancement: Alpine.js or vanilla JS
3. For server-side templates: HTMX for dynamic updates

**Tasks**:
- [ ] Choose primary approach per module
  - [ ] Gallery view: Consider lightweight framework
  - [ ] Admin panel: Possibly Vue/React
  - [ ] Theme templates: Alpine.js + HTMX
  - [ ] Settings: HTMX for progressive enhancement
- [ ] Create modern DOM utilities to replace jQuery
  - [ ] Element selection (native querySelectorAll)
  - [ ] DOM manipulation (innerHTML, appendChild, etc.)
  - [ ] Event delegation
  - [ ] Fetch wrapper for AJAX
- [ ] Create helper library (`core.js`):
  ```javascript
  // core.js - Modern jQuery replacement
  export const $ = {
    // Selectors
    id: (id: string) => document.getElementById(id),
    class: (selector: string) => document.querySelectorAll(selector),
    on: (el, event, handler) => el.addEventListener(event, handler),
    off: (el, event, handler) => el.removeEventListener(event, handler),

    // DOM
    html: (el, html) => el.innerHTML = html,
    text: (el, text) => el.textContent = text,
    addClass: (el, cls) => el.classList.add(cls),
    removeClass: (el, cls) => el.classList.remove(cls),
    toggleClass: (el, cls) => el.classList.toggle(cls),

    // AJAX
    get: (url, options) => fetch(url, {method: 'GET', ...options}),
    post: (url, data, options) => fetch(url, {method: 'POST', body: JSON.stringify(data), ...options}),
  };
  ```

**Phase 1 Goals**:
- [ ] jQuery removed from common layout (only in isolated components initially)
- [ ] Build system in place for new code
- [ ] TypeScript support ready
- [ ] Some core utilities converted to vanilla JS
- [ ] No breaking changes to public API

### 1.4 Modernize CSS Foundation

**Current State**: CSS files scattered, likely have duplication
**Goal**: CSS-in-JS or modern CSS architecture

**Tasks**:
- [ ] Analyze current CSS (coverage, duplication, specificity)
- [ ] Create design system/tokens
  - [ ] Color palette
  - [ ] Typography scale
  - [ ] Spacing scale
  - [ ] Breakpoints
  - [ ] Shadow/border styles
- [ ] Convert to CSS custom properties (variables)
  - [ ] Color tokens
  - [ ] Size tokens
  - [ ] Animation tokens
- [ ] Implement CSS Grid & Flexbox layout
- [ ] Create SCSS/CSS module structure
- [ ] Set up responsive design guidelines
  - [ ] Mobile-first approach
  - [ ] Touch-friendly interactions
  - [ ] Accessible focus states

**CSS Architecture**:
```scss
// tokens/colors.scss
$colors: (
  primary: #1a73e8,
  secondary: #f57c00,
  success: #34a853,
  error: #ea4335,
  surface: #f8f9fa,
  text-primary: #202124,
  text-secondary: #5f6368,
);

$breakpoints: (
  mobile: 320px,
  tablet: 768px,
  desktop: 1024px,
  wide: 1440px,
);

// Utilities
@mixin respond-to($size) {
  @media (min-width: map-get($breakpoints, $size)) {
    @content;
  }
}

// Usage
.gallery {
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));

  @include respond-to(mobile) {
    grid-template-columns: repeat(2, 1fr);
  }

  @include respond-to(tablet) {
    grid-template-columns: repeat(3, 1fr);
  }

  @include respond-to(desktop) {
    grid-template-columns: repeat(4, 1fr);
  }
}
```

### 1.5 Establish Code Standards

**Tasks**:
- [ ] Configure ESLint with strict rules
- [ ] Configure Prettier for formatting
- [ ] Set up pre-commit hooks
- [ ] Create style guide
- [ ] Add linting to CI/CD
- [ ] Configure TypeScript strict mode

**ESLint Config**:
```javascript
// .eslintrc.json
{
  "extends": ["eslint:recommended", "prettier"],
  "parser": "@typescript-eslint/parser",
  "plugins": ["@typescript-eslint"],
  "rules": {
    "no-var": "error",
    "prefer-const": "error",
    "prefer-arrow-callback": "error",
    "no-console": "warn",
    "no-debugger": "error",
    "@typescript-eslint/no-explicit-any": "error",
    "@typescript-eslint/explicit-function-return-types": "error"
  }
}
```

---

## Phase 2: Component Modernization (Releases 14.8 - 15.0)

### 2.1 Gallery Component Refactoring

**Current State**: jQuery-based gallery with multiple plugins
**Goal**: Modern, performant gallery with accessibility

**Tasks**:
- [ ] Create modern `Gallery` component
  - [ ] Vanilla JS or lightweight framework
  - [ ] Responsive grid layout
  - [ ] Lazy loading with Intersection Observer
  - [ ] Image optimization
  - [ ] Lightbox integration (continue with PhotoSwipe 5.x)
  - [ ] Touch gestures for mobile
  - [ ] Keyboard navigation
  - [ ] ARIA labels for accessibility
- [ ] Implement lazy loading
  ```javascript
  export class LazyImageLoader {
    constructor() {
      this.observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            this.loadImage(entry.target);
            this.observer.unobserve(entry.target);
          }
        });
      });
    }

    load(img: HTMLImageElement) {
      this.observer.observe(img);
    }

    private loadImage(img: HTMLImageElement) {
      const src = img.dataset.src || '';
      const srcset = img.dataset.srcset || '';

      img.src = src;
      if (srcset) img.srcset = srcset;
      img.classList.add('loaded');
    }
  }
  ```
- [ ] Create responsive images with srcset
- [ ] Add PWA support (service worker for offline access)
- [ ] Optimize images (WebP, multiple sizes)

**Gallery HTML**:
```html
<!-- Lazy-loaded images with srcset -->
<img
  class="gallery-image"
  data-src="/i/2/1.jpg"
  data-srcset="/i/2/1-small.jpg 400w, /i/2/1-medium.jpg 800w"
  alt="Photo title"
  loading="lazy"
/>

<!-- Or with picture element for format selection -->
<picture>
  <source srcset="/images/photo.webp" type="image/webp">
  <img src="/images/photo.jpg" alt="Photo">
</picture>
```

### 2.2 Admin Panel Modernization

**Current State**: jQuery forms, manual state management
**Goal**: Interactive admin panel with component architecture

**Tasks**:
- [ ] Create reusable admin components
  - [ ] Modal/dialog
  - [ ] Data table with sorting/filtering
  - [ ] Form builder
  - [ ] Notification toast
  - [ ] Dropdown/select
  - [ ] Pagination
  - [ ] Loading state
- [ ] Implement form validation
  - [ ] Client-side validation
  - [ ] Server-side validation
  - [ ] Async validation (check username uniqueness)
  - [ ] Custom validators
- [ ] Create state management layer
  - [ ] Simple state observable or reactive system
  - [ ] Local storage for preferences
  - [ ] Session storage for temporary data
- [ ] Add real-time feedback
  - [ ] Input validation feedback
  - [ ] Auto-save functionality
  - [ ] Optimistic updates
  - [ ] Error recovery
- [ ] Improve accessibility
  - [ ] ARIA labels
  - [ ] Keyboard navigation
  - [ ] Screen reader support

**Admin Component Example**:
```typescript
// admin/components/DataTable.ts
export interface DataTableConfig<T> {
  columns: Column<T>[];
  data: T[];
  sortable?: boolean;
  filterable?: boolean;
  paginated?: boolean;
}

export class DataTable<T> {
  constructor(private config: DataTableConfig<T>) {
    this.render();
    this.attachEventListeners();
  }

  private render() {
    const table = document.createElement('table');

    // Create headers
    const thead = document.createElement('thead');
    // ...

    // Create body
    const tbody = document.createElement('tbody');
    this.config.data.forEach(row => {
      const tr = document.createElement('tr');
      this.config.columns.forEach(col => {
        const td = document.createElement('td');
        td.textContent = col.render(row);
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });

    table.appendChild(thead);
    table.appendChild(tbody);

    return table;
  }

  private attachEventListeners() {
    // Sorting
    // Filtering
    // Pagination
  }
}
```

### 2.3 Navigation & Menubar Refactoring

**Current State**: Server-rendered menus with jQuery interactions
**Goal**: Accessible, responsive navigation

**Tasks**:
- [ ] Create modern navigation component
  - [ ] Hamburger menu for mobile
  - [ ] Mega menu for categories
  - [ ] Breadcrumb navigation
  - [ ] Active state indicators
- [ ] Implement mobile-first responsive design
  - [ ] Collapse/expand on mobile
  - [ ] Touch-friendly tap targets (48px minimum)
  - [ ] Smooth transitions
- [ ] Add keyboard navigation
  - [ ] Arrow keys for menu navigation
  - [ ] Escape to close
  - [ ] Tab through items
- [ ] Accessibility improvements
  - [ ] ARIA roles and labels
  - [ ] Screen reader announcements
  - [ ] Focus management
  - [ ] Skip links

**Navigation Component**:
```typescript
export class Navigation {
  constructor(private rootElement: HTMLElement) {
    this.initializeMenus();
    this.attachEventListeners();
  }

  private attachEventListeners() {
    // Mobile menu toggle
    const hamburger = this.rootElement.querySelector('[data-toggle="menu"]');
    hamburger?.addEventListener('click', () => this.toggleMobileMenu());

    // Keyboard navigation
    this.rootElement.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') this.focusNext();
      if (e.key === 'ArrowUp') this.focusPrev();
      if (e.key === 'Escape') this.closeMenus();
    });
  }

  private toggleMobileMenu() {
    const nav = this.rootElement.querySelector('nav');
    nav?.classList.toggle('open');

    // Update ARIA
    const hamburger = this.rootElement.querySelector('[data-toggle="menu"]');
    const isOpen = nav?.classList.contains('open');
    hamburger?.setAttribute('aria-expanded', String(isOpen));
  }
}
```

### 2.4 Form Handling & Validation

**Current State**: Basic jQuery form handling
**Goal**: Robust form system with validation

**Tasks**:
- [ ] Create form validation engine
  - [ ] Built-in validators (email, number, min/max, regex)
  - [ ] Custom validators
  - [ ] Async validators (AJAX)
  - [ ] Cross-field validation
- [ ] Create form builder
  - [ ] Fluent API
  - [ ] Auto-generate from schema
  - [ ] Conditional fields
- [ ] Implement error display
  - [ ] Field-level errors
  - [ ] Form-level errors
  - [ ] Success messages
  - [ ] Real-time validation feedback
- [ ] Add accessibility
  - [ ] Label associations
  - [ ] Error announcements
  - [ ] ARIA-invalid states
  - [ ] Required field indicators

**Form Validation Example**:
```typescript
export class FormValidator {
  private validators = {
    required: (value: string) => value?.trim() !== '',
    email: (value: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value),
    minLength: (min: number) => (value: string) => value.length >= min,
    maxLength: (max: number) => (value: string) => value.length <= max,
    pattern: (regex: RegExp) => (value: string) => regex.test(value),
    custom: (fn: (v: string) => boolean) => fn,
  };

  validate(form: HTMLFormElement, schema: FormSchema): ValidationResult {
    const errors = {};

    for (const [fieldName, rules] of Object.entries(schema)) {
      const field = form.elements[fieldName];
      const value = field.value;

      for (const rule of rules) {
        if (!this.validators[rule.type]?.(rule.params)(value)) {
          errors[fieldName] = rule.message;
          break;
        }
      }
    }

    return { isValid: Object.keys(errors).length === 0, errors };
  }
}

// Usage
const schema = {
  username: [
    { type: 'required', message: 'Username is required' },
    { type: 'minLength', params: 3, message: 'Min 3 chars' },
  ],
  email: [
    { type: 'required', message: 'Email is required' },
    { type: 'email', message: 'Invalid email' },
  ],
};
```

### 2.5 Image Loading & Optimization

**Current State**: No lazy loading, all images loaded
**Goal**: Optimized image delivery

**Tasks**:
- [ ] Implement lazy loading (native and polyfill)
- [ ] Create responsive image system
  - [ ] Multiple sizes per image
  - [ ] AVIF/WebP format support
  - [ ] Automatic format selection
  - [ ] Responsive srcset
- [ ] Optimize image assets
  - [ ] Compress JPEG (quality 80-85)
  - [ ] Optimize PNG (quantization)
  - [ ] Create WebP versions
  - [ ] Generate thumbnails
- [ ] Add image caching
  - [ ] Service worker caching
  - [ ] Cache-Control headers
  - [ ] Long-lived cache for hashed filenames
- [ ] Implement progressive loading
  - [ ] LQIP (Low Quality Image Placeholder)
  - [ ] Blur-up effect
  - [ ] Skeleton loaders

**Responsive Image System**:
```typescript
export class ResponsiveImageGenerator {
  private sizes = [320, 640, 1024, 1440];
  private formats = ['webp', 'jpeg'];

  async generateDerivatives(imageId: number, sourceFile: string) {
    for (const size of this.sizes) {
      // Generate each size
      await this.generateSize(sourceFile, size);

      // Generate each format
      for (const format of this.formats) {
        await this.generateFormat(sourceFile, size, format);
      }
    }
  }

  generateSrcset(imageId: number): string {
    return this.sizes
      .map(size => `/i/${imageId}/${size}.webp ${size}w`)
      .join(', ');
  }
}

// HTML output
<picture>
  <source srcset="/i/123/320.webp 320w, /i/123/640.webp 640w" type="image/webp">
  <source srcset="/i/123/320.jpg 320w, /i/123/640.jpg 640w" type="image/jpeg">
  <img src="/i/123/640.jpg" alt="Photo" loading="lazy">
</picture>
```

---

## Phase 3: Advanced Features (Releases 15.1 - 15.3)

### 3.1 Progressive Web App (PWA)

**Current State**: No PWA support
**Goal**: Installable, offline-capable web app

**Tasks**:
- [ ] Create service worker
  - [ ] Cache strategies (network-first, cache-first, stale-while-revalidate)
  - [ ] Offline fallback page
  - [ ] Background sync
  - [ ] Push notifications
- [ ] Create manifest.json
  - [ ] App metadata
  - [ ] Icons (multiple sizes)
  - [ ] Start URL
  - [ ] Display mode (standalone)
- [ ] Implement offline mode
  - [ ] Cache gallery pages
  - [ ] Cache images for viewing
  - [ ] Sync actions when online
  - [ ] Offline indicator
- [ ] Add installation UX
  - [ ] Install prompt
  - [ ] App shortcuts
  - [ ] Splash screen

**Service Worker Example**:
```typescript
const CACHE_NAME = 'piwigo-v1';
const URLS_TO_CACHE = [
  '/',
  '/index.html',
  '/css/app.css',
  '/js/app.js',
  '/offline.html'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(URLS_TO_CACHE))
  );
});

self.addEventListener('fetch', event => {
  // Network-first strategy
  event.respondWith(
    fetch(event.request)
      .catch(() => caches.match(event.request))
      .catch(() => caches.match('/offline.html'))
  );
});
```

### 3.2 Real-Time Features with WebSockets

**Current State**: Polling, no real-time updates
**Goal**: Real-time notifications and updates

**Tasks**:
- [ ] Create WebSocket client
  - [ ] Auto-reconnect
  - [ ] Message queuing
  - [ ] Heartbeat/keep-alive
- [ ] Implement real-time features
  - [ ] Live notifications
  - [ ] Real-time comment updates
  - [ ] Live search results
  - [ ] Collaborative editing (admin)
- [ ] Add fallback for browsers without WebSocket
  - [ ] Server-Sent Events (SSE)
  - [ ] Long polling
- [ ] Create reactive state updates
  - [ ] Component re-render on data change
  - [ ] Optimistic updates
  - [ ] Conflict resolution

**WebSocket Implementation**:
```typescript
export class WebSocketClient {
  private ws?: WebSocket;
  private reconnectAttempts = 0;
  private maxReconnectAttempts = 5;

  connect(url: string) {
    this.ws = new WebSocket(url);

    this.ws.addEventListener('open', () => this.onOpen());
    this.ws.addEventListener('message', (e) => this.onMessage(e));
    this.ws.addEventListener('close', () => this.onClose());
    this.ws.addEventListener('error', (e) => this.onError(e));
  }

  private onClose() {
    if (this.reconnectAttempts < this.maxReconnectAttempts) {
      const delay = Math.pow(2, this.reconnectAttempts) * 1000;
      setTimeout(() => this.connect(this.url), delay);
      this.reconnectAttempts++;
    }
  }

  send(type: string, data: any) {
    if (this.ws?.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify({ type, data }));
    }
  }
}
```

### 3.3 Advanced Search Interface

**Current State**: Basic search form
**Goal**: Rich, interactive search UI

**Tasks**:
- [ ] Create advanced search UI
  - [ ] Multiple filter panel
  - [ ] Date range picker
  - [ ] Tag selection
  - [ ] Category browser
  - [ ] Rating filter
- [ ] Implement search as-you-type
  - [ ] Debounced API requests
  - [ ] Result previews
  - [ ] Search suggestions
- [ ] Add search result enhancements
  - [ ] Faceted results (filter by category, etc.)
  - [ ] Result count
  - [ ] Sort options
  - [ ] Result view options (grid/list)
- [ ] Create saved searches
- [ ] Add search analytics

**Search Component**:
```typescript
export class AdvancedSearch {
  private searchInput: HTMLInputElement;
  private filters = {
    dateRange: null,
    categories: [],
    tags: [],
    rating: 0,
  };

  constructor() {
    this.initializeUI();
    this.attachEventListeners();
  }

  private attachEventListeners() {
    // Debounced search
    this.searchInput.addEventListener('input',
      this.debounce((e) => this.search(), 300)
    );

    // Filter changes
    document.addEventListener('filter:change', (e) => {
      this.filters[e.detail.type] = e.detail.value;
      this.search();
    });
  }

  private async search() {
    const query = this.searchInput.value;
    const results = await this.api.search({
      q: query,
      filters: this.filters,
    });

    this.renderResults(results);
  }

  private debounce(fn: Function, delay: number) {
    let timeout: NodeJS.Timeout;
    return (...args: any[]) => {
      clearTimeout(timeout);
      timeout = setTimeout(() => fn(...args), delay);
    };
  }
}
```

### 3.4 Accessibility Improvements

**Current State**: Limited accessibility features
**Goal**: WCAG 2.1 AA compliance

**Tasks**:
- [ ] Audit accessibility (use Axe or WAVE)
- [ ] Fix critical issues
  - [ ] Color contrast
  - [ ] Missing alt text
  - [ ] Missing ARIA labels
  - [ ] Keyboard navigation
  - [ ] Focus indicators
- [ ] Add accessibility features
  - [ ] Dark mode support (prefers-color-scheme)
  - [ ] Reduced motion support (prefers-reduced-motion)
  - [ ] Text sizing controls
  - [ ] High contrast mode
  - [ ] Screen reader optimization
- [ ] Create accessibility guide for plugins/themes
- [ ] Add accessibility testing to CI/CD

**Accessibility Implementation**:
```css
/* Respect user preferences */
@media (prefers-reduced-motion: reduce) {
  * {
    animation: none !important;
    transition: none !important;
  }
}

@media (prefers-color-scheme: dark) {
  :root {
    --color-bg: #1a1a1a;
    --color-text: #e0e0e0;
    --color-primary: #64b5f6;
  }
}

/* Focus indicators */
:focus-visible {
  outline: 3px solid var(--color-primary);
  outline-offset: 2px;
}

/* Text sizing */
@supports (font-size: clamp(1rem, 1vw + 1rem, 2rem)) {
  body { font-size: clamp(1rem, 1vw + 1rem, 1.5rem); }
}
```

### 3.5 Performance Monitoring & Optimization

**Current State**: No real-time performance monitoring
**Goal**: Observable performance metrics

**Tasks**:
- [ ] Implement Core Web Vitals tracking
  - [ ] Largest Contentful Paint (LCP)
  - [ ] First Input Delay (FID)
  - [ ] Cumulative Layout Shift (CLS)
- [ ] Add real-time performance monitoring
  - [ ] Page load time
  - [ ] API response times
  - [ ] Resource loading times
- [ ] Create performance dashboard
  - [ ] Historical trends
  - [ ] Browser/device breakdown
  - [ ] Alerts for degradation
- [ ] Implement performance optimizations
  - [ ] Code splitting per route
  - [ ] Tree shaking
  - [ ] CSS-in-JS optimization
  - [ ] Bundle size limits

**Performance Monitoring**:
```typescript
export class PerformanceMonitor {
  measureWebVitals() {
    // Largest Contentful Paint
    new PerformanceObserver((list) => {
      const entries = list.getEntries();
      const lastEntry = entries[entries.length - 1];
      console.log('LCP:', lastEntry.renderTime || lastEntry.loadTime);
    }).observe({ entryTypes: ['largest-contentful-paint'] });

    // First Input Delay
    new PerformanceObserver((list) => {
      list.getEntries().forEach(entry => {
        console.log('FID:', entry.processingDuration);
      });
    }).observe({ entryTypes: ['first-input'] });

    // Cumulative Layout Shift
    let clsValue = 0;
    new PerformanceObserver((list) => {
      list.getEntries().forEach(entry => {
        if (!entry.hadRecentInput) {
          clsValue += entry.value;
          console.log('CLS:', clsValue);
        }
      });
    }).observe({ entryTypes: ['layout-shift'] });
  }
}
```

---

## Phase 4: Testing & Quality (Parallel to Phases 2-3)

### 4.1 Unit Testing

**Current State**: Minimal testing
**Goal**: Comprehensive unit test coverage

**Tasks**:
- [ ] Set up Jest or Vitest
- [ ] Create tests for core utilities
  - [ ] DOM utilities
  - [ ] Date/time helpers
  - [ ] Validators
  - [ ] Formatters
- [ ] Test components in isolation
  - [ ] Gallery component
  - [ ] Admin components
  - [ ] Form validation
  - [ ] API client
- [ ] Target 70%+ coverage

**Jest Configuration**:
```javascript
// jest.config.js
export default {
  preset: 'ts-jest',
  testEnvironment: 'jsdom',
  roots: ['<rootDir>/src'],
  testMatch: ['**/__tests__/**/*.ts', '**/?(*.)+(spec|test).ts'],
  collectCoverageFrom: [
    'src/**/*.ts',
    '!src/**/*.d.ts',
    '!src/index.ts',
  ],
  coverageThreshold: {
    global: {
      branches: 70,
      functions: 70,
      lines: 70,
      statements: 70,
    },
  },
};
```

### 4.2 E2E Testing

**Current State**: Manual testing
**Goal**: Automated end-to-end tests

**Tasks**:
- [ ] Set up Playwright or Cypress
- [ ] Test critical user paths
  - [ ] User registration
  - [ ] Photo upload
  - [ ] Gallery browsing
  - [ ] Search functionality
  - [ ] Admin operations
- [ ] Test browser compatibility
  - [ ] Chrome/Chromium
  - [ ] Firefox
  - [ ] Safari
  - [ ] Mobile browsers
- [ ] Test performance in E2E tests
- [ ] Visual regression testing

**E2E Test Example**:
```typescript
// tests/e2e/gallery.spec.ts
import { test, expect } from '@playwright/test';

test('User can browse gallery and open lightbox', async ({ page }) => {
  await page.goto('/');

  // Wait for gallery to load
  const gallery = page.locator('[data-testid="gallery"]');
  await expect(gallery).toBeVisible();

  // Click first image
  const firstImage = gallery.locator('img').first();
  await firstImage.click();

  // Lightbox should open
  const lightbox = page.locator('[data-testid="lightbox"]');
  await expect(lightbox).toBeVisible();

  // Navigation should work
  const nextButton = page.locator('[data-testid="lightbox-next"]');
  await nextButton.click();

  // Image should change
  const newImage = lightbox.locator('img');
  const newSrc = await newImage.getAttribute('src');
  expect(newSrc).not.toBe(await firstImage.getAttribute('src'));
});
```

### 4.3 Accessibility Testing

**Current State**: Manual checks
**Goal**: Automated accessibility testing

**Tasks**:
- [ ] Set up axe-core or Lighthouse
- [ ] Integrate into CI/CD
- [ ] Test critical flows
- [ ] Manual keyboard testing
- [ ] Screen reader testing

### 4.4 Performance Testing

**Current State**: Ad-hoc checking
**Goal**: Continuous performance monitoring

**Tasks**:
- [ ] Set up Lighthouse CI
- [ ] Define performance budgets
  - [ ] Bundle size limits
  - [ ] Performance score targets
  - [ ] Core Web Vitals targets
- [ ] Test against multiple devices
- [ ] Monitor performance trends

---

## Phase 5: Documentation & Education (Ongoing)

### 5.1 Developer Documentation

**Tasks**:
- [ ] Create component library documentation
- [ ] Document architecture decisions
- [ ] Create setup guide for new developers
- [ ] Document CSS/design system
- [ ] Create Storybook for components

**Storybook Integration**:
```bash
npx storybook init
```

```typescript
// components/Gallery/Gallery.stories.ts
import { Gallery } from './Gallery';

export default {
  title: 'Components/Gallery',
  component: Gallery,
};

export const Default = {
  args: {
    images: [
      { id: 1, src: '/image1.jpg', alt: 'Image 1' },
      { id: 2, src: '/image2.jpg', alt: 'Image 2' },
    ],
  },
};
```

### 5.2 Migration Guides

**Tasks**:
- [ ] Create jQuery to vanilla JS migration guide
- [ ] Create TypeScript adoption guide
- [ ] Document CSS migration
- [ ] Document build system usage
- [ ] Create plugin/theme upgrade guide

---

## Bundle Size Analysis & Goals

### Current State (Estimated)
```
Total:  ~868KB minified
Gzip:   ~250KB
```

### Target State (Phase 3 completion)
```
Core App:   120KB (React/Vue app if used)
jQuery Removal:  -85KB
Plugin Cleanup:  -50KB
Optimization:    -100KB
Modern Bundling: -60KB
Result:     ~350KB minified, ~80KB gzip
Target Savings: 60% reduction
```

---

## Migration Strategy & Rollout

### Backward Compatibility

**For Plugins/Themes**:
1. Keep jQuery available during grace period
2. Provide migration helpers
3. Document new patterns
4. Timeline: 12-month deprecation window

**For Users**:
1. Progressive enhancement approach
2. Fallback for unsupported browsers
3. No breaking UI changes per release

### Rollout Timeline

| Phase | Version | Timeline | Focus |
|-------|---------|----------|-------|
| 1 | 14.5-14.7 | 3 months | Build system, TypeScript, CSS foundation |
| 2 | 14.8-15.0 | 6 months | Component modernization, jQuery removal |
| 3 | 15.1-15.3 | 6 months | Advanced features, optimization |
| 4 | Parallel | Ongoing | Testing, quality, performance |
| 5 | Ongoing | Continuous | Docs, education, community |

---

## Risk Mitigation

### High-Risk Areas
1. **Browser compatibility**: Extensive testing, polyfills
2. **Plugin/theme breaking**: Backward compatibility layer
3. **Performance regression**: Benchmarking before/after
4. **User disruption**: Gradual rollout, feature flags

### Monitoring & Metrics
- Browser analytics (usage by browser version)
- Error rate monitoring
- Performance metrics (Core Web Vitals)
- User feedback/issue tracking
- Plugin compatibility status

---

## Success Criteria

- [ ] Bundle size reduced by 60%
- [ ] Mobile performance score ≥90
- [ ] Desktop performance score ≥95
- [ ] Core Web Vitals all green
- [ ] 70%+ unit test coverage
- [ ] WCAG 2.1 AA compliance
- [ ] All E2E critical paths passing
- [ ] Plugin ecosystem updated 90%+
- [ ] Zero breaking changes for end users
- [ ] Community satisfaction rating ≥4.5/5

---

## Long-Term Vision

### Post-Modernization Opportunities

1. **Component Library**
   - Shared UI components across platform
   - Storybook for documentation
   - Version published to npm

2. **Native Mobile Apps**
   - React Native or Flutter
   - Share API client
   - Same features as web

3. **Desktop App**
   - Electron-based native app
   - Offline-first photo management
   - Desktop integration

4. **Advanced Features**
   - Machine learning image recognition
   - Real-time collaboration
   - Advanced analytics dashboard
   - Integration marketplace

---

## Comparison: Before vs. After

### Before (jQuery Era)
- 868KB JS (250KB gzip)
- jQuery 1.11.3 (2014)
- Manual DOM manipulation
- Minimal testing
- Limited mobile optimization
- Basic accessibility
- No offline support
- Single bundle per page

### After (Modern Era)
- 350KB JS (80KB gzip) - 60% reduction
- Modern frameworks/vanilla
- Component-based architecture
- 70%+ test coverage
- Mobile-first responsive design
- WCAG 2.1 AA compliant
- Full PWA support
- Code splitting per route
- TypeScript for safety
- Observable performance

---

**Document Version**: 1.0
**Last Updated**: November 2025
**Next Review**: Q2 2025

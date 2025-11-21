# TypeScript Migration Guide for Piwigo

**Context**: Piwigo uses Smarty templating with server-side variable passing to client-side JavaScript

---

## Executive Summary

**Yes, TypeScript migration is possible and recommended.** The current architecture has:
- ✅ Existing build system capability (Bun package manager, ScriptLoader, FileCombiner)
- ✅ Clean separation between server templates and client JS
- ✅ Data attributes for type-safe Smarty-to-JS communication
- ❌ No existing build tool (webpack, Vite)
- ❌ jQuery and global namespace pollution
- ❌ Minimal type definitions

**Migration Strategy**: Gradual, starting with build system + TypeScript, then converting high-value files.

---

## Current Architecture Challenge

### How Data Flows Today

```
PHP/Smarty         Generates        Browser
┌────────────┐     ┌─────────┐      ┌──────────┐
│ Page Data  │────>│ HTML    │─────>│ JS reads │
│ Variables  │     │ with:   │      │ from:    │
│            │     │ • JSON  │      │ • data-* │
│            │     │ • attrs │      │ • window │
└────────────┘     │ • inline│      │ • inline │
                   │ scripts │      │          │
                   └─────────┘      └──────────┘
```

### The Key Challenge: Smarty Variables in JavaScript

**Current pattern:**
```smarty
{footer_script require="jquery"}<script>
  var rootUrl = "{get_absolute_root_url()}";      // String interpolation
  var photoId = {$photo.id};                       // Number interpolation
  var categories = {$categories|json_encode};     // JSON object
</script>{/footer_script}
```

**TypeScript requires type declarations**, but these values come from Smarty at runtime.

---

## Solution: Typed Data Contracts

Instead of inline scripts with loose types, define **type contracts** between Smarty and TypeScript:

### Step 1: Create Type Definition File for Smarty Data

```typescript
// src/types/smarty-data.ts
/**
 * Types for data passed from Smarty templates to JavaScript
 * These types must match the PHP data being encoded
 */

export interface BodyData {
  rootUrl: string;
  imageId?: number;
  categoryId?: number;
  userId: number;
  isAdmin: boolean;
  isGuest: boolean;
  pwgToken: string;
  language: string;
  theme: string;
  [key: string]: any;  // Allow extensions from plugins
}

export interface PhotoData {
  id: number;
  name: string;
  file: string;
  comment?: string;
  author?: string;
  dateAvailable: string;
  dateCreation?: string;
  width: number;
  height: number;
  averageRate?: number;
  hit: number;
  tags: Tag[];
  categories: Category[];
}

export interface Category {
  id: number;
  name: string;
  url: string;
  nbImages: number;
  nbComments: number;
}

export interface Tag {
  id: number;
  name: string;
  urlName: string;
  url: string;
}

export interface User {
  id: number;
  username: string;
  email: string;
  status: 'active' | 'inactive';
  theme: string;
  language: string;
}

export interface PwgError {
  code: number;
  message: string;
}

export interface ApiResponse<T> {
  stat: 'ok' | 'fail';
  result: T;
  error?: PwgError;
}
```

### Step 2: Create Safe Data Access Layer

```typescript
// src/core/data-provider.ts
import type { BodyData, PhotoData, Category } from '../types/smarty-data';

/**
 * Safely retrieves and parses Smarty-generated data from HTML
 * This is the bridge between server-rendered data and TypeScript code
 */

export class DataProvider {
  private bodyData: BodyData | null = null;

  /**
   * Get global body data passed from Smarty
   * Example: <body data-infos='{"rootUrl": "..."}'>
   */
  getBodyData(): BodyData {
    if (!this.bodyData) {
      const element = document.body;
      const dataAttr = element.getAttribute('data-infos');

      if (!dataAttr) {
        throw new Error(
          'Body data not found. Ensure Smarty template passes data-infos attribute'
        );
      }

      try {
        this.bodyData = JSON.parse(dataAttr) as BodyData;
      } catch (error) {
        throw new Error(`Failed to parse body data: ${error}`);
      }
    }

    return this.bodyData;
  }

  /**
   * Get data from a specific element's data attributes
   * Safely parses JSON with error handling
   */
  getElementData<T = Record<string, any>>(
    element: HTMLElement,
    attributeName: string = 'data-json'
  ): T {
    const jsonStr = element.getAttribute(attributeName);
    if (!jsonStr) {
      throw new Error(
        `Element data not found: ${attributeName}`
      );
    }

    try {
      return JSON.parse(jsonStr) as T;
    } catch (error) {
      throw new Error(
        `Failed to parse element data (${attributeName}): ${error}`
      );
    }
  }

  /**
   * Safely get a global variable that was defined by Smarty inline script
   * Example: var photosUploaded = {...}; in footer_script
   */
  getGlobalVariable<T = any>(variableName: keyof Window): T {
    const value = (window as any)[variableName];

    if (value === undefined) {
      throw new Error(
        `Global variable not found: ${variableName}. ` +
        `Ensure Smarty template defines this variable.`
      );
    }

    return value as T;
  }

  /**
   * Safely get rootUrl with validation
   */
  getRootUrl(): string {
    const data = this.getBodyData();
    if (!data.rootUrl) {
      throw new Error('rootUrl not provided in Smarty body data');
    }
    return data.rootUrl;
  }

  /**
   * Check if user has admin privileges
   */
  isAdmin(): boolean {
    return this.getBodyData().isAdmin ?? false;
  }

  /**
   * Check if current user is guest
   */
  isGuest(): boolean {
    return this.getBodyData().isGuest ?? true;
  }

  /**
   * Get CSRF token for form submissions
   */
  getCsrfToken(): string {
    const data = this.getBodyData();
    if (!data.pwgToken) {
      throw new Error('CSRF token not available');
    }
    return data.pwgToken;
  }
}

// Export singleton instance
export const dataProvider = new DataProvider();
```

### Step 3: Update Smarty Template to Provide Typed Data

```smarty
{* themes/bootstrap_darkroom/template/header.tpl *}

{* Pass structured data from PHP to TypeScript *}
<body id="{$BODY_ID}"
      class="{foreach $BODY_CLASSES as $class}{$class} {/foreach}"
      data-infos='{
        "rootUrl": "{get_absolute_root_url()|json_encode}",
        "imageId": {$image.id|default:'null'},
        "categoryId": {$category.id|default:'null'},
        "userId": {$user.id|json_encode},
        "isAdmin": {if is_admin()}true{else}false{/if},
        "isGuest": {if is_guest()}true{else}false{/if},
        "pwgToken": "{$pwg_token|json_encode}",
        "language": "{$LANGUAGE_INFOS.code|json_encode}",
        "theme": "{$THEME|json_encode}"
      }'>

  {* Rest of template *}
</body>
```

### Step 4: Use Type-Safe Data in Components

```typescript
// src/components/Gallery.ts
import { dataProvider } from '../core/data-provider';
import type { PhotoData } from '../types/smarty-data';

export class Gallery {
  private rootUrl: string;
  private photoElement: HTMLElement;

  constructor(selector: string) {
    // Type-safe data retrieval
    this.rootUrl = dataProvider.getRootUrl();

    const element = document.querySelector(selector);
    if (!element || !(element instanceof HTMLElement)) {
      throw new Error(`Gallery element not found: ${selector}`);
    }

    this.photoElement = element;
    this.initialize();
  }

  private initialize(): void {
    // Process each photo element
    this.photoElement.querySelectorAll('[data-photo]').forEach(el => {
      if (!(el instanceof HTMLElement)) return;

      // Retrieve photo data from element attribute
      // Type is known to be PhotoData thanks to TypeScript
      const photo = dataProvider.getElementData<PhotoData>(el, 'data-photo');

      // Now photo is fully typed!
      console.log(`Photo: ${photo.name} (${photo.width}x${photo.height})`);

      this.attachPhotoHandlers(el, photo);
    });
  }

  private attachPhotoHandlers(el: HTMLElement, photo: PhotoData): void {
    el.addEventListener('click', () => {
      // Navigate to photo with type-safe data
      this.openPhoto(photo.id);
    });
  }

  private openPhoto(photoId: number): void {
    const url = `${this.rootUrl}index.php?/picture/${photoId}`;
    window.location.href = url;
  }
}
```

### Step 5: Create API Client with Type Safety

```typescript
// src/api/api-client.ts
import { dataProvider } from '../core/data-provider';
import type { ApiResponse } from '../types/smarty-data';

/**
 * Type-safe API client for Piwigo web service
 * All API calls are fully typed for safety
 */

export class ApiClient {
  private rootUrl: string;
  private token: string;

  constructor() {
    this.rootUrl = dataProvider.getRootUrl();
    this.token = dataProvider.getCsrfToken();
  }

  /**
   * Call Piwigo web service with full type safety
   */
  async call<T = any>(
    method: string,
    params: Record<string, any> = {}
  ): Promise<T> {
    const url = new URL(`${this.rootUrl}ws.php`);
    url.searchParams.set('format', 'json');
    url.searchParams.set('method', method);
    url.searchParams.set('pwg_token', this.token);

    // Add additional parameters
    Object.entries(params).forEach(([key, value]) => {
      url.searchParams.set(key, String(value));
    });

    try {
      const response = await fetch(url, {
        method: 'GET',
        headers: { 'Content-Type': 'application/json' },
      });

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const data: ApiResponse<T> = await response.json();

      if (data.stat === 'fail') {
        throw new Error(data.error?.message || 'API call failed');
      }

      return data.result;
    } catch (error) {
      throw new Error(
        `API call failed (${method}): ${error instanceof Error ? error.message : String(error)}`
      );
    }
  }

  /**
   * Typed methods for common API calls
   */
  async getImageList(categoryId?: number, limit: number = 100): Promise<any[]> {
    const params: Record<string, any> = { per_page: limit };
    if (categoryId) params.cat_id = categoryId;

    return this.call('pwg.images.getList', params);
  }

  async getCategories(imageId?: number): Promise<any[]> {
    const params: Record<string, any> = {};
    if (imageId) params.image_id = imageId;

    return this.call('pwg.categories.getList', params);
  }

  async searchPhotos(query: string, limit: number = 20): Promise<any[]> {
    return this.call('pwg.images.search', {
      query,
      per_page: limit,
    });
  }
}

export const apiClient = new ApiClient();
```

---

## Phase-Based Migration Plan

### Phase 1: Infrastructure Setup (1-2 weeks)

**Goal**: Set up TypeScript build system without breaking existing code

**Tasks**:
```bash
# 1. Create TypeScript configuration
npx tsc --init --target ES2020 --module ESNext --lib ES2020,DOM,DOM.Iterable --strict

# 2. Install TypeScript and build tools
npm install --save-dev typescript webpack webpack-cli ts-loader
npm install --save-dev @types/jquery @types/node

# 3. Create webpack config for TypeScript
# 4. Create type definitions for Smarty data
# 5. Create data-provider.ts (bridge)
# 6. Add build script to package.json
```

**Example package.json additions:**
```json
{
  "devDependencies": {
    "typescript": "^5.3.0",
    "webpack": "^5.89.0",
    "webpack-cli": "^5.1.0",
    "ts-loader": "^9.5.0",
    "@types/jquery": "^3.5.0"
  },
  "scripts": {
    "build": "webpack --mode production",
    "dev": "webpack --mode development --watch",
    "type-check": "tsc --noEmit"
  }
}
```

### Phase 2: High-Value File Conversion (2-3 weeks)

Convert critical files in order of dependency:

1. **Core Utilities** (`themes/default/js/scripts.js`)
   - Global helper functions
   - Once converted, can be imported by other modules

2. **API Communication** (`PwgWS` in scripts.js)
   - Create `ApiClient` as shown above
   - Replace all `PwgWS` usage

3. **Gallery Component** (`theme.js`)
   - Most complex, good candidate for modern architecture

4. **Admin Components** (`admin/themes/default/js/common.js`)

### Phase 3: Plugin/Theme Support (1-2 weeks)

- [ ] Create type definitions for plugin API
- [ ] Document migration path for plugins
- [ ] Provide type definitions for common jQuery plugins

### Phase 4: Full Migration (ongoing)

- [ ] Convert remaining files
- [ ] Remove jQuery dependency
- [ ] Modernize to vanilla JavaScript/TypeScript

---

## Step-by-Step Example: Converting a Simple File

### Before (jQuery + Global Functions)

```javascript
// themes/default/js/rating.js
function pwg_add_rate(image_id, rating)
{
    var url = baseUrl + 'ws.php?method=pwg.images.rate&image_id=' + image_id + '&rate=' + rating + '&format=json';
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);

    xhr.onload = function() {
        if (xhr.status === 200) {
            var response = JSON.parse(xhr.responseText);
            if (response.stat == 'ok') {
                jQuery('#img' + image_id + ' .rate-value').text(response.result.average);
            }
        }
    };

    xhr.send();
}

jQuery(document).ready(function() {
    jQuery('.rating-btn').each(function() {
        var imageId = jQuery(this).data('image-id');
        jQuery(this).on('click', function() {
            var rating = jQuery(this).data('rating');
            pwg_add_rate(imageId, rating);
        });
    });
});
```

### After (TypeScript with Type Safety)

```typescript
// src/components/rating.ts
import { apiClient } from '../api/api-client';

export interface RatingData {
  imageId: number;
  rating: number;
  averageRating: number;
  userRating?: number;
}

export class RatingComponent {
  private apiClient: ApiClient;

  constructor() {
    this.apiClient = apiClient;
    this.initialize();
  }

  private initialize(): void {
    // Find all rating buttons
    document.querySelectorAll('[data-rating]').forEach(el => {
      if (!(el instanceof HTMLElement)) return;

      const imageId = el.getAttribute('data-image-id');
      const rating = el.getAttribute('data-rating');

      if (!imageId || !rating) return;

      el.addEventListener('click', () => {
        this.rateImage(parseInt(imageId), parseInt(rating));
      });
    });
  }

  private async rateImage(imageId: number, rating: number): Promise<void> {
    try {
      const result = await this.apiClient.call('pwg.images.rate', {
        image_id: imageId,
        rate: rating,
      });

      // Update UI with result
      const ratingElement = document.querySelector(
        `[data-image-id="${imageId}"] .rate-value`
      );

      if (ratingElement instanceof HTMLElement) {
        ratingElement.textContent = String(result.average);
      }

      // Dispatch custom event for other components to listen
      window.dispatchEvent(
        new CustomEvent('rating:changed', {
          detail: { imageId, newRating: result.average },
        })
      );
    } catch (error) {
      console.error('Failed to rate image:', error);
      // Show error notification to user
    }
  }

  /**
   * Update rating UI for multiple images
   */
  updateRatings(ratings: RatingData[]): void {
    ratings.forEach(({ imageId, averageRating }) => {
      const element = document.querySelector(
        `[data-image-id="${imageId}"] .rate-value`
      );
      if (element instanceof HTMLElement) {
        element.textContent = String(averageRating);
      }
    });
  }
}

// Entry point
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    new RatingComponent();
  });
} else {
  new RatingComponent();
}
```

### Updated Smarty Template

```smarty
{* themes/bootstrap_darkroom/template/photo.tpl *}

{* Pass photo data to TypeScript component *}
<div class="photo-rating" data-image-id="{$photo.id}">
  {foreach from=$ratings item=star}
    <button
      class="rating-btn star-{$star}"
      data-rating="{$star}"
      data-image-id="{$photo.id}"
      aria-label="{$star} {'star'|translate|escape}">
      ★
    </button>
  {/foreach}

  <span class="rate-value">{$photo.average_rating}</span>
</div>

{* Load compiled TypeScript component *}
{combine_script
  id='rating-component'
  path='dist/components/rating.js'
  load='footer'}
```

---

## Type Definitions for Common Libraries

### jQuery Types

```typescript
// src/types/jquery.d.ts
declare global {
  interface Window {
    jQuery: typeof jQuery;
    $: typeof jQuery;
  }
}

declare const jQuery: any;
declare const $: any;
```

### Smarty Plugin Functions

```typescript
// src/types/smarty-functions.d.ts
/**
 * Global functions provided by Smarty templates
 */

declare global {
  interface Window {
    rootUrl: string;
    pwg_token: string;
    bd_popup(url: string): void;
    phpWGOpenWindow(url: string, name: string, features: string): void;
  }
}
```

---

## Webpack Configuration

```typescript
// webpack.config.ts
import path from 'path';
import type { Configuration } from 'webpack';

const config: Configuration = {
  mode: 'production',
  entry: {
    // Entry points for different pages/features
    app: './src/app.ts',
    admin: './src/admin.ts',
    gallery: './src/components/gallery.ts',
    rating: './src/components/rating.ts',
  },
  output: {
    path: path.resolve(__dirname, 'dist'),
    filename: '[name].[contenthash].js',
    chunkFilename: '[name].[contenthash].chunk.js',
    publicPath: '/dist/',
  },
  module: {
    rules: [
      {
        test: /\.tsx?$/,
        use: 'ts-loader',
        exclude: /node_modules/,
      },
    ],
  },
  resolve: {
    extensions: ['.tsx', '.ts', '.js'],
    alias: {
      '@': path.resolve(__dirname, 'src/'),
    },
  },
  devtool: 'source-map',
  optimization: {
    splitChunks: {
      chunks: 'all',
      cacheGroups: {
        vendor: {
          test: /[\\/]node_modules[\\/]/,
          name: 'vendors',
          priority: 10,
        },
        common: {
          minChunks: 2,
          priority: 5,
          reuseExistingChunk: true,
        },
      },
    },
  },
};

export default config;
```

---

## Compatibility Layer for Gradual Migration

While migrating, maintain compatibility with existing code:

```typescript
// src/legacy-compatibility.ts
/**
 * Provides compatibility layer between old jQuery code and new TypeScript code
 * Allows gradual migration without breaking existing functionality
 */

declare global {
  interface Window {
    PwgWS: any;
    baseUrl: string;
    [key: string]: any;
  }
}

import { apiClient } from './api/api-client';

// Expose new API client as legacy global for old code
window.apiClient = apiClient;

// Create shim for old PwgWS if needed
if (!window.PwgWS) {
  window.PwgWS = class PwgWS {
    constructor(private rootUrl: string) {}

    callService(method: string, params: any, callback: any) {
      apiClient.call(method, params)
        .then(result => callback({ result }))
        .catch(error => callback({ error: { message: error.message } }));
    }
  };
}

// Expose baseUrl globally for old code
window.baseUrl = dataProvider.getRootUrl();
```

---

## ESLint + TypeScript Configuration

```json
{
  ".eslintrc.json": {
    "parser": "@typescript-eslint/parser",
    "extends": [
      "eslint:recommended",
      "plugin:@typescript-eslint/recommended",
      "prettier"
    ],
    "plugins": ["@typescript-eslint"],
    "rules": {
      "no-var": "error",
      "prefer-const": "error",
      "@typescript-eslint/no-explicit-any": "warn",
      "@typescript-eslint/explicit-function-return-types": "error",
      "@typescript-eslint/no-unused-vars": [
        "error",
        {
          "argsIgnorePattern": "^_",
          "varsIgnorePattern": "^_"
        }
      ]
    }
  }
}
```

---

## Testing TypeScript Components

```typescript
// src/components/__tests__/rating.spec.ts
import { RatingComponent } from '../rating';
import { apiClient } from '../../api/api-client';

// Mock the API client
jest.mock('../../api/api-client');

describe('RatingComponent', () => {
  let component: RatingComponent;

  beforeEach(() => {
    document.body.innerHTML = `
      <div class="photo-rating" data-image-id="123">
        <button class="rating-btn" data-rating="5" data-image-id="123">★</button>
        <span class="rate-value">4.0</span>
      </div>
    `;

    component = new RatingComponent();
  });

  it('should update rating when button clicked', async () => {
    (apiClient.call as jest.Mock).mockResolvedValue({ average: 4.5 });

    const button = document.querySelector('.rating-btn') as HTMLElement;
    button.click();

    await new Promise(resolve => setTimeout(resolve, 100));

    const ratingValue = document.querySelector('.rate-value');
    expect(ratingValue?.textContent).toBe('4.5');
  });

  it('should handle API errors gracefully', async () => {
    (apiClient.call as jest.Mock).mockRejectedValue(new Error('API error'));

    const consoleSpy = jest.spyOn(console, 'error');

    const button = document.querySelector('.rating-btn') as HTMLElement;
    button.click();

    await new Promise(resolve => setTimeout(resolve, 100));

    expect(consoleSpy).toHaveBeenCalledWith('Failed to rate image:', expect.any(Error));
  });
});
```

---

## Benefits of This Approach

| Benefit | How Achieved |
|---------|-------------|
| **Type Safety** | Full TypeScript with strict mode |
| **Smarty Compatibility** | Data contract between server and client |
| **Gradual Migration** | Can convert files one at a time |
| **Backward Compatible** | Legacy code works alongside TypeScript |
| **No Breaking Changes** | Old jQuery plugins still work |
| **Better Tooling** | IDE autocomplete, refactoring support |
| **Testability** | Decoupled from DOM, easy to test |
| **Performance** | Tree shaking, code splitting |
| **Maintainability** | Clear types, explicit contracts |

---

## Common Pitfalls to Avoid

### 1. ❌ Assuming Smarty Variables at Type-Time

```typescript
// WRONG - Smarty variables don't exist at compile time
const rootUrl = rootUrl;  // Would error

// CORRECT - Retrieve at runtime
const rootUrl = dataProvider.getRootUrl();
```

### 2. ❌ Not Validating Smarty Data

```typescript
// WRONG - No validation
const photo = dataProvider.getElementData<PhotoData>(el);

// CORRECT - Validate structure
const photo = dataProvider.getElementData<PhotoData>(el);
if (!photo.id || !photo.name) {
  throw new Error('Invalid photo data');
}
```

### 3. ❌ Over-Typing Everything

```typescript
// WRONG - Too complex
interface GlobalWindowData extends Window {
  variableA: string;
  variableB: number;
  // ... 50 more properties
}

// CORRECT - Use specific types where needed
const token = dataProvider.getCsrfToken();
const data = dataProvider.getBodyData();
```

### 4. ❌ Forgetting to Update Smarty Templates

When converting JS to TypeScript, remember to:
- Update `{combine_script}` paths to point to built files
- Add necessary `data-*` attributes with JSON
- Remove inline `<script>` tags if functionality is now in TypeScript

---

## Migration Checklist

- [ ] Set up TypeScript configuration
- [ ] Install webpack + ts-loader
- [ ] Create type definitions for Smarty data
- [ ] Create DataProvider class
- [ ] Create ApiClient class
- [ ] Convert first utility file to TypeScript
- [ ] Test build process
- [ ] Create ESLint configuration
- [ ] Convert high-value files (gallery, admin)
- [ ] Set up unit tests
- [ ] Update build pipeline
- [ ] Document for team/community
- [ ] Remove jQuery gradually
- [ ] Full migration complete

---

## Recommended Tool Stack

```json
{
  "devDependencies": {
    "typescript": "^5.3.0",
    "webpack": "^5.89.0",
    "webpack-cli": "^5.1.0",
    "ts-loader": "^9.5.0",
    "@types/node": "^20.0.0",
    "@types/jquery": "^3.5.0",
    "@typescript-eslint/eslint-plugin": "^6.0.0",
    "@typescript-eslint/parser": "^6.0.0",
    "eslint": "^8.50.0",
    "prettier": "^3.0.0",
    "jest": "^29.7.0",
    "ts-jest": "^29.1.0",
    "@testing-library/dom": "^9.3.0"
  }
}
```

---

## Conclusion

**TypeScript migration is not only possible but highly recommended** for Piwigo. The key is:

1. ✅ Understand data flow between Smarty and JavaScript
2. ✅ Create type definitions for Smarty data
3. ✅ Build bridge layer (DataProvider)
4. ✅ Set up modern build system (webpack/Vite + TypeScript)
5. ✅ Migrate files gradually, starting with utilities
6. ✅ Keep backward compatibility during transition
7. ✅ Test thoroughly at each step

The Smarty-to-JavaScript connection isn't a blocker—it's just a design pattern that requires careful type definition and validation.

---

**Document Version**: 1.0
**Last Updated**: November 2025
**Estimated Migration Time**: 3-6 months for full migration

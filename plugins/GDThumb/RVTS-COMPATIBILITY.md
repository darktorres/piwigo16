# GDThumb + RV Thumb Scroller: Theme Compatibility Issues

## Root Cause: `merge()` destroys the element RVTS depends on

The theme-dependent failure happens on pages that have **both categories and images** (e.g., homepage, parent albums).

### The merge mechanism

When GDThumb renders category thumbnails (`gdthumb_cat.tpl:71`), it initializes with `do_merge = true`:

```javascript
GDThumb.setup('crop', 200, 10, true, big_thumb, ...);
//                               ^^^^
```

This triggers `GDThumb.merge()` in `gdthumb.js:58-70`:

```javascript
merge: function () {
    var mainlists = $(".content ul.thumbnails");  // <-- KEY SELECTOR
    if (mainlists.length < 2) {
        // only one list, skip
    } else {
        $(".thumbnailCategories li").addClass("album");
        $(".thumbnailCategories").append($(".content ul#thumbnails").html());
        $("ul#thumbnails").remove();   // <-- DESTROYS RVTS's target
    }
},
```

Meanwhile, RVTS binds to `#thumbnails` in `rv_tscroller.js:120`:

```javascript
RVTS.$thumbs = $("#thumbnails");  // already removed by merge!
```

### Why it's theme-dependent

The merge selector is `$(".content ul.thumbnails")` — it looks for `ul.thumbnails` inside an element with **class `content`**.

| Theme | Content wrapper class | `.content` matches? | Merge triggers? | RVTS works? |
|---|---|---|---|---|
| **default** | `class="content"` | Yes | Yes → removes `#thumbnails` | **Broken** |
| **elegant** | inherits default | Yes | Yes → removes `#thumbnails` | **Broken** |
| **modus** | inherits default | Yes | Yes → removes `#thumbnails` | **Broken** |
| **bootstrap_darkroom** | `class="content-grid"` | **No** | No → `#thumbnails` stays | **Works** |

Relevant lines:

- `themes/default/template/index.tpl:12`: `<div id="content" class="content ...">`
- `themes/bootstrap_darkroom/template/index.tpl:225-226`: `<div id="content" class="content-grid ...">`

On bootstrap_darkroom, `$(".content ul.thumbnails")` finds nothing, merge is skipped, and `#thumbnails` survives for RVTS.

### Only affects mixed pages

On leaf categories (images only, no sub-albums), there's only one `ul.thumbnails` list, so `mainlists.length < 2` is true and merge is skipped. RVTS works on all themes for image-only pages.

## Secondary Issues

### 1. RVTS_loaded handler accumulation (`gdthumb.js:31,45`)

```javascript
// setup() binds this ONCE:
$(window).bind("RVTS_loaded", function() { GDThumb.init(); });

// init() binds ANOTHER handler EACH TIME it's called:
jQuery(window).bind("RVTS_loaded", GDThumb.build);  // accumulates!
```

After N scroll loads, `GDThumb.build()` fires N+1 times per event. This causes progressive performance degradation.

### 2. Prefilter strips CSS classes (`main.php:220-223`)

```php
$pattern = '#\<div.*?id\="thumbnails".*?\>\{\$THUMBNAILS\}\</div\>#';
$replacement = '<ul id="thumbnails">{$THUMBNAILS}</ul>';
```

On bootstrap_darkroom, this converts `<div id="thumbnails" class="row">` to `<ul id="thumbnails">`, stripping `class="row"`. GDThumb's JS adds class `"thumbnails"` later, but there's a brief FOUC since all GDThumb CSS uses `ul.thumbnails` selectors.

### 3. Big thumb state persists across RVTS loads (`main.php:51`, `gdthumb.js:29`)

Server-side disables big_thumb for RVTS AJAX (`$conf->gdThumb['big_thumb'] = false`), but the client-side `GDThumb.big_thumb` variable retains its initial value and re-applies big-thumb sizing on every `GDThumb.build()` call after scroll.

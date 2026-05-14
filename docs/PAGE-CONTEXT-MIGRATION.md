# §1.1 Page Context Migration Design

Replace `$GLOBALS['page']` with proper typed, injectable value objects.

## Problem

`$GLOBALS['page']` is an untyped `array<string, mixed>` used as a
request-scoped data bus across 55 files and ~80 distinct keys. All reads
return `mixed`, callers narrow types inline, and the data is invisible to
the DI container and type checkers.

## Approach: Immutable Value Objects + DI Registry

Each group of keys becomes a **typed immutable-ish value object** built
once per request and distributed via the DI container through a thin static
registry (the pattern already used by `TemplateRegistry`, `CurrentUser`, etc.).
No key reads `mixed` at the call site.

---

## Group 1 — Per-request service caches → service instance state

These are memoization caches that belong to the service that owns the data.
Move them to `private` instance fields; no new class needed.

| Key | Owner | Migration |
|---|---|---|
| `is_ext_imagick`, `ext_imagick_command` | `PwgImage` | `private static ?string $cmd = null` + lazy init |
| `fs_quick_check_already_called` | `ImageAdminService` | `private bool $fsQuickCheckCalled = false` |
| `tag_id_from_tag_name_cache` | `TagAdminService` | `private array<string, int|string> $tagCache = []` |
| `user_use_cache` | `UserBootstrap` | local variable, never crosses method boundaries |
| `sizes_loaded_in_tpl` | `SizesProcessor` | `private bool $loadedInTpl = false` |

---

## Group 2 — Gallery section / navigation context → `SectionContext`

The largest group. Populated by `SectionInitializer::initialize()`, read by
gallery controllers, picture controllers, and all renderers.

### Value object

```php
// src/Piwigo/Section/SectionContext.php
final class SectionContext
{
    public function __construct(
        public readonly string   $section        = 'categories',
        public readonly string   $sectionUrl     = '',
        public readonly string   $rootPath       = '',
        /** @var list<int> */
        public readonly array    $items          = [],
        public readonly int      $start          = 0,
        public readonly int      $startcat       = 0,
        public readonly int      $nbImagePage    = 0,
        public readonly bool     $flat           = false,
        public readonly bool     $isHomepage     = false,
        public readonly bool     $superOrderBy   = false,
        public readonly ?string  $imageId        = null,
        public readonly string   $imageFile      = '',
        /** @var array<string,mixed>|null */
        public readonly ?array   $category       = null,
        /** @var list<array<string,mixed>>|null */
        public readonly ?array   $combinedCategories = null,
        /** @var list<array<string,mixed>> */
        public readonly array    $tags           = [],
        /** @var list<string|int> */
        public readonly array    $tagIds         = [],
        /** @var list<int> */
        public readonly array    $list           = [],
        public readonly ?string  $search         = null,
        public readonly ?string  $searchId       = null,
        /** @var array<string,mixed> */
        public readonly array    $searchDetails  = [],
        /** @var array<string,mixed> */
        public readonly array    $qsearchDetails = [],
        /** @var list<string> */
        public readonly array    $whereClauses   = [],
        public readonly bool     $useRegexpICU   = false,
        /** @var list<int|string> */
        public readonly array    $chronologyDate = [],
        public readonly string   $chronologyField = '',
        public readonly string   $chronologyView  = '',
        public readonly string   $chronologyStyle = '',
        public readonly string   $title          = '',
        public readonly string   $comment        = '',
        public readonly string   $sectionTitle   = '',
        public readonly string   $feed           = '',
    ) {}
}
```

### Registry

```php
// src/Piwigo/Section/SectionContextRegistry.php
final class SectionContextRegistry
{
    private static ?SectionContext $instance = null;

    public static function set(SectionContext $ctx): void
    {
        self::$instance = $ctx;
    }

    public static function current(): SectionContext
    {
        return self::$instance ?? new SectionContext();
    }

    public static function reset(): void   // tests only
    {
        self::$instance = null;
    }
}
```

### DI wiring

```php
// config/container.php
SectionContext::class => fn() => SectionContextRegistry::current(),
```

### How `SectionInitializer` builds it

`SectionInitializer::initialize()` accumulates values in local variables
(as it does today) and at the end calls:

```php
SectionContextRegistry::set(new SectionContext(
    section:    $section,
    items:      $items,
    category:   $category,
    // …
));
```

### Callers

All renderers/services that currently do `$page = &$GLOBALS['page']` get
`SectionContext` via constructor injection:

```php
final class CategoryDefaultRenderer {
    public function __construct(
        private readonly SectionContext $ctx,
        // …
    ) {}
}
```

DI auto-wires it via the factory above.

---

## Group 3 — Picture page navigation → `PictureContext`

Populated by `PictureController`, read by picture renderers.

```php
// src/Piwigo/Picture/PictureContext.php
final class PictureContext
{
    public function __construct(
        /** @var array<string,mixed> */
        public readonly array  $current      = [],
        /** @var array<string,mixed>|null */
        public readonly ?array $next         = null,
        /** @var array<string,mixed>|null */
        public readonly ?array $previous     = null,
        /** @var array<string,mixed>|null */
        public readonly ?array $first        = null,
        /** @var array<string,mixed>|null */
        public readonly ?array $last         = null,
        public readonly ?int   $currentItem  = null,
        public readonly ?int   $nextItem     = null,
        public readonly ?int   $previousItem = null,
        public readonly ?int   $firstItem    = null,
        public readonly ?int   $lastItem     = null,
        public readonly int    $currentRank  = 0,
        public readonly int    $firstRank    = 0,
        public readonly int    $lastRank     = 0,
        /** @var array<string,mixed> */
        public readonly array  $rankOf       = [],
        public readonly bool   $showComments = false,
        public readonly string $catSlideshowUrl = '',
        /** @var array<string,mixed>|null */
        public readonly ?array $navigationBar    = null,
        /** @var array<string,mixed>|null */
        public readonly ?array $catsNavigationBar = null,
        public readonly bool   $hitBy        = false,
        public readonly string $slideshow    = '',
    ) {}
}
```

Same registry + DI pattern as `SectionContext`.

---

## Group 4 — Display / template metadata → `PageState` typed properties

These are set once per request and read by `PageHeaderRenderer`. Add them
as typed properties to the existing `PageState` singleton.

| Key | New `PageState` property | Type |
|---|---|---|
| `body_id` | `$bodyId` | `string` |
| `page_banner` | `$pageBanner` | `string\|null` |
| `meta_robots` | `$metaRobots` | `array<string,int>` |
| `gallery_title` | `$galleryTitle` | `string\|null` |

---

## Group 5 — Admin page state → local variables

Admin controller methods (`tab`, `page`, `action`, `mode`, `prefilter`,
`sort_by`, `sort_order`, `display_mode`, `cat_elements_id`, `user_filter`,
`nb_lines`, `active_menu`, `nb_pending_comments`, etc.) already set and
read these keys entirely within a single method. They are already
effectively local variables — just make them so.

No new class needed. Each admin controller method reads from `$_GET`/
`$_POST`/`$_REQUEST` directly instead of going through `$page`.

---

## Migration sequence

1. **Group 1** — Move per-request caches to service instance state.
2. **Group 4** — Add typed properties to `PageState`; migrate `PageHeaderRenderer` and the few controllers that write `body_id`, `page_banner`, `meta_robots`.
3. **Group 5** — Inline admin page state as local variables per controller method.
4. **Group 2** — Introduce `SectionContext` + registry; migrate `SectionInitializer` to build it; migrate all gallery renderers/services to constructor-inject it.
5. **Group 3** — Introduce `PictureContext` + registry; migrate `PictureController` to build it; migrate picture renderers.
6. **Remove** — Delete `$GLOBALS['page'] = []` from `PageState::attachGlobals()`; delete `PageState::attachGlobals()` if nothing else calls it; update `KernelBootTest`.

At completion, `$GLOBALS['page']` is gone entirely and `§1.1` is closed.

---

## Files affected by group

### Group 2 — SectionContext (constructor injection required)

Controllers: `GalleryController`, `PictureController`, `TagsController`,
`CommentsController`, `NbmController`, `NotificationController`,
`FeedController`, `ActionController`, `AboutController`, `SearchController`,
`PasswordController`, `ProfileController`, `RegisterController`,
`IdentificationController`

Renderers/services: `CategoryDefaultRenderer`, `CategoryCatsRenderer`,
`CalendarBase`, `CalendarWeekly`, `CalendarMonthly`, `CalendarService`,
`MenubarRenderer`, `SearchService`, `SearchFilterRenderer`,
`SelectedTagsRenderer`, `UserService`, `HtmlService`, `UrlService`

Admin (reads only): `FilterResolver`, `BatchManagerController` (partial)

SectionInitializer builds it; `SectionContextRegistry` distributes it.

### Group 3 — PictureContext

`PictureController` builds it. `PictureCommentRenderer`,
`PictureRateRenderer`, `PictureMetadataRenderer` consume it.

### Group 4 — PageState additions

`PopuphelpController`, `MiscController` (popupHelp method),
`ProfileController`, `PageHeaderRenderer`.

### Group 5 — Admin locals

`AdminController`, `AlbumController`, `BatchManagerController`,
`ConfigurationController`, `ExtensionsController`, `GroupsController`,
`MaintenanceController`, `MiscController`, `PhotoController`,
`UsersController`, `AdminService`, `AlbumsTabRenderer`,
`UserTabRenderer`, `HistoryAdminService`, `TagAdminService`,
`UserAdminService`, `Updates`.

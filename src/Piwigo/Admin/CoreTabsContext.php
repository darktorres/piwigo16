<?php

declare(strict_types=1);

namespace Piwigo\Admin;

/**
 * Legacy Coupling Retirement Phase 8, 8g: the data `CoreTabs::addCoreTabs()`
 * used to read via 8 distinct `global $x;` statements (one per page family --
 * `$my_base_url`/`$admin_album_base_url`/`$manager_link`/`$link_start`/
 * `$conf_link`/`$help_link`/`$base_url`/`$admin_photo_base_url`), bundled
 * into one value object. `addCoreTabs()` is registered as the
 * `tabsheet_before_select` event handler with a fixed 2-argument signature
 * (`Tabsheet::select()` -> `EventDispatcher::triggerChange()`), so it cannot
 * take this as a real parameter -- see `CoreTabs`'s own docblock for why a
 * static setter/getter (matching its existing `UrlServiceInterface`
 * dependency) is the correct shape instead of a redesign.
 *
 * All 8 fields are nullable: any single request only ever sets the ONE
 * field its own page family needs, never all 8.
 */
final readonly class CoreTabsContext
{
    public function __construct(
        public ?string $myBaseUrl = null,
        public ?string $adminAlbumBaseUrl = null,
        public ?string $managerLink = null,
        public ?string $linkStart = null,
        public ?string $confLink = null,
        public ?string $helpLink = null,
        public ?string $baseUrl = null,
        public ?string $adminPhotoBaseUrl = null,
    ) {}
}

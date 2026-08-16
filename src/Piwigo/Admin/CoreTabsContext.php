<?php

declare(strict_types=1);

namespace Piwigo\Admin;

/**
 * Bundles the per-page-family context data `CoreTabs::addCoreTabs()` needs
 * (`myBaseUrl`/`adminAlbumBaseUrl`/`managerLink`/`linkStart`/`confLink`/
 * `helpLink`/`baseUrl`/`adminPhotoBaseUrl`) into one value object.
 * `addCoreTabs()` is registered as the `tabsheet_before_select` event
 * handler with a fixed signature (`Tabsheet::select()` ->
 * `EventDispatcher::dispatch(new TabsheetBeforeSelect(...))`), so it
 * cannot take this as a real parameter -- see `CoreTabs`'s own docblock for
 * why a static setter/getter (matching its existing `UrlServiceInterface`
 * dependency) is the correct shape here.
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

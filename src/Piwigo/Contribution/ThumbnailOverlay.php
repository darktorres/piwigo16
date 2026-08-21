<?php

declare(strict_types=1);

namespace Piwigo\Contribution;

/**
 * An icon overlay a plugin contributes to every thumbnail on the
 * gallery index -- the typed replacement for a hand-written
 * `set_prefilter('index_thumbnails', ...)` patch, which real plugins
 * (`quick_fav`, `quick_star`) hand-write against the raw HTML,
 * inserting right before each thumbnail's own `<img>`.
 *
 * One overlay marker per contribution, rendered identically for every
 * thumbnail on the page -- the per-photo state (is this one a
 * favorite, what's its rating) is not core's to know; a plugin's own
 * already-loaded JS asset reads the rendered `data-image-id` and does
 * its own state lookup/toggle client-side, the same mount-point
 * pattern `PictureInfoRow`'s own research already established for
 * JS-driven widgets.
 */
final readonly class ThumbnailOverlay
{
    public function __construct(
        public string $icon,
        public ?string $id = null,
        public int $order = 50,
    ) {}
}

<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

/**
 * A `DISPLAY`/`URL`/`SELECTED` option row, shared by
 * {@see \Piwigo\Controller\GalleryController::__invoke()}'s own
 * `$image_orders` (sort-order picker) and `$image_derivatives`
 * (thumbnail-size picker) lists -- both build the exact same 3-field
 * shape independently. Deliberately mutable (not `final readonly`, same
 * rationale as {@see \Piwigo\Category\Projection\ComputedCategoryRow}):
 * `$image_orders[0]`'s own `$selected` is overwritten once, after the
 * whole list is built, to force-unselect the "Default" entry when
 * another one was already selected.
 */
final class ImageOrderOption
{
    public function __construct(
        public readonly string $display,
        public readonly string $url,
        public bool $selected,
    ) {}
}

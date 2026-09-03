<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Latte\Runtime\Html;

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
 *
 * `$display` is Html, not string (P59 correction): `$image_orders`'
 * own labels are `Lang::t()` translations (some plugin-contributed via
 * `GetCategoryPreferredImageOrders`, the established plugin-authored
 * trust tier) that embed a literal `&rarr;` entity -- real markup, not
 * just escaped text. An earlier pass dropped this print's own
 * `|noescape` without also retyping the field, corrupting the arrow
 * into literal `&amp;rarr;` text (caught by `category-1.html`'s own
 * golden-HTML snapshot, among many others).
 */
final class ImageOrderOption
{
    public function __construct(
        public readonly Html $display,
        public readonly string $url,
        public bool $selected,
    ) {}
}

<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Piwigo\Image\DerivativeImage;

/**
 * Which derivative of a photo the picture page is showing, and which
 * others its "Photo sizes" switcher offers.
 *
 * Both `picture.latte` (the switcher itself) and `picture_content.latte`
 * (the `<img>` it swaps) need this pair. It used to reach them as one
 * shared ambient `current` template variable, appended to by
 * `PictureController::defaultPictureContent()`; when P40 split that
 * variable into `PictureView::$navCurrent` and
 * `PictureContentView::$current`, only the second half kept receiving it
 * and the switcher stopped rendering entirely.
 *
 * `$unique` is keyed by IMG_* type string and holds only the sizes worth
 * offering: square and thumbnail are never offered, nor is a type whose
 * URL duplicates one already listed, nor -- when the sizes icon is turned
 * off -- anything but the selected size itself.
 */
final readonly class DerivativeChoice
{
    /**
     * @param array<string, DerivativeImage> $unique
     */
    public function __construct(
        public DerivativeImage $selected,
        public array $unique,
    ) {}
}

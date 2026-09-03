<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Piwigo\Image\DerivativeImage;
use Piwigo\Image\Projection\Image;
use Piwigo\Image\SrcImage;

/**
 * One photo as the picture page needs it: the images-table row itself,
 * composed rather than flattened, plus the fields
 * {@see \Piwigo\Controller\PictureController::__invoke()} computes for it.
 *
 * Built once per navigation slot -- first/previous/current/next/last --
 * and carried across
 * {@see \Piwigo\Controller\Event\PicturePicturesData} and
 * {@see \Piwigo\Controller\Event\RenderElementContent}, which is why this
 * exists as a type rather than as a docblock: both events used to restate
 * the shape in prose, and one of those restatements was wrong in a way
 * that hid a live bug (it claimed `hit: string` where `Image::toArray()`
 * really returns int, so PHPStan trusted the docblock instead of flagging
 * the int flowing into a string property).
 *
 * The last two are current-only, and further depend on whether the
 * visitor may see the original -- both stay null for a photo the current
 * user cannot download.
 */
final readonly class PictureElement
{
    /**
     * @param array<string, DerivativeImage> $derivatives keyed by IMG_* type
     */
    public function __construct(
        public Image $image,
        public SrcImage $srcImage,
        public array $derivatives,
        public string $pathExt,
        public string $fileExt,
        public string $url,
        public string $title,
        public ?string $elementUrl,
        public ?string $downloadUrl,
    ) {}
}

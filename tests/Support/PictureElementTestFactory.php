<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Controller\Projection\PictureElement;
use Piwigo\Controller\Projection\PictureNavEntry;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\Projection\Image;
use Piwigo\Image\Projection\SrcImageInfo;
use Piwigo\Image\SrcImage;

/**
 * Builds the {@see PictureElement}/{@see PictureNavEntry} pair the picture
 * page's Views take, for tests that only need them to exist.
 *
 * `SrcImage`'s constructor resolves `CurrentConfig` out of the container,
 * so building one needs a booted Kernel -- hence {@see boot()}, which
 * every caller pairs with `Kernel::reset()`. One shared root across all
 * callers on purpose: `Kernel::boot()` is a no-op when already booted to
 * the same root and throws on a different one, so a stray un-reset boot
 * here cannot break a sibling test the way an ad-hoc per-file root would.
 */
final class PictureElementTestFactory
{
    public static function boot(): void
    {
        Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-picture-element-test'));
    }

    public static function image(int $id = 42, string $file = 'photo.jpg', ?float $ratingScore = null): Image
    {
        return new Image(
            id: ImageId::from($id),
            file: $file,
            dateAvailable: '2026-08-01 00:00:00',
            dateCreation: null,
            name: 'Photo ' . $id,
            comment: null,
            author: null,
            hit: 0,
            filesize: 120,
            width: 200,
            height: 150,
            coi: null,
            representativeExt: null,
            dateMetadataUpdate: null,
            ratingScore: $ratingScore,
            path: 'upload/2026/08/01/' . $file,
            storageCategoryId: null,
            level: 0,
            md5sum: null,
            addedBy: null,
            rotation: null,
            latitude: null,
            longitude: null,
            lastmodified: '2026-08-01 00:00:00',
        );
    }

    /**
     * @param ?SrcImage $srcImage for a caller that needs the element to
     *    point at a specific file on disk -- the metadata renderer reads
     *    `$element->srcImage->getPath()` and nothing else off it
     */
    public static function build(
        int $id = 42,
        string $file = 'photo.jpg',
        ?float $ratingScore = null,
        ?SrcImage $srcImage = null,
    ): PictureElement {
        $image = self::image($id, $file, $ratingScore);
        $srcImage ??= new SrcImage(SrcImageInfo::fromRow([
            'id' => $id,
            'path' => $image->path,
            'file' => $file,
        ]));

        return new PictureElement(
            image: $image,
            srcImage: $srcImage,
            derivatives: DerivativeImage::getAll($srcImage),
            pathExt: 'jpg',
            fileExt: 'jpg',
            url: 'picture.php?/' . $id,
            title: 'Photo ' . $id,
            titleEsc: 'Photo ' . $id,
            elementUrl: null,
            downloadUrl: null,
        );
    }

    public static function navEntry(int $id = 42, string $imgUrl = 'picture.php?/42'): PictureNavEntry
    {
        return new PictureNavEntry(element: self::build($id), imgUrl: $imgUrl);
    }
}

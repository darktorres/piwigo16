<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * The photo that `admin.php?page=photos_add&formats` associates
 * uploaded formats with, as `photos_add_direct.latte` renders it:
 * a thumbnail, a name, a file type and a link to the photo editor.
 *
 * Built by {@see \Piwigo\Admin\PhotosAddDirectPageRenderer::render()}
 * from an {@see \Piwigo\Image\ImageService::getImageInfos()} row plus
 * the formats already attached to it. Null on the view when there is
 * no such photo. The template tests that directly, and
 * `exposedPageData()`'s own `have_formats_original` flag is
 * derived from it rather than carried beside it.
 */
final readonly class FormatsOriginalInfo
{
    /**
     * @param ?string $formats a rendered summary of the formats
     *   already attached ("Formats: webp (1.20MB), ..."), null when
     *   the photo has none -- the template omits the line entirely
     *   in that case rather than rendering an empty one
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $src,
        public ?string $formats,
        public string $ext,
        public string $editUrl,
    ) {}
}

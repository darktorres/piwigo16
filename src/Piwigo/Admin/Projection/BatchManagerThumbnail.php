<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Image\DerivativeImage;

/**
 * One photo tile in the batch manager's global mode, built by
 * {@see \Piwigo\Admin\BatchManagerGlobalPageRenderer::render()}.
 *
 * The producer used to `array_merge()` the whole `images` row into the
 * tile, but `batch_manager_global.latte` only ever read three of its
 * ten columns -- the rest came along because merging is easier than
 * choosing. The raw row is still what
 * {@see \Piwigo\Image\SrcImageInfo::fromRow()} and the element-name
 * renderer consume; this is only what the template renders.
 *
 * `$title` is pre-rendered HTML (a name, optionally a filename, then a
 * `<br>` and the dimensions), which is why it reaches the `title`
 * attribute already escaped by its own producer.
 */
final readonly class BatchManagerThumbnail
{
    public function __construct(
        public int $id,
        public string $file,
        public int $level,
        public DerivativeImage $thumb,
        public string $title,
        public string $fileSrc,
        public string $editUrl,
    ) {}
}

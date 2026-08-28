<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * {@see \Piwigo\Admin\PictureModifyPageRenderer::render()}'s own
 * `$introVars` shape for `picture_modify.latte` -- a fixed 9-key row
 * (`formats` is the one genuinely optional field, only present when the
 * image has real derivative formats; the template itself guards it with
 * `isset()`).
 */
final readonly class PictureIntroVars
{
    public function __construct(
        public string $file,
        public string $date,
        public string $age,
        public string $addedBy,
        public string $size,
        public string $stats,
        public string $id,
        public string $ext,
        public bool $isSvg,
        public ?string $formats,
    ) {}
}

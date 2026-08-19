<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `picture_formats.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\PictureFormatsPageRenderer::render()} -- shared by two
 * real callers, {@see \Piwigo\Controller\Admin\PictureFormatsSubController}
 * and {@see \Piwigo\Controller\Admin\PhotoSubController}'s own "formats"
 * tab. `$formats` stays a loose row shape: each entry is a real format
 * DB row plus 3 view-only keys spliced on per row (`download_url`/
 * `label`/`filesize`), not a fixed structural shape worth minting its
 * own DTO for here.
 */
#[Template('picture_formats.latte')]
final readonly class PictureFormatsView implements View
{
    /**
     * @param list<array<string, mixed>> $formats
     */
    public function __construct(
        public string $addFormatsUrl,
        public string $imgSquareSrc,
        public array $formats,
        public string $pwgToken,
    ) {}
}

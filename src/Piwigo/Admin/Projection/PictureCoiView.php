<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `picture_coi.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\PictureCoiPageRenderer::render()}. No `$title` field --
 * `TITLE` has zero real references in `picture_coi.latte`'s own body.
 * `$coi` is genuinely optional: only a real, non-empty crop-of-interest
 * string on the image row produces one. `$croppedDerivatives` is
 * always included -- `picture_coi.latte`'s own `{foreach}` has no
 * guard around it.
 */
#[Template('picture_coi.latte')]
final readonly class PictureCoiView implements View
{
    /**
     * @param array{l: float, t: float, r: float, b: float}|null $coi
     * @param list<array{U_IMG: string, HTM_SIZE: string}> $croppedDerivatives
     */
    public function __construct(
        public string $alt,
        public string $imgUrl,
        public ?array $coi,
        public array $croppedDerivatives,
    ) {}
}

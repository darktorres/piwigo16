<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Template;

use Piwigo\Config\CurrentConfig;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\Projection\SrcImageInfo;
use Piwigo\Image\SrcImage;

final readonly class TemplateAdapter
{
    public function __construct(
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * $img stays array|SrcImage, not SrcImageInfo -- this is a real Latte
     * template-boundary method (assigned to templates as `pwg`, see
     * Template::render()), so a raw array (whatever the template's own
     * already-toArray()'d data holds) is a genuine caller shape here, not
     * a leftover to eliminate.
     *
     * @param array<string, mixed>|SrcImage $img
     */
    public function derivative(string|DerivativeParams $type, array|SrcImage $img): DerivativeImage
    {
        // Mirrors derivativeUrl()/DerivativeImage::url()'s own
        // is_object($infos) ? $infos : new SrcImage($infos) handling — the
        // constructor itself only accepts a real SrcImageInfo/SrcImage.
        return new DerivativeImage($type, $img instanceof SrcImage ? $img : new SrcImage(SrcImageInfo::fromRow($img)), $this->currentConfig);
    }

    /**
     * @param array<string, mixed> $img
     */
    public function derivativeUrl(string|DerivativeParams $type, array $img): string
    {
        return DerivativeImage::url($type, SrcImageInfo::fromRow($img));
    }
}

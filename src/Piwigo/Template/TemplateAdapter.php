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
use Piwigo\Image\SrcImage;

final class TemplateAdapter
{
    public function __construct(
        private readonly CurrentConfig $currentConfig,
    ) {}

    /**
     * @param string $type
     * @param array<string, mixed>|SrcImage $img
     */
    public function derivative($type, $img): DerivativeImage
    {
        // Mirrors derivativeUrl()/DerivativeImage::url()'s own
        // is_object($infos) ? $infos : new SrcImage($infos) handling — the
        // constructor itself only accepts a real SrcImage.
        return new DerivativeImage($type, is_object($img) ? $img : new SrcImage($img), $this->currentConfig);
    }

    /**
     * @param string $type
     * @param array<string, mixed> $img
     */
    public function derivativeUrl($type, $img): string
    {
        return DerivativeImage::url($type, $img);
    }
}

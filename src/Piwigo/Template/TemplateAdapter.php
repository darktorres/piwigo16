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
use Piwigo\Image\SrcImage;

final readonly class TemplateAdapter
{
    public function __construct(
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * @param array<string, mixed>|SrcImage $img
     */
    public function derivative(string|DerivativeParams $type, array|SrcImage $img): DerivativeImage
    {
        // Mirrors derivativeUrl()/DerivativeImage::url()'s own
        // is_object($infos) ? $infos : new SrcImage($infos) handling — the
        // constructor itself only accepts a real SrcImage.
        return new DerivativeImage($type, is_object($img) ? $img : new SrcImage($img), $this->currentConfig);
    }

    /**
     * @param array<string, mixed> $img
     */
    public function derivativeUrl(string|DerivativeParams $type, array $img): string
    {
        return DerivativeImage::url($type, $img);
    }
}

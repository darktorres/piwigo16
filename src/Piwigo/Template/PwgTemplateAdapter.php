<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Template;

use Piwigo\Image\DerivativeImage;
use Piwigo\Image\SrcImage;

class PwgTemplateAdapter
{
    #[\Deprecated(message: 'use "translate" modifier')]
    public function l10n(string $text): string
    {
        return l10n($text);
    }

    #[\Deprecated(message: 'use "translate_dec" modifier')]
    public function l10n_dec(string $s, string $p, int $v): string
    {
        return l10n_dec($s, $p, $v);
    }

    #[\Deprecated(message: 'use "translate" or "sprintf" modifier')]
    public function sprintf(): mixed
    {
        $args = func_get_args();
        return call_user_func_array(sprintf(...), $args);
    }

    /**
     * @param string $type
     * @param array<string, mixed>|SrcImage $img
     */
    public function derivative($type, $img): DerivativeImage
    {
        // Mirrors derivative_url()/DerivativeImage::url()'s own
        // is_object($infos) ? $infos : new SrcImage($infos) handling — the
        // constructor itself only accepts a real SrcImage.
        return new DerivativeImage($type, is_object($img) ? $img : new SrcImage($img));
    }

    /**
     * @param string $type
     * @param array<string, mixed> $img
     * @return string|array<int|string, mixed>
     */
    public function derivative_url($type, $img): string|array
    {
        return DerivativeImage::url($type, $img);
    }
}

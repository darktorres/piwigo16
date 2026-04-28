<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Piwigo\Image\DerivativeImage;
use Piwigo\Image\SrcImage;

/**
 * This class contains basic functions that can be called directly from the
 * templates in the form $pwg->l10n('edit')
 */
class PwgTemplateAdapter
{
    #[\Deprecated(message: 'use "translate" modifier')]
    public function l10n(?string $text)
    {
        return l10n($text);
    }

    #[\Deprecated(message: 'use "translate_dec" modifier')]
    public function l10n_dec($s, $p, $v): string
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
     * @param array|\Piwigo\Image\SrcImage $img
     */
    public function derivative($type, $img): DerivativeImage
    {
        $src_image = ($img instanceof SrcImage) ? $img : new SrcImage(is_array($img) ? $img : []);
        return new DerivativeImage($type, $src_image);
    }

    /**
     * @param string $type
     * @param array $img
     * @return string
     */
    public function derivative_url($type, $img): string|array
    {
        return DerivativeImage::url($type, $img);
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\SrcImage;

/**
 * This class contains basic functions that can be called directly from the
 * templates in the form $pwg->l10n('edit')
 */
class PwgTemplateAdapter
{
    #[\Deprecated(message: 'use "translate" modifier')]
    public function l10n(?string $text): string
    {
        return l10n($text);
    }

    #[\Deprecated(message: 'use "translate_dec" modifier')]
    public function l10nDec(string $s, string $p, int|float|null $v): string
    {
        return l10n_dec($s, $p, $v);
    }

    #[\Deprecated(message: 'use "translate" or "sprintf" modifier')]
    public function sprintf(): mixed
    {
        $args = func_get_args();
        return call_user_func_array(sprintf(...), $args);
    }

    /** @param array<mixed>|SrcImage $img */
    public function derivative(string|DerivativeParams $type, array|SrcImage $img): DerivativeImage
    {
        $src_image = ($img instanceof SrcImage) ? $img : new SrcImage($img);
        return new DerivativeImage($type, $src_image);
    }

    /**
     * @param string $type
     * @param array $img
     * @return string
     */
    /**
 * @param array<mixed> $img
 * @return string|array<mixed>
 */
    public function derivativeUrl(string $type, array $img): string|array
    {
        return DerivativeImage::url($type, $img);
    }
}

<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * This class contains basic functions that can be called directly from the
 * templates in the form $pwg->l10n('edit')
 */
class PwgTemplateAdapter
{
    /**
     * @deprecated use "translate" modifier
     */
    public function l10n(
        string $text
    ): string {
        return functions::l10n($text);
    }

    /**
     * @deprecated use "translate_dec" modifier
     */
    public function l10n_dec(
        string $s,
        string $p,
        int|string $v
    ): string {
        return functions::l10n_dec($s, $p, $v);
    }

    /**
     * @deprecated use "translate" or "sprintf" modifier
     */
    public function sprintf(): string
    {
        $args = func_get_args();
        return call_user_func_array(sprintf(...), $args);
    }

    public function derivative(
        DerivativeParams|string $type,
        SrcImage|array $img
    ): DerivativeImage {
        return new DerivativeImage($type, $img);
    }

    public function derivative_url(
        DerivativeParams|string $type,
        SrcImage|array $img
    ): string {
        return DerivativeImage::url($type, $img);
    }
}

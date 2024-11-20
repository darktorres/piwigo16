<?php

/*
Theme Name: Smart Pocket
Version: 14.5.0
Description: Mobile theme.
Theme URI: https://piwigo.org/ext/extension_view.php?eid=599
Author: P@t
Author URI: http://piwigo.org
*/

namespace Piwigo\themes\smartpocket;

use Piwigo\inc\DerivativeImage;
use Piwigo\inc\DerivativeParams;
use Piwigo\inc\ImageStdParams;
use Piwigo\inc\SrcImage;

class SPThumbPicker
{
    public array $candidates;

    public DerivativeParams $default;

    public int $height;

    public function init(
        int $height
    ): void {
        $this->candidates = [];

        foreach (ImageStdParams::get_defined_type_map() as $params) {
            if ($params->max_height() < $height ||
                $params->sizing->max_crop
            ) {
                continue;
            }

            if ($params->max_height() > 3 * $height) {
                break;
            }

            $this->candidates[] = $params;
        }

        $this->default = ImageStdParams::get_custom($height * 3, $height, 1, 0, $height);
        $this->height = $height;
    }

    public function pick(
        SrcImage $src_image,
        int $height
    ): DerivativeImage {
        $ok = false;

        foreach ($this->candidates as $candidate) {
            $deriv = new DerivativeImage($candidate, $src_image);
            $size = $deriv->get_size();

            if ($size[1] >= $height - 2) {
                $ok = true;
                break;
            }
        }

        if (! $ok) {
            $deriv = new DerivativeImage($this->default, $src_image);
        }

        return $deriv;
    }
}

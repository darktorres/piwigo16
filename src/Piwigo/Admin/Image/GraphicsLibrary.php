<?php

declare(strict_types=1);

namespace Piwigo\Admin\Image;

/**
 * Graphics processing library selection.
 *
 * Corresponds to the `graphics_library` config key values.
 */
enum GraphicsLibrary: string
{
    case Auto       = 'auto';
    case ExtImagick = 'ext_imagick';
    case Imagick    = 'imagick';
    case Gd         = 'gd';
}

<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * Standard derivative image sizes.
 *
 * Values match the IMG_* defines in include/derivative_std_params.inc.php.
 */
enum DerivativeSize: string
{
    case Square      = 'square';
    case Thumb       = 'thumb';
    case TwoSmall    = '2small';
    case XSmall      = 'xsmall';
    case Small       = 'small';
    case Medium      = 'medium';
    case Large       = 'large';
    case XLarge      = 'xlarge';
    case TwoXLarge   = 'xxlarge';
    case ThreeXLarge = '3xlarge';
    case FourXLarge  = '4xlarge';
    case Custom      = 'custom';
}

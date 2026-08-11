<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Image;

/**
 * Container for watermark configuration.
 */
final class WatermarkParams
{
    public string $file = '';

    /**
     * @var int[]
     */
    public array $min_size = [500, 500];

    public int $xpos = 50;

    public int $ypos = 50;

    public int $xrepeat = 0;

    public int $yrepeat = 0;

    public int $opacity = 100;
}

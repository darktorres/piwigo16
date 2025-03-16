<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * Container for watermark configuration.
 */
final class WatermarkParams
{
    /**
     * @var string
     */
    public $file = '';

    /**
     * @var int[]
     */
    public $min_size = [500, 500];

    /**
     * @var int
     */
    public $xpos = 50;

    /**
     * @var int
     */
    public $ypos = 50;

    /**
     * @var int
     */
    public $xrepeat = 0;

    /**
     * @var int
     */
    public $yrepeat = 0;

    /**
     * @var int
     */
    public $opacity = 100;
}

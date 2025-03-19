<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin\inc;

// +-----------------------------------------------------------------------+
// |                           Image Interface                             |
// +-----------------------------------------------------------------------+

// Define all needed methods for image class
interface imageInterface
{
    public function get_width(): int;

    public function get_height(): int;

    public function set_compression_quality(
        int $quality
    ): bool;

    public function crop(
        int $width,
        int $height,
        int $x,
        int $y
    );

    public function strip(): bool;

    public function rotate(
        int $rotation
    ): bool;

    public function resize(
        int $width,
        int $height
    ): bool;

    public function sharpen(
        int $amount
    ): bool;

    public function compose(
        pwg_image $overlay,
        int $x,
        int $y,
        int $opacity
    ): bool;

    public function write(
        string $destination_filepath
    ): bool;
}

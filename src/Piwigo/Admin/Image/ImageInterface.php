<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Image;

interface ImageInterface
{
    public function get_width(): int|float;

    public function get_height(): int|float;

    public function set_compression_quality(int $quality): bool;

    public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool;

    public function strip(): bool;

    public function rotate(int|float $rotation): bool;

    public function resize(int|float $width, int|float $height): bool;

    public function sharpen(int|float $amount): bool;

    public function compose(PwgImage $overlay, int|float $x, int|float $y, int|float $opacity): bool;

    public function write(string $destination_filepath): bool;
}

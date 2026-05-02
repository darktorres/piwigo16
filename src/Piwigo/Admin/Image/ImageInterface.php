<?php

declare(strict_types=1);

namespace Piwigo\Admin\Image;

// Define all needed methods for image class
interface ImageInterface
{
    public function get_width(): int;

    public function get_height(): int;

    public function set_compression_quality(int $quality): bool;

    public function crop(int $width, int $height, int $x, int $y): bool;

    public function strip(): bool;

    public function rotate(int $rotation): bool;

    public function resize(int $width, int $height): bool;

    public function sharpen(int $amount): bool;

    public function compose(mixed $overlay, int $x, int $y, int $opacity): bool;

    public function write(string $destination_filepath): bool;
}

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
// |                       Class for libvips library                       |
// +-----------------------------------------------------------------------+

use Jcupitt\Vips\Image;
use Override;

final class image_vips implements imageInterface
{
    public Image $image;

    public int $quality = 75;

    public string|false $source_filepath;

    public function __construct(
        string $source_filepath
    ) {
        $this->source_filepath = realpath($source_filepath);
        $this->image = Image::newFromFile($this->source_filepath);
    }

    public function add_command(
        string $command,
        ?array $params = null
    ): void {}

    #[Override]
    public function get_width(): int
    {
        return $this->image->width;
    }

    #[Override]
    public function get_height(): int
    {
        return $this->image->height;
    }

    #[Override]
    public function crop(
        int $width,
        int $height,
        int $x,
        int $y
    ): true {
        $this->image = $this->image->crop($x, $y, $width, $height);
        return true;
    }

    #[Override]
    public function strip(): true
    {
        return true;
    }

    #[Override]
    public function rotate(
        int $rotation
    ): true {
        $this->image = $this->image->rotate($rotation);
        return true;
    }

    #[Override]
    public function set_compression_quality(
        int $quality
    ): true {
        $this->quality = $quality;
        return true;
    }

    #[Override]
    public function resize(
        int $width,
        int $height
    ): true {
        $this->image = $this->image->thumbnailImage($width, [
            'height' => $height,
            'size' => 'down',
        ]);
        return true;
    }

    #[Override]
    public function sharpen(
        int $amount
    ): true {
        return true;
    }

    #[Override]
    public function compose(
        pwg_image $overlay,
        int $x,
        int $y,
        int $opacity
    ): true {
        return true;
    }

    #[Override]
    public function write(
        string $destination_filepath
    ): true {
        $ext = strtolower(pathinfo($destination_filepath, PATHINFO_EXTENSION));
        $options = match ($ext) {
            'jpg', 'jpeg' => ['Q' => $this->quality],
            'webp'        => ['Q' => $this->quality],
            'png'         => ['compression' => (int) round((100 - $this->quality) / 10)],
            default       => [],
        };
        $this->image->writeToFile($destination_filepath, $options);
        return true;
    }

    public function destroy(): void
    {
        unset($this->image);
    }
}

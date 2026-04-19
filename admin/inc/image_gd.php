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
// |                       Class for GD library                            |
// +-----------------------------------------------------------------------+

use Override;
use Piwigo\inc\functions;

final class image_gd implements imageInterface
{
    public bool|\GdImage $image;

    public int $quality = 95;

    public function __construct(
        string $source_filepath
    ) {
        $gd_info = gd_info();
        $extension = strtolower(functions::get_extension($source_filepath));

        if (in_array($extension, ['jpg', 'jpeg'], true)) {
            $this->image = imagecreatefromjpeg($source_filepath);
        } elseif ($extension === 'png') {
            $this->image = imagecreatefrompng($source_filepath);
        } elseif ($extension === 'gif' &&
                  $gd_info['GIF Read Support'] &&
                  $gd_info['GIF Create Support']
        ) {
            $this->image = imagecreatefromgif($source_filepath);
        } else {
            exit('[Image GD] unsupported file extension');
        }
    }

    #[Override]
    public function get_width(): int
    {
        return imagesx($this->image);
    }

    #[Override]
    public function get_height(): int
    {
        return imagesy($this->image);
    }

    #[Override]
    public function crop(
        int $width,
        int $height,
        int $x,
        int $y
    ): bool {
        $dest = imagecreatetruecolor($width, $height);

        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        imageantialias($dest, true);

        $result = imagecopymerge($dest, $this->image, 0, 0, $x, $y, $width, $height, 100);

        if ($result) {
            imagedestroy($this->image);
            $this->image = $dest;
        } else {
            imagedestroy($dest);
        }

        return $result;
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
        $dest = imagerotate($this->image, $rotation, 0);
        imagedestroy($this->image);
        $this->image = $dest;
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
    ): bool {
        $dest = imagecreatetruecolor($width, $height);

        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        imageantialias($dest, true);

        $result = imagecopyresampled($dest, $this->image, 0, 0, 0, 0, $width, $height, $this->get_width(), $this->get_height());

        if ($result) {
            imagedestroy($this->image);
            $this->image = $dest;
        } else {
            imagedestroy($dest);
        }

        return $result;
    }

    #[Override]
    public function sharpen(
        int $amount
    ): bool {
        $m = pwg_image::get_sharpen_matrix($amount);
        return imageconvolution($this->image, $m, 1, 0);
    }

    #[Override]
    public function compose(
        pwg_image $overlay,
        int $x,
        int $y,
        int $opacity
    ): true {
        $ioverlay = $overlay->image->image;
        /* A replacement for php's imagecopymerge() function that supports the alpha channel
        See php bug #23815:  http://bugs.php.net/bug.php?id=23815 */

        $ow = imagesx($ioverlay);
        $oh = imagesy($ioverlay);

        // Create a new blank image the site of our source image
        $cut = imagecreatetruecolor($ow, $oh);

        // Copy the blank image into the destination image where the source goes
        imagecopy($cut, $this->image, 0, 0, $x, $y, $ow, $oh);

        // Place the source image in the destination image
        imagecopy($cut, $ioverlay, 0, 0, 0, 0, $ow, $oh);
        imagecopymerge($this->image, $cut, $x, $y, 0, 0, $ow, $oh, $opacity);
        imagedestroy($cut);
        return true;
    }

    #[Override]
    public function write(
        string $destination_filepath
    ): bool {
        $extension = strtolower(functions::get_extension($destination_filepath));

        if ($extension === 'png') {
            return imagepng($this->image, $destination_filepath);
        } elseif ($extension === 'gif') {
            return imagegif($this->image, $destination_filepath);
        }

        return imagejpeg($this->image, $destination_filepath, $this->quality);
    }

    public function destroy(): void
    {
        imagedestroy($this->image);
    }
}

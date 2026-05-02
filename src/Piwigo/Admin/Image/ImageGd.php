<?php

declare(strict_types=1);

namespace Piwigo\Admin\Image;

class ImageGd implements ImageInterface
{
    public \GdImage $image;
    public int $quality = 95;

    public function __construct(string $source_filepath)
    {
        $gd_info = gd_info();
        $extension = strtolower(get_extension($source_filepath));

        if (in_array($extension, ['jpg', 'jpeg'])) {
            $img = imagecreatefromjpeg($source_filepath);
        } elseif ($extension == 'png') {
            $img = imagecreatefrompng($source_filepath);
        } elseif ($extension == 'gif' and $gd_info['GIF Read Support'] and $gd_info['GIF Create Support']) {
            $img = imagecreatefromgif($source_filepath);
        } else {
            die('[Image GD] unsupported file extension');
        }

        if ($img === false) {
            die('[Image GD] failed to load image: '.$source_filepath);
        }
        $this->image = $img;
    }

    public function get_width(): int
    {
        return imagesx($this->image);
    }

    public function get_height(): int
    {
        return imagesy($this->image);
    }

    public function crop(int $width, int $height, int $x, int $y): bool
    {
        $dest = imagecreatetruecolor(max(1, $width), max(1, $height));

        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        if (function_exists('imageantialias')) {
            imageantialias($dest, true);
        }

        $result = imagecopymerge($dest, $this->image, 0, 0, $x, $y, $width, $height, 100);
        $this->image = $dest;
        return $result;
    }

    public function strip(): bool
    {
        return true;
    }

    public function rotate(int $rotation): bool
    {
        $dest = imagerotate($this->image, $rotation, 0);
        if ($dest === false) {
            return false;
        }
        $this->image = $dest;
        return true;
    }

    public function set_compression_quality(int $quality): bool
    {
        $this->quality = $quality;
        return true;
    }

    public function resize(int $width, int $height): bool
    {
        $dest = imagecreatetruecolor(max(1, $width), max(1, $height));

        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        if (function_exists('imageantialias')) {
            imageantialias($dest, true);
        }

        $result = imagecopyresampled($dest, $this->image, 0, 0, 0, 0, $width, $height, $this->get_width(), $this->get_height());
        $this->image = $dest;
        return $result;
    }

    public function sharpen(int $amount): bool
    {
        $m = PwgImage::get_sharpen_matrix($amount);
        return imageconvolution($this->image, $m, 1, 0);
    }

    public function compose(mixed $overlay, int $x, int $y, int $opacity): bool
    {
        if (!($overlay instanceof ImageGd)) {
            return false;
        }
        $ioverlay = $overlay->image;
        /* A replacement for php's imagecopymerge() function that supports the alpha channel
        See php bug #23815:  http://bugs.php.net/bug.php?id=23815 */

        $ow = imagesx($ioverlay);
        $oh = imagesy($ioverlay);

        // Create a new blank image the site of our source image
        $cut = imagecreatetruecolor(max(1, $ow), max(1, $oh));

        // Copy the blank image into the destination image where the source goes
        imagecopy($cut, $this->image, 0, 0, $x, $y, $ow, $oh);

        // Place the source image in the destination image
        imagecopy($cut, $ioverlay, 0, 0, 0, 0, $ow, $oh);
        imagecopymerge($this->image, $cut, $x, $y, 0, 0, $ow, $oh, $opacity);
        return true;
    }

    public function write(string $destination_filepath): bool
    {
        $extension = strtolower(get_extension($destination_filepath));

        if ($extension == 'png') {
            return imagepng($this->image, $destination_filepath);
        } elseif ($extension == 'gif') {
            return imagegif($this->image, $destination_filepath);
        } else {
            return imagejpeg($this->image, $destination_filepath, $this->quality);
        }
    }

    public function destroy(): void
    {
    }
}

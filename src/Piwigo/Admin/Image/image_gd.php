<?php

declare(strict_types=1);

namespace Piwigo\Admin\Image;

class image_gd implements imageInterface
{
    public $image;
    public $quality = 95;

    public function __construct($source_filepath)
    {
        $gd_info = gd_info();
        $extension = strtolower(get_extension($source_filepath));

        if (in_array($extension, ['jpg', 'jpeg'])) {
            $this->image = imagecreatefromjpeg($source_filepath);
        } elseif ($extension == 'png') {
            $this->image = imagecreatefrompng($source_filepath);
        } elseif ($extension == 'gif' and $gd_info['GIF Read Support'] and $gd_info['GIF Create Support']) {
            $this->image = imagecreatefromgif($source_filepath);
        } else {
            die('[Image GD] unsupported file extension');
        }
    }

    public function get_width(): int
    {
        return imagesx($this->image);
    }

    public function get_height(): int
    {
        return imagesy($this->image);
    }

    public function crop($width, $height, $x, $y)
    {
        $dest = imagecreatetruecolor($width, $height);

        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        if (function_exists('imageantialias')) {
            imageantialias($dest, true);
        }

        $result = imagecopymerge($dest, $this->image, 0, 0, $x, $y, $width, $height, 100);

        if ($result !== false) {
            $this->image = $dest;
        } else {
        }
        return $result;
    }

    public function strip(): bool
    {
        return true;
    }

    public function rotate($rotation): bool
    {
        $dest = imagerotate($this->image, $rotation, 0);
        $this->image = $dest;
        return true;
    }

    public function set_compression_quality($quality): bool
    {
        $this->quality = $quality;
        return true;
    }

    public function resize($width, $height)
    {
        $dest = imagecreatetruecolor($width, $height);

        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        if (function_exists('imageantialias')) {
            imageantialias($dest, true);
        }

        $result = imagecopyresampled($dest, $this->image, 0, 0, 0, 0, $width, $height, $this->get_width(), $this->get_height());

        if ($result !== false) {
            $this->image = $dest;
        } else {
        }
        return $result;
    }

    public function sharpen($amount): bool
    {
        $m = pwg_image::get_sharpen_matrix($amount);
        return imageconvolution($this->image, $m, 1, 0);
    }

    public function compose($overlay, $x, $y, $opacity): bool
    {
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
        return true;
    }

    public function write($destination_filepath): void
    {
        $extension = strtolower(get_extension($destination_filepath));

        if ($extension == 'png') {
            imagepng($this->image, $destination_filepath);
        } elseif ($extension == 'gif') {
            imagegif($this->image, $destination_filepath);
        } else {
            imagejpeg($this->image, $destination_filepath, $this->quality);
        }
    }

    public function destroy()
    {
    }
}

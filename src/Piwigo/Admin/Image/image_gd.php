<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Image;

class image_gd implements imageInterface
{
    public \GdImage $image;

    public int $quality = 95;

    public function __construct(
        string $source_filepath
    ) {
        $gd_info = gd_info();
        $extension = strtolower(get_extension($source_filepath));

        if (in_array($extension, ['jpg', 'jpeg'])) {
            $image = imagecreatefromjpeg($source_filepath);
        } elseif ($extension == 'png') {
            $image = imagecreatefrompng($source_filepath);
        } elseif ($extension == 'gif' and $gd_info['GIF Read Support'] and $gd_info['GIF Create Support']) {
            $image = imagecreatefromgif($source_filepath);
        } else {
            die('[Image GD] unsupported file extension');
        }

        if ($image === false) {
            die('[Image GD] unable to decode image');
        }

        $this->image = $image;
    }

    #[\Override]
    public function get_width(): int
    {
        return imagesx($this->image);
    }

    #[\Override]
    public function get_height(): int
    {
        return imagesy($this->image);
    }

    #[\Override]
    public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool
    {
        // GD's native imagecreatetruecolor()/imagecopymerge() require int
        // arguments (unlike Imagick/ext_imagick, which tolerate float pixel
        // coordinates) — real callers do pass floats here (e.g. i.php's
        // $crop_rect->width()/->l are int|float, since ImageRect::crop_h()/
        // crop_v() accumulate floor()'s float return type), which threw a
        // TypeError under this backend before this cast was added (verified
        // directly: imagecreatetruecolor(900.0, ...) throws "must be of type
        // int, float given" under strict_types).
        $width = (int) $width;
        $height = (int) $height;
        $x = (int) $x;
        $y = (int) $y;

        // Image dimensions are always >= 1px for any real, successfully
        // decoded image (see the domain this class operates on — a 0 or
        // negative crop size is not a real scenario, not something to
        // silently clamp).
        assert($width > 0 && $height > 0);

        $dest = imagecreatetruecolor($width, $height);

        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        if (function_exists('imageantialias')) {
            imageantialias($dest, true);
        }

        $result = imagecopymerge($dest, $this->image, 0, 0, $x, $y, $width, $height, 100);

        $this->image = $dest;
        return $result;
    }

    #[\Override]
    public function strip(): bool
    {
        return true;
    }

    #[\Override]
    public function rotate(int|float $rotation): bool
    {
        $dest = imagerotate($this->image, $rotation, 0);
        if ($dest === false) {
            return false;
        }
        $this->image = $dest;
        return true;
    }

    #[\Override]
    public function set_compression_quality(int $quality): bool
    {
        $this->quality = $quality;
        return true;
    }

    #[\Override]
    public function resize(int|float $width, int|float $height): bool
    {
        // see crop()'s comment: GD's native functions require int arguments
        $width = (int) $width;
        $height = (int) $height;

        // see crop()'s comment: dimensions are always >= 1px in this domain
        assert($width > 0 && $height > 0);

        $dest = imagecreatetruecolor($width, $height);

        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        if (function_exists('imageantialias')) {
            imageantialias($dest, true);
        }

        $result = imagecopyresampled($dest, $this->image, 0, 0, 0, 0, $width, $height, $this->get_width(), $this->get_height());

        $this->image = $dest;
        return $result;
    }

    #[\Override]
    public function sharpen(int|float $amount): bool
    {
        $m = pwg_image::get_sharpen_matrix($amount);
        return imageconvolution($this->image, $m, 1, 0);
    }

    #[\Override]
    public function compose(pwg_image $overlay, int|float $x, int|float $y, int|float $opacity): bool
    {
        // see crop()'s comment: GD's native imagecopy()/imagecopymerge()
        // require int arguments — real callers pass floats here too (i.php's
        // watermark positioning uses round(), which always returns float),
        // which threw a TypeError under this backend before this cast was
        // added.
        $x = (int) $x;
        $y = (int) $y;

        // See image_imagick::compose()'s comment: only valid when both
        // images use the same backend, always true in practice.
        $overlay_backend = $overlay->image;
        if (! $overlay_backend instanceof self) {
            throw new \LogicException('pwg_image::compose(): overlay must use the same image backend');
        }
        $ioverlay = $overlay_backend->image;
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
        // imagecopymerge()'s $pct is int; see crop()'s comment on float callers.
        imagecopymerge($this->image, $cut, $x, $y, 0, 0, $ow, $oh, (int) $opacity);
        return true;
    }

    #[\Override]
    public function write(string $destination_filepath): bool
    {
        $extension = strtolower(get_extension($destination_filepath));

        if ($extension == 'png') {
            return imagepng($this->image, $destination_filepath);
        }
        if ($extension == 'gif') {
            return imagegif($this->image, $destination_filepath);
        }
        return imagejpeg($this->image, $destination_filepath, $this->quality);
    }

    public function destroy(): bool
    {
        return true;
    }
}

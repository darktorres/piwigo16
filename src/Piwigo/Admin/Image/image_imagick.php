<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Image;

class image_imagick implements imageInterface
{
    /**
     * @var \Imagick
     */
    public $image;

    public function __construct(
        string $source_filepath
    ) {
        // A bug cause that Imagick class can not be extended
        $this->image = new \Imagick($source_filepath);
    }

    #[\Override]
    public function get_width(): int
    {
        return $this->image->getImageWidth();
    }

    #[\Override]
    public function get_height(): int
    {
        return $this->image->getImageHeight();
    }

    #[\Override]
    public function set_compression_quality(int $quality): bool
    {
        return $this->image->setImageCompressionQuality($quality);
    }

    #[\Override]
    public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool
    {
        // Imagick::cropImage() requires int arguments — see image_gd's
        // crop() for why real callers pass floats here.
        return $this->image->cropImage((int) $width, (int) $height, (int) $x, (int) $y);
    }

    #[\Override]
    public function strip(): bool
    {
        return $this->image->stripImage();
    }

    #[\Override]
    public function rotate(int|float $rotation): bool
    {
        $this->image->rotateImage(new \ImagickPixel(), -$rotation);
        $this->image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
        return true;
    }

    #[\Override]
    public function resize(int|float $width, int|float $height): bool
    {
        $this->image->setInterlaceScheme(\Imagick::INTERLACE_LINE);

        // TODO need to explain this condition
        if ($this->get_width() % 2 == 0
            && $this->get_height() % 2 == 0
            && $this->get_width() > 3 * $width) {
            $this->image->scaleImage($this->get_width() / 2, $this->get_height() / 2);
        }

        // Imagick::resizeImage() requires int columns/rows — see
        // image_gd's crop() for why real callers pass floats here.
        return $this->image->resizeImage((int) $width, (int) $height, \Imagick::FILTER_LANCZOS, 0.9);
    }

    #[\Override]
    public function sharpen(int|float $amount): bool
    {
        $m = pwg_image::get_sharpen_matrix($amount);
        return $this->image->convolveImage($m);
    }

    #[\Override]
    public function compose(pwg_image $overlay, int|float $x, int|float $y, int|float $opacity): bool
    {
        // compose() reaches into the overlay's own backend object to get
        // its raw Imagick instance — only valid when both images use the
        // same backend (always true in practice: i.php constructs both
        // via `new pwg_image(...)`, which resolves the backend from the
        // single $conf['graphics_library'] setting).
        $overlay_backend = $overlay->image;
        if (! $overlay_backend instanceof self) {
            throw new \LogicException('pwg_image::compose(): overlay must use the same image backend');
        }
        $ioverlay = $overlay_backend->image;
        /*if ($ioverlay->getImageAlphaChannel() !== Imagick::ALPHACHANNEL_OPAQUE)
        {
          // Force the image to have an alpha channel
          $ioverlay->setImageAlphaChannel(Imagick::ALPHACHANNEL_OPAQUE);
        }*/

        global $dirty_trick_xrepeat;
        if (! isset($dirty_trick_xrepeat) && $opacity < 100) {// NOTE: Using setImageOpacity will destroy current alpha channels!
            $ioverlay->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $opacity / 100, \Imagick::CHANNEL_ALPHA);
            $dirty_trick_xrepeat = true;
        }

        // Imagick::compositeImage() requires int x/y — see image_gd's
        // crop() for why real callers pass floats here.
        return $this->image->compositeImage($ioverlay, \Imagick::COMPOSITE_DISSOLVE, (int) $x, (int) $y);
    }

    #[\Override]
    public function write(string $destination_filepath): bool
    {
        // use 4:2:2 chroma subsampling (reduce file size by 20-30% with "almost" no human perception)
        $this->image->setSamplingFactors([2, 1]);
        return $this->image->writeImage($destination_filepath);
    }
}

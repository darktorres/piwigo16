<?php

declare(strict_types=1);

namespace Piwigo\Admin\Image;

class ImageImagick implements ImageInterface
{
    /**
     * @var \Imagick
     */
    public \Imagick $image;

    private bool $alphaRepeatApplied = false;

    public function __construct(string $source_filepath)
    {
        // A bug cause that Imagick class can not be extended
        $this->image = new \Imagick($source_filepath);
    }

    public function get_width(): int
    {
        return $this->image->getImageWidth();
    }

    public function get_height(): int
    {
        return $this->image->getImageHeight();
    }

    public function set_compression_quality(int $quality): bool
    {
        return $this->image->setImageCompressionQuality($quality);
    }

    public function crop(int $width, int $height, int $x, int $y): bool
    {
        return $this->image->cropImage($width, $height, $x, $y);
    }

    public function strip(): bool
    {
        return $this->image->stripImage();
    }

    public function rotate(int $rotation): bool
    {
        $this->image->rotateImage(new \ImagickPixel(), -$rotation);
        $this->image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
        return true;
    }

    public function resize(int $width, int $height): bool
    {
        $this->image->setInterlaceScheme(\Imagick::INTERLACE_LINE);

        // Pre-scale by 50% when the source is more than 3× the target width and has
        // even dimensions. A single 50% reduction before the final Lanczos resize
        // reduces aliasing artifacts and is faster than one large resizeImage call.
        // Even dimensions are required because Imagick handles odd intermediates poorly.
        if ($this->get_width() % 2 == 0
            && $this->get_height() % 2 == 0
            && $this->get_width() > 3 * $width) {
            $this->image->scaleImage($this->get_width() / 2, $this->get_height() / 2);
        }

        return $this->image->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 0.9);
    }

    public function sharpen(int $amount): bool
    {
        $m = PwgImage::get_sharpen_matrix($amount);
        return  $this->image->convolveImage($m);
    }

    public function compose(mixed $overlay, int $x, int $y, int $opacity): bool
    {
        if (!($overlay instanceof ImageImagick)) {
            return false;
        }
        $ioverlay = $overlay->image;
        /*if ($ioverlay->getImageAlphaChannel() !== \Imagick::ALPHACHANNEL_OPAQUE)
        {
          // Force the image to have an alpha channel
          $ioverlay->setImageAlphaChannel(\Imagick::ALPHACHANNEL_OPAQUE);
        }*/

        if (!$this->alphaRepeatApplied && $opacity < 100) {// NOTE: Using setImageOpacity will destroy current alpha channels!
            $ioverlay->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $opacity / 100, \Imagick::CHANNEL_ALPHA);
            $this->alphaRepeatApplied = true;
        }

        return $this->image->compositeImage($ioverlay, \Imagick::COMPOSITE_DISSOLVE, $x, $y);
    }

    public function write(string $destination_filepath): bool
    {
        // use 4:2:2 chroma subsampling (reduce file size by 20-30% with "almost" no human perception)
        $this->image->setSamplingFactors([2,1]);
        return $this->image->writeImage($destination_filepath);
    }
}

<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Image;

use Imagick;
use ImagickKernel;
use ImagickPixel;
use LogicException;
use Override;

final class ImageImagick implements ImageInterface
{
    /**
     * @var Imagick
     */
    public $image;

    /**
     * compose()'s own "already dimmed this overlay once" memoization flag.
     * Instance-scoped, not static: each ImageImagick instance wraps
     * exactly one source image for its own lifetime (see the
     * constructor), and compose() reads/writes this flag
     * on the OVERLAY's own instance (`$overlay_backend`, not `$this`) --
     * the thing being memoized is "has this specific overlay's alpha
     * channel already been dimmed," which must survive across multiple
     * compose() calls onto different destination images (x-repeat/
     * y-repeat watermark tiling reuses the same overlay object) but must
     * never leak onto an unrelated overlay, the way a shared global or a
     * static property would.
     */
    private bool $dirtyTrickXrepeatApplied = false;

    public function __construct(
        string $source_filepath
    ) {
        // A bug cause that Imagick class can not be extended
        $this->image = new Imagick($source_filepath);
    }

    #[Override]
    public function getWidth(): int
    {
        return $this->image->getImageWidth();
    }

    #[Override]
    public function getHeight(): int
    {
        return $this->image->getImageHeight();
    }

    #[Override]
    public function setCompressionQuality(int $quality): bool
    {
        return $this->image->setImageCompressionQuality($quality);
    }

    #[Override]
    public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool
    {
        // Imagick::cropImage() requires int arguments — see ImageGd's
        // crop() for why real callers pass floats here.
        return $this->image->cropImage((int) $width, (int) $height, (int) $x, (int) $y);
    }

    #[Override]
    public function strip(): bool
    {
        return $this->image->stripImage();
    }

    #[Override]
    public function rotate(int|float $rotation): bool
    {
        $this->image->rotateImage(new ImagickPixel(), -$rotation);
        $this->image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        return true;
    }

    #[Override]
    public function resize(int|float $width, int|float $height): bool
    {
        $this->image->setInterlaceScheme(Imagick::INTERLACE_LINE);

        // Pre-halving pass: for a source more than 3x the target width
        // (with even dimensions, so halving lands on whole pixels), a
        // cheap scaleImage() box-filter halving first, then the accurate
        // but expensive Lanczos resizeImage() below on the now-much-smaller
        // image, is faster than running Lanczos over the full original
        // resolution directly. ImageGd's resize() has no equivalent step
        // -- GD's imagecopyresampled() doesn't need it.
        if ($this->getWidth() % 2 === 0
            && $this->getHeight() % 2 === 0
            && $this->getWidth() > 3.0 * (float) $width) {
            $this->image->scaleImage(intdiv($this->getWidth(), 2), intdiv($this->getHeight(), 2));
        }

        // Imagick::resizeImage() requires int columns/rows — see
        // ImageGd's crop() for why real callers pass floats here.
        return $this->image->resizeImage((int) $width, (int) $height, Imagick::FILTER_LANCZOS, 0.9);
    }

    #[Override]
    public function sharpen(int|float $amount): bool
    {
        // Real bug, found live by a new unit test that actually asserted on
        // the pixel data instead of just the boolean return: Imagick::
        // convolveImage() has required an ImagickKernel object (not a raw
        // matrix array) since imagick 3.5.0 -- passing the bare array always
        // threw a TypeError. ImageDerivativeController's own real call site
        // (the only one) doesn't catch it, so any real request for a
        // derivative with a configured sharpen level fatally errored
        // instead of degrading -- confirmed uncaught up the stack.
        $m = ImageBackend::getSharpenMatrix($amount);
        return $this->image->convolveImage(ImagickKernel::fromMatrix($m));
    }

    #[Override]
    public function compose(ImageBackend $overlay, int|float $x, int|float $y, int|float $opacity): bool
    {
        // compose() reaches into the overlay's own backend object to get
        // its raw Imagick instance — only valid when both images use the
        // same backend (always true in practice: i.php constructs both
        // via `new ImageBackend(...)`, which resolves the backend from the
        // single \Piwigo\Config\CurrentConfig::graphicsLibrary() setting).
        $overlay_backend = $overlay->image;
        if (! $overlay_backend instanceof self) {
            throw new LogicException('ImageBackend::compose(): overlay must use the same image backend');
        }
        $ioverlay = $overlay_backend->image;
        /*if ($ioverlay->getImageAlphaChannel() !== Imagick::ALPHACHANNEL_OPAQUE)
        {
          // Force the image to have an alpha channel
          $ioverlay->setImageAlphaChannel(Imagick::ALPHACHANNEL_OPAQUE);
        }*/

        if (! $overlay_backend->dirtyTrickXrepeatApplied && $opacity < 100) {// NOTE: Using setImageOpacity will destroy current alpha channels!
            $ioverlay->evaluateImage(Imagick::EVALUATE_MULTIPLY, (float) $opacity / 100.0, Imagick::CHANNEL_ALPHA);
            $overlay_backend->dirtyTrickXrepeatApplied = true;
        }

        // Imagick::compositeImage() requires int x/y — see ImageGd's
        // crop() for why real callers pass floats here.
        return $this->image->compositeImage($ioverlay, Imagick::COMPOSITE_DISSOLVE, (int) $x, (int) $y);
    }

    #[Override]
    public function write(string $destination_filepath): bool
    {
        // use 4:2:2 chroma subsampling (reduce file size by 20-30% with "almost" no human perception)
        $this->image->setSamplingFactors([2, 1]);
        return $this->image->writeImage($destination_filepath);
    }
}

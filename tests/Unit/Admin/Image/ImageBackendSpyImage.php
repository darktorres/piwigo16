<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Admin\Image;

use Piwigo\Admin\Image\ImageBackend;
use Piwigo\Admin\Image\ImageInterface;

/**
 * Records every call pwgResize() makes on the underlying ImageInterface,
 * in order -- several pwgResize() mutations (skipping strip()/crop()/
 * rotate(), or calling rotate() when the resolved rotation is exactly 0)
 * produce a destination file with the exact same final dimensions as the
 * unmutated code, so asserting on width/height/file-existence alone can't
 * catch them. Asserting on the real call sequence can.
 */
final class ImageBackendSpyImage implements ImageInterface
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    public function __construct(
        private readonly int|float $width,
        private readonly int|float $height,
    ) {}

    public function getWidth(): int|float
    {
        return $this->width;
    }

    public function getHeight(): int|float
    {
        return $this->height;
    }

    public function setCompressionQuality(int $quality): bool
    {
        $this->calls[] = "setCompressionQuality({$quality})";
        return true;
    }

    public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool
    {
        $this->calls[] = "crop({$width},{$height},{$x},{$y})";
        return true;
    }

    public function strip(): bool
    {
        $this->calls[] = 'strip';
        return true;
    }

    public function rotate(int|float $rotation): bool
    {
        $this->calls[] = "rotate({$rotation})";
        return true;
    }

    public function resize(int|float $width, int|float $height): bool
    {
        $this->calls[] = "resize({$width},{$height})";
        return true;
    }

    public function sharpen(int|float $amount): bool
    {
        $this->calls[] = "sharpen({$amount})";
        return true;
    }

    public function compose(ImageBackend $overlay, int|float $x, int|float $y, int|float $opacity): bool
    {
        $this->calls[] = 'compose';
        return true;
    }

    public function write(string $destination_filepath): bool
    {
        $this->calls[] = 'write';
        touch($destination_filepath);
        return true;
    }
}

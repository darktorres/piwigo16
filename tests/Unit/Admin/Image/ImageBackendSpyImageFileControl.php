<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Admin\Image;

use Piwigo\Admin\Image\ImageBackend;
use Piwigo\Admin\Image\ImageInterface;

/**
 * Same call-recording contract as ImageBackendSpyImage, but write() controls
 * the destination file directly -- $writeBytes=null never creates it at
 * all (getResizeResult()'s own filesize() call observes a genuinely
 * missing file), otherwise it writes exactly that many bytes (exact
 * control over the "X KB" computation).
 */
final class ImageBackendSpyImageFileControl implements ImageInterface
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    public function __construct(
        private readonly int|float $width,
        private readonly int|float $height,
        private readonly ?int $writeBytes,
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
        $this->calls[] = 'setCompressionQuality';
        return true;
    }

    public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool
    {
        $this->calls[] = 'crop';
        return true;
    }

    public function strip(): bool
    {
        $this->calls[] = 'strip';
        return true;
    }

    public function rotate(int|float $rotation): bool
    {
        $this->calls[] = 'rotate';
        return true;
    }

    public function resize(int|float $width, int|float $height): bool
    {
        $this->calls[] = 'resize';
        return true;
    }

    public function sharpen(int|float $amount): bool
    {
        $this->calls[] = 'sharpen';
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
        if ($this->writeBytes !== null) {
            file_put_contents($destination_filepath, str_repeat('x', $this->writeBytes));
        }
        return true;
    }
}

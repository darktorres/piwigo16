<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * Mutable context for a single derivative image request inside i.php.
 *
 * Carries root path, source paths/URL, and the derivative params through
 * the image-handling pipeline so each pass can read/mutate them without
 * relying on a shared global array.
 */
final class ImageDerivativeContext
{
    public string $rootPath = '';
    public string $srcLocation = '';
    public string $srcPath = '';
    public string $srcUrl = '';
    public string $derivativePath = '';
    public ?DerivativeParams $derivativeParams = null;
    public ?string $derivativeType = null;
    public string $derivativeExt = '';
    /** @var array<int, int|float>|null */
    public ?array $originalSize = null;
    public ?int $rotationAngle = null;
    public ?string $coi = null;
    public int $countQueries = 0;
    public float $queriesTime = 0.0;
}

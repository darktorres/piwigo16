<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * Mutable context for a single derivative image request inside i.php,
 * replacing the $page global array that previously carried derivative state.
 *
 * Properties map 1-to-1 to former $page keys so the migration diff stays small.
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

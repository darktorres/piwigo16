<?php

declare(strict_types=1);

namespace Piwigo\Tests\Browser\Helpers;

/**
 * The deterministic solid-color + "Photo N" label content every fixture
 * photo (piwigo_images ids 1-5, in RegenerateFixtureTest's own upload
 * order) gets. Shared so tools/regenerate-fixture-photos.php (fast,
 * DB-row-targeted, run before every Browser/VR suite via
 * tools/reimport-fixture.sh) and RegenerateFixtureTest itself (slow, full
 * from-scratch install+upload, run manually/rarely to refresh
 * tests/Fixtures/piwigo-17.0.sql) can never drift out of sync with each
 * other again -- exactly the gap that let the committed VisualRegressionTest
 * baselines go stale against the real, current fixture photo content.
 */
final class FixturePhotoGenerator
{
    /** @var list<array{int, int, int}> */
    private const array COLORS = [
        [220, 50, 50],
        [50, 180, 80],
        [50, 100, 220],
        [230, 200, 50],
        [150, 60, 200],
    ];

    /**
     * Writes a 200x150 JPEG for fixture photo $index (1-based, matching
     * COLORS' own indexing and the "Photo N" label burned into it) to
     * $destPath, creating its parent directory if needed.
     */
    public static function write(int $index, string $destPath): void
    {
        $color = self::COLORS[$index - 1] ?? null;
        if ($color === null) {
            throw new \InvalidArgumentException("No fixture color defined for photo index {$index}.");
        }
        [$r, $g, $b] = $color;

        $img = imagecreatetruecolor(200, 150);
        if ($img === false) {
            throw new \RuntimeException('imagecreatetruecolor failed');
        }

        $bgColor = imagecolorallocate($img, $r, $g, $b);
        if ($bgColor === false) {
            throw new \RuntimeException('imagecolorallocate failed');
        }
        imagefill($img, 0, 0, $bgColor);

        $textColor = imagecolorallocate($img, 255, 255, 255);
        if ($textColor === false) {
            throw new \RuntimeException('imagecolorallocate failed');
        }
        imagestring($img, 5, 60, 70, 'Photo ' . $index, $textColor);

        $dir = dirname($destPath);
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }

        // A pre-existing $destPath may be www-data-owned (a live, Apache-
        // driven upload from an earlier test:browser run) -- directory
        // write permission, not file ownership, governs deletion (this
        // project's own established fix for the same class of permission
        // gap elsewhere, e.g. tools/reimport-fixture.sh's own cache
        // clears), so remove it first rather than trying to truncate a
        // file this process may not own.
        if (is_file($destPath) && ! @unlink($destPath)) {
            imagedestroy($img);

            throw new \RuntimeException("Failed to remove the existing file before regenerating it: {$destPath}");
        }

        if (! imagejpeg($img, $destPath, 80)) {
            imagedestroy($img);

            throw new \RuntimeException("Failed to write JPEG to: {$destPath}");
        }
        imagedestroy($img);
    }
}

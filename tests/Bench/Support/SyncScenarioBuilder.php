<?php

declare(strict_types=1);

namespace Piwigo\Tests\Bench\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Builds a nested album tree of synthetic JPEGs under a scratch
 * `galleries/` directory, for SiteSyncBench.
 *
 * Branching factor 4, with depth chosen so leaf folders hold roughly
 * TARGET_IMAGES_PER_LEAF images each -- depth grows with the requested
 * image count, so 10,000 images produces a genuinely deep/wide tree, not
 * one flat folder of 10,000 files. Depth is never 0: every image lives in
 * at least one subdirectory, never loose directly in the gallery root --
 * SiteUpdateSubController::handle()'s dirs-scanning block only ever turns
 * *subdirectories* of the site root into categories (see
 * LocalSiteReader::getFullDirectories()), so a file placed directly in
 * the root would silently fail `isset($db_fulldirs[$dirname])` and never
 * be counted as a new element at all.
 */
final class SyncScenarioBuilder
{
    private const int BRANCHING_FACTOR = 4;

    private const int TARGET_IMAGES_PER_LEAF = 15;

    /**
     * Writes the tree and returns every image path created. Image content
     * doesn't need to be unique -- metadata/EXIF reads just need *valid*
     * JPEG bytes -- so one real image is generated once via GD and copied
     * to every other path.
     *
     * @return list<string> absolute paths of every image file created
     */
    public static function build(string $galleriesDir, int $imageCount): array
    {
        if ($imageCount < 1) {
            throw new InvalidArgumentException('imageCount must be >= 1');
        }

        $root = rtrim($galleriesDir, '/');
        $leafDirs = self::createTree($root, self::depthFor($imageCount));

        $templateFile = $root . '/.scenario-template.jpg';
        self::writeJpeg($templateFile);

        $paths = [];
        $leafCount = count($leafDirs);
        for ($i = 0; $i < $imageCount; $i++) {
            $path = $leafDirs[$i % $leafCount] . '/photo' . $i . '.jpg';
            if (! copy($templateFile, $path)) {
                throw new RuntimeException("Failed to write scenario photo: {$path}");
            }
            $paths[] = $path;
        }

        unlink($templateFile);

        return $paths;
    }

    private static function depthFor(int $imageCount): int
    {
        $desiredLeaves = max(1, (int) ceil($imageCount / self::TARGET_IMAGES_PER_LEAF));
        $depth = (int) ceil(log($desiredLeaves, self::BRANCHING_FACTOR));

        return max(1, $depth);
    }

    /**
     * @return list<string> absolute paths of every leaf directory
     */
    private static function createTree(string $root, int $depth): array
    {
        $current = [$root];
        for ($level = 0; $level < $depth; $level++) {
            $next = [];
            foreach ($current as $parent) {
                for ($branch = 0; $branch < self::BRANCHING_FACTOR; $branch++) {
                    $dir = $parent . '/lvl' . $level . '_' . $branch;
                    if (! is_dir($dir) && ! mkdir($dir, 0o777, true) && ! is_dir($dir)) {
                        throw new RuntimeException("Failed to create scenario dir: {$dir}");
                    }
                    $next[] = $dir;
                }
            }
            $current = $next;
        }

        return $current;
    }

    private static function writeJpeg(string $path): void
    {
        $img = imagecreatetruecolor(64, 48);
        if ($img === false) {
            throw new RuntimeException('imagecreatetruecolor failed');
        }

        $color = imagecolorallocate($img, 120, 140, 200);
        if ($color === false) {
            throw new RuntimeException('imagecolorallocate failed');
        }
        imagefill($img, 0, 0, $color);

        if (! imagejpeg($img, $path, 60)) {
            throw new RuntimeException("Failed to write template JPEG: {$path}");
        }
    }
}

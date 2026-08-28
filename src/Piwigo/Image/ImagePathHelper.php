<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;

/**
 * Pure image-path helpers -- Piwigo\Image already hosts every other
 * path/derivative concern (SrcImage/DerivativeImage/DerivativeUrlCodec),
 * a real caller (SrcImage itself), and these 3 functions have zero
 * dependency beyond string manipulation. getElementPath()'s own
 * url_is_remote() call takes an explicit UrlServiceInterface method
 * param -- Image is L2aCoreDomain and Url is L2bExtendedDomain, so a
 * constructor/static-property dependency isn't legal here, matching the
 * per-method-injection convention used elsewhere for a static method with
 * several real callers.
 */
final class ImagePathHelper
{
    /**
     * Transforms an original path to its pwg representative
     */
    public static function originalToRepresentative(string $path, string $representativeExt): string
    {
        return self::inSubdirWithExtension($path, 'pwg_representative/', $representativeExt);
    }

    /**
     * Transforms an original path to its format
     */
    public static function originalToFormat(string $path, string $formatExt): string
    {
        return self::inSubdirWithExtension($path, 'pwg_format/', $formatExt);
    }

    /**
     * `galleries/2024/photo.raw` + `pwg_format/` + `jpg` ->
     * `galleries/2024/pwg_format/photo.jpg`. Shared by the two callers
     * above, which differed only in the subdirectory they insert.
     *
     * Both used to assert that the path had a directory component and an
     * extension, and then feed `strrpos()`'s result straight into `$pos +
     * 1`. `assert()` was compiled out, and `false + 1` is `1` -- so a path
     * missing either one silently produced a string built from offset 1 of
     * itself: `originalToRepresentative('', 'jpg')` returned `'pjpg'`.
     * The asserted invariant was never guaranteed anyway; `images.path` is
     * `NOT NULL DEFAULT ''`, so an empty path is representable, and
     * `SrcImageInfo::fromRow()` deliberately tolerates a missing one.
     *
     * Each case is answered instead of assumed: no path means no derived
     * path, a path with no directory takes the subdirectory at the front,
     * and a path with no extension gains one rather than having its first
     * character overwritten.
     */
    private static function inSubdirWithExtension(string $path, string $subdir, string $extension): string
    {
        if ($path === '') {
            return '';
        }

        $slash = strrpos($path, '/');
        $path = substr_replace($path, $subdir, $slash === false ? 0 : $slash + 1, 0);

        $dot = strrpos($path, '.');

        return $dot === false
            ? $path . '.' . $extension
            : substr_replace($path, $extension, $dot + 1);
    }

    /**
     * get the full path of an image. Same cross-domain-generic-row-reader
     * rationale as SrcImage::__construct() -- only 'path' is read here.
     *
     * @param string $path images.path -- `varchar(255) NOT NULL` in the schema
     */
    public static function getElementPath(string $path, UrlServiceInterface $urlService, Paths $paths): string
    {
        // `path` is root-relative for uploaded photos, but already
        // absolute for locally site-synced photos (see ImageEntity::$path's
        // own docblock) -- same guard MetadataService::getSyncMetadata()
        // already uses, needed here too or a synced photo's path gets the
        // root prepended a second time, producing an unreadable, doubled-up
        // path.
        if (! $urlService->urlIsRemote($path) && ! str_starts_with($path, '/')) {
            $path = $paths->root . $path;
        }
        return $path;
    }
}

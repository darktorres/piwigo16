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
        // real image paths always carry a directory component and a file
        // extension (galleries/YYYY/photo.jpg-style), same invariant
        // getElementPath() below documents for images.path.
        $pos = strrpos($path, '/');
        assert($pos !== false);
        $path = substr_replace($path, 'pwg_representative/', $pos + 1, 0);
        $pos = strrpos($path, '.');
        assert($pos !== false);
        return substr_replace($path, $representativeExt, $pos + 1);
    }

    /**
     * Transforms an original path to its format
     */
    public static function originalToFormat(string $path, string $formatExt): string
    {
        $pos = strrpos($path, '/');
        assert($pos !== false);
        $path = substr_replace($path, 'pwg_format/', $pos + 1, 0);
        $pos = strrpos($path, '.');
        assert($pos !== false);
        return substr_replace($path, $formatExt, $pos + 1);
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

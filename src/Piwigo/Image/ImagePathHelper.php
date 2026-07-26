<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Core\CurrentPaths;
use Piwigo\Core\UrlServiceInterface;

/**
 * P23 batch 8d: pure image-path helpers relocated from
 * include/functions.inc.php -- Piwigo\Image already hosts every other
 * path/derivative concern (SrcImage/DerivativeImage/DerivativeUrlCodec),
 * a real caller (SrcImage itself), and these 3 functions have zero
 * dependency beyond string manipulation. getElementPath()'s own
 * url_is_remote() call now takes an explicit UrlServiceInterface method
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
     * @param array<string, mixed> $elementInfo element information from db (at least 'path')
     */
    public static function getElementPath(array $elementInfo, UrlServiceInterface $urlService): string
    {
        $path = $elementInfo['path'];
        // images.path is `varchar(255) NOT NULL` in the schema — a genuine DB
        // row for this element always carries a string here.
        assert(is_string($path));

        if (! $urlService->urlIsRemote($path)) {
            $path = CurrentPaths::get()->root . $path;
        }
        return $path;
    }
}

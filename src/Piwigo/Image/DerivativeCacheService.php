<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Exception;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Paths;

/**
 * Derivative-cache invalidation. Piwigo's real derivative-generation design
 * is lazy: i.php regenerates a missing/stale derivative file on the next
 * request that needs it (SEC-33/35). "Regenerating" a derivative is
 * therefore really "delete the cached file(s)", which is exactly what the
 * original admin/include/functions.php free functions
 * (delete_element_derivatives()/clear_derivative_cache()) already did --
 * ported here verbatim as a proper service so Piwigo\Job's handlers have a
 * real, autoloadable delegation target.
 */
final class DerivativeCacheService
{
    public function __construct(
        private readonly CurrentConfig $currentConfig,
        private readonly Paths $paths,
    ) {}

    /**
     * Deletes all cached derivative files for one or several types.
     *
     * @param 'all'|string|array<int|string, string> $types type name(s)
     *   (e.g. ImageStdParams::SQUARE), not ids
     */
    public function clearDerivativeCache(string|array $types = 'all'): void
    {
        if ($types === 'all') {
            $resolvedTypes = ImageStdParams::get_all_types();
            $resolvedTypes[] = ImageStdParams::CUSTOM;
        } elseif (is_array($types)) {
            $resolvedTypes = array_values($types);
        } else {
            $resolvedTypes = [$types];
        }

        for ($i = 0; $i < count($resolvedTypes); $i++) {
            $type = $resolvedTypes[$i];
            if ($type === ImageStdParams::CUSTOM) {
                $type = $this->derivativeToUrl($type) . '_[a-zA-Z0-9]+';
            } elseif (in_array($type, ImageStdParams::get_all_types(), true)) {
                $type = $this->derivativeToUrl($type);
            } else {
                $type = $this->derivativeToUrl(ImageStdParams::CUSTOM) . '_' . $type;
            }
            $resolvedTypes[$i] = $type;
        }

        $pattern = '#.*-';
        if (count($resolvedTypes) > 1) {
            $pattern .= '(' . implode('|', $resolvedTypes) . ')';
        } else {
            $pattern .= $resolvedTypes[0];
        }
        $pattern .= '\.[a-zA-Z0-9]{3,4}$#';

        $root = $this->paths->root . $this->currentConfig->derivativeDir;
        $contents = @opendir($root);
        if ($contents !== false) {
            while (($node = readdir($contents)) !== false) {
                if ($node !== '.' && $node !== '..' && is_dir($root . $node)) {
                    $this->clearDerivativeCacheRecursive($root . $node, $pattern);
                }
            }
            closedir($contents);
        }
    }

    /**
     * Deletes derivatives of a particular element.
     *
     * @param array{path: string, representative_ext?: string} $infos
     * @param string $type 'all', or one of the ImageStdParams size-type constants
     */
    public function deleteElementDerivatives(array $infos, string $type = 'all'): void
    {
        $path = $infos['path'];
        $representativeExt = $infos['representative_ext'] ?? null;
        if ($representativeExt !== null && $representativeExt !== '') {
            $path = ImagePathHelper::originalToRepresentative($path, $representativeExt);
        }
        if (substr_compare($path, '../', 0, 3) === 0) {
            $path = substr($path, 3);
        }
        $dot = strrpos($path, '.');
        if ($dot === false) {
            throw new Exception("deleteElementDerivatives(): path '{$path}' has no extension");
        }
        if ($type === 'all') {
            $pattern = '-*';
        } else {
            $pattern = '-' . $this->derivativeToUrl($type) . '*';
        }
        $path = substr_replace($path, $pattern, $dot, 0);

        $glob = glob($this->paths->root . $this->currentConfig->derivativeDir . $path);
        if ($glob !== false) {
            foreach ($glob as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Used by clearDerivativeCache() itself, and directly by
     * admin/site_update.php's own recursive-delete need (a different glob
     * pattern, not routed through clearDerivativeCache()'s type
     * resolution) -- kept public to match that real second caller.
     */
    public function clearDerivativeCacheRecursive(string $path, string $pattern): bool
    {
        FilesystemHelper::guardAgainstRealRoot($path, __METHOD__);
        $rmdir = true;
        $rmIndex = false;

        $contents = opendir($path);
        if ($contents === false) {
            return false;
        }

        while (($node = readdir($contents)) !== false) {
            if ($node === '.' || $node === '..') {
                continue;
            }
            if (is_dir($path . '/' . $node)) {
                $subRmdir = $this->clearDerivativeCacheRecursive($path . '/' . $node, $pattern);
                $rmdir = $rmdir && $subRmdir;
            } elseif ($pattern !== '' && preg_match($pattern, $node) === 1) {
                unlink($path . '/' . $node);
            } elseif ($node === 'index.htm') {
                $rmIndex = true;
            } else {
                $rmdir = false;
            }
        }
        closedir($contents);

        if ($rmdir) {
            if ($rmIndex) {
                unlink($path . '/index.htm');
            }
            clearstatcache();
            @rmdir($path);
        }

        return $rmdir;
    }

    private function derivativeToUrl(string $type): string
    {
        return substr($type, 0, 2);
    }
}

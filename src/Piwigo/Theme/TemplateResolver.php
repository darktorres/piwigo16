<?php

declare(strict_types=1);

namespace Piwigo\Theme;

/**
 * Walks a theme's parent chain to resolve `.latte` template paths and
 * asset directory lookups.
 *
 * Themes use composition: a child theme's resolution chain starts with
 * itself, then each ancestor up to a root theme. For any relative
 * path, the resolver returns the first existing file in chain order —
 * so children can override individual files without copying the whole
 * parent tree.
 *
 * Usage:
 *
 *   $resolver = new TemplateResolver($registry, $themesDir);
 *   $absPath  = $resolver->resolve('standard_pages', 'index.latte');
 *
 * Returns null when no theme in the chain has the file. Asset
 * directories (img, icon, mimeIcon) use the same walk via assetDir().
 */
final class TemplateResolver
{
    public function __construct(
        private readonly ThemeRegistry $registry,
        private readonly string $themesDir,
    ) {
    }

    /**
     * Resolve a relative template path against the theme chain starting
     * at $startThemeId. Returns the absolute filesystem path of the
     * first matching file, or null when no theme in the chain has it.
     */
    public function resolve(string $startThemeId, string $relativePath): ?string
    {
        $relativePath = ltrim($relativePath, '/');
        if ($relativePath === '') {
            return null;
        }
        foreach ($this->registry->getResolutionChain($startThemeId) as $themeId) {
            $candidate = rtrim($this->themesDir, '/') . '/' . $themeId . '/' . $relativePath;
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Resolve an asset directory by kind ('img', 'icon', 'mimeIcon', …).
     * Walks the chain and returns the first theme whose manifest
     * declares the kind under `assets`. Returns null when no theme in
     * the chain advertises that kind.
     */
    public function assetDir(string $startThemeId, string $kind): ?string
    {
        foreach ($this->registry->getResolutionChain($startThemeId) as $themeId) {
            $manifest = $this->registry->getManifest($themeId);
            if ($manifest === null) {
                continue;
            }
            $dir = $manifest->assets[$kind] ?? null;
            if (is_string($dir) && $dir !== '') {
                return rtrim($this->themesDir, '/') . '/' . $themeId . '/' . ltrim($dir, '/');
            }
        }
        return null;
    }

    /**
     * Return the LocalHead template path for the chain's first theme
     * that declares one. Used at <head> render time so a child can
     * inherit the parent's localHead without re-declaring it.
     */
    public function localHead(string $startThemeId): ?string
    {
        foreach ($this->registry->getResolutionChain($startThemeId) as $themeId) {
            $manifest = $this->registry->getManifest($themeId);
            if ($manifest === null) {
                continue;
            }
            if (is_string($manifest->localHead) && $manifest->localHead !== '') {
                return rtrim($this->themesDir, '/') . '/' . $themeId . '/' . ltrim($manifest->localHead, '/');
            }
        }
        return null;
    }
}

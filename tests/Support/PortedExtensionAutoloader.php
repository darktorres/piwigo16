<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Composer\Autoload\ClassLoader;
use JsonException;

/**
 * Registers PSR-4 autoloading for every already-ported plugin's/theme's
 * own `src/` (`../piwigo16-plugins`, `../piwigo16-themes`,
 * `<id>_17.0.0/`), read from that port's own real `plugin.json`/
 * `theme.json` `autoload.psr-4` map -- the exact same mechanism
 * `ThemeRegistry::registerAutoload()`/`PluginRegistry`'s own equivalent
 * use at runtime, not a hand-guessed namespace-to-path mapping. Globbed
 * fresh on every call, so a newly ported extension's own tests need no
 * bootstrap change -- same shape as
 * `tools/analyse-ported-extensions.sh`/`tools/build-ported-extension-assets.mjs`.
 *
 * Called once from `tests/bootstrap.php`, so every test file (in any
 * suite) can freely `use Piwigo\Theme\Modus\...` etc. without its own
 * per-file `ClassLoader` boilerplate. Registering costs nothing when no
 * ported extension's own test references it -- a few filesystem stats,
 * no class actually loaded until first referenced.
 */
final class PortedExtensionAutoloader
{
    /**
     * @var list<string>
     */
    private const array SIBLING_DIRS = ['../piwigo16-plugins', '../piwigo16-themes'];

    public static function register(): void
    {
        $loader = new ClassLoader();
        $registeredAny = false;

        foreach (self::SIBLING_DIRS as $siblingDir) {
            if (! is_dir($siblingDir)) {
                continue;
            }

            $portDirs = glob($siblingDir . '/*_17.0.0', GLOB_ONLYDIR);
            foreach ($portDirs === false ? [] : $portDirs as $portDir) {
                foreach (self::readAutoloadPsr4($portDir) as $prefix => $relativeDir) {
                    $loader->addPsr4($prefix, rtrim($portDir, '/') . '/' . ltrim($relativeDir, '/'));
                    $registeredAny = true;
                }
            }
        }

        if ($registeredAny) {
            $loader->register();
        }
    }

    /**
     * @return array<string, string>
     */
    private static function readAutoloadPsr4(string $portDir): array
    {
        $manifestPath = self::findManifest($portDir);
        if ($manifestPath === null) {
            return [];
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $autoload = $decoded['autoload'] ?? null;
        $psr4 = is_array($autoload) ? ($autoload['psr-4'] ?? null) : null;
        if (! is_array($psr4)) {
            return [];
        }

        $result = [];
        foreach ($psr4 as $prefix => $relativeDir) {
            if (is_string($prefix) && is_string($relativeDir)) {
                $result[$prefix] = $relativeDir;
            }
        }

        return $result;
    }

    private static function findManifest(string $portDir): ?string
    {
        foreach (['theme.json', 'plugin.json'] as $filename) {
            $path = rtrim($portDir, '/') . '/' . $filename;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}

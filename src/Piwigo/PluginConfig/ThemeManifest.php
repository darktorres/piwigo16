<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use InvalidArgumentException;

/**
 * Validated `theme.json` manifest, post-schema-validation.
 *
 * Shares every base field `PluginManifest` has (`id`, `name`, `version`,
 * `description`, `license`, `minPiwigo`, `main`, `homepage?`, `author?`,
 * `authorUri?`, `hasSettings`, `require`, `autoloadPsr4`) -- unlike the
 * reference fork's own `Theme\ThemeManifest`, which carries only `id`/
 * `name`/`version`/`main` plus theme-specific fields. Themes need
 * `minPiwigo`-gating and `require:` dependency-graph resolution exactly
 * like plugins do (`ThemeRegistry` does the same manifest-scan +
 * `composer/semver` dependency-graph work `PluginRegistry` does, P27.3),
 * and all 3 bundled themes genuinely ship a real `admin/admin.inc.php`
 * settings page, so `hasSettings` applies here too.
 *
 * Adds theme-only facts the reference fork wrongly put directly on its
 * `ThemeInterface` instead of the manifest: `parent`, `loadParentCss`,
 * `assets`, `localHead`, `colorscheme`, `useStandardPages`. Deliberately
 * has **no** `mobile` field -- checked `Admin\Extensions\
 * ExtensionScanner::scanTheme()` (the only place `'mobile'` is read
 * today) and it is a legacy-scanner-only concept tied to
 * `CurrentConfig::mobileTheme` admin config that none of the 3 bundled
 * themes actually declare; "which installed theme serves mobile" stays a
 * pure admin/config pairing, not a manifest field with zero real caller.
 *
 * `iconDir`/`imgDir`/`mimeIconDir`/`loadParentLocalHead` (P27.10, real
 * `theme.schema.json` fields a `theme.json` file can declare) are
 * deliberately **not** mirrored as properties here -- `Template::
 * loadThemeJson()` is the one and only real reader, via its own direct
 * `json_decode()` (this class's own docblock already explains why: a
 * purely file-based lookup with no reason to pull in `ThemeRegistry`'s
 * DB/EntityManager dependencies), so a `ThemeManifest` property for
 * either would have zero real callers -- a `shipmonk.deadProperty.
 * neverRead` violation, confirmed live when first added.
 */
final readonly class ThemeManifest
{
    /**
     * @param array<string, string> $require      Composer-style version constraints keyed by 'piwigo' or 'plugin/<id>'/'theme/<id>'.
     * @param array<string, string> $assets        Map of asset kind -> directory relative to theme root.
     * @param array<string, string> $autoloadPsr4  PSR-4 namespace prefix -> directory map, relative to theme root.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $description,
        public string $license,
        public string $minPiwigo,
        public string $main,
        public ?string $homepage = null,
        public ?string $author = null,
        public ?string $authorUri = null,
        public bool|string $hasSettings = false,
        public array $require = [],
        public array $autoloadPsr4 = [],
        public ?string $parent = null,
        public bool $loadParentCss = false,
        public array $assets = [],
        public ?string $localHead = null,
        public ?string $colorscheme = null,
        public bool $useStandardPages = false,
    ) {}

    /**
     * Builds a manifest from the `json_decode(..., true)` array result.
     * Caller is responsible for schema-validating the array first.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $psr4 = [];
        $autoload = $data['autoload'] ?? null;
        if (is_array($autoload) && is_array($autoload['psr-4'] ?? null)) {
            foreach ($autoload['psr-4'] as $ns => $path) {
                if (is_string($ns) && is_string($path)) {
                    $psr4[$ns] = $path;
                }
            }
        }

        $require = [];
        $requireRaw = $data['require'] ?? null;
        if (is_array($requireRaw)) {
            foreach ($requireRaw as $name => $constraint) {
                if (is_string($name) && is_string($constraint)) {
                    $require[$name] = $constraint;
                }
            }
        }

        $hasSettings = $data['hasSettings'] ?? false;
        if (! is_bool($hasSettings) && $hasSettings !== 'webmaster') {
            $hasSettings = false;
        }

        $assets = [];
        $rawAssets = $data['assets'] ?? null;
        if (is_array($rawAssets)) {
            foreach ($rawAssets as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $assets[$key] = $value;
                }
            }
        }

        return new self(
            id: self::requireString($data, 'id'),
            name: self::requireString($data, 'name'),
            version: self::requireString($data, 'version'),
            description: self::requireString($data, 'description'),
            license: self::requireString($data, 'license'),
            minPiwigo: self::requireString($data, 'minPiwigo'),
            main: self::requireString($data, 'main'),
            homepage: self::optionalString($data, 'homepage'),
            author: self::optionalString($data, 'author'),
            authorUri: self::optionalString($data, 'authorUri'),
            hasSettings: $hasSettings,
            require: $require,
            autoloadPsr4: $psr4,
            parent: self::optionalString($data, 'parent'),
            loadParentCss: is_bool($data['loadParentCss'] ?? null) ? $data['loadParentCss'] : false,
            assets: $assets,
            localHead: self::optionalString($data, 'localHead'),
            colorscheme: self::optionalString($data, 'colorscheme'),
            useStandardPages: is_bool($data['useStandardPages'] ?? null) ? $data['useStandardPages'] : false,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value)) {
            throw new InvalidArgumentException("theme.json field '{$key}' must be a string (schema-validated input expected)");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}

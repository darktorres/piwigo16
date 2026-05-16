<?php

declare(strict_types=1);

namespace Piwigo\Theme;

/**
 * Validated theme.json manifest, post-schema-validation.
 *
 * Constructed by [[ThemeRegistry]] after json_decode + opis/json-schema
 * acceptance. Every property mirrors a field documented in
 * `docs/schemas/theme.schema.json` — additionalProperties is false
 * there so any unrecognised key in the JSON is rejected before this
 * value object is built.
 */
final readonly class ThemeManifest
{
    /**
     * @param ?string                $parent        Parent theme id for inheritance, null for root themes.
     * @param bool                   $loadParentCss Whether the parent's CSS loads before this theme's.
     * @param array<string, string>  $assets        Map of asset kind → directory relative to theme root.
     * @param array<string, string>  $autoloadPsr4  PSR-4 namespace prefix → directory map, relative to theme root.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $main,
        public ?string $parent = null,
        public bool $loadParentCss = false,
        public array $assets = [],
        public ?string $localHead = null,
        public array $autoloadPsr4 = [],
    ) {
    }

    /**
     * Build a manifest from the json_decode(..., true) array result.
     * Caller is responsible for schema-validating the array first.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $assets = [];
        $rawAssets = $data['assets'] ?? null;
        if (is_array($rawAssets)) {
            foreach ($rawAssets as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $assets[$key] = $value;
                }
            }
        }

        $psr4 = [];
        $autoload = $data['autoload'] ?? null;
        if (is_array($autoload) && is_array($autoload['psr-4'] ?? null)) {
            foreach ($autoload['psr-4'] as $ns => $path) {
                if (is_string($ns) && is_string($path)) {
                    $psr4[$ns] = $path;
                }
            }
        }

        $parent = $data['parent'] ?? null;
        $localHead = $data['localHead'] ?? null;
        $loadParentCss = $data['loadParentCss'] ?? false;

        return new self(
            id: self::requireString($data, 'id'),
            name: self::requireString($data, 'name'),
            version: self::requireString($data, 'version'),
            main: self::requireString($data, 'main'),
            parent: is_string($parent) ? $parent : null,
            loadParentCss: is_bool($loadParentCss) ? $loadParentCss : false,
            assets: $assets,
            localHead: is_string($localHead) ? $localHead : null,
            autoloadPsr4: $psr4,
        );
    }

    /** @param array<string, mixed> $data */
    private static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException("theme.json field '{$key}' must be a string (schema-validated input expected)");
        }
        return $value;
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Plugin;

/**
 * Validated plugin.json manifest, post-schema-validation.
 *
 * Constructed by PluginRegistry after json_decode + opis/json-schema
 * acceptance. Every property mirrors a field documented in
 * docs/schemas/plugin.schema.json — additionalProperties is false there
 * so any unrecognised key in the JSON is rejected before we get here.
 */
final readonly class PluginManifest
{
    /**
     * @param array<string, string> $require        Composer-style version constraints keyed by 'piwigo' or 'plugin/<id>'.
     * @param array<string, string> $autoloadPsr4   PSR-4 namespace prefix -> directory map, relative to the plugin root.
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
    ) {
    }

    /**
     * Build a manifest from the json_decode(..., true) array result.
     * Caller is responsible for schema-validating the array first; the
     * docs/schemas/plugin.schema.json contract guarantees the seven
     * required fields are strings and the optional ones are well-typed.
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
        if (!is_bool($hasSettings) && $hasSettings !== 'webmaster') {
            $hasSettings = false;
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
        );
    }

    /** @param array<string, mixed> $data */
    private static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException("plugin.json field '{$key}' must be a string (schema-validated input expected)");
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        return is_string($value) ? $value : null;
    }
}

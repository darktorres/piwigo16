<?php

declare(strict_types=1);

namespace Piwigo\Asset;

use InvalidArgumentException;

/**
 * One entry from Vite's own `dist/.vite/manifest.json`
 * (`build.manifest: true` in `vite.config.ts`). Shape matches the real
 * generated file, e.g. `{"build/vitals.ts": {"file": "vitals.js",
 * "name": "vitals", "src": "build/vitals.ts", "isEntry": true}}` --
 * `css`/`imports` are only present when Vite actually splits CSS/JS out
 * of the entry, so both default to empty.
 */
final readonly class ViteManifestEntry
{
    /**
     * @param list<string> $css CSS chunk paths (relative to `dist/`) Vite
     *   pulled out of this entry, if any.
     * @param list<string> $imports Shared JS chunks this entry statically
     *   imports, as *manifest keys* (`_common-vA1Nr0H_.js`), not paths.
     *   They are keys into the same map entries live in, so
     *   `ViteManifest::resolve()` looks one up directly and the graph can
     *   be walked transitively. Empty until code is actually shared --
     *   before real ES-module output, every entry was self-contained.
     */
    public function __construct(
        public string $file,
        public array $css = [],
        public bool $isEntry = false,
        public array $imports = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data one manifest.json entry,
     *   already `json_decode(..., true)`-d -- `array-key`, not
     *   `string`, for the same reason as `ViteManifest::decode()`'s own
     *   docblock: `json_decode()`'s return type can't statically prove
     *   an object (not array) payload
     */
    public static function fromArray(array $data): self
    {
        $file = $data['file'] ?? null;
        if (! is_string($file)) {
            throw new InvalidArgumentException('ViteManifestEntry: missing or non-string "file"');
        }

        $css = [];
        $rawCss = $data['css'] ?? null;
        if (is_array($rawCss)) {
            foreach ($rawCss as $entry) {
                if (is_string($entry)) {
                    $css[] = $entry;
                }
            }
        }

        $imports = [];
        $rawImports = $data['imports'] ?? null;
        if (is_array($rawImports)) {
            foreach ($rawImports as $entry) {
                if (is_string($entry)) {
                    $imports[] = $entry;
                }
            }
        }

        return new self(
            file: $file,
            css: $css,
            isEntry: (bool) ($data['isEntry'] ?? false),
            imports: $imports,
        );
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Asset;

use InvalidArgumentException;
use Piwigo\Core\Paths;

/**
 * Reads Vite's own `dist/.vite/manifest.json` (`build.manifest: true`
 * in `vite.config.ts`), keyed by the source path used as a
 * `rollupOptions.input` entry (e.g. `"build/vitals.ts"`).
 *
 * `dist/` is gitignored -- a fresh checkout before the first
 * `bun run build`, or a Unit-test context that never runs a real Vite
 * build, has no manifest file at all. `resolve()` returns `null` in
 * that case rather than throwing, matching this codebase's established
 * "degrade gracefully when unpopulated" pattern (e.g.
 * `Template::concat()`) -- a caller falls back to the raw, un-bundled
 * file path, which is every asset not yet converted by docs/PLAN.md's
 * P46 (66 of the real 78 theme JS files remain, as of P46-B; `vitals`
 * plus P46-B's own 12 entries already resolve through here).
 */
final class ViteManifest
{
    /**
     * @var array<string, ViteManifestEntry>|null
     */
    private ?array $entries = null;

    public function __construct(
        private readonly Paths $paths,
    ) {}

    public function resolve(string $entrySource): ?ViteManifestEntry
    {
        return $this->entries()[$entrySource] ?? null;
    }

    /**
     * @return array<string, ViteManifestEntry>
     */
    private function entries(): array
    {
        if ($this->entries === null) {
            $this->entries = self::parseEntries(self::decode($this->readRaw()));
        }

        return $this->entries;
    }

    private function readRaw(): ?string
    {
        $path = $this->paths->root . 'dist/.vite/manifest.json';
        if (! is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);

        return $content !== false ? $content : null;
    }

    /**
     * `json_decode()`'s return type can't statically prove string keys
     * -- a malformed payload (e.g. a JSON array literal instead of an
     * object) decodes to int keys, which `parseEntries()`'s own
     * `is_string($source)` check below skips at runtime.
     *
     * @return array<array-key, mixed>
     */
    private static function decode(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Builds the resolved entry map from a `json_decode(..., true)`
     * result -- the real production path via `decode()` above, or a
     * test fixture passed directly. A single malformed entry is
     * skipped, not fatal -- consistent with this class's whole
     * "gracefully resolve to nothing, let the caller fall back" design.
     *
     * @param array<array-key, mixed> $data
     * @return array<string, ViteManifestEntry>
     */
    public static function parseEntries(array $data): array
    {
        $entries = [];
        foreach ($data as $source => $entry) {
            if (! is_string($source) || ! is_array($entry)) {
                continue;
            }
            try {
                $entries[$source] = ViteManifestEntry::fromArray($entry);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $entries;
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Asset;

use Psr\Log\LoggerInterface;

/**
 * Discovers and emits frontend asset tags for plugin entries.
 *
 * Plugins ship their own Vite builds — running `vite build` against
 * `plugins/<id>/src/{admin,public}/index.ts` produces the Vite-format
 * `plugins/<id>/dist/.vite/manifest.json`. Each entry resolves to one
 * `<script type="module">` (the module entry) plus zero or more
 * `<link rel="stylesheet">` tags (CSS associated with the chunk).
 *
 * Usage from a plugin's subscriber:
 *
 *   public function onAdminPagesRegistering(AdminPagesRegistering $event): void
 *   {
 *       $this->assetService->registerEntry('my_plugin/admin');
 *   }
 *
 * Then the admin renderer calls `$assetService->renderHeadTags()`
 * during header emission to write the actual tags.
 *
 * Manifests are loaded lazily on first lookup and cached for the
 * lifetime of the service. Missing manifests warn through the logger
 * but never fault — a plugin that hasn't been built yet just emits no
 * tags rather than failing the entire request.
 */
final class AssetService
{
    /**
     * Registrations made via registerEntry(), keyed by `pluginId` and
     * preserving insertion order.
     *
     * @var array<string, list<string>>
     */
    private array $registered = [];

    /**
     * Decoded manifests keyed by pluginId. `false` means "we tried and
     * the file is missing or malformed" so we don't re-read each call.
     *
     * @var array<string, array<string, mixed>|false>
     */
    private array $manifests = [];

    public function __construct(
        private readonly string $pluginsDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Register one plugin entry by its `<pluginId>/<entryName>` reference.
     *
     * `entryName` matches the Vite input filename minus its extension —
     * e.g. for `plugins/foo/src/admin/index.ts` the entry name is `admin`
     * if the plugin's `vite.config` aliases inputs that way, or the full
     * `src/admin/index.ts` if it doesn't.
     *
     * Duplicates are folded silently so plugins that register the same
     * entry from multiple subscribers don't double-emit tags.
     */
    public function registerEntry(string $entryRef): void
    {
        $slash = strpos($entryRef, '/');
        if ($slash === false || $slash === 0 || $slash === strlen($entryRef) - 1) {
            // Malformed input — log and ignore. Plugin authors get a hint via the logger.
            $this->logger->warning('AssetService: ignoring malformed entry reference', [
                'entry_ref' => $entryRef,
            ]);
            return;
        }
        $pluginId = substr($entryRef, 0, $slash);
        $entryName = substr($entryRef, $slash + 1);

        $existing = $this->registered[$pluginId] ?? [];
        if (in_array($entryName, $existing, true)) {
            return;
        }
        $existing[] = $entryName;
        $this->registered[$pluginId] = $existing;
    }

    /**
     * Render every registered entry into a flat list of HTML tags
     * (CSS first, then the module `<script>`). Returns an empty list
     * when nothing is registered or no manifests are findable.
     *
     * @return list<string>
     */
    public function renderHeadTags(): array
    {
        $tags = [];
        foreach ($this->registered as $pluginId => $entries) {
            $manifest = $this->manifestFor($pluginId);
            if ($manifest === null) {
                continue;
            }
            foreach ($entries as $entryName) {
                foreach ($this->tagsForEntry($pluginId, $entryName, $manifest) as $tag) {
                    $tags[] = $tag;
                }
            }
        }
        return $tags;
    }

    /**
     * Discard any cached manifest and the registration log. Useful in
     * tests; not called by production code.
     */
    public function reset(): void
    {
        $this->registered = [];
        $this->manifests = [];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    private function tagsForEntry(string $pluginId, string $entryName, array $manifest): array
    {
        // Vite's standard manifest keys entries by their input source path
        // (e.g. "src/admin/index.ts"). Plugin authors may also use a
        // simplified alias if their vite.config defines `build.rollupOptions.input`
        // explicitly. Look up both styles.
        $info = $manifest[$entryName] ?? null;
        if (!is_array($info)) {
            // Try the conventional "src/<name>/index.ts" form.
            $info = $manifest["src/{$entryName}/index.ts"] ?? null;
        }
        if (!is_array($info)) {
            $this->logger->warning('AssetService: entry not found in plugin manifest', [
                'plugin_id' => $pluginId,
                'entry'     => $entryName,
            ]);
            return [];
        }

        $tags = [];
        $cssList = is_array($info['css'] ?? null) ? $info['css'] : [];
        foreach ($cssList as $cssFile) {
            if (is_string($cssFile)) {
                $tags[] = '<link rel="stylesheet" href="' . $this->urlFor($pluginId, $cssFile) . '">';
            }
        }
        $jsFile = $info['file'] ?? null;
        if (is_string($jsFile)) {
            $tags[] = '<script type="module" src="' . $this->urlFor($pluginId, $jsFile) . '"></script>';
        }
        return $tags;
    }

    private function urlFor(string $pluginId, string $relativePath): string
    {
        return 'plugins/' . $pluginId . '/dist/' . ltrim($relativePath, '/');
    }

    /**
     * Load and cache the manifest for a plugin. Tries Vite's default
     * `dist/.vite/manifest.json` first, then falls back to the legacy
     * `dist/manifest.json` location for plugins that ship a manifest
     * at the top of dist (some custom Vite plugins emit there).
     *
     * @return array<string, mixed>|null
     */
    private function manifestFor(string $pluginId): ?array
    {
        if (isset($this->manifests[$pluginId])) {
            $cached = $this->manifests[$pluginId];
            return $cached === false ? null : $cached;
        }
        $base = rtrim($this->pluginsDir, '/') . '/' . $pluginId . '/dist';
        $candidates = [
            $base . '/.vite/manifest.json',
            $base . '/manifest.json',
        ];
        foreach ($candidates as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->logger->warning('AssetService: plugin manifest is not valid JSON', [
                    'plugin_id' => $pluginId,
                    'path'      => $path,
                    'error'     => $e->getMessage(),
                ]);
                $this->manifests[$pluginId] = false;
                return null;
            }
            if (!is_array($decoded)) {
                $this->manifests[$pluginId] = false;
                return null;
            }
            // Manifest keys are always strings (Vite's JSON output), but
            // PHPStan widens json_decode's return to array<array-key, mixed>.
            // Re-key into a string-keyed array so downstream types narrow
            // cleanly without inline @var hints.
            $narrowed = [];
            foreach ($decoded as $key => $val) {
                if (is_string($key)) {
                    $narrowed[$key] = $val;
                }
            }
            $this->manifests[$pluginId] = $narrowed;
            return $narrowed;
        }

        $this->logger->info('AssetService: no manifest for plugin', [
            'plugin_id' => $pluginId,
            'tried'     => $candidates,
        ]);
        $this->manifests[$pluginId] = false;
        return null;
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Theme;

use Opis\JsonSchema\Validator;
use Piwigo\Event\Theme\ThemeChanged;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Registry for Piwigo 17+ themes.
 *
 * Scans `themes/<id>/theme.json`, validates each manifest against
 * `docs/schemas/theme.schema.json` via opis/json-schema, builds the
 * parent chain (with cycle detection), and exposes a typed
 * activate/deactivate/install/uninstall/update API to admin code.
 *
 * Mirrors [[\Piwigo\Plugin\PluginRegistry]] structurally but with theme
 * specifics:
 *  - Inheritance is via a single `parent` field (no `require` graph).
 *  - Activation fires [[ThemeChanged]] so subscribers can react to the
 *    switch (e.g. clear a template cache).
 *  - `getResolutionChain(id)` exposes the parent walk so
 *    [[TemplateResolver]] can answer asset/template lookups without
 *    re-walking on every request.
 */
final class ThemeRegistry
{
    /** @var array<string, ThemeManifest> */
    private array $manifests = [];

    /** @var array<string, list<string>> Resolution chain by theme id (self first, then ancestors). */
    private array $resolutionChains = [];

    private bool $loaded = false;

    private readonly Validator $validator;

    /** @var object|null Decoded theme.schema.json, lazily loaded on first validate. */
    private ?object $schema = null;

    public function __construct(
        private readonly ThemeRepository $repository,
        private readonly LoggerInterface $logger,
        private readonly string $themesDir,
        private readonly string $schemaPath,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
        $this->validator = new Validator();
    }

    /**
     * Scan the themes directory, validate every theme.json, build each
     * theme's parent chain, and stage the resulting manifests.
     *
     * Re-callable: idempotent on the second invocation.
     */
    public function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->manifests = [];
        $this->resolutionChains = [];

        foreach ($this->scanThemeDirs() as $dir) {
            $themeId = basename($dir);
            $manifestPath = $dir . '/theme.json';
            if (!is_file($manifestPath)) {
                continue;
            }

            try {
                $manifest = $this->loadManifest($themeId, $manifestPath);
            } catch (ThemeValidationException $e) {
                $this->logger->warning('Skipping theme with invalid manifest', [
                    'theme_id' => $themeId,
                    'manifest' => $manifestPath,
                    'error'    => $e->getMessage(),
                ]);
                continue;
            }

            $this->manifests[$manifest->id] = $manifest;
        }

        // Build resolution chain per theme — parent walks happen here so
        // every later getResolutionChain() call is O(1).
        foreach (array_keys($this->manifests) as $id) {
            $this->resolutionChains[$id] = $this->buildResolutionChain($id);
        }
        $this->loaded = true;
    }

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    /**
     * Discard the cached scan and rebuild it on next access. Used by the
     * admin UX after extracting a freshly-uploaded theme.
     */
    public function reload(): void
    {
        $this->loaded = false;
        $this->load();
    }

    public function getManifest(string $themeId): ?ThemeManifest
    {
        $this->load();
        return $this->manifests[$themeId] ?? null;
    }

    /** @return array<string, ThemeManifest> */
    public function getAllManifests(): array
    {
        $this->load();
        return $this->manifests;
    }

    /**
     * Absolute filesystem path to a theme's directory, or null if the
     * theme id isn't known to the registry.
     */
    public function getPath(string $themeId): ?string
    {
        $this->load();
        if (!isset($this->manifests[$themeId])) {
            return null;
        }
        return rtrim($this->themesDir, '/') . '/' . $themeId;
    }

    /**
     * Return the resolution chain for a theme: the theme itself first,
     * then each ancestor in order. Empty list when the theme id is
     * unknown.
     *
     * @return list<string>
     */
    public function getResolutionChain(string $themeId): array
    {
        $this->load();
        return $this->resolutionChains[$themeId] ?? [];
    }

    /**
     * Install a theme: validate manifest + parent chain, insert the DB
     * row, then invoke the theme's install() hook. Idempotent if the
     * theme is already installed.
     */
    public function install(string $themeId): void
    {
        $manifest = $this->requireManifest($themeId);
        $this->assertParentSatisfied($manifest);

        $existing = $this->repository->findAll($themeId);
        if ($existing !== []) {
            return;
        }

        $this->repository->activate($manifest->id, $manifest->version, $manifest->name);
        $this->bootInstance($manifest)->install();
    }

    /**
     * Activate a theme: set the theme as active (the legacy
     * `themes` table tracks active themes as rows), fire ThemeChanged,
     * then invoke the theme's activate() hook. Idempotent.
     */
    public function activate(string $themeId, ?string $previousThemeId = null): void
    {
        $manifest = $this->requireManifest($themeId);
        $this->assertParentSatisfied($manifest);

        $rows = $this->repository->findAll($themeId);
        if ($rows === []) {
            // Legacy `themes` table treats presence == active; insert.
            $this->repository->activate($manifest->id, $manifest->version, $manifest->name);
        }

        if ($this->dispatcher !== null) {
            $this->dispatcher->dispatch(new ThemeChanged($manifest->id, $previousThemeId));
        }
        $this->bootInstance($manifest)->activate();
    }

    /**
     * Deactivate a theme: delete its row from the legacy `themes` table,
     * then invoke deactivate(). Idempotent if the theme isn't active.
     */
    public function deactivate(string $themeId): void
    {
        $manifest = $this->requireManifest($themeId);

        $rows = $this->repository->findAll($themeId);
        if ($rows === []) {
            return;
        }

        $this->repository->deactivate($themeId);
        $this->bootInstance($manifest)->deactivate();
    }

    /**
     * Uninstall a theme: invoke uninstall(), then delete the DB row.
     * Idempotent.
     */
    public function uninstall(string $themeId): void
    {
        $manifest = $this->requireManifest($themeId);

        $rows = $this->repository->findAll($themeId);
        if ($rows === []) {
            return;
        }

        $this->bootInstance($manifest)->uninstall();
        $this->repository->deactivate($themeId);
    }

    /**
     * Reconcile DB version with filesystem version: if they differ,
     * invoke the theme's update($old, $new) hook. The legacy `themes`
     * table stores only the active row's version; DDL doesn't expose
     * an UPDATE method, so update() runs without persisting the new
     * version. B17 collapses themes/users handling onto a versioned
     * ledger that this method will then bump.
     */
    public function update(string $themeId): void
    {
        $manifest = $this->requireManifest($themeId);

        $rows = $this->repository->findAll($themeId);
        if ($rows === []) {
            return;
        }
        $oldVersionRaw = $rows[0]['version'] ?? '';
        $oldVersion = is_string($oldVersionRaw) ? $oldVersionRaw : '';
        $newVersion = $manifest->version;
        if ($oldVersion === $newVersion) {
            return;
        }

        $this->bootInstance($manifest)->update($oldVersion, $newVersion);
    }

    /**
     * Decode + schema-validate the manifest file and return the typed
     * value object.
     */
    private function loadManifest(string $themeId, string $manifestPath): ThemeManifest
    {
        if (!is_readable($manifestPath)) {
            throw new ThemeValidationException($themeId, $manifestPath, 'cannot read theme.json');
        }
        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            throw new ThemeValidationException($themeId, $manifestPath, 'cannot read theme.json');
        }
        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ThemeValidationException($themeId, $manifestPath, 'theme.json is not valid JSON: ' . $e->getMessage(), $e);
        }
        if (!is_object($decoded)) {
            throw new ThemeValidationException($themeId, $manifestPath, 'theme.json top level must be an object');
        }

        $result = $this->validator->validate($decoded, $this->schema());
        if (!$result->isValid()) {
            $err = $result->error();
            $errMsg = $err === null ? 'schema validation failed' : ($err->message() . ' at /' . implode('/', $err->data()->path()));
            throw new ThemeValidationException($themeId, $manifestPath, $errMsg);
        }

        // Schema-validated: required fields are typed strings per the schema.
        /** @var \stdClass&object{id:string,name:string,version:string,main:string} $manifest */
        $manifest = $decoded;

        if ($manifest->id !== $themeId) {
            throw new ThemeValidationException(
                $themeId,
                $manifestPath,
                "manifest id '{$manifest->id}' does not match directory '{$themeId}'",
            );
        }

        $arrRaw = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($arrRaw)) {
            throw new ThemeValidationException($themeId, $manifestPath, 'theme.json must decode to an object');
        }
        $arr = [];
        foreach ($arrRaw as $key => $value) {
            if (is_string($key)) {
                $arr[$key] = $value;
            }
        }
        return ThemeManifest::fromArray($arr);
    }

    private function schema(): object
    {
        if ($this->schema !== null) {
            return $this->schema;
        }
        if (!is_readable($this->schemaPath)) {
            throw new \RuntimeException("Theme schema not found at {$this->schemaPath}");
        }
        $raw = file_get_contents($this->schemaPath);
        if ($raw === false) {
            throw new \RuntimeException("Theme schema not found at {$this->schemaPath}");
        }
        $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($decoded)) {
            throw new \RuntimeException('Theme schema must decode to an object');
        }
        return $this->schema = $decoded;
    }

    /**
     * Resolve a theme's parent chain starting from $themeId. Each entry
     * is the theme id, ancestors after self. Throws on cycle.
     *
     * @return list<string>
     */
    private function buildResolutionChain(string $themeId): array
    {
        $chain = [];
        $visited = [];
        $cursor = $themeId;
        while ($cursor !== null && $cursor !== '') {
            if (isset($visited[$cursor])) {
                throw new ThemeDependencyException(
                    $themeId,
                    "Theme parent chain forms a cycle involving '{$cursor}'.",
                );
            }
            $visited[$cursor] = true;
            $chain[] = $cursor;
            $manifest = $this->manifests[$cursor] ?? null;
            if ($manifest === null) {
                // Parent named but no manifest — record and stop so the
                // partial chain is still useful for diagnostics.
                break;
            }
            $cursor = $manifest->parent;
        }
        return $chain;
    }

    private function assertParentSatisfied(ThemeManifest $manifest): void
    {
        if ($manifest->parent === null || $manifest->parent === '') {
            return;
        }
        if (!isset($this->manifests[$manifest->parent])) {
            throw new ThemeDependencyException(
                $manifest->id,
                "Theme '{$manifest->id}' names parent '{$manifest->parent}' but no theme.json for it exists.",
            );
        }
    }

    private function requireManifest(string $themeId): ThemeManifest
    {
        $this->load();
        $manifest = $this->manifests[$themeId] ?? null;
        if ($manifest === null) {
            throw new ThemeValidationException(
                $themeId,
                rtrim($this->themesDir, '/') . "/{$themeId}/theme.json",
                "Theme '{$themeId}' has no validated manifest.",
            );
        }
        return $manifest;
    }

    /**
     * Instantiate the theme's main class. The class is expected to be
     * autoloaded by Composer's classmap or PSR-4 settings; ThemeRegistry
     * does not register per-theme PSR-4 loaders.
     */
    private function bootInstance(ThemeManifest $manifest): ThemeInterface
    {
        $class = $manifest->main;
        if (!class_exists($class)) {
            throw new ThemeValidationException(
                $manifest->id,
                rtrim($this->themesDir, '/') . "/{$manifest->id}/theme.json",
                "Theme main class '{$class}' does not exist.",
            );
        }
        // Theme main-class name comes from the manifest at runtime —
        // dispatching to it is exactly what the registry exists for, so
        // the project-wide noDynamicNew rule explicitly allows this.
        // @phpstan-ignore-next-line piwigo.noDynamicNew
        $instance = new $class();
        if (!$instance instanceof ThemeInterface) {
            throw new ThemeValidationException(
                $manifest->id,
                rtrim($this->themesDir, '/') . "/{$manifest->id}/theme.json",
                "Theme main class '{$class}' must implement ThemeInterface.",
            );
        }
        return $instance;
    }

    /** @return iterable<string> */
    private function scanThemeDirs(): iterable
    {
        if (!is_dir($this->themesDir)) {
            return;
        }
        $entries = scandir($this->themesDir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = rtrim($this->themesDir, '/') . '/' . $entry;
            if (is_dir($path)) {
                yield $path;
            }
        }
    }
}

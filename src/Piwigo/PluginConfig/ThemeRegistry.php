<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Composer\Autoload\ClassLoader;
use Composer\Semver\Semver;
use JsonException;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Paths;
use Piwigo\Core\ThemeRepository;
use RuntimeException;

/**
 * Registry for Piwigo 17+ themes. Cannot be adapted from
 * `../piwigo16-rewrite`'s own `Theme\ThemeRegistry` the same way
 * `PluginRegistry` was from its `Plugin\PluginRegistry` counterpart --
 * the reference genuinely has no request-time boot method at all
 * (see this class's own P27 plan section for the full discussion of
 * why "active" doesn't mean the same thing for themes it does for
 * plugins, and why `bootCurrent()` below is designed fresh, not ported).
 *
 * A `themes` row's mere existence already means active -- there is no
 * persisted installed-but-inactive state the way plugins have
 * (confirmed against `Admin\Extensions\ExtensionRepository`'s own real
 * docblock and `ExtensionLifecycle::performThemeAction()`'s real
 * dispatch, which only ever has `activate`/`deactivate` cases, never a
 * separate `install`/`uninstall`). `install()`/`uninstall()` below are
 * still real, separate hooks on the theme's own class (matching
 * `ExtensionInterface`'s uniform 5-hook contract) -- they just don't
 * carry their own DB write, since there's nothing to persist beyond
 * what `activate()`/`deactivate()` already do.
 *
 * No topological sort across every installed theme, unlike
 * `PluginRegistry`: many plugins boot simultaneously every request, but
 * exactly one theme (+ its parent chain) does, and that chain's own
 * order is already fully determined by walking `ThemeManifest::$parent`
 * pointers -- a general `require:` dependency graph across every
 * *other* installed theme has no bearing on boot order.
 */
final class ThemeRegistry
{
    /**
     * @var array<string, ThemeManifest>
     */
    private array $manifests = [];

    private bool $loaded = false;

    private readonly Validator $validator;

    private ?object $schema = null;

    private bool $autoloadRegistered = false;

    public function __construct(
        private readonly ThemeRepository $repository,
        private readonly EventDispatcher $eventDispatcher,
        private readonly ExtensionContextFactory $contextFactory,
        private readonly CurrentConfig $currentConfig,
        private readonly Paths $paths,
    ) {
        $this->validator = new Validator();
    }

    /**
     * Scans `themes/<id>/theme.json`, validates every manifest against
     * `docs/schemas/theme.schema.json`. Re-callable: idempotent on the
     * second invocation.
     */
    public function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->manifests = [];

        foreach ($this->scanThemeDirs() as $dir) {
            $themeId = basename($dir);
            $manifestPath = $dir . '/theme.json';
            if (! is_file($manifestPath)) {
                continue;
            }

            try {
                $manifest = $this->loadManifest($themeId, $manifestPath);
            } catch (ThemeValidationException) {
                continue;
            }

            $this->manifests[$manifest->id] = $manifest;
        }

        $this->registerAutoload();
        $this->loaded = true;
    }

    /**
     * $autoloadRegistered also has to reset here, not just $loaded --
     * same real bug PluginRegistry::reload()'s own docblock documents
     * (found live via the identical scenario, 2 theme fixtures written
     * in the same request with a reload() between them): registerAutoload()
     * has its own separate one-time guard, so without this, a theme
     * whose manifest is only discovered by a POST-first-load() reload()
     * never gets its own PSR-4 mapping registered at all.
     */
    public function reload(): void
    {
        $this->loaded = false;
        $this->autoloadRegistered = false;
        $this->load();
    }

    public function getManifest(string $themeId): ?ThemeManifest
    {
        $this->load();

        return $this->manifests[$themeId] ?? null;
    }

    /**
     * @return array<string, ThemeManifest>
     */
    public function getAllManifests(): array
    {
        $this->load();

        return $this->manifests;
    }

    public function isInstalled(string $themeId): bool
    {
        $id = ThemeId::tryFrom($themeId);

        return $id instanceof ThemeId && $this->repository->find($id) !== null;
    }

    /**
     * Runs the theme's own `install()` hook. No DB write of its own --
     * see this class's own docblock for why there's nothing separate to
     * persist for themes.
     */
    public function install(string $themeId): void
    {
        $manifest = $this->requireManifest($themeId);
        $instance = $this->bootInstance($manifest);
        $this->assertSettingsContractSatisfied($manifest, $instance);
        $instance->install();
    }

    /**
     * Activates a theme: check dependencies, invoke `activate()`, then
     * insert the `themes` row if not already present. Idempotent if
     * already active. `bootInstance()` (validates the manifest's own
     * `main` class) and the hook itself both run BEFORE the DB write --
     * same reasoning as PluginRegistry::install()'s own docblock: the
     * reverse order would leave a permanent "ghost" row for a theme
     * whose main class is broken, since a later activate() call would
     * see isInstalled()===true and never retry bootInstance().
     */
    public function activate(string $themeId): void
    {
        $manifest = $this->requireManifest($themeId);
        $this->assertDependenciesSatisfied($manifest);

        $instance = $this->bootInstance($manifest);
        $this->assertSettingsContractSatisfied($manifest, $instance);
        $instance->activate();

        if (! $this->isInstalled($themeId)) {
            $this->repository->insert(ThemeId::from($themeId), $manifest->version, $manifest->name);
        }
    }

    /**
     * Runs the theme's own `deactivate()` hook, then deletes the
     * `themes` row. Idempotent if already inactive.
     */
    public function deactivate(string $themeId): void
    {
        $manifest = $this->requireManifest($themeId);
        $this->assertNoActiveDependents($themeId);

        if (! $this->isInstalled($themeId)) {
            return;
        }

        $this->bootInstance($manifest)
            ->deactivate();
        $this->repository->delete(ThemeId::from($themeId));
    }

    /**
     * Runs the theme's own `uninstall()` hook. No DB write of its own
     * -- `deactivate()` already removed the row.
     */
    public function uninstall(string $themeId): void
    {
        $manifest = $this->requireManifest($themeId);
        $this->bootInstance($manifest)
            ->uninstall();
    }

    /**
     * Reconciles DB version with the manifest's filesystem version:
     * invokes `update($old, $new)` and bumps the DB row when they
     * differ. Idempotent when versions already match.
     */
    public function update(string $themeId): void
    {
        $manifest = $this->requireManifest($themeId);
        $id = ThemeId::tryFrom($themeId);
        if (! $id instanceof ThemeId) {
            return;
        }
        $entity = $this->repository->find($id);
        if ($entity === null) {
            return;
        }
        $oldVersion = $entity->version;
        $newVersion = $manifest->version;
        if ($oldVersion === $newVersion) {
            return;
        }

        $this->bootInstance($manifest)
            ->update($oldVersion, $newVersion);
        $this->repository->updateVersion($id, $newVersion);
    }

    /**
     * Boots the current request's theme + its full parent-resolution
     * chain -- called once per request with whichever theme
     * `Bootstrap\RequestBootstrap` already resolved, right
     * after picking it and before constructing `Template`.
     *
     * Chain order is furthest ancestor first, self last, for both
     * registration and boot -- the reverse of the natural-seeming
     * "self first, then walk up" order that matches CSS/asset-file
     * lookup. `dispatch()` is a pipeline (each handler runs against the
     * same event instance, mutating whatever the previous handler already
     * changed), so the *last* handler to run has final say --
     * registering the child before the parent would give the parent's
     * handler the last word over the child's.
     * See this class's own P27 plan section for the full reasoning.
     *
     * Two-pass construct-once/reuse, same shape as
     * `PluginRegistry::bootActive()` and for the identical two reasons
     * (a theme dispatching its own custom event from `boot()` needs
     * every chain member's `subscribedEvents()` already registered;
     * `bootInstance()` has zero caching, so registering and booting
     * against two separately-constructed instances would make anything
     * `boot()` sets on `$this` invisible to the handlers).
     */
    public function bootCurrent(ThemeId $themeId): void
    {
        /** @var array<string, ExtensionInterface> $instances */
        $instances = [];
        foreach ($this->resolveParentChain($themeId->value) as $id) {
            $manifest = $this->getManifest($id);
            if (! $manifest instanceof ThemeManifest) {
                continue;
            }
            $instance = $this->bootInstance($manifest);
            $instances[$id] = $instance;

            $this->eventDispatcher->registerSubscriber($instance);
        }

        foreach ($instances as $id => $instance) {
            $instance->boot($this->contextFactory->build(ThemeId::from($id)));
        }
    }

    /**
     * Constructs and `boot()`s a single theme for
     * `Controller\Admin\ThemeSubController`'s own settings-page dispatch
     * -- a page-scoped, throwaway boot, deliberately NOT
     * `bootCurrent()`: an admin can open *any* installed theme's
     * settings page (configuring `modus` while the live site itself
     * runs `elegant`), not just the current request's own theme + parent
     * chain `bootCurrent()` is scoped to. Doesn't register
     * `subscribedEvents()` against the live dispatcher and doesn't touch
     * `bootCurrent()`'s own cache -- this instance exists only to run
     * `handleSettingsRequest()` on, once, for this one request.
     */
    public function bootForSettingsPage(string $themeId): ExtensionInterface
    {
        $manifest = $this->requireManifest($themeId);
        $instance = $this->bootInstance($manifest);
        $instance->boot($this->contextFactory->build(ThemeId::from($themeId)));

        return $instance;
    }

    /**
     * Furthest ancestor first, self last. Cycle-safe: stops the moment
     * an id already seen in this walk would repeat. A missing/
     * unmanifested ancestor stops the walk there too -- the
     * already-collected chain below it still boots, rather than
     * crashing the whole request over one broken ancestor.
     *
     * @return list<string>
     */
    private function resolveParentChain(string $themeId): array
    {
        $chain = [];
        $seen = [];
        $current = $themeId;
        while ($current !== null && ! isset($seen[$current])) {
            $seen[$current] = true;
            array_unshift($chain, $current);
            $manifest = $this->getManifest($current);
            $current = $manifest?->parent;
        }

        return $chain;
    }

    /**
     * `require:` constraints against Piwigo core + other themes.
     * Cross-registry `plugin/<id>` dependencies aren't resolvable here
     * (would need real coupling to `PluginRegistry`) -- deliberately
     * not built: zero bundled themes declare any `require:` entry at
     * all yet, so this would be
     * speculative-ahead-of-a-real-caller infrastructure. Add real
     * cross-registry resolution only once a real theme needs it.
     */
    private function assertDependenciesSatisfied(ThemeManifest $manifest): void
    {
        foreach ($manifest->require as $depName => $constraint) {
            $depVersion = match (true) {
                $depName === 'piwigo' => AppInfo::VERSION,
                str_starts_with($depName, 'theme/') => $this->resolveDependencyVersion(substr($depName, 6)),
                default => null,
            };

            if ($depVersion === null) {
                throw new ThemeDependencyException(
                    $manifest->id,
                    "Theme '{$manifest->id}' requires '{$depName}' (constraint: {$constraint}) but it is not active.",
                );
            }
            if (! Semver::satisfies($depVersion, $constraint)) {
                throw new ThemeDependencyException(
                    $manifest->id,
                    "Theme '{$manifest->id}' requires '{$depName}' {$constraint} but only {$depVersion} is available.",
                );
            }
        }

        if (! Semver::satisfies(AppInfo::VERSION, '>=' . $manifest->minPiwigo)) {
            throw new ThemeDependencyException(
                $manifest->id,
                "Theme '{$manifest->id}' requires Piwigo >= {$manifest->minPiwigo}; running " . AppInfo::VERSION,
            );
        }
    }

    private function resolveDependencyVersion(string $themeId): ?string
    {
        $manifest = $this->manifests[$themeId] ?? null;
        if ($manifest === null) {
            return null;
        }
        if (! $this->isInstalled($themeId)) {
            return null;
        }

        return $manifest->version;
    }

    /**
     * `deactivate()`'s reverse-dependency guard -- refuses if any other
     * installed theme's manifest still declares `require:
     * {"theme/<this-id>": ...}`. Same shape as `PluginRegistry`'s own
     * guard; deliberately separate from `ExtensionLifecycle`'s existing
     * "last theme"/"child theme" guards, which stay in
     * `ExtensionLifecycle` since they encode different rules
     * (parent/child inheritance, not `require:` dependencies).
     */
    private function assertNoActiveDependents(string $themeId): void
    {
        $this->load();
        $dependents = [];
        foreach ($this->manifests as $candidateId => $candidate) {
            if ($candidateId === $themeId) {
                continue;
            }
            if (! $this->isInstalled($candidateId)) {
                continue;
            }
            if (array_key_exists('theme/' . $themeId, $candidate->require)) {
                $dependents[] = $candidateId;
            }
        }

        if ($dependents !== []) {
            throw new ThemeDependencyException(
                $themeId,
                "Theme '{$themeId}' cannot be removed: still required by " . implode(', ', $dependents) . '.',
            );
        }
    }

    /**
     * `install()`/`activate()`'s own manifest-authoring check --
     * same reasoning as `PluginRegistry`'s own identically-named method.
     */
    private function assertSettingsContractSatisfied(ThemeManifest $manifest, ExtensionInterface $instance): void
    {
        if ($manifest->hasSettings !== false && ! $instance instanceof SettingsPageInterface) {
            throw new ThemeValidationException(
                $manifest->id,
                rtrim($this->currentConfig->themesPath, '/') . "/{$manifest->id}/theme.json",
                "Theme '{$manifest->id}' declares hasSettings but its main class does not implement SettingsPageInterface.",
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
                rtrim($this->currentConfig->themesPath, '/') . "/{$themeId}/theme.json",
                "Theme '{$themeId}' has no validated manifest.",
            );
        }

        return $manifest;
    }

    private function bootInstance(ThemeManifest $manifest): ExtensionInterface
    {
        $class = $manifest->main;
        if (! class_exists($class)) {
            throw new ThemeValidationException(
                $manifest->id,
                rtrim($this->currentConfig->themesPath, '/') . "/{$manifest->id}/theme.json",
                "Theme main class '{$class}' does not exist (autoload missing).",
            );
        }

        $instance = new $class();
        if (! $instance instanceof ExtensionInterface) {
            throw new ThemeValidationException(
                $manifest->id,
                rtrim($this->currentConfig->themesPath, '/') . "/{$manifest->id}/theme.json",
                "Theme main class '{$class}' must implement ExtensionInterface.",
            );
        }

        return $instance;
    }

    private function registerAutoload(): void
    {
        if ($this->autoloadRegistered) {
            return;
        }

        $loader = new ClassLoader();
        foreach ($this->manifests as $id => $manifest) {
            $baseDir = rtrim($this->currentConfig->themesPath, '/') . '/' . $id . '/';
            foreach ($manifest->autoloadPsr4 as $prefix => $relativeDir) {
                $loader->addPsr4($prefix, $baseDir . ltrim($relativeDir, '/'));
            }
        }
        $loader->register();
        $this->autoloadRegistered = true;
    }

    private function loadManifest(string $themeId, string $manifestPath): ThemeManifest
    {
        if (! is_readable($manifestPath)) {
            throw new ThemeValidationException($themeId, $manifestPath, 'cannot read theme.json');
        }
        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            throw new ThemeValidationException($themeId, $manifestPath, 'cannot read theme.json');
        }

        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ThemeValidationException($themeId, $manifestPath, 'theme.json is not valid JSON: ' . $e->getMessage(), $e);
        }
        if (! is_object($decoded)) {
            throw new ThemeValidationException($themeId, $manifestPath, 'theme.json top level must be an object');
        }

        $result = $this->validator->validate($decoded, $this->schema());
        if (! $result->isValid()) {
            $err = $result->error();
            $errMsg = $err instanceof ValidationError ? $err->message() . ' at /' . implode('/', $err->data()->path()) : ('schema validation failed');
            throw new ThemeValidationException($themeId, $manifestPath, $errMsg);
        }

        $id = property_exists($decoded, 'id') && is_string($decoded->id) ? $decoded->id : null;
        if ($id !== $themeId) {
            throw new ThemeValidationException(
                $themeId,
                $manifestPath,
                "manifest id '" . ($id ?? '?') . "' does not match directory '{$themeId}'",
            );
        }

        try {
            /** @var array<string, mixed> $arr */
            $arr = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ThemeValidationException($themeId, $manifestPath, 'theme.json is not valid JSON: ' . $e->getMessage(), $e);
        }

        return ThemeManifest::fromArray($arr);
    }

    private function schema(): object
    {
        if ($this->schema !== null) {
            return $this->schema;
        }

        $schemaPath = rtrim($this->paths->root, '/') . '/docs/schemas/theme.schema.json';
        if (! is_readable($schemaPath)) {
            throw new RuntimeException("Theme schema not found at {$schemaPath}");
        }
        $raw = file_get_contents($schemaPath);
        if ($raw === false) {
            throw new RuntimeException("Theme schema not found at {$schemaPath}");
        }

        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Theme schema is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (! is_object($decoded)) {
            throw new RuntimeException('Theme schema must decode to an object');
        }

        return $this->schema = $decoded;
    }

    /**
     * @return iterable<string>
     */
    private function scanThemeDirs(): iterable
    {
        $themesDir = rtrim($this->currentConfig->themesPath, '/');
        if (! is_dir($themesDir)) {
            return;
        }
        $entries = scandir($themesDir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $themesDir . '/' . $entry;
            if (is_dir($path)) {
                yield $path;
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Composer\Autoload\ClassLoader;
use Composer\Semver\Semver;
use JsonException;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use Piwigo\Common\ValueObject\PluginId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Env;
use Piwigo\Core\Paths;
use Piwigo\Event\Lifecycle\PluginsLoaded;
use RuntimeException;

/**
 * Registry for Piwigo 17+ plugins -- replaces `Admin\PluginLoader`'s
 * DB-query-and-raw-`include_once` per-request loop (P27.4 retargets the
 * actual call site).
 *
 * Adapted, not copied, from `../piwigo16-rewrite`'s own `Plugin\
 * PluginRegistry` -- see this fork's own P27 plan for the full
 * reference-verdict discussion and every correction made here.
 */
final class PluginRegistry
{
    /**
     * @var array<string, PluginManifest>
     */
    private array $manifests = [];

    /**
     * @var list<string> Topologically-sorted load order: dependencies precede dependents.
     */
    private array $loadOrder = [];

    private bool $loaded = false;

    private readonly Validator $validator;

    private ?object $schema = null;

    private bool $autoloadRegistered = false;

    public function __construct(
        private readonly PluginRepository $repository,
        private readonly PluginMigrationRepository $migrationRepository,
        private readonly EventDispatcher $eventDispatcher,
        private readonly ExtensionContextFactory $contextFactory,
        private readonly CurrentConfig $currentConfig,
        private readonly Paths $paths,
    ) {
        $this->validator = new Validator();
    }

    /**
     * Scans `plugins/<id>/plugin.json`, validates every manifest against
     * `docs/schemas/plugin.schema.json`, and builds the dependency-sorted
     * load order. Re-callable: idempotent on the second invocation.
     */
    public function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->manifests = [];
        $this->loadOrder = [];

        foreach ($this->scanPluginDirs() as $dir) {
            $pluginId = basename($dir);
            $manifestPath = $dir . '/plugin.json';
            if (! is_file($manifestPath)) {
                continue;
            }

            try {
                $manifest = $this->loadManifest($pluginId, $manifestPath);
            } catch (PluginValidationException) {
                continue;
            }

            $this->manifests[$manifest->id] = $manifest;
        }

        $this->loadOrder = $this->topologicalSort($this->manifests);
        $this->registerAutoload();
        $this->loaded = true;
    }

    /**
     * $autoloadRegistered also has to reset here, not just $loaded --
     * found live via a real Integration test writing 2 plugin fixtures
     * in the same request and reload()ing between them: registerAutoload()
     * has its own separate one-time guard, so without this, a plugin
     * whose manifest is only discovered by a POST-first-load() reload()
     * never gets its own PSR-4 mapping registered at all, and
     * bootInstance() fails with "class does not exist" even though a
     * real, valid manifest is genuinely on disk.
     */
    public function reload(): void
    {
        $this->loaded = false;
        $this->autoloadRegistered = false;
        $this->load();
    }

    public function getManifest(string $pluginId): ?PluginManifest
    {
        $this->load();

        return $this->manifests[$pluginId] ?? null;
    }

    /**
     * @return array<string, PluginManifest>
     */
    public function getAllManifests(): array
    {
        $this->load();

        return $this->manifests;
    }

    /**
     * @return list<string>
     */
    public function getLoadOrder(): array
    {
        $this->load();

        return $this->loadOrder;
    }

    /**
     * True when the plugin id has `state=active` in the DB. Cheap
     * repository lookup -- does NOT instantiate the plugin's main class.
     */
    public function isActive(string $pluginId): bool
    {
        return $this->repository->getDbPlugins('active', $pluginId) !== [];
    }

    /**
     * @return list<string>
     */
    public function getActiveIds(): array
    {
        $ids = [];
        foreach ($this->repository->getDbPlugins('active') as $row) {
            $ids[] = $row->id->value;
        }

        return $ids;
    }

    /**
     * Install a plugin: check dependencies, invoke the plugin's
     * `install()` hook, then insert the DB row (state=inactive) and
     * record the migration ledger entry. Idempotent if already
     * installed. `bootInstance()` (which validates the manifest's own
     * `main` class exists and implements ExtensionInterface) and the
     * hook itself both run BEFORE any DB write -- deliberately, found
     * live while writing a real "install fails" test: the reverse order
     * would leave a permanent, unremovable "ghost" row for a plugin
     * whose main class is simply broken (a real, plausible authoring
     * mistake), since a later install() call short-circuits on "already
     * installed" without ever retrying bootInstance().
     */
    public function install(string $pluginId): void
    {
        $manifest = $this->requireManifest($pluginId);
        $this->assertDependenciesSatisfied($manifest);

        if ($this->repository->getDbPlugins('', $pluginId) !== []) {
            return;
        }

        $instance = $this->bootInstance($manifest);
        $instance->install();

        $id = PluginId::from($pluginId);
        $this->repository->insert($id, $manifest->version);
        $this->migrationRepository->record($id, $manifest->version, Env::now()->format('Y-m-d H:i:s'));
    }

    /**
     * Activate an installed plugin: set state=active, then invoke
     * `activate()`. Idempotent. Same validate/hook-before-persist
     * ordering as install() above, for the same reason.
     */
    public function activate(string $pluginId): void
    {
        $manifest = $this->requireManifest($pluginId);
        $this->assertDependenciesSatisfied($manifest);

        $rows = $this->repository->getDbPlugins('', $pluginId);
        if ($rows === []) {
            throw new PluginDependencyException(
                $pluginId,
                "Plugin '{$pluginId}' must be installed before activation.",
            );
        }
        if ($rows[0]->state === PluginState::Active->value) {
            return;
        }

        $instance = $this->bootInstance($manifest);
        $instance->activate();

        $this->repository->updateState(PluginId::from($pluginId), PluginState::Active);
    }

    /**
     * Deactivate a plugin: refuse if any other active plugin still
     * requires it, otherwise set state=inactive and invoke
     * `deactivate()`. Idempotent if already inactive. Same
     * validate/hook-before-persist ordering as install() above.
     */
    public function deactivate(string $pluginId): void
    {
        $manifest = $this->requireManifest($pluginId);
        $this->assertNoActiveDependents($pluginId);

        $rows = $this->repository->getDbPlugins('', $pluginId);
        if ($rows === [] || $rows[0]->state !== PluginState::Active->value) {
            return;
        }

        $instance = $this->bootInstance($manifest);
        $instance->deactivate();

        $this->repository->updateState(PluginId::from($pluginId), PluginState::Inactive);
    }

    /**
     * Uninstall a plugin: refuse if any other installed plugin still
     * requires it, otherwise invoke `uninstall()` then delete the DB
     * row. The plugin's directory is left in place -- filesystem
     * removal is a separate admin-UX concern.
     */
    public function uninstall(string $pluginId): void
    {
        $manifest = $this->requireManifest($pluginId);
        $this->assertNoActiveDependents($pluginId);

        if ($this->repository->getDbPlugins('', $pluginId) === []) {
            return;
        }

        $this->bootInstance($manifest)
            ->uninstall();
        $this->repository->delete(PluginId::from($pluginId));
    }

    /**
     * Reconcile DB version with the manifest's filesystem version: if
     * they differ, invoke `update($old, $new)`, then record the
     * migration ledger entry and bump the DB row. Idempotent when
     * versions already match. Same validate/hook-before-persist
     * ordering as install() above -- a failing update() hook no longer
     * leaves a stale ledger entry recorded against a version bump that
     * never actually happened.
     */
    public function update(string $pluginId): void
    {
        $manifest = $this->requireManifest($pluginId);

        $rows = $this->repository->getDbPlugins('', $pluginId);
        if ($rows === []) {
            return;
        }
        $oldVersion = $rows[0]->version;
        $newVersion = $manifest->version;
        if ($oldVersion === $newVersion) {
            return;
        }

        $instance = $this->bootInstance($manifest);
        $instance->update($oldVersion, $newVersion);

        $id = PluginId::from($pluginId);
        $this->migrationRepository->record($id, $newVersion, Env::now()->format('Y-m-d H:i:s'));
        $this->repository->updateVersion($pluginId, $newVersion);
    }

    /**
     * Boots every active plugin in load order -- the per-request entry
     * point replacing `Admin\PluginLoader::loadPlugins()`. A no-op when
     * `CurrentConfig::$enablePlugins` is off, matching
     * `loadPlugins()`'s own exact guard.
     *
     * Two-pass, not a single interleaved loop -- see this class's own
     * P27 plan section for the two independent reasons (dispatch
     * ordering + instance identity) found by tracing actual execution
     * rather than trusting the reference's single-loop shape.
     */
    public function bootActive(): void
    {
        if (! $this->currentConfig->enablePlugins) {
            return;
        }

        /** @var array<string, ExtensionInterface> $instances */
        $instances = [];
        foreach ($this->getLoadOrder() as $pluginId) {
            if (! $this->isActive($pluginId)) {
                continue;
            }
            $manifest = $this->requireManifest($pluginId);
            $instance = $this->bootInstance($manifest);
            $instances[$pluginId] = $instance;

            foreach ($instance->subscribedEvents() as $eventClass => $handlers) {
                foreach (is_array($handlers) ? $handlers : [$handlers] as $handler) {
                    $this->eventDispatcher->addTypedHandler($eventClass, $handler);
                }
            }
        }

        foreach ($instances as $pluginId => $instance) {
            $instance->boot($this->contextFactory->build(PluginId::from($pluginId)));
        }

        $this->eventDispatcher->dispatchNotify(new PluginsLoaded());
    }

    /**
     * `require:` constraints against Piwigo core + other plugins.
     * Throws on the first unsatisfied dependency.
     */
    private function assertDependenciesSatisfied(PluginManifest $manifest): void
    {
        foreach ($manifest->require as $depName => $constraint) {
            $depVersion = match (true) {
                $depName === 'piwigo' => AppInfo::VERSION,
                str_starts_with($depName, 'plugin/') => $this->resolveDependencyVersion(substr($depName, 7)),
                default => null,
            };

            if ($depVersion === null) {
                throw new PluginDependencyException(
                    $manifest->id,
                    "Plugin '{$manifest->id}' requires '{$depName}' (constraint: {$constraint}) but it is not active.",
                );
            }
            if (! Semver::satisfies($depVersion, $constraint)) {
                throw new PluginDependencyException(
                    $manifest->id,
                    "Plugin '{$manifest->id}' requires '{$depName}' {$constraint} but only {$depVersion} is available.",
                );
            }
        }

        if (! Semver::satisfies(AppInfo::VERSION, '>=' . $manifest->minPiwigo)) {
            throw new PluginDependencyException(
                $manifest->id,
                "Plugin '{$manifest->id}' requires Piwigo >= {$manifest->minPiwigo}; running " . AppInfo::VERSION,
            );
        }
    }

    /**
     * A dependency has to be *active*, not merely present on disk -- the
     * reference's own equivalent reads only the filesystem-scan result,
     * which would let a plugin's dependency gate pass the moment the
     * dependency's files exist, even if it was never installed or was
     * later deactivated. Real fix, found by tracing actual execution
     * rather than trusting the reference's shape.
     */
    private function resolveDependencyVersion(string $pluginId): ?string
    {
        $manifest = $this->manifests[$pluginId] ?? null;
        if ($manifest === null) {
            return null;
        }
        if (! $this->isActive($pluginId)) {
            return null;
        }

        return $manifest->version;
    }

    /**
     * `deactivate()`/`uninstall()`'s reverse-dependency guard -- refuses
     * if any other active plugin's manifest still declares `require:
     * {"plugin/<this-id>": ...}`. The reference has no equivalent of
     * this at all; this codebase already has the exact right guard
     * shape for themes (`ExtensionLifecycle`'s child-theme check),
     * applied here to the plugin `require:` graph for the first time.
     */
    private function assertNoActiveDependents(string $pluginId): void
    {
        $this->load();
        $dependents = [];
        foreach ($this->manifests as $candidateId => $candidate) {
            if ($candidateId === $pluginId) {
                continue;
            }
            if (! $this->isActive($candidateId)) {
                continue;
            }
            if (array_key_exists('plugin/' . $pluginId, $candidate->require)) {
                $dependents[] = $candidateId;
            }
        }

        if ($dependents !== []) {
            throw new PluginDependencyException(
                $pluginId,
                "Plugin '{$pluginId}' cannot be removed: still required by " . implode(', ', $dependents) . '.',
            );
        }
    }

    /**
     * Kahn's algorithm -- emits each plugin id only after all of its
     * `require: { plugin/... }` dependencies have been emitted. Detects
     * cycles by checking whether any nodes remain after the work queue
     * drains.
     *
     * @param array<string, PluginManifest> $manifests
     * @return list<string>
     */
    private function topologicalSort(array $manifests): array
    {
        /** @var array<string, list<string>> $deps */
        $deps = [];
        /** @var array<string, int> $inDegree */
        $inDegree = [];
        foreach ($manifests as $id => $manifest) {
            $inDegree[$id] = 0;
            $deps[$id] = [];
        }
        foreach ($manifests as $id => $manifest) {
            foreach (array_keys($manifest->require) as $depName) {
                if (str_starts_with($depName, 'plugin/')) {
                    $depId = substr($depName, 7);
                    if (isset($manifests[$depId])) {
                        $deps[$id][] = $depId;
                        $inDegree[$id]++;
                    }
                }
            }
        }

        $queue = [];
        foreach ($inDegree as $id => $degree) {
            if ($degree === 0) {
                $queue[] = $id;
            }
        }

        $sorted = [];
        while ($queue !== []) {
            $id = array_shift($queue);
            $sorted[] = $id;
            foreach ($manifests as $candidate => $candidateManifest) {
                if (in_array($id, $deps[$candidate], true)) {
                    $inDegree[$candidate]--;
                    if ($inDegree[$candidate] === 0) {
                        $queue[] = $candidate;
                    }
                }
            }
        }

        if (count($sorted) !== count($manifests)) {
            $cycle = array_diff(array_keys($manifests), $sorted);
            throw new PluginDependencyException(
                array_first($cycle) ?? '',
                'Plugin dependency cycle detected involving: ' . implode(', ', $cycle),
            );
        }

        return $sorted;
    }

    private function requireManifest(string $pluginId): PluginManifest
    {
        $this->load();
        $manifest = $this->manifests[$pluginId] ?? null;
        if ($manifest === null) {
            throw new PluginValidationException(
                $pluginId,
                rtrim($this->paths->plugins, '/') . "/{$pluginId}/plugin.json",
                "Plugin '{$pluginId}' has no validated manifest.",
            );
        }

        return $manifest;
    }

    /**
     * Instantiates the plugin's main class -- expected to be reachable
     * via `registerAutoload()`'s runtime PSR-4 registration (every
     * scanned manifest, not just active ones, see this class's own P27
     * plan section for why).
     */
    private function bootInstance(PluginManifest $manifest): ExtensionInterface
    {
        $class = $manifest->main;
        if (! class_exists($class)) {
            throw new PluginValidationException(
                $manifest->id,
                rtrim($this->paths->plugins, '/') . "/{$manifest->id}/plugin.json",
                "Plugin main class '{$class}' does not exist (autoload missing).",
            );
        }

        $instance = new $class();
        if (! $instance instanceof ExtensionInterface) {
            throw new PluginValidationException(
                $manifest->id,
                rtrim($this->paths->plugins, '/') . "/{$manifest->id}/plugin.json",
                "Plugin main class '{$class}' must implement ExtensionInterface.",
            );
        }

        return $instance;
    }

    /**
     * `addPsr4()`s every scanned manifest's `autoloadPsr4` map onto a
     * runtime `ClassLoader`, `register()`ed once. Every scanned
     * manifest, not just active ones: `install()` calls
     * `bootInstance($manifest)->install()` on a plugin whose DB state is
     * Inactive by construction (`activate()` is what sets it active),
     * so a not-yet-active plugin's main class still needs to resolve at
     * `install()` time.
     */
    private function registerAutoload(): void
    {
        if ($this->autoloadRegistered) {
            return;
        }

        $loader = new ClassLoader();
        foreach ($this->manifests as $id => $manifest) {
            $baseDir = rtrim($this->paths->plugins, '/') . '/' . $id . '/';
            foreach ($manifest->autoloadPsr4 as $prefix => $relativeDir) {
                $loader->addPsr4($prefix, $baseDir . ltrim($relativeDir, '/'));
            }
        }
        $loader->register();
        $this->autoloadRegistered = true;
    }

    private function loadManifest(string $pluginId, string $manifestPath): PluginManifest
    {
        if (! is_readable($manifestPath)) {
            throw new PluginValidationException($pluginId, $manifestPath, 'cannot read plugin.json');
        }
        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            throw new PluginValidationException($pluginId, $manifestPath, 'cannot read plugin.json');
        }

        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PluginValidationException($pluginId, $manifestPath, 'plugin.json is not valid JSON: ' . $e->getMessage(), $e);
        }
        if (! is_object($decoded)) {
            throw new PluginValidationException($pluginId, $manifestPath, 'plugin.json top level must be an object');
        }

        $result = $this->validator->validate($decoded, $this->schema());
        if (! $result->isValid()) {
            $err = $result->error();
            $errMsg = $err instanceof ValidationError ? $err->message() . ' at /' . implode('/', $err->data()->path()) : ('schema validation failed');
            throw new PluginValidationException($pluginId, $manifestPath, $errMsg);
        }

        $id = property_exists($decoded, 'id') && is_string($decoded->id) ? $decoded->id : null;
        if ($id !== $pluginId) {
            throw new PluginValidationException(
                $pluginId,
                $manifestPath,
                "manifest id '" . ($id ?? '?') . "' does not match directory '{$pluginId}'",
            );
        }

        try {
            /** @var array<string, mixed> $arr */
            $arr = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PluginValidationException($pluginId, $manifestPath, 'plugin.json is not valid JSON: ' . $e->getMessage(), $e);
        }

        return PluginManifest::fromArray($arr);
    }

    private function schema(): object
    {
        if ($this->schema !== null) {
            return $this->schema;
        }

        $schemaPath = rtrim($this->paths->root, '/') . '/docs/schemas/plugin.schema.json';
        if (! is_readable($schemaPath)) {
            throw new RuntimeException("Plugin schema not found at {$schemaPath}");
        }
        $raw = file_get_contents($schemaPath);
        if ($raw === false) {
            throw new RuntimeException("Plugin schema not found at {$schemaPath}");
        }

        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Plugin schema is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (! is_object($decoded)) {
            throw new RuntimeException('Plugin schema must decode to an object');
        }

        return $this->schema = $decoded;
    }

    /**
     * @return iterable<string>
     */
    private function scanPluginDirs(): iterable
    {
        $pluginsDir = rtrim($this->paths->plugins, '/');
        if (! is_dir($pluginsDir)) {
            return;
        }
        $entries = scandir($pluginsDir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $pluginsDir . '/' . $entry;
            if (is_dir($path)) {
                yield $path;
            }
        }
    }
}

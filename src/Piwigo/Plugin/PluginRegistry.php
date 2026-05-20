<?php

declare(strict_types=1);

namespace Piwigo\Plugin;

use Composer\Semver\Semver;
use Opis\JsonSchema\Validator;
use Piwigo\Core\AppInfo;
use Piwigo\Event\Lifecycle\PluginsLoaded;
use Piwigo\Lang\LangService;
use Piwigo\Plugin\Migration\PluginMigrationRunner;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcher;

/**
 * Registry for Piwigo 17+ plugins.
 *
 * Scans `plugins/<id>/plugin.json`, validates each manifest against
 * `docs/schemas/plugin.schema.json` via opis/json-schema, resolves the
 * dependency graph using composer/semver, and exposes a typed
 * activate/deactivate/install/uninstall/update API to admin code.
 * `bootActive()` is the per-request entry point that walks the
 * dependency-sorted active plugins, calls each PluginInterface::boot,
 * and registers their subscribedEvents() with the Symfony dispatcher.
 */
final class PluginRegistry
{
    /** @var array<string, PluginManifest> */
    private array $manifests = [];

    /** @var array<string, string> Topologically-sorted load order: id => id (preserves array_keys order). */
    private array $loadOrder = [];

    private bool $loaded = false;

    private readonly Validator $validator;

    /** @var object|null Decoded plugin.schema.json, lazily loaded on first validate. */
    private ?object $schema = null;

    public function __construct(
        private readonly PluginRepository $repository,
        private readonly LoggerInterface $logger,
        private readonly string $pluginsDir,
        private readonly string $schemaPath,
        private readonly ?PluginMigrationRunner $migrationRunner = null,
    ) {
        $this->validator = new Validator();
    }

    /**
     * Scan the plugins directory, validate every plugin.json, build the
     * dependency graph, and stage the resulting manifests.
     *
     * Re-callable: idempotent on the second invocation.
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
            if (!is_file($manifestPath)) {
                continue;
            }

            try {
                $manifest = $this->loadManifest($pluginId, $manifestPath);
            } catch (PluginValidationException $e) {
                $this->logger->warning('Skipping plugin with invalid manifest', [
                    'plugin_id' => $pluginId,
                    'manifest'  => $manifestPath,
                    'error'     => $e->getMessage(),
                ]);
                continue;
            }

            $this->manifests[$manifest->id] = $manifest;
        }

        $this->loadOrder = $this->topologicalSort($this->manifests);
        $this->loaded = true;
    }

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    /**
     * Discard the cached scan and rebuild it on next access. Used by the
     * admin UX after extracting a freshly-uploaded plugin so the new
     * plugin.json is validated immediately.
     */
    public function reload(): void
    {
        $this->loaded = false;
        $this->load();
    }

    /**
     * Absolute filesystem path to a plugin's directory, or null if the
     * plugin id isn't known to the registry.
     */
    public function getPath(string $pluginId): ?string
    {
        $this->load();
        if (!isset($this->manifests[$pluginId])) {
            return null;
        }
        return rtrim($this->pluginsDir, '/') . '/' . $pluginId;
    }

    public function getManifest(string $pluginId): ?PluginManifest
    {
        $this->load();
        return $this->manifests[$pluginId] ?? null;
    }

    /** @return array<string, PluginManifest> */
    public function getAllManifests(): array
    {
        $this->load();
        return $this->manifests;
    }

    /**
     * Topologically-sorted list of plugin ids — dependencies precede
     * dependents. Stable order across calls.
     *
     * @return list<string>
     */
    public function getLoadOrder(): array
    {
        $this->load();
        return array_values($this->loadOrder);
    }

    /**
     * Install a plugin: validate manifest, check dependencies, insert
     * the DB row, then invoke the plugin's install() hook. Idempotent
     * if the plugin is already installed (early-returns without firing
     * install() again).
     */
    public function install(string $pluginId): void
    {
        $manifest = $this->requireManifest($pluginId);
        $this->assertDependenciesSatisfied($manifest);

        $existing = $this->repository->findAll('', $pluginId);
        if ($existing !== []) {
            return;
        }

        $this->repository->insert($pluginId, $manifest->version);
        $this->applyMigrations($manifest);
        $this->bootInstance($manifest)->install();
    }

    /**
     * Activate an installed plugin: set state=active in the DB, then
     * invoke the plugin's activate() hook. Idempotent.
     */
    public function activate(string $pluginId): void
    {
        $manifest = $this->requireManifest($pluginId);
        $this->assertDependenciesSatisfied($manifest);

        $rows = $this->repository->findAll('', $pluginId);
        if ($rows === []) {
            throw new PluginDependencyException(
                $pluginId,
                "Plugin '$pluginId' must be installed before activation.",
            );
        }
        if ($rows[0]->state === PluginState::Active) {
            return;
        }

        $this->repository->updateState($pluginId, 'active');
        $this->bootInstance($manifest)->activate();
    }

    /**
     * Deactivate a plugin: set state=inactive in the DB, then invoke
     * deactivate(). Idempotent if the plugin is already inactive.
     */
    public function deactivate(string $pluginId): void
    {
        $manifest = $this->requireManifest($pluginId);

        $rows = $this->repository->findAll('', $pluginId);
        if ($rows === [] || $rows[0]->state !== PluginState::Active) {
            return;
        }

        $this->repository->updateState($pluginId, 'inactive');
        $this->bootInstance($manifest)->deactivate();
    }

    /**
     * Uninstall a plugin: invoke uninstall(), then delete the DB row.
     * The plugin's directory is left in place — that's a filesystem
     * concern handled separately by the admin UX (extraction is
     * mirror-write, removal is `rm -rf` via Filesystem).
     */
    public function uninstall(string $pluginId): void
    {
        $manifest = $this->requireManifest($pluginId);

        $rows = $this->repository->findAll('', $pluginId);
        if ($rows === []) {
            return;
        }

        $this->reverseMigrations($manifest);
        $this->bootInstance($manifest)->uninstall();
        $this->repository->delete($pluginId);
    }

    /**
     * Reconcile DB version with filesystem version: if they differ,
     * invoke the plugin's update($old, $new) hook and bump the DB row.
     * Idempotent when versions already match.
     */
    public function update(string $pluginId): void
    {
        $manifest = $this->requireManifest($pluginId);

        $rows = $this->repository->findAll('', $pluginId);
        if ($rows === []) {
            return;
        }
        $oldVersion = $rows[0]->version;
        $newVersion = $manifest->version;
        if ($oldVersion === $newVersion) {
            return;
        }

        $this->applyMigrations($manifest);
        $this->bootInstance($manifest)->update($oldVersion, $newVersion);
        $this->repository->updateVersion($pluginId, $newVersion);
    }

    /**
     * Boot every active plugin in load order: instantiate the
     * PluginInterface implementation, call boot($container), and attach
     * each entry of its subscribedEvents() map to the Symfony dispatcher.
     * Fires PluginsLoaded once after the loop completes — observers that
     * need the plugin graph fully wired (and aren't themselves part of
     * it) subscribe there.
     *
     * Replaces the legacy `PluginService::loadPlugins()` boot — called
     * once during CommonBootstrap after authentication is in place.
     * Plugin-side errors are not swallowed: a missing main class or an
     * unreachable autoload surfaces as a PluginValidationException, the
     * same shape admin lifecycle hooks already raise.
     *
     * subscribedEvents() shape (mirror of Symfony's EventSubscriberInterface):
     *   class-string => string                              // method name, default priority
     *   class-string => array{0: string, 1?: int}           // method name + priority
     *   class-string => list<array{0: string, 1?: int}>     // multiple handlers
     */
    public function bootActive(SymfonyEventDispatcher $dispatcher, ContainerInterface $container): void
    {
        foreach ($this->getLoadOrder() as $pluginId) {
            if (!$this->isActive($pluginId)) {
                continue;
            }
            $manifest = $this->requireManifest($pluginId);
            $instance = $this->bootInstance($manifest);
            $instance->boot($container);

            foreach ($instance->subscribedEvents() as $eventClass => $handlers) {
                foreach ($this->normalizeSubscribedHandlers($handlers) as $entry) {
                    [$method, $priority] = $entry;
                    // Wrap in a closure so the method name (dynamic per
                    // plugin) doesn't trip the static-callable analyser.
                    $listener = static function (object $event) use ($instance, $method): void {
                        $instance->{$method}($event);
                    };
                    $dispatcher->addListener($eventClass, $listener, $priority);
                }
            }
        }

        $dispatcher->dispatch(new PluginsLoaded());
    }

    /**
     * Normalize the three legal shapes of PluginInterface::subscribedEvents
     * entries to a uniform `list<array{0: string, 1: int}>` so bootActive
     * can register them with a single loop. Defensive at the runtime
     * boundary — plugins author the source map, so unexpected shapes
     * fall through to an empty list rather than fatal.
     *
     * @return list<array{0: string, 1: int}>
     *
     * @param array<mixed>|string $handlers
     */
    private function normalizeSubscribedHandlers(array|string $handlers): array
    {
        if (is_string($handlers)) {
            return [[$handlers, 0]];
        }
        // Single-handler shape: [method:string, priority?:int]
        $first = $handlers[0] ?? null;
        if (is_string($first)) {
            $rawPriority = $handlers[1] ?? 0;
            return [[$first, is_int($rawPriority) ? $rawPriority : 0]];
        }
        // Multi-handler shape: list<[method, priority?]>
        $out = [];
        foreach ($handlers as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $method = $entry[0] ?? null;
            if (!is_string($method)) {
                continue;
            }
            $rawPriority = $entry[1] ?? 0;
            $out[] = [$method, is_int($rawPriority) ? $rawPriority : 0];
        }
        return $out;
    }

    /**
     * Walk active plugins in load order and merge each one's `.po`
     * translations into the running LangService. Called from request
     * bootstrap once the registry has resolved the dependency graph;
     * plugin code is then free to use `$lang->t('key')` everywhere
     * without manual `loadLanguage()` calls.
     */
    public function loadActiveLanguages(LangService $lang): void
    {
        foreach ($this->getLoadOrder() as $pluginId) {
            if (!$this->isActive($pluginId)) {
                continue;
            }
            $pluginDir = $this->getPath($pluginId);
            if ($pluginDir === null) {
                continue;
            }
            $lang->loadPluginTranslations($pluginId, $pluginDir);
        }
    }

    /**
     * True when the plugin id has state=active in the DB. Cheap repository
     * lookup — does NOT instantiate the PluginInterface.
     */
    public function isActive(string $pluginId): bool
    {
        $rows = $this->repository->findAll('active', $pluginId);
        return $rows !== [];
    }

    /**
     * Distinct ids of every plugin currently in state=active. Used by the
     * admin dashboards (`pwg_loaded_plugins` legacy template vars) and the
     * extension static-asset proxy. Order matches the repository's natural
     * scan order — callers that need topological ordering should still
     * walk [[getLoadOrder]] and filter through [[isActive]].
     *
     * @return list<string>
     */
    public function getActiveIds(): array
    {
        $ids = [];
        foreach ($this->repository->findAll('active') as $row) {
            if ($row->id !== '') {
                $ids[] = $row->id;
            }
        }
        return $ids;
    }

    /**
     * Resolve the absolute path holding `Version*.php` files for this
     * plugin (manifest field `migrations.path` is relative to the plugin
     * root). Returns null when the manifest does not ship migrations.
     */
    private function migrationsDir(PluginManifest $manifest): ?string
    {
        if ($manifest->migrationsPath === null) {
            return null;
        }
        return rtrim($this->pluginsDir, '/') . '/' . $manifest->id . '/' . trim($manifest->migrationsPath, '/');
    }

    private function applyMigrations(PluginManifest $manifest): void
    {
        if ($this->migrationRunner === null) {
            return;
        }
        $this->migrationRunner->runUp(
            $manifest->id,
            $manifest->migrationsNamespace,
            $this->migrationsDir($manifest),
        );
    }

    private function reverseMigrations(PluginManifest $manifest): void
    {
        if ($this->migrationRunner === null) {
            return;
        }
        $this->migrationRunner->runDown(
            $manifest->id,
            $manifest->migrationsNamespace,
            $this->migrationsDir($manifest),
        );
    }

    /**
     * Decode + schema-validate the manifest file and return the typed
     * value object.
     */
    private function loadManifest(string $pluginId, string $manifestPath): PluginManifest
    {
        if (!is_readable($manifestPath)) {
            throw new PluginValidationException($pluginId, $manifestPath, 'cannot read plugin.json');
        }
        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            throw new PluginValidationException($pluginId, $manifestPath, 'cannot read plugin.json');
        }
        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PluginValidationException($pluginId, $manifestPath, 'plugin.json is not valid JSON: ' . $e->getMessage(), $e);
        }
        if (!is_object($decoded)) {
            throw new PluginValidationException($pluginId, $manifestPath, 'plugin.json top level must be an object');
        }

        $result = $this->validator->validate($decoded, $this->schema());
        if (!$result->isValid()) {
            $err = $result->error();
            $errMsg = $err === null ? 'schema validation failed' : ($err->message() . ' at /' . implode('/', $err->data()->path()));
            throw new PluginValidationException($pluginId, $manifestPath, $errMsg);
        }

        // Schema-validated: every required field (including `id`) is now
        // typed `string` per docs/schemas/plugin.schema.json.
        /** @var \stdClass&object{id:string,name:string,version:string,description:string,license:string,minPiwigo:string,main:string} $manifest */
        $manifest = $decoded;

        // The directory basename must match the id: prevents tarball
        // tampering where a plugin claims a different id than its dir.
        if ($manifest->id !== $pluginId) {
            throw new PluginValidationException(
                $pluginId,
                $manifestPath,
                "manifest id '{$manifest->id}' does not match directory '{$pluginId}'",
            );
        }

        /** @var array<string, mixed> $arr */
        $arr = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return PluginManifest::fromArray($arr);
    }

    private function schema(): object
    {
        if ($this->schema !== null) {
            return $this->schema;
        }
        if (!is_readable($this->schemaPath)) {
            throw new \RuntimeException("Plugin schema not found at {$this->schemaPath}");
        }
        $raw = file_get_contents($this->schemaPath);
        if ($raw === false) {
            throw new \RuntimeException("Plugin schema not found at {$this->schemaPath}");
        }
        $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($decoded)) {
            throw new \RuntimeException('Plugin schema must decode to an object');
        }
        return $this->schema = $decoded;
    }

    /**
     * Resolve `require:` constraints against Piwigo core + other plugins.
     * Throws on first unsatisfied dependency.
     */
    private function assertDependenciesSatisfied(PluginManifest $manifest): void
    {
        foreach ($manifest->require as $depName => $constraint) {
            $depVersion = match (true) {
                $depName === 'piwigo'                  => AppInfo::VERSION,
                str_starts_with($depName, 'plugin/') => $this->resolveDependencyVersion(substr($depName, 7)),
                default                                  => null,
            };

            if ($depVersion === null) {
                throw new PluginDependencyException(
                    $manifest->id,
                    "Plugin '{$manifest->id}' requires '{$depName}' (constraint: {$constraint}) but it is not installed.",
                );
            }
            if (!Semver::satisfies($depVersion, $constraint)) {
                throw new PluginDependencyException(
                    $manifest->id,
                    "Plugin '{$manifest->id}' requires '{$depName}' {$constraint} but only {$depVersion} is available.",
                );
            }
        }

        // minPiwigo gate — independent of `require:` so plugins can use
        // the simpler "minimum core branch" form without spelling out
        // `require: { piwigo: ... }`.
        if (!Semver::satisfies(AppInfo::VERSION, '>=' . $manifest->minPiwigo)) {
            throw new PluginDependencyException(
                $manifest->id,
                "Plugin '{$manifest->id}' requires Piwigo >= {$manifest->minPiwigo}; running " . AppInfo::VERSION,
            );
        }
    }

    private function resolveDependencyVersion(string $pluginId): ?string
    {
        return $this->manifests[$pluginId]->version ?? null;
    }

    /**
     * Kahn's algorithm — emits each plugin id only after all of its
     * `require: { plugin/... }` dependencies have been emitted. Detects
     * cycles by checking whether any nodes remain after the work queue
     * drains.
     *
     * @param array<string, PluginManifest> $manifests
     * @return array<string, string> Insertion-ordered map id => id.
     */
    private function topologicalSort(array $manifests): array
    {
        /** @var array<string, list<string>> $deps     plugin id => list of plugin-ids it requires */
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

        /** @var array<string, string> $sorted */
        $sorted = [];
        while ($queue !== []) {
            $id = array_shift($queue);
            $sorted[$id] = $id;
            foreach ($manifests as $candidate => $manifest) {
                if (in_array($id, $deps[$candidate], true)) {
                    $inDegree[$candidate]--;
                    if ($inDegree[$candidate] === 0) {
                        $queue[] = $candidate;
                    }
                }
            }
        }

        if (count($sorted) !== count($manifests)) {
            $cycle = array_diff(array_keys($manifests), array_keys($sorted));
            throw new PluginDependencyException(
                array_values($cycle)[0] ?? '',
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
                rtrim($this->pluginsDir, '/') . "/{$pluginId}/plugin.json",
                "Plugin '{$pluginId}' has no validated manifest.",
            );
        }
        return $manifest;
    }

    /**
     * Instantiate the plugin's main class. The class is expected to be
     * autoloaded through Composer's classmap or PSR-4 settings;
     * PluginRegistry does NOT install per-plugin PSR-4 loaders — that's
     * the autoload pass of B12 (asset pipeline + autoload registration).
     * For B7, classes must already be reachable.
     */
    private function bootInstance(PluginManifest $manifest): PluginInterface
    {
        $class = $manifest->main;
        if (!class_exists($class)) {
            throw new PluginValidationException(
                $manifest->id,
                rtrim($this->pluginsDir, '/') . "/{$manifest->id}/plugin.json",
                "Plugin main class '{$class}' does not exist (autoload missing — B12 will wire per-plugin PSR-4).",
            );
        }
        // Plugin main-class name comes from the manifest at runtime —
        // dispatching to it is exactly what the registry exists for, so
        // the project-wide noDynamicNew rule explicitly allows this site.
        // @phpstan-ignore-next-line piwigo.noDynamicNew
        $instance = new $class();
        if (!$instance instanceof PluginInterface) {
            throw new PluginValidationException(
                $manifest->id,
                rtrim($this->pluginsDir, '/') . "/{$manifest->id}/plugin.json",
                "Plugin main class '{$class}' must implement PluginInterface.",
            );
        }
        return $instance;
    }

    /** @return iterable<string> */
    private function scanPluginDirs(): iterable
    {
        if (!is_dir($this->pluginsDir)) {
            return;
        }
        $entries = scandir($this->pluginsDir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = rtrim($this->pluginsDir, '/') . '/' . $entry;
            if (is_dir($path)) {
                yield $path;
            }
        }
    }
}

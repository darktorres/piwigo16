<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Piwigo\Plugin\PluginDependencyException;
use Piwigo\Plugin\PluginRegistry;
use Piwigo\Plugin\PluginRepository;
use Piwigo\Plugin\PluginValidationException;
use Piwigo\Tests\Fixtures\Plugins\ValidPlugin\Plugin as ValidPlugin;
use Psr\Log\NullLogger;

/**
 * Exercises PluginRegistry against the per-test fixture trees under
 * tests/fixtures/plugins/. Each fixture is a self-contained plugin
 * directory with a plugin.json (and, when relevant, a Plugin.php).
 *
 * The PluginRepository dependency is stubbed with an in-memory shim so
 * we don't need a DB for registry-level tests.
 */
final class PluginRegistryTest extends TestCase
{
    private string $fixturesDir;

    private string $schemaPath;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        // Fixtures live outside the PSR-4 tests/ namespace casing on
        // purpose (lowercase dir names mirror real plugins/ layout), so
        // the autoloader skips them. Load the fixture class once here.
        require_once PHPWG_ROOT_PATH . 'tests/fixtures/plugins/valid_plugin/Plugin.php';
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->fixturesDir = PHPWG_ROOT_PATH . 'tests/fixtures/plugins';
        $this->schemaPath  = PHPWG_ROOT_PATH . 'docs/schemas/plugin.schema.json';

        ValidPlugin::$installCount   = 0;
        ValidPlugin::$activateCount  = 0;
        ValidPlugin::$deactivateCount = 0;
        ValidPlugin::$uninstallCount = 0;
        ValidPlugin::$lastUpdateOldVersion = null;
        ValidPlugin::$lastUpdateNewVersion = null;
    }

    public function testLoadAcceptsValidManifestAndSkipsInvalidOnes(): void
    {
        $registry = $this->makeRegistry();
        $registry->load();

        // valid + missing_dep + cycle_a + cycle_b + orphan_class survive schema.
        // invalid_schema is silently skipped (logged at warning level).
        $ids = array_keys($registry->getAllManifests());
        sort($ids);
        // cycle_a/cycle_b will be schema-valid; topological sort throws below.
        // So the test relies on a registry built from JUST the valid fixture.
        self::assertContains('valid_plugin', $ids);
        self::assertNotContains('invalid_schema', $ids, 'malformed manifest must be skipped');
    }

    public function testValidPluginInstallActivateDeactivateUninstallLifecycle(): void
    {
        $repo = $this->stubRepository();
        $registry = $this->makeRegistry($repo);

        $registry->install('valid_plugin');
        self::assertSame(1, ValidPlugin::$installCount);
        self::assertNotEmpty($repo->findAll('', 'valid_plugin'));

        $registry->install('valid_plugin'); // idempotent — install() runs only once
        /** @psalm-suppress RedundantConditionGivenDocblockType — static counter narrowed to =int(1) after idempotent re-run */
        self::assertSame(1, ValidPlugin::$installCount);

        $registry->activate('valid_plugin');
        /** @psalm-suppress RedundantConditionGivenDocblockType — same Psalm static-counter narrowing as the install case above */
        self::assertSame(1, ValidPlugin::$activateCount);

        $registry->activate('valid_plugin'); // idempotent — already active
        /** @psalm-suppress RedundantConditionGivenDocblockType — same Psalm static-counter narrowing as the install case above */
        self::assertSame(1, ValidPlugin::$activateCount);

        $registry->deactivate('valid_plugin');
        /** @psalm-suppress RedundantConditionGivenDocblockType — same Psalm static-counter narrowing as the install case above */
        self::assertSame(1, ValidPlugin::$deactivateCount);

        $registry->uninstall('valid_plugin');
        /** @psalm-suppress RedundantConditionGivenDocblockType — same Psalm static-counter narrowing as the install case above */
        self::assertSame(1, ValidPlugin::$uninstallCount);
        self::assertEmpty($repo->findAll('', 'valid_plugin'));
    }

    public function testMissingDependencyRefusesActivation(): void
    {
        $registry = $this->makeRegistry();
        $registry->load();

        $this->expectException(PluginDependencyException::class);
        $this->expectExceptionMessageMatches('/missing_dep.*does_not_exist/');
        $registry->install('missing_dep');
    }

    public function testDependencyCycleRefusesLoad(): void
    {
        // The cycle pair lives in tests/fixtures/plugin_cycles/ so the
        // main fixtures dir stays acyclic — Kahn's algorithm there must
        // drain cleanly. This test points the registry at the isolated
        // cycle directory and asserts the dependency exception.
        $registry = new PluginRegistry(
            $this->stubRepository(),
            new NullLogger(),
            PHPWG_ROOT_PATH . 'tests/fixtures/plugin_cycles',
            $this->schemaPath,
        );

        $this->expectException(PluginDependencyException::class);
        $this->expectExceptionMessageMatches('/cycle/i');
        $registry->load();
    }

    public function testOrphanClassRefusesActivation(): void
    {
        $registry = $this->makeRegistry();
        $registry->load();

        $this->expectException(PluginValidationException::class);
        $this->expectExceptionMessageMatches('/main class.*does not exist/');
        $registry->install('orphan_class');
    }

    public function testUpdateRunsWhenManifestVersionChanges(): void
    {
        $repo = $this->stubRepository();
        $registry = $this->makeRegistry($repo);

        $registry->install('valid_plugin');
        // Simulate filesystem version > DB version (mimics a fresh tarball drop).
        $repo->updateVersion('valid_plugin', '0.9.0');

        $registry->update('valid_plugin');
        self::assertSame('0.9.0', ValidPlugin::$lastUpdateOldVersion);
        self::assertSame('1.0.0', ValidPlugin::$lastUpdateNewVersion);
        self::assertSame('1.0.0', $repo->findAll('', 'valid_plugin')[0]['version']);
    }

    public function testGetPathReturnsAbsoluteFilesystemPath(): void
    {
        $registry = $this->makeRegistry();
        $path = $registry->getPath('valid_plugin');
        self::assertNotNull($path);
        self::assertSame($this->fixturesDir . '/valid_plugin', $path);

        self::assertNull($registry->getPath('not_a_plugin'));
    }

    private function makeRegistry(?PluginRepository $repo = null): PluginRegistry
    {
        return new PluginRegistry(
            $repo ?? $this->stubRepository(),
            new NullLogger(),
            $this->fixturesDir,
            $this->schemaPath,
        );
    }

    private function stubRepository(): PluginRepository
    {
        /** @psalm-suppress PropertyNotSetInConstructor — parent's $conn/$tablePrefix intentionally skipped; stub has no DB */
        return new class () extends PluginRepository {
            /** @var array<string, array<string, mixed>> */
            private array $rows = [];

            public function __construct()
            {
                // Skip parent constructor — no DB needed.
            }

            #[\Override]
            public function findAll(?string $state = '', ?string $id = ''): array
            {
                if ($id !== null && $id !== '') {
                    return isset($this->rows[$id]) ? [$this->rows[$id]] : [];
                }
                $out = array_values($this->rows);
                if ($state !== null && $state !== '') {
                    $out = array_values(array_filter($out, static fn (array $r): bool => ($r['state'] ?? '') === $state));
                }
                return $out;
            }

            #[\Override]
            public function insert(string $pluginId, string $version): void
            {
                $this->rows[$pluginId] = ['id' => $pluginId, 'version' => $version, 'state' => 'inactive'];
            }

            #[\Override]
            public function updateVersion(string $pluginId, string $version): void
            {
                if (isset($this->rows[$pluginId])) {
                    $this->rows[$pluginId]['version'] = $version;
                }
            }

            #[\Override]
            public function updateState(string $pluginId, string $state): void
            {
                if (isset($this->rows[$pluginId])) {
                    $this->rows[$pluginId]['state'] = $state;
                }
            }

            #[\Override]
            public function delete(string $pluginId): void
            {
                unset($this->rows[$pluginId]);
            }
        };
    }
}

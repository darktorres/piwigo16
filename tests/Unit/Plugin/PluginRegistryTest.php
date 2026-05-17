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
 * tests/Fixtures/Plugins/. Each fixture is a self-contained plugin
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
    protected function setUp(): void
    {
        $repoRoot          = dirname(__DIR__, 3);
        $this->fixturesDir = $repoRoot . '/tests/Fixtures/Plugins';
        $this->schemaPath  = $repoRoot . '/docs/schemas/plugin.schema.json';

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

        // ValidPlugin + MissingDep + OrphanClass survive schema.
        // InvalidSchema is silently skipped (logged at warning level);
        // PluginCycles live under a separate fixture root.
        $ids = array_keys($registry->getAllManifests());
        sort($ids);
        self::assertContains('ValidPlugin', $ids);
        self::assertNotContains('InvalidSchema', $ids, 'malformed manifest must be skipped');
    }

    public function testValidPluginInstallActivateDeactivateUninstallLifecycle(): void
    {
        $repo = $this->stubRepository();
        $registry = $this->makeRegistry($repo);

        $registry->install('ValidPlugin');
        self::assertSame(1, ValidPlugin::$installCount);
        self::assertNotEmpty($repo->findAll('', 'ValidPlugin'));

        $registry->install('ValidPlugin'); // idempotent — install() runs only once
        /** @psalm-suppress RedundantConditionGivenDocblockType — static counter narrowed to =int(1) after idempotent re-run */
        self::assertSame(1, ValidPlugin::$installCount);

        $registry->activate('ValidPlugin');
        /** @psalm-suppress RedundantConditionGivenDocblockType — same Psalm static-counter narrowing as the install case above */
        self::assertSame(1, ValidPlugin::$activateCount);

        $registry->activate('ValidPlugin'); // idempotent — already active
        /** @psalm-suppress RedundantConditionGivenDocblockType — same Psalm static-counter narrowing as the install case above */
        self::assertSame(1, ValidPlugin::$activateCount);

        $registry->deactivate('ValidPlugin');
        /** @psalm-suppress RedundantConditionGivenDocblockType — same Psalm static-counter narrowing as the install case above */
        self::assertSame(1, ValidPlugin::$deactivateCount);

        $registry->uninstall('ValidPlugin');
        /** @psalm-suppress RedundantConditionGivenDocblockType — same Psalm static-counter narrowing as the install case above */
        self::assertSame(1, ValidPlugin::$uninstallCount);
        self::assertEmpty($repo->findAll('', 'ValidPlugin'));
    }

    public function testMissingDependencyRefusesActivation(): void
    {
        $registry = $this->makeRegistry();
        $registry->load();

        $this->expectException(PluginDependencyException::class);
        $this->expectExceptionMessageMatches('/MissingDep.*does_not_exist/');
        $registry->install('MissingDep');
    }

    public function testDependencyCycleRefusesLoad(): void
    {
        // The cycle pair lives in tests/Fixtures/PluginCycles/ so the
        // main fixtures dir stays acyclic — Kahn's algorithm there must
        // drain cleanly. This test points the registry at the isolated
        // cycle directory and asserts the dependency exception.
        $registry = new PluginRegistry(
            $this->stubRepository(),
            new NullLogger(),
            dirname(__DIR__, 3) . '/tests/Fixtures/PluginCycles',
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
        $registry->install('OrphanClass');
    }

    public function testUpdateRunsWhenManifestVersionChanges(): void
    {
        $repo = $this->stubRepository();
        $registry = $this->makeRegistry($repo);

        $registry->install('ValidPlugin');
        // Simulate filesystem version > DB version (mimics a fresh tarball drop).
        $repo->updateVersion('ValidPlugin', '0.9.0');

        $registry->update('ValidPlugin');
        self::assertSame('0.9.0', ValidPlugin::$lastUpdateOldVersion);
        self::assertSame('1.0.0', ValidPlugin::$lastUpdateNewVersion);
        self::assertSame('1.0.0', $repo->findAll('', 'ValidPlugin')[0]['version']);
    }

    public function testGetPathReturnsAbsoluteFilesystemPath(): void
    {
        $registry = $this->makeRegistry();
        $path = $registry->getPath('ValidPlugin');
        self::assertNotNull($path);
        self::assertSame($this->fixturesDir . '/ValidPlugin', $path);

        self::assertNull($registry->getPath('NotAPlugin'));
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

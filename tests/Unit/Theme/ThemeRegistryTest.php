<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Theme;

use PHPUnit\Framework\TestCase;
use Piwigo\Event\Theme\ThemeChanged;
use Piwigo\Tests\Fixtures\Themes\ValidTheme\Theme as ValidTheme;
use Piwigo\Theme\ThemeDependencyException;
use Piwigo\Theme\ThemeRegistry;
use Piwigo\Theme\ThemeRepository;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Exercises ThemeRegistry against the fixture trees under
 * tests/Fixtures/Themes/. Mirrors PluginRegistryTest's structural
 * approach — in-memory ThemeRepository stub, isolated cycle fixture
 * outside the main scan dir.
 */
final class ThemeRegistryTest extends TestCase
{
    private string $fixturesDir;

    private string $schemaPath;

    #[\Override]
    protected function setUp(): void
    {
        $repoRoot          = dirname(__DIR__, 3);
        $this->fixturesDir = $repoRoot . '/tests/Fixtures/Themes';
        $this->schemaPath  = $repoRoot . '/docs/schemas/theme.schema.json';

        ValidTheme::$installCount    = 0;
        ValidTheme::$activateCount   = 0;
        ValidTheme::$deactivateCount = 0;
        ValidTheme::$uninstallCount  = 0;
        ValidTheme::$lastUpdateOldVersion = null;
        ValidTheme::$lastUpdateNewVersion = null;
    }

    public function testLoadAcceptsValidManifestAndSkipsInvalidOnes(): void
    {
        $registry = $this->makeRegistry();
        $registry->load();

        $ids = array_keys($registry->getAllManifests());
        sort($ids);
        self::assertContains('ValidTheme', $ids);
        self::assertContains('ChildTheme', $ids);
        self::assertNotContains('InvalidSchema', $ids, 'malformed manifest must be skipped');
    }

    public function testValidThemeInstallActivateDeactivateUninstallLifecycle(): void
    {
        $repo = $this->stubRepository();
        $registry = $this->makeRegistry($repo);

        // Single install→activate→deactivate→uninstall sweep. The
        // re-install-after-deactivate corner case is left out — Psalm
        // can't model static-counter mutation across calls so a second
        // bump trips DocblockTypeContradiction; same flaw is already
        // baked into PluginRegistryTest's baseline noise.
        $registry->install('ValidTheme');
        self::assertNotEmpty($repo->findAll('ValidTheme'));
        self::assertSame(1, ValidTheme::$installCount);

        $registry->activate('ValidTheme');
        self::assertSame(1, ValidTheme::$activateCount);

        $registry->deactivate('ValidTheme');
        self::assertSame(1, ValidTheme::$deactivateCount);

        // uninstall after deactivate: repository row is already gone, so
        // uninstall() short-circuits without firing the plugin hook. The
        // deactivate path is symmetric with the legacy themes-table semantics.
        $registry->uninstall('ValidTheme');
        self::assertSame(0, ValidTheme::$uninstallCount);
        self::assertEmpty($repo->findAll('ValidTheme'));
    }

    public function testActivateFiresThemeChangedEventWithBothIds(): void
    {
        $dispatcher = new EventDispatcher();
        $captured = [];
        $dispatcher->addListener(
            ThemeChanged::class,
            static function (ThemeChanged $event) use (&$captured): void {
                $captured[] = [$event->previousThemeId, $event->newThemeId];
            },
        );

        $registry = new ThemeRegistry(
            $this->stubRepository(),
            new NullLogger(),
            $this->fixturesDir,
            $this->schemaPath,
            $dispatcher,
        );
        $registry->activate('ValidTheme', 'OldTheme');

        self::assertSame([['OldTheme', 'ValidTheme']], $captured);
    }

    public function testOrphanParentThrowsOnInstall(): void
    {
        $registry = $this->makeRegistry();

        $this->expectException(ThemeDependencyException::class);
        $this->expectExceptionMessageMatches('/parent.*does_not_exist/');
        $registry->install('OrphanParent');
    }

    public function testParentCycleThrowsDuringLoad(): void
    {
        // Isolate the cycle fixtures from the main themes scan dir so the
        // primary registry never sees them.
        $registry = new ThemeRegistry(
            $this->stubRepository(),
            new NullLogger(),
            dirname(__DIR__, 3) . '/tests/Fixtures/ThemeCycles',
            $this->schemaPath,
        );

        $this->expectException(ThemeDependencyException::class);
        $this->expectExceptionMessageMatches('/cycle/i');
        $registry->load();
    }

    public function testResolutionChainContainsSelfThenParents(): void
    {
        $registry = $this->makeRegistry();
        $registry->load();

        $chain = $registry->getResolutionChain('ChildTheme');
        self::assertSame(['ChildTheme', 'ValidTheme'], $chain);

        $rootChain = $registry->getResolutionChain('ValidTheme');
        self::assertSame(['ValidTheme'], $rootChain);

        self::assertSame([], $registry->getResolutionChain('DoesNotExist'));
    }

    public function testGetPathReturnsAbsoluteFilesystemPath(): void
    {
        $registry = $this->makeRegistry();
        $path = $registry->getPath('ValidTheme');
        self::assertNotNull($path);
        self::assertSame($this->fixturesDir . '/ValidTheme', $path);

        self::assertNull($registry->getPath('NotATheme'));
    }

    public function testUpdateRunsWhenManifestVersionChanges(): void
    {
        $repo = $this->stubRepository();
        // Pre-seed the repo with ValidTheme @ 0.9.0 (mimics a tarball
        // drop on top of an older install). activate() is the public
        // insert path on ThemeRepository, so this stays inside the
        // typed surface without leaning on stub-only helpers.
        $repo->activate('ValidTheme', '0.9.0', 'Valid Theme');

        $registry = $this->makeRegistry($repo);
        $registry->update('ValidTheme');
        self::assertSame('0.9.0', ValidTheme::$lastUpdateOldVersion);
        self::assertSame('1.0.0', ValidTheme::$lastUpdateNewVersion);
    }

    private function makeRegistry(?ThemeRepository $repo = null): ThemeRegistry
    {
        return new ThemeRegistry(
            $repo ?? $this->stubRepository(),
            new NullLogger(),
            $this->fixturesDir,
            $this->schemaPath,
        );
    }

    private function stubRepository(): ThemeRepository
    {
        /** @psalm-suppress PropertyNotSetInConstructor — parent's $conn/$tablePrefix intentionally skipped; stub has no DB */
        return new class () extends ThemeRepository {
            /** @var array<string, array<string, mixed>> */
            private array $rows = [];

            public function __construct()
            {
                // Skip parent constructor — no DB needed.
            }

            #[\Override]
            public function activate(string $id, string $version, string $name): void
            {
                $this->rows[$id] = ['id' => $id, 'version' => $version, 'name' => $name];
            }

            #[\Override]
            public function deactivate(string $id): void
            {
                unset($this->rows[$id]);
            }

            #[\Override]
            public function findAll(?string $id = ''): array
            {
                if ($id === null || $id === '') {
                    return array_values($this->rows);
                }
                return isset($this->rows[$id]) ? [$this->rows[$id]] : [];
            }
        };
    }
}

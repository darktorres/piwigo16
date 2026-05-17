<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\InstallSentinel;
use Piwigo\Core\Lang;
use Piwigo\Lang\LangService;
use Piwigo\Lang\Translator;
use Piwigo\Plugin\PluginRegistry;
use Piwigo\Plugin\PluginRepository;
use Psr\Log\NullLogger;

/**
 * Verifies PluginRegistry::loadActiveLanguages() loads .po files for
 * plugins in 'active' state and skips inactive ones — that's the only
 * point a plugin's translation table merges into the running
 * Translator without the plugin author writing any boilerplate.
 */
final class PluginRegistryLanguagesTest extends TestCase
{
    private LangService $lang;

    private bool $wasInstalled = false;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        require_once PHPWG_ROOT_PATH . 'tests/fixtures/plugins/valid_plugin/Plugin.php';
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->wasInstalled = InstallSentinel::isInstalled();
        InstallSentinel::markUninstalled();
        Lang::reset();
        Translator::reset();
        $this->lang = new LangService(\Piwigo\Core\Paths::fromRoot(dirname(__DIR__, 3)));
    }

    #[\Override]
    protected function tearDown(): void
    {
        Lang::reset();
        Translator::reset();
        if ($this->wasInstalled) {
            InstallSentinel::markInstalled();
        }
    }

    public function testLoadActiveLanguagesMergesOnlyActivePluginPo(): void
    {
        $repo = $this->stubRepository(['valid_plugin' => 'active']);
        $registry = $this->makeRegistry($repo);

        $registry->loadActiveLanguages($this->lang);

        self::assertSame('Hello from valid_plugin fixture', $this->lang->t('hello_plugin'));
    }

    public function testInactivePluginsAreSkipped(): void
    {
        $repo = $this->stubRepository(['valid_plugin' => 'inactive']);
        $registry = $this->makeRegistry($repo);

        $registry->loadActiveLanguages($this->lang);

        // No translation was loaded → t() echoes the key.
        self::assertSame('hello_plugin', $this->lang->t('hello_plugin'));
    }

    private function makeRegistry(PluginRepository $repo): PluginRegistry
    {
        return new PluginRegistry(
            $repo,
            new NullLogger(),
            PHPWG_ROOT_PATH . 'tests/fixtures/plugins',
            PHPWG_ROOT_PATH . 'docs/schemas/plugin.schema.json',
        );
    }

    /** @param array<string, string> $states plugin id => state */
    private function stubRepository(array $states): PluginRepository
    {
        /** @psalm-suppress PropertyNotSetInConstructor — parent's $conn/$tablePrefix intentionally skipped; stub has no DB */
        return new class ($states) extends PluginRepository {
            /** @var array<string, array<string, mixed>> */
            private array $rows = [];

            /** @param array<string, string> $states */
            public function __construct(array $states)
            {
                foreach ($states as $id => $state) {
                    $this->rows[$id] = ['id' => $id, 'state' => $state, 'version' => '1.0.0'];
                }
            }

            #[\Override]
            public function findAll(?string $state = '', ?string $id = ''): array
            {
                $out = array_values($this->rows);
                if ($state !== null && $state !== '') {
                    $out = array_values(array_filter($out, static fn (array $r): bool => ($r['state'] ?? '') === $state));
                }
                if ($id !== null && $id !== '') {
                    $out = array_values(array_filter($out, static fn (array $r): bool => ($r['id'] ?? '') === $id));
                }
                return $out;
            }
        };
    }
}

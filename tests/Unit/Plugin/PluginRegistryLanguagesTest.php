<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\InstallSentinel;
use Piwigo\Core\Lang;
use Piwigo\Lang\LangService;
use Piwigo\Lang\Translator;
use Piwigo\Plugin\PluginRecord;
use Piwigo\Plugin\PluginRegistry;
use Piwigo\Plugin\PluginRepository;
use Piwigo\Plugin\PluginState;
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
    protected function setUp(): void
    {
        $paths              = \Piwigo\Core\Paths::fromRoot(dirname(__DIR__, 3));
        $this->wasInstalled = InstallSentinel::isInstalled($paths);
        InstallSentinel::markUninstalled($paths);
        Lang::reset();
        Translator::reset();
        $this->lang = new LangService($paths);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Lang::reset();
        Translator::reset();
        if ($this->wasInstalled) {
            InstallSentinel::markInstalled(\Piwigo\Core\Paths::fromRoot(dirname(__DIR__, 3)));
        }
    }

    public function testLoadActiveLanguagesMergesOnlyActivePluginPo(): void
    {
        $repo = $this->stubRepository(['ValidPlugin' => 'active']);
        $registry = $this->makeRegistry($repo);

        $registry->loadActiveLanguages($this->lang);

        self::assertSame('Hello from ValidPlugin fixture', $this->lang->t('hello_plugin'));
    }

    public function testInactivePluginsAreSkipped(): void
    {
        $repo = $this->stubRepository(['ValidPlugin' => 'inactive']);
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
            dirname(__DIR__, 3) . '/tests/Fixtures/Plugins',
            dirname(__DIR__, 3) . '/docs/schemas/plugin.schema.json',
        );
    }

    /** @param array<string, string> $states plugin id => state */
    private function stubRepository(array $states): PluginRepository
    {
        /** @psalm-suppress PropertyNotSetInConstructor — parent's $conn/$tablePrefix intentionally skipped; stub has no DB */
        return new class ($states) extends PluginRepository {
            /** @var array<string, PluginRecord> */
            private array $rows = [];

            /** @param array<string, string> $states */
            public function __construct(array $states)
            {
                foreach ($states as $id => $state) {
                    $this->rows[$id] = new PluginRecord($id, PluginState::tryFrom($state) ?? PluginState::Inactive, '1.0.0');
                }
            }

            #[\Override]
            public function findAll(?string $state = '', ?string $id = ''): array
            {
                $out = array_values($this->rows);
                if ($state !== null && $state !== '') {
                    $out = array_values(array_filter($out, static fn (PluginRecord $r): bool => $r->state->value === $state));
                }
                if ($id !== null && $id !== '') {
                    $out = array_values(array_filter($out, static fn (PluginRecord $r): bool => $r->id === $id));
                }
                return $out;
            }
        };
    }
}

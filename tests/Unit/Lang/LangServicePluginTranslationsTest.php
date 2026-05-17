<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Lang;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\InstallSentinel;
use Piwigo\Core\Lang;
use Piwigo\Lang\LangService;
use Piwigo\Lang\Translator;

/**
 * Plugin-facing translation loading: a plugin places `.po` files at
 * `<pluginRoot>/language/<locale>/plugin.po` and the framework loads
 * them via LangService::loadPluginTranslations() — never via a manual
 * `loadLanguage()` call from plugin code.
 *
 * Translator/Lang are global facades; reset() between tests so each
 * assertion runs against a clean translation table.
 */
final class LangServicePluginTranslationsTest extends TestCase
{
    private LangService $lang;

    private bool $wasInstalled = false;

    #[\Override]
    protected function setUp(): void
    {
        // LangService::loadLanguage() routes through UserService when the
        // install sentinel is set — but Kernel isn't booted in unit scope.
        // Flip the stamp off so the fallback to AppInfo::DEFAULT_LANGUAGE
        // (en_UK) takes over, and restore in tearDown.
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

    public function testTAliasReturnsKeyWhenNoTranslationLoaded(): void
    {
        self::assertSame('hello_plugin', $this->lang->t('hello_plugin'));
    }

    public function testLoadPluginTranslationsMergesFixturePoFile(): void
    {
        $pluginDir = dirname(__DIR__, 3) . '/tests/fixtures/plugins/valid_plugin';
        $loaded = $this->lang->loadPluginTranslations('valid_plugin', $pluginDir);

        self::assertTrue($loaded, 'Fixture plugin.po must be discoverable under language/en_UK/');
        self::assertSame('Hello from valid_plugin fixture', $this->lang->t('hello_plugin'));
        self::assertSame('Welcome, Alice', $this->lang->t('greeting', 'Alice'));
    }

    public function testLoadPluginTranslationsReturnsFalseWhenNoPoFile(): void
    {
        // orphan_class fixture has no language/ subdirectory.
        $pluginDir = dirname(__DIR__, 3) . '/tests/fixtures/plugins/orphan_class';
        self::assertFalse($this->lang->loadPluginTranslations('orphan_class', $pluginDir));
    }

    public function testLoadPluginTranslationsShortCircuitsOnEmptyInputs(): void
    {
        self::assertFalse($this->lang->loadPluginTranslations('', dirname(__DIR__, 3) . '/tests/fixtures/plugins/valid_plugin'));
        self::assertFalse($this->lang->loadPluginTranslations('valid_plugin', ''));
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Error;
use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Lang;
use Piwigo\Core\ThemeCatalog;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Event\Lifecycle\GetPwgThemes;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;

/**
 * Piwigo\Core\ThemeCatalog::getPwgThemes()/checkThemeInstalled() need a
 * real DB connection (Tables::themes() row set) and a real on-disk theme
 * directory (checkThemeInstalled() does a genuine file_exists()) --
 * Integration, not Unit, matching this repo's own established split. The
 * fixture's own piwigo_themes table is empty (confirmed live, same fact
 * UserServiceTest's own comments note), and 'default' is the one real
 * theme directory this repo ships (themes/default/themeconf.inc.php) --
 * every row this file inserts is cleaned up in its own test's finally
 * block, leaving the table empty again for later tests/classes.
 */
final class ThemeCatalogTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $this->conn = DbConnection::build();
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        // Untranslated-key fallback for Lang::t('Mobile') below --
        // deterministic regardless of what an earlier Integration test in
        // this shared process may have loaded into Translator's mirror.
        Lang::current()->reset();
        Translator::get()->reset();
    }

    #[Override]
    protected function tearDown(): void
    {
        Lang::current()->reset();
        Translator::get()->reset();
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        parent::tearDown();
    }

    public function test_get_pwg_themes_skips_a_row_whose_name_is_null(): void
    {
        // piwigo_themes.name is a nullable column (schema-confirmed) --
        // is_string($name) is a real runtime guard against exactly this,
        // not defensive padding.
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::themes() . " (id, version, name) VALUES ('broken-theme', '1.0', NULL)"
        );

        try {
            $themes = ThemeCatalog::getPwgThemes(EventDispatcher::get(), CurrentPaths::get());
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::themes() . " WHERE id = 'broken-theme'");
        }

        // The fixture's piwigo_themes table is otherwise empty, so a
        // fully empty result confirms the row was skipped, not merely
        // filtered out for some unrelated reason.
        expect($themes)->toBe([]);
    }

    public function test_get_pwg_themes_skips_the_configured_mobile_theme_when_show_mobile_is_false(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::themes() . " (id, version, name) VALUES ('mobile-candidate', '1.0', 'Mobile Candidate')"
        );
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->setMobilTheme('mobile-candidate');

        try {
            $themes = ThemeCatalog::getPwgThemes(EventDispatcher::get(), CurrentPaths::get(), showMobile: false);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::themes() . " WHERE id = 'mobile-candidate'");
        }

        expect($themes)->toBe([]);
    }

    public function test_get_pwg_themes_appends_the_mobile_suffix_and_includes_the_theme_when_show_mobile_is_true(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::themes() . " (id, version, name) VALUES ('default', '1.0', 'Default')"
        );
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->setMobilTheme('default');

        try {
            $themes = ThemeCatalog::getPwgThemes(EventDispatcher::get(), CurrentPaths::get(), showMobile: true);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::themes() . " WHERE id = 'default'");
        }

        // 'default' is checkThemeInstalled()'s one real installed theme,
        // so it's the only row that survives both the mobile-suffix
        // append AND the installed-directory check.
        expect($themes)->toBe(['default' => 'Default (Mobile)']);
    }

    public function test_get_pwg_themes_throws_when_a_plugin_filter_returns_something_other_than_a_get_pwg_themes_instance(): void
    {
        // addEventHandler(), not addTypedHandler() -- a real plugin
        // handler is untyped from PHPStan's perspective, and this test
        // exercises dispatchChange()'s own runtime enforcement, not a
        // static one.
        EventDispatcher::get()->addEventHandler(GetPwgThemes::class, static fn (): ?string => null);

        $this->expectException(Error::class);
        $this->expectExceptionMessage('must return an instance of');

        try {
            ThemeCatalog::getPwgThemes(EventDispatcher::get(), CurrentPaths::get());
        } finally {
            // EventDispatcher is a shared process-wide singleton -- a real
            // reset (not just removing this one handler, no such API
            // exists) so this fake filter can never intercept a later
            // Integration test's own getPwgThemes()/EventDispatcher call.
            EventDispatcher::get()->reset();
        }
    }
}

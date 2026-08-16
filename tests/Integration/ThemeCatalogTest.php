<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\ThemeCatalog;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TranslatorTestFactory;

/**
 * Piwigo\Core\ThemeCatalog::getPwgThemes()/checkThemeInstalled() need a
 * real DB connection ('themes' row set) and a real on-disk theme
 * directory (checkThemeInstalled() does a genuine file_exists()) --
 * Integration, not Unit, matching this repo's own established split. The
 * fixture's own themes table is empty (confirmed live, same fact
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
        LangTestFactory::get()->reset();
        TranslatorTestFactory::get()->reset();
    }

    #[Override]
    protected function tearDown(): void
    {
        LangTestFactory::get()->reset();
        TranslatorTestFactory::get()->reset();
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        parent::tearDown();
    }

    public function testGetPwgThemesSkipsARowWhoseNameIsNull(): void
    {
        // themes.name is a nullable column (schema-confirmed) --
        // is_string($name) is a real runtime guard against exactly this,
        // not defensive padding.
        $this->conn->executeStatement(
            "INSERT INTO themes (id, version, name) VALUES ('broken-theme', '1.0', NULL)"
        );

        try {
            $themes = ThemeCatalog::getPwgThemes(CurrentPathsTestFactory::get(), CurrentConfigTestFactory::get(), LangTestFactory::get(), EntityManagerFactory::build($this->conn));
        } finally {
            $this->conn->executeStatement("DELETE FROM themes WHERE id = 'broken-theme'");
        }

        // The fixture's themes table is otherwise empty -- getPwgThemes()
        // always synthesizes a 'default' entry regardless (see its own
        // docblock), so a result containing only that confirms the
        // null-named row was skipped, not merely filtered out for some
        // unrelated reason.
        expect($themes)
            ->toBe([
                'default' => 'Default',
            ]);
    }

    public function testGetPwgThemesSkipsTheConfiguredMobileThemeWhenShowMobileIsFalse(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO themes (id, version, name) VALUES ('mobile-candidate', '1.0', 'Mobile Candidate')"
        );
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->mobileTheme = 'mobile-candidate';

        try {
            $themes = ThemeCatalog::getPwgThemes(CurrentPathsTestFactory::get(), CurrentConfigTestFactory::get(), LangTestFactory::get(), EntityManagerFactory::build($this->conn), showMobile: false);
        } finally {
            $this->conn->executeStatement("DELETE FROM themes WHERE id = 'mobile-candidate'");
        }

        // getPwgThemes() always synthesizes a 'default' entry (see its own
        // docblock) -- confirms 'mobile-candidate' itself was hidden, not
        // merely that the whole result happened to be empty.
        expect($themes)
            ->toBe([
                'default' => 'Default',
            ]);
    }

    public function testGetPwgThemesAppendsTheMobileSuffixAndIncludesTheThemeWhenShowMobileIsTrue(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO themes (id, version, name) VALUES ('default', '1.0', 'Default')"
        );
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->mobileTheme = 'default';

        try {
            $themes = ThemeCatalog::getPwgThemes(CurrentPathsTestFactory::get(), CurrentConfigTestFactory::get(), LangTestFactory::get(), EntityManagerFactory::build($this->conn), showMobile: true);
        } finally {
            $this->conn->executeStatement("DELETE FROM themes WHERE id = 'default'");
        }

        // 'default' is checkThemeInstalled()'s one real installed theme,
        // so it's the only row that survives both the mobile-suffix
        // append AND the installed-directory check.
        expect($themes)
            ->toBe([
                'default' => 'Default (Mobile)',
            ]);
    }

    public function testGetPwgThemesStillSynthesizesTheDefaultThemeWithNoRealRowAtAll(): void
    {
        // No themes row at all (this file's own fixture starts and stays
        // empty) -- confirms 'default' is present even with nothing in
        // the DB, not just when a real row happens to exist. Was also
        // this class's own coverage of the `GetPwgThemes` plugin event
        // (P32 Stage A5 -- deleted, zero production listeners, and this
        // was its only test-suite use, testing the filter mechanism
        // itself rather than isolating something else).
        $themes = ThemeCatalog::getPwgThemes(CurrentPathsTestFactory::get(), CurrentConfigTestFactory::get(), LangTestFactory::get(), EntityManagerFactory::build($this->conn));

        expect($themes)
            ->toBe([
                'default' => 'Default',
            ]);
    }
}

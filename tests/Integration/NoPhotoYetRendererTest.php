<?php

declare(strict_types=1);

// P23 batch 8f-4: the conf_update_param() function stub is gone --
// NoPhotoYetRenderer now calls its own constructor-injected ConfigService
// (Legacy Coupling Retirement Phase 8, 8d), a real DBAL-based write no
// bare-function stub can intercept, so the genuine write path runs
// against the same config table via $this->conn below -- no separate
// MysqliDb connection needed any more.

namespace Piwigo\Tests\Integration {

use Doctrine\DBAL\Connection;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Page\NoPhotoYetRenderer;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;

/**
 * Only exercises the guard-condition-false and nb_photos>0 branches --
 * the nb_photos===0 branch calls exit(), unsafe to invoke from a test
 * process (same "don't stub/exercise what would kill the test" reasoning
 * as fatal_error() elsewhere in this suite). The real fixture already
 * has 5 images, so the guard-passing path here naturally never reaches
 * that branch.
 */
final class NoPhotoYetRendererTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private NoPhotoYetRenderer $renderer;

    private Connection $conn;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->renderer = new NoPhotoYetRenderer($this->conn, new ConfigService($this->buildConfigRepository()), new RedirectService(), new UrlService(new HtmlService()), Paths::fromRoot(dirname(__DIR__, 2)));

        // NoPhotoYetRenderer calls Piwigo\Auth\AccessControl::isAGuest()/
        // isAdmin() directly (real class methods), which read
        // Piwigo\Users\CurrentUser (Legacy Coupling Retirement Track A
        // batch A3).
        CurrentUser::set(User::fromUserArray(['status' => 'guest', 'username' => 'fixture_guest']));
        unset($_SESSION['no_photo_yet']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        unset($_SESSION['no_photo_yet']);
        // the fixture doesn't seed a 'no_photo_yet' row at all (real
        // installs only get one once conf_update_param() first writes it,
        // same as this class's own tests) -- delete rather than reset to
        // a value, restoring the true baseline.
        $this->conn->executeStatement(
            "DELETE FROM " . Tables::config() . " WHERE param = 'no_photo_yet'"
        );
        parent::tearDown();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        // Close the legacy global mysqli handle setUp() opened: the rest of
        // the Integration suite shares this PHP process and is written to the
        // invariant that no legacy connection exists (see e.g.
        // SectionInitializerTest's header) -- leaking it flips later files'
        // MysqliDb-reachable branches from dead to live.
        if (($GLOBALS['mysqli'] ?? null) instanceof \mysqli) {
            $GLOBALS['mysqli']->close();
        }
        unset($GLOBALS['mysqli']);
        parent::tearDownAfterClass();
    }

    private function seedFlag(string $value): void
    {
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::config() . " (param, value) VALUES ('no_photo_yet', ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [$value]
        );
    }

    private function readFlag(): string|false
    {
        $value = $this->conn->createQueryBuilder()
            ->select('value')
            ->from(Tables::config())
            ->where("param = 'no_photo_yet'")
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : false;
    }

    public function test_render_does_nothing_when_the_session_hide_flag_is_set(): void
    {
        $_SESSION['no_photo_yet'] = 'browse';
        $this->seedFlag('true');

        $this->renderer->render();

        self::assertSame('true', $this->readFlag());
    }

    public function test_render_deactivates_the_flag_when_the_gallery_already_has_photos(): void
    {
        $this->seedFlag('true');

        $this->renderer->render();

        self::assertSame('false', $this->readFlag());
    }
}
}

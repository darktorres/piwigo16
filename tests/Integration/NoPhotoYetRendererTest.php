<?php

declare(strict_types=1);

// conf_update_param() (include/functions.inc.php) isn't loaded by this
// isolated Integration test process (no test file requires the whole
// procedural bootstrap) -- a minimal, faithful stand-in persisting via
// the real config table with bound parameters, matching what the real
// function ultimately does for a plain scalar $value. Guarded so it
// doesn't collide if another file ever defines the same stub.
namespace {
    if (! function_exists('conf_update_param')) {
        function conf_update_param(string $param, mixed $value, bool $updateGlobal = false, mixed $parser = null): void
        {
            $dbValue = is_string($value) ? $value : '';
            \Piwigo\Db\DbConnection::build()->executeStatement(
                'INSERT INTO ' . \Piwigo\Db\Tables::config() . ' (param, value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$param, $dbValue]
            );

            if ($updateGlobal) {
                /** @var array<string, mixed> $conf */
                global $conf;
                $conf[$param] = $value;
            }
        }
    }
}

namespace Piwigo\Tests\Integration {

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Page\NoPhotoYetRenderer;

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

        Config::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->renderer = new NoPhotoYetRenderer($this->conn);

        $GLOBALS['conf'] = is_array($GLOBALS['conf'] ?? null) ? $GLOBALS['conf'] : [];
        $GLOBALS['user'] = ['status' => 'guest', 'username' => 'fixture_guest'];
        // is_a_guest()/is_admin() are real functions_user.inc.php functions,
        // but whichever Integration test file's own function_exists()-
        // guarded global stub for them loaded first in this shared process
        // wins for the whole run (see CommentServiceTest.php's own stub) --
        // that stub reads $GLOBALS['test_is_guest'], not global $user, when
        // called with no argument (this class's own real call convention).
        $GLOBALS['test_is_guest'] = true;
        $GLOBALS['test_is_admin'] = false;
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

<?php

declare(strict_types=1);

// NoPhotoYetRenderer calls its own constructor-injected ConfigService, a
// real DBAL-based write -- the genuine write path runs against the same
// config table via $this->conn below.

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use LogicException;
    use mysqli;
    use Override;
    use Piwigo\Auth\AccessLevelChecker;
    use Piwigo\Bootstrap\RedirectService;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Config\ConfigService;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Core\AdminContext;
    use Piwigo\Core\ApiContext;
    use Piwigo\Core\Kernel;
    use Piwigo\Core\LayoutState;
    use Piwigo\Core\Paths;
    use Piwigo\Core\ProcessCache;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Http\ResponseReadyException;
    use Piwigo\Image\ImageEntity;
    use Piwigo\Page\NoPhotoYetRenderer;
    use Piwigo\PluginConfig\EventDispatcher;
    use Piwigo\Template\Renderer;
    use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
    use Piwigo\Tests\Support\CurrentConfigTestFactory;
    use Piwigo\Tests\Support\CurrentTemplateTestFactory;
    use Piwigo\Tests\Support\CurrentUserTestFactory;
    use Piwigo\Tests\Support\HtmlServiceTestFactory;
    use Piwigo\Tests\Support\ImageStdParamsTestFactory;
    use Piwigo\Tests\Support\LangTestFactory;
    use Piwigo\Tests\Support\PageStateTestFactory;
    use Piwigo\Tests\Support\UrlServiceTestFactory;
    use Piwigo\Users\User;
    use Piwigo\Users\UserService;

    /**
     * [P44-A] That same untestable admin (isAdmin()) branch is also where
     * `no_photo_yet.latte`'s `nextStepUrl`/`deactivateUrl`/`loginUrl` prints
     * live -- previously `|noescape`'d with `nextStepUrl` sourced straight
     * from the admin-configurable `noPhotoYetUrl` config value with no
     * escaping at all (an admin-self-XSS gap: only reachable by an admin
     * configuring a malicious value into their own site). Fixed by
     * removing `|noescape` from all 3 (trusting Latte's own auto-escape,
     * this campaign's established pattern) -- not independently
     * regression-tested here for the same 2 reasons this docblock already
     * gives for the branch itself being unreachable from this test file.
     *
     * Exercises the guard-condition-false branch, the nb_photos>0 branch, and
     * (via the transaction+rollback trick documented above the 2 tests near
     * the bottom of this file) the nb_photos===0 branch's 'browse'/'deactivate'
     * GET-param sub-branches, both of which redirect via the catchable
     * RedirectServiceInterface::redirect() before reaching real terminal
     * behavior. The nb_photos===0 branch's remaining "neither browse nor
     * deactivate" sub-branch (NoPhotoYetRenderer.php's own real body, roughly
     * lines 76-111: the header() call, both isAdmin()
     * template->assign() arms, the loc_end_no_photo_yet EventDispatcher
     * notify, and finally `Renderer::render()` + `Template::finalizeHtml()`
     * + a bare exit()) stays genuinely untested from here, for 2
     * independent reasons:
     *
     *  1. That exit() is a real, uncatchable process termination -- unlike
     *     redirect(), it isn't routed through anything interceptable (see
     *     NoPhotoYetRenderer's own class docblock), and rendering
     *     against the real themes/default/template/no_photo_yet.latte (which
     *     does exist in this repo) has no reason to throw first, so calling
     *     render() this way from this shared PHPUnit/Pest CLI process would
     *     kill the whole process mid-suite -- same "don't stub/exercise what
     *     would kill the test" reasoning as fatal_error() elsewhere in this
     *     suite. No runkit/uopz extension is installed here to stub exit()
     *     itself either (see Unit/Core/ErrorCollectorTest.php's own docblock
     *     for the same constraint applied to headers_sent()).
     *
     *  2. Reaching it at all requires NoPhotoYetRepository::countAllImages()
     *     (an unfiltered `SELECT COUNT(*) FROM images`) to observe zero rows
     *     for a REAL request against the live Apache-served app -- which
     *     rules out the same connection-local transaction+rollback trick the
     *     2 tests below use for the 'browse'/'deactivate' sub-branches,
     *     since Apache's own DB connection is a separate connection that
     *     never sees this test's uncommitted work. The only way to make it
     *     see zero rows for real is to actually empty the shared `images`
     *     table (committed, not just uncommitted) for the duration of a live
     *     HTTP request, then restore it -- but that table is cascade-linked
     *     (ON DELETE CASCADE/SET NULL) from image_category, image_tag,
     *     image_format, comments, favorites, caddie, lounge, rate,
     *     categories.representative_picture_id and history.image_id, is
     *     shared with every other Browser test file in the same suite run,
     *     and already holds far more rows than the committed 5-image
     *     fixture ships. A byte-exact restore across that
     *     many FK-linked tables is not something that can safely be authored
     *     AND verified here (verifying it would mean actually running it
     *     against that same shared table), so it's deliberately left
     *     undone rather than risked -- verifying it live against the same
     *     shared table used by every other Browser test in the run would
     *     itself be the unsafe step.
     */
    final class NoPhotoYetRendererTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private NoPhotoYetRenderer $renderer;

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

            $currentConfig = Kernel::container()->get(CurrentConfig::class);
            if (! $currentConfig instanceof CurrentConfig) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
            }
            $currentConfig->reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();

            $this->conn = DbConnection::build();
            CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), CurrentConfigTestFactory::get()));
            $userService = Kernel::container()->get(UserService::class);
            if (! $userService instanceof UserService) {
                throw new LogicException('Container returned an unexpected type for ' . UserService::class);
            }
            $this->renderer = new NoPhotoYetRenderer(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), EntityManagerFactory::build($this->conn)->getRepository(ImageEntity::class), new ConfigService($this->buildConfigRepository(), CurrentConfigTestFactory::get()), new RedirectService(LangTestFactory::get(), $userService, new EventDispatcher(), new LayoutState(), new Renderer(CurrentTemplateTestFactory::get())), UrlServiceTestFactory::build(), Paths::fromRoot(dirname(__DIR__, 2)), new AdminContext(), new ApiContext(), new EventDispatcher(), CurrentUserTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get(), new ProcessCache(), CurrentConfigServiceTestFactory::get(), new Renderer(CurrentTemplateTestFactory::get()), PageStateTestFactory::get(), HtmlServiceTestFactory::build(), ImageStdParamsTestFactory::get());

            // NoPhotoYetRenderer calls Piwigo\Auth\AccessControl::isAGuest()/
            // isAdmin() directly (real class methods), which read
            // Piwigo\Users\CurrentUser.
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 2,
                'status' => 'guest',
                'username' => 'fixture_guest',
            ]));
            unset($_SESSION['no_photo_yet']);
        }

        #[Override]
        protected function tearDown(): void
        {
            unset($_SESSION['no_photo_yet']);
            // the fixture doesn't seed a 'no_photo_yet' row at all (real
            // installs only get one once conf_update_param() first writes it,
            // same as this class's own tests) -- delete rather than reset to
            // a value, restoring the true baseline.
            $this->conn->executeStatement(
                "DELETE FROM config WHERE param = 'no_photo_yet'"
            );
            parent::tearDown();
        }

        #[Override]
        public static function tearDownAfterClass(): void
        {
            // Close the legacy global mysqli handle setUp() opened: the rest of
            // the Integration suite shares this PHP process and is written to the
            // invariant that no legacy connection exists (see e.g.
            // SectionInitializerTest's header) -- leaking it flips later files'
            // MysqliDb-reachable branches from dead to live.
            if (($GLOBALS['mysqli'] ?? null) instanceof mysqli) {
                $GLOBALS['mysqli']->close();
            }
            unset($GLOBALS['mysqli']);
            parent::tearDownAfterClass();
        }

        private function seedFlag(string $value): void
        {
            // `ON DUPLICATE KEY UPDATE` is MySQL-only syntax. Postgres's
            // portable equivalent is `ON CONFLICT (<unique/PK column>) DO
            // UPDATE SET ... = EXCLUDED. ...` -- `param` is config's own
            // primary key.
            $onConflict = $this->dbDriver === 'pgsql'
                ? 'ON CONFLICT (param) DO UPDATE SET value = EXCLUDED.value'
                : 'ON DUPLICATE KEY UPDATE value = VALUES(value)';
            $this->conn->executeStatement(
                "INSERT INTO config (param, value) VALUES ('no_photo_yet', ?) {$onConflict}",
                [$value]
            );
        }

        private function readFlag(): string|false
        {
            $value = $this->conn->createQueryBuilder()
                ->select('value')
                ->from('config')
                ->where("param = 'no_photo_yet'")
                ->executeQuery()
                ->fetchOne();

            return is_string($value) ? $value : false;
        }

        public function testRenderDoesNothingWhenTheSessionHideFlagIsSet(): void
        {
            $_SESSION['no_photo_yet'] = 'browse';
            $this->seedFlag('true');

            $this->renderer->render();

            self::assertSame('true', $this->readFlag());
        }

        public function testRenderDeactivatesTheFlagWhenTheGalleryAlreadyHasPhotos(): void
        {
            $this->seedFlag('true');

            $this->renderer->render();

            // ConfigService::confUpdateParam() json_encode()s the string
            // 'false' (no_photo_yet is ?string-typed, not bool -- a real
            // three-state marker: null/'true'/'false'), so the raw stored
            // bytes are JSON-quoted.
            self::assertSame('"false"', $this->readFlag());
        }

        // The 2 tests below reach the nb_photos===0 branch by wrapping the
        // fixture's own images-table deletion in a transaction on this same
        // Doctrine connection ($this->conn -- the exact connection the
        // renderer's own COUNT(*) query runs against, so it observes the
        // uncommitted delete without needing a separate restore step) and
        // always rolling it back in `finally`, restoring every FK-cascaded
        // row (image_category, favorites, comments, ...) exactly as the
        // shared fixture left them for every other test in this file/process.
        // Only the 'browse'/'deactivate' GET-param sub-branches are safe to
        // reach this way -- both call RedirectServiceInterface::redirect(),
        // which (per RedirectService's own docblock) throws the catchable
        // Piwigo\Http\ResponseReadyException instead of a real exit(), same
        // established pattern as MaintenanceActionDispatcherTest. The
        // remaining "neither browse nor deactivate" sub-branch (roughly
        // NoPhotoYetRenderer.php lines 76-111) still ends in a real,
        // uncatchable exit() after Renderer::render()/Template::finalizeHtml()
        // and stays untested --
        // see this class's own docblock above for the full reasoning (both
        // the uncatchable-exit() half and the separate "would require
        // destructively emptying the shared images table" half).

        public function testRenderSetsTheBrowseSessionFlagAndRedirectsWhenTheGalleryIsEmpty(): void
        {
            $_GET['no_photo_yet'] = 'browse';
            $this->conn->beginTransaction();

            try {
                $this->conn->executeStatement('DELETE FROM images');

                try {
                    $this->renderer->render();
                    self::fail('Expected RedirectServiceInterface::redirect() to throw ResponseReadyException');
                } catch (ResponseReadyException) {
                    // redirect() is `never`-typed -- this catchable exception is
                    // its real non-exit() implementation.
                }

                self::assertSame('browse', $_SESSION['no_photo_yet'] ?? null);
            } finally {
                $this->conn->rollBack();
                unset($_GET['no_photo_yet']);
            }
        }

        public function testRenderDeactivatesTheFlagAndRedirectsWhenTheGalleryIsEmpty(): void
        {
            $_GET['no_photo_yet'] = 'deactivate';
            $this->conn->beginTransaction();

            try {
                $this->conn->executeStatement('DELETE FROM images');

                try {
                    $this->renderer->render();
                    self::fail('Expected RedirectServiceInterface::redirect() to throw ResponseReadyException');
                } catch (ResponseReadyException) {
                }
            } finally {
                $this->conn->rollBack();
                unset($_GET['no_photo_yet']);
            }

            // confUpdateParam() persists through ConfigService's own
            // (separate, non-transactional) connection -- unaffected by this
            // test's rollback of the images-table deletion above. Checked only
            // after rollBack(): $this->conn's own transaction (still open
            // above, opened before that separate connection's write) reads
            // under a consistent snapshot that predates it, so a read
            // through the same still-open transaction cannot see it.
            self::assertSame('"false"', $this->readFlag());
            $this->conn->executeStatement("DELETE FROM config WHERE param = 'no_photo_yet'");
        }
    }
}

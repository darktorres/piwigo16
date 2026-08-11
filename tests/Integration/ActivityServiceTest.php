<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use LogicException;
    use Override;
    use Piwigo\Activity\ActivityEntity;
    use Piwigo\Activity\ActivityService;
    use Piwigo\Activity\Projection\CoreUpdateHistoryRow;
    use Piwigo\Activity\Projection\SystemActionCount;
    use Piwigo\Activity\Projection\UserAgentBreakdown;
    use Piwigo\Common\ValueObject\SqlDateTime;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Core\ActivitySystem;
    use Piwigo\Core\Kernel;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Tests\Support\CurrentUserTestFactory;
    use Piwigo\Users\User;

    final class ActivityServiceTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private ActivityService $service;

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

            $currentConfig->phpExtensionInUrls = false;
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 1,
            ]));
            CurrentUserTestFactory::get()->markRealUserResolved();
            unset($_REQUEST['method'], $_REQUEST['action'], $_GET['page'], $_POST['destination_tag'], $_SESSION['connected_with']);
            $_SERVER['SCRIPT_NAME'] = '/some/script.php';

            $this->conn = DbConnection::build();
            $this->service = new ActivityService(EntityManagerFactory::build($this->conn)->getRepository(ActivityEntity::class));
        }

        #[Override]
        protected function tearDown(): void
        {
            $this->conn->executeStatement("DELETE FROM activity WHERE object LIKE 'test-%'");
            parent::tearDown();
        }

        public function testRecordIsANoOpForAnAutomaticUploadAsyncLogin(): void
        {
            // object_id 888888 is disposable/non-colliding -- the fixture
            // already has 3 real user/1/login rows (activity_id 2, 3, 19), so
            // asserting an absolute 0 for that exact combination would be
            // wrong regardless of this test's own outcome.
            $_REQUEST['method'] = 'pwg.images.uploadAsync';

            $this->service->record('user', 888888, 'login');

            self::assertSame(0, $this->countRows('user', 888888, 'login'));
        }

        public function testRecordIsANoOpForAMismatchedPerformAction(): void
        {
            // matches the real calling convention (src/Piwigo/Admin/plugins.php):
            // pwg_activity('system', ActivitySystem::Plugin, $action, ...) --
            // object_id is always the fixed int sentinel 2, never a plugin
            // slug string (the `activity` table's object_id column is
            // `int unsigned NOT NULL`).
            $_REQUEST['method'] = 'pwg.plugins.performAction';
            $_REQUEST['action'] = 'deactivate';

            $this->service->record('system', 2, 'restore');

            self::assertSame(0, $this->countRows('system', 2, 'restore'));
        }

        public function testRecordProceedsForAMatchingPerformAction(): void
        {
            $_REQUEST['method'] = 'pwg.plugins.performAction';
            $_REQUEST['action'] = 'restore';

            $this->service->record('system', 2, 'restore');

            try {
                self::assertSame(1, $this->countRows('system', 2, 'restore'));
            } finally {
                $this->conn->executeStatement("DELETE FROM activity WHERE object = 'system' AND action = 'restore'");
            }
        }

        public function testRecordStoresTheRequestMethodInDetails(): void
        {
            $_REQUEST['method'] = 'pwg.tags.add';

            $this->service->record('test-tag', 1, 'add');

            $details = $this->fetchDetails('test-tag', 1, 'add');
            self::assertIsArray($details);
            self::assertSame('pwg.tags.add', $details['method']);
        }

        public function testRecordFallsBackToScriptBasenameWhenNoMethod(): void
        {
            $this->service->record('test-script', 1, 'add');

            $details = $this->fetchDetails('test-script', 1, 'add');
            self::assertIsArray($details);
            self::assertSame('script', $details['script']);
        }

        public function testRecordAppendsTheAdminPageToTheScriptDetail(): void
        {
            $_SERVER['SCRIPT_NAME'] = '/admin.php';
            $_GET['page'] = 'user_activity';

            $this->service->record('test-admin', 1, 'add');

            $details = $this->fetchDetails('test-admin', 1, 'add');
            self::assertIsArray($details);
            self::assertSame('admin/user_activity', $details['script']);
        }

        public function testRecordUsesBareAdminScriptWhenNoPageParamIsPresent(): void
        {
            // Distinguishes the `&&` from a `||` mutant on this condition:
            // script==='admin' is true but pageParam is null, so the concat
            // branch must NOT be taken -- a `||` mutant would wrongly
            // concatenate an empty page param onto the script.
            $_SERVER['SCRIPT_NAME'] = '/admin.php';

            $this->service->record('test-admin-no-page', 1, 'add');

            $details = $this->fetchDetails('test-admin-no-page', 1, 'add');
            self::assertIsArray($details);
            self::assertSame('admin', $details['script']);
        }

        public function testRecordUnsetsMethodAndScriptOnAutoupdate(): void
        {
            $_REQUEST['method'] = 'pwg.plugins.performAction';
            $_REQUEST['action'] = 'autoupdate';

            $this->service->record('test-autoupdate', 1, 'autoupdate');

            $details = $this->fetchDetails('test-autoupdate', 1, 'autoupdate');
            self::assertIsArray($details);
            self::assertArrayNotHasKey('method', $details);
            self::assertArrayNotHasKey('script', $details);
        }

        public function testRecordCapturesTheUserAgentOnLogin(): void
        {
            // user-agent capture is hardcoded to the literal object 'user',
            // so this can't use the 'test-' prefix tearDown() otherwise relies
            // on -- clean up explicitly instead (object_id 777 doesn't collide
            // with any fixture row).
            $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

            try {
                $this->service->record('user', 777, 'login');

                $userAgent = $this->conn->createQueryBuilder()
                    ->select('user_agent')
                    ->from('activity')
                    ->where('object_id = 777')
                    ->executeQuery()
                    ->fetchOne();

                self::assertSame('TestAgent/1.0', $userAgent);
            } finally {
                $this->conn->executeStatement('DELETE FROM activity WHERE object_id = 777');
            }
        }

        public function testRecordDoesNotCaptureUserAgentForANonLoginAction(): void
        {
            $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

            $this->service->record('test-non-login', 1, 'add');

            $userAgent = $this->conn->createQueryBuilder()
                ->select('user_agent')
                ->from('activity')
                ->where("object = 'test-non-login'")
                ->executeQuery()
                ->fetchOne();

            self::assertNull($userAgent);
        }

        public function testRecordDoesNotCaptureUserAgentWhenObjectIsUserButActionIsNotLogin(): void
        {
            // Distinguishes the object/action `&&` from a `||` mutant:
            // object==='user' alone (action isn't 'login') must not be enough
            // to capture the user agent.
            $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

            try {
                $this->service->record('user', 778, 'add');

                $userAgent = $this->conn->createQueryBuilder()
                    ->select('user_agent')
                    ->from('activity')
                    ->where('object_id = 778')
                    ->executeQuery()
                    ->fetchOne();

                self::assertNull($userAgent);
            } finally {
                $this->conn->executeStatement('DELETE FROM activity WHERE object_id = 778');
            }
        }

        public function testRecordMarksApiKeyAuthenticationWhenSessionIndicatesIt(): void
        {
            $_SESSION['connected_with'] = 'api_key';
            $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

            try {
                $this->service->record('test-api-key', 1, 'add');

                $details = $this->fetchDetails('test-api-key', 1, 'add');
                self::assertIsArray($details);
                self::assertSame('api_key', $details['connected_with']);
            } finally {
                unset($_SESSION['connected_with']);
            }
        }

        public function testRecordDoesNotMarkApiKeyAuthenticationWithoutAUserAgent(): void
        {
            // Distinguishes the `&&` from a `||` mutant on this condition:
            // connected_with==='api_key' is true but there's no user agent, so
            // the block must not run at all.
            $_SESSION['connected_with'] = 'api_key';
            unset($_SERVER['HTTP_USER_AGENT']);

            try {
                $this->service->record('test-api-key-no-ua', 1, 'add');

                $details = $this->fetchDetails('test-api-key-no-ua', 1, 'add');
                self::assertIsArray($details);
                self::assertArrayNotHasKey('connected_with', $details);
            } finally {
                unset($_SESSION['connected_with']);
            }
        }

        public function testRecordMarksSyncForAPhotoDeleteViaSiteUpdate(): void
        {
            $_GET['page'] = 'site_update';

            try {
                $this->service->record('photo', 888885, 'delete');

                $details = $this->fetchDetails('photo', 888885, 'delete');
                self::assertIsArray($details);
                self::assertTrue($details['sync']);
            } finally {
                $this->conn->executeStatement('DELETE FROM activity WHERE object_id = 888885');
            }
        }

        public function testRecordMarksSyncForAnAlbumDeleteViaSiteUpdate(): void
        {
            // Distinguishes in_array()'s ['album', 'photo'] haystack from a
            // mutant that drops 'album' -- object='photo' alone (see the test
            // above) can't catch that specific truncation.
            $_GET['page'] = 'site_update';

            try {
                $this->service->record('album', 888884, 'delete');

                $details = $this->fetchDetails('album', 888884, 'delete');
                self::assertIsArray($details);
                self::assertTrue($details['sync']);
            } finally {
                $this->conn->executeStatement('DELETE FROM activity WHERE object_id = 888884');
            }
        }

        public function testRecordDoesNotMarkSyncForAPhotoAddEvenWithSiteUpdatePage(): void
        {
            // Distinguishes the leading `&&` from a `||` mutant: object is a
            // sync-eligible type and pageParam is 'site_update', but action
            // isn't 'delete', so sync must not be set.
            $_GET['page'] = 'site_update';

            try {
                $this->service->record('photo', 888883, 'add');

                $details = $this->fetchDetails('photo', 888883, 'add');
                self::assertIsArray($details);
                self::assertArrayNotHasKey('sync', $details);
            } finally {
                $this->conn->executeStatement('DELETE FROM activity WHERE object_id = 888883');
            }
        }

        public function testRecordDetectsATagMerge(): void
        {
            // merge detection is hardcoded to the literal object 'tag', so
            // this can't use the 'test-' prefix tearDown() otherwise relies on
            // -- clean up explicitly instead (the fixture has no 'tag'+'delete'
            // row, only 'tag'+'add', so this DELETE can't touch fixture data).
            $_POST['destination_tag'] = '5';

            try {
                $this->service->record('tag', 1, 'delete');

                $details = $this->fetchDetails('tag', 1, 'delete');
                self::assertIsArray($details);
                self::assertSame('merge', $details['action']);
                self::assertSame('5', $details['destination_tag']);
            } finally {
                $this->conn->executeStatement("DELETE FROM activity WHERE object = 'tag' AND action = 'delete'");
            }
        }

        public function testRecordDoesNotDetectATagMergeForANonDeleteAction(): void
        {
            // Distinguishes the trailing `&&` from a `||` mutant: object is
            // 'tag' and destination_tag is present, but action isn't 'delete',
            // so merge detection must not fire.
            $_POST['destination_tag'] = '5';

            try {
                $this->service->record('tag', 780, 'add');

                $details = $this->fetchDetails('tag', 780, 'add');
                self::assertIsArray($details);
                self::assertArrayNotHasKey('action', $details);
            } finally {
                $this->conn->executeStatement('DELETE FROM activity WHERE object_id = 780');
            }
        }

        public function testRecordFansOutOverMultipleObjectIds(): void
        {
            $this->service->record('test-multi', [10, 20, 30], 'add');

            self::assertSame(3, $this->countRows('test-multi', null, 'add'));
        }

        public function testRecordUsesTheRealSessionIdAsTheSessionIndexWhenASessionIdIsSet(): void
        {
            // session_id() as a pure getter never returns bool in real PHP
            // usage (see tests/Unit/Csrf/CsrfServiceTest.php's own identical
            // precedent for session_id()) -- only its '' vs a real id string
            // distinction is observable, exercised here and by the sibling
            // "no session id" test below.
            session_id('activityservicetest-fixed-session-id');

            $this->service->record('test-session-idx', 1, 'add');

            $sessionIdx = $this->conn->createQueryBuilder()
                ->select('session_idx')
                ->from('activity')
                ->where("object = 'test-session-idx'")
                ->executeQuery()
                ->fetchOne();

            self::assertSame('activityservicetest-fixed-session-id', $sessionIdx);
        }

        public function testRecordUsesNoneForTheSessionIndexWhenNoSessionIdIsSet(): void
        {
            session_id('');

            $this->service->record('test-no-session-idx', 1, 'add');

            $sessionIdx = $this->conn->createQueryBuilder()
                ->select('session_idx')
                ->from('activity')
                ->where("object = 'test-no-session-idx'")
                ->executeQuery()
                ->fetchOne();

            self::assertSame('none', $sessionIdx);
        }

        public function testRecordUsesTheObjectIdAsPerformedByOnLogout(): void
        {
            // should be ignored for logout
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 999999,
            ]));
            CurrentUserTestFactory::get()->markRealUserResolved();

            $this->service->record('test-logout', 1, 'logout');

            $performedBy = $this->conn->createQueryBuilder()
                ->select('performed_by')
                ->from('activity')
                ->where("object = 'test-logout'")
                ->executeQuery()
                ->fetchOne();

            self::assertSame(1, $performedBy);
        }

        public function testRecordUsesTheCurrentUserAsPerformedByOtherwise(): void
        {
            CurrentUserTestFactory::get()->set(User::fromUserArray([
                'id' => 3,
            ]));
            CurrentUserTestFactory::get()->markRealUserResolved();

            $this->service->record('test-performer', 999, 'add');

            $performedBy = $this->conn->createQueryBuilder()
                ->select('performed_by')
                ->from('activity')
                ->where("object = 'test-performer'")
                ->executeQuery()
                ->fetchOne();

            self::assertSame(3, $performedBy);
        }

        public function testRecordDetectsTheAutoLoginAuthFunctionFromTheCallStack(): void
        {
            // No real caller in this rewrite is literally named auto_login()
            // yet (grep-confirmed) -- ActivityService::record() still walks
            // debug_backtrace() looking for that bare function/method name, so
            // this closes the gap directly against a same-named private
            // helper below rather than a real call site.
            try {
                $this->auto_login();

                $details = $this->fetchDetails('user', 555, 'login');
                self::assertIsArray($details);
                self::assertSame('auto_login', $details['auth_function']);
            } finally {
                $this->conn->executeStatement('DELETE FROM activity WHERE object_id = 555');
            }
        }

        public function testRecordDetectsTheAuthKeyLoginAuthFunctionFromTheCallStack(): void
        {
            try {
                $this->auth_key_login();

                $details = $this->fetchDetails('user', 556, 'login');
                self::assertIsArray($details);
                self::assertSame('auth_key_login', $details['auth_function']);
            } finally {
                $this->conn->executeStatement('DELETE FROM activity WHERE object_id = 556');
            }
        }

        public function testRecordDoesNotDetectAuthFunctionForANonLoginAction(): void
        {
            // Distinguishes the `&&` from a `||` mutant: object is 'user' and
            // the call stack literally contains auto_login(), but the action
            // isn't 'login', so auth_function detection must not run at all.
            try {
                $this->auto_login('add', 779);

                $details = $this->fetchDetails('user', 779, 'add');
                self::assertIsArray($details);
                self::assertArrayNotHasKey('auth_function', $details);
            } finally {
                $this->conn->executeStatement('DELETE FROM activity WHERE object_id = 779');
            }
        }

        public function testRecordMarksAPhotoAddAsBrowserAddedWhenTheRefererIsTheAdminPhotosAddPage(): void
        {
            // added_with detection is hardcoded to the literal object 'photo'
            // (the fixture already has 5 real photo/add rows the repository's
            // own count-by-object test depends on, object_id 1-5), so this
            // can't use the 'test-' prefix tearDown() relies on -- a
            // disposable, non-colliding object_id cleaned up explicitly.
            $_SERVER['HTTP_REFERER'] = 'https://example.test/admin.php?page=photos_add';

            try {
                $this->service->record('photo', 888887, 'add');

                $details = $this->fetchDetails('photo', 888887, 'add');
                self::assertIsArray($details);
                self::assertSame('browser', $details['added_with']);
            } finally {
                $this->conn->executeStatement('DELETE FROM activity WHERE object_id = 888887');
            }
        }

        public function testRecordDoesNotMarkAddedWithForAPhotoDelete(): void
        {
            // Distinguishes both `&&`s from `||` mutants: object is 'photo'
            // and sync isn't set, but action isn't 'add', so added_with
            // detection must not run at all.
            unset($_SERVER['HTTP_REFERER']);

            try {
                $this->service->record('photo', 888886, 'delete');

                $details = $this->fetchDetails('photo', 888886, 'delete');
                self::assertIsArray($details);
                self::assertArrayNotHasKey('added_with', $details);
            } finally {
                $this->conn->executeStatement('DELETE FROM activity WHERE object_id = 888886');
            }
        }

        public function testGetUserObjectLogWithUsernamesDelegatesToTheRepository(): void
        {
            $rows = $this->service->getUserObjectLogWithUsernames();

            // Fixture: object='user' rows are activity_id 3, 4, 15, 16 (2
            // logins + 2 adds), all performed_by fixture_admin -- same baseline
            // ActivityRepositoryTest::test_find_user_object_log_with_usernames()
            // asserts directly against the repository.
            self::assertCount(4, $rows);
            foreach ($rows as $row) {
                self::assertSame('user', $row->object);
                self::assertSame('fixture_admin', $row->username);
            }
        }

        public function testGetSystemActionCountsByObjectIdDelegatesToTheRepository(): void
        {
            // Fixture: activity_id 1 (object='system', object_id=3, action=
            // 'activate') and activity_id 2 (object='system', object_id=1,
            // action='install') -- see ActivityRepositoryTest's own fixture
            // docblock for the full row-by-row breakdown. Neither collides with
            // any object_id/action pair the record()-based tests above insert
            // (2/'restore').
            $rows = $this->service->getSystemActionCountsByObjectId();

            $activate = array_values(array_filter(
                $rows,
                static fn (SystemActionCount $row): bool => $row->objectId === 3 && $row->action === 'activate'
            ));
            self::assertCount(1, $activate);
            self::assertSame('system', $activate[0]->object);
            self::assertSame(1, $activate[0]->counter);

            $install = array_values(array_filter(
                $rows,
                static fn (SystemActionCount $row): bool => $row->objectId === 1 && $row->action === 'install'
            ));
            self::assertCount(1, $install);
            self::assertSame(1, $install[0]->counter);
        }

        public function testGetUserAgentBreakdownDelegatesToTheRepository(): void
        {
            // Fixture: activity_id 3/4 (object='user', action='login', both
            // performed_by fixture_admin) both carry user_agent=
            // 'PiwigoFixtureRegen/1.0' -- see ActivityRepositoryTest's own
            // fixture docblock. Distinct from every user-agent value the
            // record()-based tests above insert ('TestAgent/1.0').
            $rows = $this->service->getUserAgentBreakdown();

            $matching = array_values(array_filter(
                $rows,
                static fn (UserAgentBreakdown $row): bool => $row->userAgent === 'PiwigoFixtureRegen/1.0'
            ));
            self::assertCount(1, $matching);
            self::assertSame(2, $matching[0]->counter);
        }

        public function testGetCoreUpdateHistoryDelegatesToTheRepository(): void
        {
            // No fixture row matches ('activity_id 2 is object_id=1/'install',
            // not 'update'/'autoupdate' -- see findCoreUpdateHistory()'s own
            // action filter), so this inserts its own disposable row directly
            // via the repository, same technique/shape as
            // ActivityRepositoryTest::test_find_core_update_history_filters_by_object_and_actions().
            $repo = EntityManagerFactory::build($this->conn)->getRepository(ActivityEntity::class);
            $repo->insertMany([
                [
                    'object' => 'system',
                    'objectId' => ActivitySystem::Core,
                    'action' => 'update',
                    'performedBy' => null,
                    'sessionIdx' => 'sess-1',
                    'ipAddress' => null,
                    'occuredOn' => SqlDateTime::from('2026-07-10 00:00:00'),
                    'details' => [
                        'from_version' => '16.0.0',
                        'to_version' => '17.0.0',
                    ],
                    'userAgent' => null,
                ],
            ]);

            try {
                $rows = $this->service->getCoreUpdateHistory();

                $matching = array_values(array_filter($rows, static fn (CoreUpdateHistoryRow $row): bool => $row->action === 'update'));
                self::assertCount(1, $matching);
                self::assertSame('2026-07-10 00:00:00', $matching[0]->occuredOn);
                self::assertIsString($matching[0]->details);
                // MySQL's JSON column type reorders object members (by key
                // length, then lexicographically) independent of the original
                // insertion order -- ksort() both sides, matching this
                // codebase's own established convention for this gotcha (see
                // tests/Contract/WsImagesFilteredSearchTest.php's docblock).
                $expectedDetails = [
                    'from_version' => '16.0.0',
                    'to_version' => '17.0.0',
                ];
                $actualDetails = json_decode($matching[0]->details, true);
                ksort($expectedDetails);
                self::assertIsArray($actualDetails);
                ksort($actualDetails);
                self::assertSame($expectedDetails, $actualDetails);
            } finally {
                $this->conn->executeStatement(
                    "DELETE FROM activity WHERE object = 'system' AND action = 'update' AND object_id = " . ActivitySystem::Core
                );
            }
        }

        // Named literally auto_login()/auth_key_login() on purpose -- see the
        // two test methods above; ActivityService::record() matches on the
        // bare function/method name from debug_backtrace(), not this class's
        // own naming convention. $action/$objectId are overridable so
        // test_record_does_not_detect_auth_function_for_a_non_login_action()
        // can reuse this same literally-named call site with a non-'login'
        // action, rather than needing its own identically-named sibling method
        // (PHP allows only one method per name).
        private function auto_login(string $action = 'login', int $objectId = 555): void
        {
            $this->service->record('user', $objectId, $action);
        }

        private function auth_key_login(): void
        {
            $this->service->record('user', 556, 'login');
        }

        public function testRecordWritesANullPerformedByWhenNoUserIsLoaded(): void
        {
            // activity.performed_by has an ON DELETE SET NULL foreign key to
            // users.id, and 0 is never a valid user id (AUTO_INCREMENT starts
            // at 1), so no-known-actor must be represented as NULL, not 0.
            // CurrentUserTestFactory::get()->isInitialized() is always true by
            // this point (setUp() already called
            // CurrentUserTestFactory::get()->set()) -- resetRealUserResolvedFlag()
            // simulates "no real user resolved this request".
            CurrentUserTestFactory::get()->resetRealUserResolvedFlag();

            $this->service->record('test-no-user', 1, 'add');

            $performedBy = $this->conn->createQueryBuilder()
                ->select('performed_by')
                ->from('activity')
                ->where("object = 'test-no-user'")
                ->executeQuery()
                ->fetchOne();

            self::assertNull($performedBy);
        }

        private function countRows(string $object, int|string|null $objectId, string $action): int
        {
            $qb = $this->conn->createQueryBuilder()
                ->select('COUNT(*)')
                ->from('activity')
                ->where('object = :object')
                ->andWhere('action = :action')
                ->setParameter('object', $object)
                ->setParameter('action', $action);

            if ($objectId !== null) {
                $qb->andWhere('object_id = :objectId')
                    ->setParameter('objectId', $objectId);
            }

            $value = $qb->executeQuery()
                ->fetchOne();

            return is_numeric($value) ? (int) $value : 0;
        }

        /**
         * @return array<string, mixed>|null
         */
        private function fetchDetails(string $object, int $objectId, string $action): ?array
        {
            $value = $this->conn->createQueryBuilder()
                ->select('details')
                ->from('activity')
                ->where('object = :object')
                ->andWhere('object_id = :objectId')
                ->andWhere('action = :action')
                ->setParameter('object', $object)
                ->setParameter('objectId', $objectId)
                ->setParameter('action', $action)
                ->executeQuery()
                ->fetchOne();

            if (! is_string($value)) {
                return null;
            }

            $details = json_decode($value, true);
            if (! is_array($details)) {
                return null;
            }

            $stringKeyed = [];
            foreach ($details as $key => $item) {
                if (is_string($key)) {
                    $stringKeyed[$key] = $item;
                }
            }

            return $stringKeyed;
        }
    }
}

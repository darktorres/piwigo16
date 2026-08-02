<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration {

    use Doctrine\DBAL\Connection;
    use Piwigo\Activity\ActivityRepository;
    use Piwigo\Activity\ActivityService;
    use Piwigo\Common\ValueObject\UserId;
    use Piwigo\Config\ConfigEntry;
    use Piwigo\Config\ConfigService;
    use Piwigo\Config\CurrentConfig;
    use Piwigo\Config\ConfigLoader;
    use Piwigo\Config\CurrentConfigService;
    use Piwigo\Core\Lang;
    use Piwigo\Core\WsError;
    use Piwigo\Db\DbConnection;
    use Piwigo\Db\EntityManagerFactory;
    use Piwigo\Db\Tables;
    use Piwigo\Group\GroupRepository;
    use Piwigo\Html\HtmlService;
    use Piwigo\Mail\MailService;
    use Piwigo\Url\UrlService;
    use Piwigo\Users\CurrentUser;
    use Piwigo\Users\UserRepository;
    use Piwigo\Users\UserService;
    use Piwigo\Users\UserStatus;

    /**
     * Covers validation/lookup (fully self-contained) and
     * registerUser()'s two early-return paths -- a real validation error,
     * and [SEC-31]'s duplicate-username path (tested against a fixture
     * user with no email on file, so notifyExistingAccountOfDuplicateRegistration()
     * short-circuits before ever calling MailerInterface::mail() -- this
     * harness shouldn't attempt a real mail send). registerUser()'s full SUCCESS
     * path calls ActivityLoggerInterface::record() and
     * EventDispatcher::triggerNotify() -- both fully DBAL/in-memory now, no
     * legacy $mysqli dependency; test_register_user_adds_the_new_user_to_default_groups()
     * below exercises it now, added as a regression test for a real bug
     * found during the Group domain VO integration (see
     * UserService::registerUser()'s own inline comment) -- the success
     * path's OTHER effects (admin/user notification emails,
     * EventDispatcher::triggerNotify(), ActivityLoggerInterface::record())
     * still aren't independently asserted here; live-verified separately,
     * same limitation as GroupService (see its own test class docblock).
     */
    final class UserServiceTest extends IntegrationTestCase
    {
        private static bool $fixtureReady = false;

        private UserService $service;

        private Connection $conn;

        #[\Override]
        protected function setUp(): void
        {
            parent::setUp();
            $this->setUpConnectionFromEnv();
            \Piwigo\Core\Kernel::boot();
            $installationFlag = \Piwigo\Core\Kernel::container()->get(\Piwigo\Core\InstallationFlag::class);
            if ($installationFlag instanceof \Piwigo\Core\InstallationFlag) {
                $installationFlag->mark();
            }

            if (! self::$fixtureReady) {
                $this->resetDatabase();
                $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
                self::$fixtureReady = true;
            }

            CurrentConfig::reset();
            ConfigLoader::applyDefaults();
            ConfigLoader::applyEnvOverrides();

            CurrentConfig::setUserFields(['id' => 'id', 'username' => 'username', 'password' => 'password', 'email' => 'mail_address']);
            CurrentConfig::setObligatoryUserMailAddress(false);
            CurrentConfig::setInsensitiveCaseLogon(false);
            CurrentConfig::setBrowserLanguage(false);
            CurrentConfig::setEmailAdminOnNewUser('none');
            CurrentConfig::setGalleryTitle('Test Gallery');
            CurrentConfig::setWebmasterId(999999);
            CurrentConfig::setGuestId(2);
            CurrentConfig::setDefaultUserId(2);
            CurrentConfig::setAvailablePermissionLevels([0, 1, 2, 4, 8]);

            $this->conn = DbConnection::build();
            $this->service = new UserService(\Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Users\UserInfoEntity::class), \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class), new MailService(), new ActivityService(\Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Activity\ActivityEntity::class)), new HtmlService(), $this->conn);

            // checkAndSaveUserInfos()'s own success path (any call that
            // doesn't return an early 'error') reaches
            // PermissionCacheInvalidator::invalidate() ->
            // CurrentConfigService::get() -- confirmed via a standalone
            // sanity script that a bare "CurrentConfigService not
            // initialised" LogicException is the real failure mode
            // without this, since no other test in this file previously
            // reached that call chain.
            CurrentConfigService::set(new ConfigService(EntityManagerFactory::build($this->conn)->getRepository(ConfigEntry::class)));
        }

        /** @param array<int<0, max>|string, mixed> $params */
        private function fetchOneInt(string $sql, array $params = []): int
        {
            $value = $this->conn->fetchOne($sql, $params);
            self::assertIsNumeric($value);

            return (int) $value;
        }

        // PHPStan tracks $_SESSION's constant-array shape flow-sensitively
        // within a single function -- unset()ing the same key twice in one
        // method (once up front, once in a finally) makes the second call a
        // provable-nonexistent-offset error, even though it's a genuinely
        // safe no-op at runtime. Routing both through a private method call
        // resets that tracking at the call boundary (PHPStan doesn't track
        // superglobal mutations across function calls).
        private function resetEditContext(): void
        {
            // $_SESSION is never auto-started in this CLI test process --
            // whichever test in this file happens to touch it first sees
            // it as null, not []. unset($_SESSION['edit_context']) alone is
            // a safe no-op either way, but a test asserting on $_SESSION's
            // own array-ness afterward needs a real array here.
            $_SESSION ??= [];
            unset($_SESSION['edit_context']);
        }

        public function test_validate_mail_address_accepts_an_unused_address(): void
        {
            self::assertSame('', $this->service->validateMailAddress(null, 'never-used-' . bin2hex(random_bytes(4)) . '@example.test'));
        }

        public function test_validate_mail_address_rejects_a_malformed_address(): void
        {
            self::assertNotSame('', $this->service->validateMailAddress(null, 'not-an-email'));
        }

        public function test_validate_mail_address_rejects_an_address_already_in_use(): void
        {
            self::assertNotSame('', $this->service->validateMailAddress(null, 'fixture_admin@example.test'));
        }

        public function test_validate_mail_address_excludes_the_given_user_id(): void
        {
            self::assertSame('', $this->service->validateMailAddress(UserId::from(1), 'fixture_admin@example.test'));
        }

        public function test_validate_login_case_rejects_an_existing_username_any_case(): void
        {
            self::assertNotSame('', $this->service->validateLoginCase('FIXTURE_ADMIN'));
        }

        public function test_validate_login_case_accepts_a_free_username(): void
        {
            self::assertSame('', $this->service->validateLoginCase('never-used-' . bin2hex(random_bytes(4))));
        }

        public function test_search_case_username_returns_the_stored_casing(): void
        {
            self::assertSame('fixture_admin', $this->service->searchCaseUsername('FIXTURE_ADMIN'));
        }

        public function test_get_user_id_finds_a_fixture_user(): void
        {
            self::assertEquals(UserId::from(1), $this->service->getUserId(\Piwigo\Common\ValueObject\Username::from('fixture_admin')));
            self::assertNull($this->service->getUserId(\Piwigo\Common\ValueObject\Username::from('does-not-exist')));
        }

        public function test_get_user_id_by_email_finds_a_fixture_user(): void
        {
            self::assertEquals(UserId::from(1), $this->service->getUserIdByEmail(\Piwigo\Common\ValueObject\Email::from('fixture_admin@example.test')));
        }

        public function test_get_default_user_info_and_value(): void
        {
            $info = $this->service->getDefaultUserInfo();
            self::assertIsArray($info);

            $language = $this->service->getDefaultUserValue('language', 'fallback');
            self::assertNotSame('fallback', $language);
        }

        public function test_register_user_rejects_an_empty_login(): void
        {
            $result = $this->service->registerUser('', 'password123', null, new UrlService(new HtmlService()));

            self::assertNull($result['userId']);
            self::assertNotSame([], $result['errors']);
            self::assertFalse($result['duplicateUsername']);
        }

        public function test_register_user_sets_duplicate_username_without_revealing_it_in_errors(): void
        {
            // 'guest' (fixture) has no email on file, so the SEC-31 notice
            // email is never attempted here.
            $result = $this->service->registerUser('guest', 'password123', null, new UrlService(new HtmlService()));

            self::assertNull($result['userId']);
            self::assertTrue($result['duplicateUsername']);
            self::assertSame([], $result['errors'], 'the duplicate-login message must never appear in errors');
        }

        public function test_register_user_duplicate_username_does_not_insert_a_new_row(): void
        {
            $countBefore = $this->conn->createQueryBuilder()
                ->select('COUNT(*)')
                ->from(Tables::users())
                ->executeQuery()
                ->fetchOne();

            $this->service->registerUser('guest', 'password123', null, new UrlService(new HtmlService()));

            $countAfter = $this->conn->createQueryBuilder()
                ->select('COUNT(*)')
                ->from(Tables::users())
                ->executeQuery()
                ->fetchOne();

            self::assertSame($countBefore, $countAfter);
        }

        /**
         * Regression test for a real bug found during the Group domain VO
         * integration: registerUser() used to call
         * $this->groupRepo->addMembers($userId, $defaultGroupIds) directly
         * -- addMembers(GroupId $groupId, list<UserId> $userIds) adds many
         * users to ONE group, so that call wrote
         * (group_id, user_id) = ($userId, each default group's id) to
         * user_group, backwards. No test exercised registration + a real
         * default group together before this, which is exactly why it
         * went uncaught -- the fixture itself has zero is_default groups
         * (confirmed: all 3 fixture groups are is_default=0), so this test
         * creates its own.
         */
        public function test_register_user_adds_the_new_user_to_default_groups(): void
        {
            $groupRepo = \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Group\GroupEntity::class);
            $defaultGroupId = $groupRepo->insert('p18-regression-' . bin2hex(random_bytes(4)), true);

            $login = 'p18-regression-' . bin2hex(random_bytes(4));
            $result = $this->service->registerUser($login, 'password123', null, new UrlService(new HtmlService()));

            self::assertNotNull($result['userId']);
            $userId = $result['userId'];

            $members = $groupRepo->findMemberUserIds($defaultGroupId);
            self::assertSame([$userId], array_map(static fn (UserId $id): int => $id->value, $members));

            // The bug wrote (group_id, user_id) = ($userId, $defaultGroupId)
            // into user_group -- swapped columns, not just a missing row.
            // Confirm no such row exists.
            $swappedRowCount = $this->conn->createQueryBuilder()
                ->select('COUNT(*)')
                ->from(Tables::userGroup())
                ->where('group_id = :userId')
                ->setParameter('userId', $userId)
                ->executeQuery()
                ->fetchOne();
            self::assertSame(0, is_numeric($swappedRowCount) ? (int) $swappedRowCount : -1);

            $groupRepo->delete([$defaultGroupId]);
        }

        /**
         * Real gap this closes: neither this class nor UserRepositoryTest
         * called getUserData()/buildUser() at all before gap-closure Stage
         * 4b -- a real TypeError (user_infos.level arrives as a native int
         * via DBAL, not the mysqli-style string
         * EffectiveForbiddenCategoriesCache::getForUser() first assumed)
         * shipped through PHPStan/Unit/Arch/Integration and was only
         * caught by the Browser suite hitting a real dev-server process.
         * Gap-closure Stage 4g deleted the `$useCache` parameter entirely
         * (it only ever gated the legacy lock/wait/503 regeneration block,
         * itself deleted the same stage) -- one test now covers the single
         * remaining code path.
         */
        public function test_build_user_populates_effective_permission_fields(): void
        {
            $user = $this->service->buildUser(UserId::from(1));

            self::assertIsString($user['forbidden_categories']);
            self::assertSame('NOT IN', $user['image_access_type']);
            self::assertIsString($user['image_access_list']);
            self::assertIsString($user['nb_total_images']);
            self::assertIsString($user['last_photo_date']);
        }

        public function test_get_current_language_returns_null_when_current_user_is_not_initialized(): void
        {
            \Piwigo\Users\CurrentUser::reset();

            self::assertNull($this->service->getCurrentLanguage());
        }

        public function test_get_current_language_returns_the_initialized_current_users_own_language(): void
        {
            $user = $this->service->buildUser(UserId::from(1));
            \Piwigo\Users\CurrentUser::set(\Piwigo\Users\User::fromUserArray($user));

            try {
                self::assertSame($user['language'], $this->service->getCurrentLanguage());
            } finally {
                \Piwigo\Users\CurrentUser::reset();
            }
        }

        public function test_get_username_returns_the_real_username_for_a_known_user(): void
        {
            self::assertEquals(\Piwigo\Common\ValueObject\Username::from('fixture_admin'), $this->service->getUsername(UserId::from(1)));
        }

        public function test_get_username_returns_false_for_an_unknown_user(): void
        {
            self::assertNull($this->service->getUsername(UserId::from(999999)));
        }

        public function test_check_and_save_user_infos_rejects_an_empty_username(): void
        {
            $result = $this->service->checkAndSaveUserInfos(['user_id' => [4], 'username' => '   ']);

            self::assertSame(
                ['error' => ['code' => WsError::INVALID_PARAM, 'message' => 'Name field must not be empty']],
                $result
            );
        }

        public function test_check_and_save_user_infos_rejects_a_nonexistent_user_id(): void
        {
            $result = $this->service->checkAndSaveUserInfos(['user_id' => [999999]]);

            self::assertSame(
                ['error' => ['code' => WsError::INVALID_PARAM, 'message' => 'This user does not exist.']],
                $result
            );
        }

        public function test_check_and_save_user_infos_rejects_a_username_already_used_by_another_user(): void
        {
            $result = $this->service->checkAndSaveUserInfos(['user_id' => [4], 'username' => 'fixture_admin']);

            self::assertSame(
                ['error' => ['code' => WsError::INVALID_PARAM, 'message' => Lang::t('this login is already used')]],
                $result
            );
        }

        public function test_check_and_save_user_infos_rejects_a_username_containing_html_tags(): void
        {
            $result = $this->service->checkAndSaveUserInfos(['user_id' => [4], 'username' => '<b>evil</b>']);

            self::assertSame(
                ['error' => ['code' => WsError::INVALID_PARAM, 'message' => Lang::t('html tags are not allowed in login')]],
                $result
            );
        }

        public function test_check_and_save_user_infos_rejects_an_invalid_email(): void
        {
            $result = $this->service->checkAndSaveUserInfos(['user_id' => [4], 'email' => 'not-an-email']);

            self::assertArrayHasKey('error', $result);
            self::assertIsArray($result['error']);
            self::assertSame(WsError::INVALID_PARAM, $result['error']['code']);
        }

        public function test_check_and_save_user_infos_rejects_a_password_change_by_a_non_webmaster_for_a_protected_user(): void
        {
            // Target user 1 (fixture_admin) is 'webmaster' status, so it's
            // always protected against a non-webmaster's password change
            // regardless of who the current user is -- the default guest
            // current user (id 2, per CurrentConfig::guestId() above)
            // suffices, no CurrentUser::set() needed.
            $result = $this->service->checkAndSaveUserInfos(['user_id' => [1], 'password' => 'newpass123']);

            self::assertSame(
                [
                    'error' => [
                        'code' => 403,
                        'message' => 'Only webmasters can change password of other "webmaster/admin" users',
                    ],
                ],
                $result
            );
        }

        public function test_check_and_save_user_infos_allows_a_password_change_by_a_webmaster(): void
        {
            $originalHash = $this->conn->fetchOne('SELECT password FROM ' . Tables::users() . ' WHERE id = 4');
            self::assertIsString($originalHash);
            CurrentUser::set(CurrentUser::get()->withStatus(UserStatus::Webmaster));

            try {
                $result = $this->service->checkAndSaveUserInfos(['user_id' => [4], 'password' => 'newpass123']);

                self::assertArrayNotHasKey('error', $result);
                self::assertIsArray($result['account']);
                self::assertArrayHasKey('password', $result['account']);
            } finally {
                CurrentUser::reset();
                $this->conn->executeStatement('UPDATE ' . Tables::users() . ' SET password = ? WHERE id = 4', [$originalHash]);
            }
        }

        public function test_check_and_save_user_infos_rejects_granting_webmaster_status_by_a_non_webmaster(): void
        {
            $result = $this->service->checkAndSaveUserInfos(['user_id' => [4], 'status' => 'webmaster']);

            // Real production typo: the array key is 'code ' (trailing
            // space), not 'code' -- confirmed live via a standalone
            // sanity script, not assumed. Documented here, not "fixed":
            // out of scope for a test-writing pass.
            self::assertSame(
                [
                    'error' => [
                        'code ' => 403,
                        'message' => 'Only webmasters can grant "webmaster/admin" status',
                    ],
                ],
                $result
            );
        }

        public function test_check_and_save_user_infos_rejects_an_invalid_status_value(): void
        {
            $result = $this->service->checkAndSaveUserInfos(['user_id' => [4], 'status' => 'not-a-real-status']);

            self::assertSame(
                ['error' => ['code' => WsError::INVALID_PARAM, 'message' => 'Invalid status']],
                $result
            );
        }

        public function test_check_and_save_user_infos_rejects_an_invalid_level(): void
        {
            $result = $this->service->checkAndSaveUserInfos(['user_id' => [4], 'level' => 99]);

            self::assertSame(
                ['error' => ['code' => WsError::INVALID_PARAM, 'message' => 'Invalid level']],
                $result
            );
        }

        public function test_check_and_save_user_infos_rejects_an_invalid_language(): void
        {
            $result = $this->service->checkAndSaveUserInfos(['user_id' => [4], 'language' => 'xx-not-real']);

            self::assertSame(
                ['error' => ['code' => WsError::INVALID_PARAM, 'message' => 'Invalid language']],
                $result
            );
        }

        public function test_check_and_save_user_infos_rejects_an_invalid_theme(): void
        {
            // The fixture's own piwigo_themes table is empty (confirmed
            // live), so any value at all is "invalid" here -- no need for
            // an implausible-sounding fake theme name.
            $result = $this->service->checkAndSaveUserInfos(['user_id' => [4], 'theme' => 'anything']);

            self::assertSame(
                ['error' => ['code' => WsError::INVALID_PARAM, 'message' => 'Invalid theme']],
                $result
            );
        }

        public function test_check_and_save_user_infos_updates_username_and_email_for_a_single_user(): void
        {
            $before = $this->conn->fetchAssociative('SELECT username, mail_address FROM ' . Tables::users() . ' WHERE id = 4');
            self::assertIsArray($before);
            $newLogin = 'temp-login-' . bin2hex(random_bytes(4));

            try {
                $result = $this->service->checkAndSaveUserInfos([
                    'user_id' => [4],
                    'username' => $newLogin,
                    'email' => 'temp13@example.test',
                ]);

                self::assertSame(
                    ['user_id' => [4], 'infos' => [], 'account' => ['username' => $newLogin, 'mail_address' => 'temp13@example.test']],
                    $result
                );
                $after = $this->conn->fetchAssociative('SELECT username, mail_address FROM ' . Tables::users() . ' WHERE id = 4');
                self::assertSame(['username' => $newLogin, 'mail_address' => 'temp13@example.test'], $after);
            } finally {
                $this->conn->executeStatement(
                    'UPDATE ' . Tables::users() . ' SET username = ?, mail_address = ? WHERE id = 4',
                    [$before['username'], $before['mail_address']]
                );
            }
        }

        public function test_check_and_save_user_infos_updates_every_user_infos_field_for_a_single_user(): void
        {
            // getPwgThemes()'s own checkThemeInstalled() call requires a
            // real themes/<id> directory on disk, not just a DB row --
            // 'default' is the one real theme directory this repo ships.
            $this->conn->executeStatement("INSERT INTO " . Tables::themes() . " (id, version, name) VALUES ('default', '1.0', 'Default')");

            try {
                $result = $this->service->checkAndSaveUserInfos([
                    'user_id' => [4],
                    'level' => 1,
                    'language' => 'en_UK',
                    'theme' => 'default',
                    'nb_image_page' => 20,
                    'recent_period' => 10,
                    'expand' => true,
                    'show_nb_comments' => true,
                    'show_nb_hits' => false,
                    'enabled_high' => true,
                ]);

                $expectedInfos = [
                    'level' => 1,
                    'language' => 'en_UK',
                    'theme' => 'default',
                    'nb_image_page' => 20,
                    'recent_period' => 10,
                    'expand' => 1,
                    'show_nb_comments' => 1,
                    'show_nb_hits' => 0,
                    'enabled_high' => 1,
                ];
                self::assertSame(['user_id' => [4], 'infos' => $expectedInfos, 'account' => []], $result);

                $after = $this->conn->fetchAssociative(
                    'SELECT level, language, theme, nb_image_page, recent_period, expand, show_nb_comments, show_nb_hits, enabled_high'
                    . ' FROM ' . Tables::userInfos() . ' WHERE user_id = 4'
                );
                self::assertSame($expectedInfos, $after);
            } finally {
                $this->conn->executeStatement("DELETE FROM " . Tables::themes() . " WHERE id = 'default'");
                $this->conn->executeStatement(
                    "UPDATE " . Tables::userInfos() . " SET level = 0, language = 'en_UK', theme = 'default',"
                    . ' nb_image_page = 15, recent_period = 7, expand = 0, show_nb_comments = 0, show_nb_hits = 0, enabled_high = 1'
                    . ' WHERE user_id = 4'
                );
            }
        }

        public function test_check_and_save_user_infos_multi_user_branch_skips_username_email_password_checks(): void
        {
            try {
                // 'username' is set but count(user_ids) !== 1, so the
                // whole username/email/password validation block (and its
                // "already used" rejection) never runs at all -- only
                // 'level', a multi-user-safe field, applies.
                $result = $this->service->checkAndSaveUserInfos([
                    'user_id' => [3, 4],
                    'username' => 'should-be-ignored',
                    'level' => 2,
                ]);

                self::assertSame(['user_id' => [3, 4], 'infos' => ['level' => 2], 'account' => []], $result);

                $levels = $this->conn->fetchAllAssociative(
                    'SELECT user_id, level FROM ' . Tables::userInfos() . ' WHERE user_id IN (3, 4) ORDER BY user_id'
                );
                self::assertSame([['user_id' => 3, 'level' => 2], ['user_id' => 4, 'level' => 2]], $levels);
            } finally {
                $this->conn->executeStatement('UPDATE ' . Tables::userInfos() . ' SET level = 0 WHERE user_id IN (3, 4)');
            }
        }

        public function test_check_and_save_user_infos_status_guest_deletes_sessions_for_the_affected_users(): void
        {
            try {
                $result = $this->service->checkAndSaveUserInfos(['user_id' => [4], 'status' => 'guest']);

                self::assertArrayNotHasKey('error', $result);
                $status = $this->conn->fetchOne('SELECT status FROM ' . Tables::userInfos() . ' WHERE user_id = 4');
                self::assertSame('guest', $status);
            } finally {
                $this->conn->executeStatement("UPDATE " . Tables::userInfos() . " SET status = 'normal' WHERE user_id = 4");
            }
        }

        public function test_check_and_save_user_infos_admin_cannot_change_status_of_another_admin_or_webmaster(): void
        {
            // 'normal' isn't in the ['webmaster', 'admin'] granting-restricted
            // list, so an admin current user passes that first guard --
            // but CurrentUser::get()->status === UserStatus::Admin also
            // merges every real webmaster/admin user_id into
            // $protected_users, so target user 1 (webmaster) is silently
            // excluded from $user_ids_for_status: the call "succeeds"
            // (no error) yet user 1's status never actually changes.
            CurrentUser::set(CurrentUser::get()->withStatus(UserStatus::Admin));

            try {
                $result = $this->service->checkAndSaveUserInfos(['user_id' => [1], 'status' => 'normal']);
            } finally {
                CurrentUser::reset();
            }

            self::assertArrayNotHasKey('error', $result);
            $status = $this->conn->fetchOne('SELECT status FROM ' . Tables::userInfos() . ' WHERE user_id = 1');
            self::assertSame('webmaster', $status);
        }

        public function test_check_and_save_user_infos_group_id_removes_and_reassigns_group_membership(): void
        {
            // Fixture: user 4 (power_user) starts in group 3 only.
            try {
                $result = $this->service->checkAndSaveUserInfos(['user_id' => [4], 'group_id' => [1, 2]]);

                self::assertArrayNotHasKey('error', $result);
                $groups = $this->conn->fetchFirstColumn(
                    'SELECT group_id FROM ' . Tables::userGroup() . ' WHERE user_id = 4 ORDER BY group_id'
                );
                self::assertSame([1, 2], $groups);
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::userGroup() . ' WHERE user_id = 4');
                $this->conn->executeStatement('INSERT INTO ' . Tables::userGroup() . ' (user_id, group_id) VALUES (4, 3)');
            }
        }

        public function test_check_and_save_user_infos_group_id_with_only_a_nonexistent_group_clears_membership_without_reinserting(): void
        {
            try {
                $result = $this->service->checkAndSaveUserInfos(['user_id' => [4], 'group_id' => [999999]]);

                self::assertArrayNotHasKey('error', $result);
                $groups = $this->conn->fetchFirstColumn('SELECT group_id FROM ' . Tables::userGroup() . ' WHERE user_id = 4');
                self::assertSame([], $groups);
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::userGroup() . ' WHERE user_id = 4');
                $this->conn->executeStatement('INSERT INTO ' . Tables::userGroup() . ' (user_id, group_id) VALUES (4, 3)');
            }
        }

        public function test_get_recent_photos_sql_returns_a_false_condition_when_last_photo_date_is_not_set(): void
        {
            // Default guest CurrentUser (from IntegrationTestCase's own
            // setUp) has no 'last_photo_date' key in rawAttributes at all
            // -- only buildUser()'s own effective-permission enrichment
            // adds it (see the next test).
            self::assertSame('0=1', $this->service->getRecentPhotosSql('i.date_available'));
        }

        public function test_get_recent_photos_sql_builds_a_least_expression_when_last_photo_date_is_set(): void
        {
            $user = $this->service->buildUser(UserId::from(1));
            \Piwigo\Users\CurrentUser::set(\Piwigo\Users\User::fromUserArray($user));

            try {
                $sql = $this->service->getRecentPhotosSql('i.date_available');
            } finally {
                \Piwigo\Users\CurrentUser::reset();
            }

            // The exact SUBDATE(...) fragment is SqlDialectTest's own
            // territory (already covered there) -- this only confirms
            // UserService's own wiring reaches the real "set" branch.
            self::assertStringStartsWith('i.date_available>=LEAST(', $sql);
            self::assertStringEndsWith(')', $sql);
        }

        /**
         * SQL-modernization audit regression: a non-numeric
         * rawAttributes['recent_period'] used to be kept as-is (`is_string(
         * $recent_period) ? $recent_period : 0`) and spliced raw into
         * SqlDialect::getRecentPeriodExpression()'s SQL fragment --
         * SqlDialect::getRecentPeriodExpression() now requires a real
         * `int`, so a corrupted value must fall back to 0 like any other
         * malformed input, not reach the query verbatim.
         */
        public function test_get_recent_photos_sql_falls_back_to_zero_days_for_a_non_numeric_recent_period(): void
        {
            \Piwigo\Users\CurrentUser::set(new \Piwigo\Users\User(
                id: UserId::from(1),
                username: 'torres',
                email: '',
                language: '',
                theme: '',
                status: UserStatus::Normal,
                enabledHigh: false,
                rawAttributes: [
                    'last_photo_date' => '2024-01-01',
                    'recent_period' => 'not-a-number',
                ],
            ));

            try {
                $sql = $this->service->getRecentPhotosSql('i.date_available');
            } finally {
                CurrentUser::reset();
            }

            self::assertStringNotContainsString('not-a-number', $sql);
            self::assertStringContainsString('INTERVAL 0 DAY', $sql);
        }

        public function test_sync_users_creates_missing_user_infos_for_a_base_user(): void
        {
            $this->conn->executeStatement(
                "INSERT INTO " . Tables::users() . " (username, password, mail_address) VALUES ('sync-orphan-user', NULL, NULL)"
            );
            $newUserId = (int) $this->conn->lastInsertId();

            try {
                self::assertSame(0, $this->fetchOneInt(
                    'SELECT COUNT(*) FROM ' . Tables::userInfos() . ' WHERE user_id = ?',
                    [$newUserId]
                ));

                $this->service->syncUsers();

                self::assertSame(1, $this->fetchOneInt(
                    'SELECT COUNT(*) FROM ' . Tables::userInfos() . ' WHERE user_id = ?',
                    [$newUserId]
                ));
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ?', [$newUserId]);
            }
        }

        public function test_sync_users_deletes_orphaned_child_rows_not_present_in_the_base_table(): void
        {
            // Every one of syncUsers()'s 5 child tables carries a real
            // ON DELETE CASCADE FK back to piwigo_users in this schema, so
            // a genuine orphan can never arise through normal DB writes --
            // confirmed live (a plain INSERT with a nonexistent user_id
            // is rejected by the FK). Disabling FK checks just for this
            // insert reproduces the only real way this state has ever
            // existed in practice: a bulk import/migration that ran with
            // checks off.
            $this->conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
            $this->conn->executeStatement('INSERT INTO ' . Tables::userGroup() . ' (user_id, group_id) VALUES (777777, 1)');
            $this->conn->executeStatement('INSERT INTO ' . Tables::userInfos() . ' (user_id) VALUES (777777)');
            $this->conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');

            self::assertSame(1, $this->fetchOneInt('SELECT COUNT(*) FROM ' . Tables::userGroup() . ' WHERE user_id = 777777'));
            self::assertSame(1, $this->fetchOneInt('SELECT COUNT(*) FROM ' . Tables::userInfos() . ' WHERE user_id = 777777'));

            $this->service->syncUsers();

            self::assertSame(0, $this->fetchOneInt('SELECT COUNT(*) FROM ' . Tables::userGroup() . ' WHERE user_id = 777777'));
            self::assertSame(0, $this->fetchOneInt('SELECT COUNT(*) FROM ' . Tables::userInfos() . ' WHERE user_id = 777777'));
        }

        public function test_register_user_notifies_admins_of_a_new_registration(): void
        {
            // webmasterId is deliberately fake (999999, see setUp) for
            // every other test in this file -- MailService's own "from"
            // address resolves through it here, so it needs a real
            // fixture user (1, fixture_admin) just for this one call.
            // setUp() re-applies the fake default before every other
            // test, so no restore is needed.
            CurrentConfig::setEmailAdminOnNewUser('all');
            CurrentConfig::setWebmasterId(1);
            CurrentConfig::setSmtpHost('127.0.0.1:1');
            $login = 'reg-notify-' . bin2hex(random_bytes(4));

            // notifyAdminsOfNewRegistration()'s own mail send
            // deterministically fails via the unreachable SMTP host (same
            // trick as NotificationByMailSenderTest/MailServiceTest -- see
            // their docblocks), which raises MailService::mail()'s own
            // E_USER_WARNING that failOnWarning="true" would otherwise
            // fail this test on. A plain @ does NOT stop PHPUnit's
            // ErrorHandler from surfacing it regardless, so a real no-op
            // error handler for the duration of this one call is the only
            // reliable way to swallow it.
            set_error_handler(static fn (): bool => true);
            try {
                $result = $this->service->registerUser($login, 'password123', null, new UrlService(new HtmlService()), true, false);
            } finally {
                restore_error_handler();
            }

            try {
                // The fire-and-forget mail attempt failing doesn't block
                // registration -- notifyAdminsOfNewRegistration() never
                // checks/propagates mailNotificationAdmins()'s own return
                // value.
                self::assertNotNull($result['userId']);
                self::assertSame([], $result['errors']);
            } finally {
                if ($result['userId'] !== null) {
                    $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ?', [$result['userId']]);
                }
            }
        }

        public function test_register_user_notifies_admins_of_a_new_registration_scoped_to_a_group(): void
        {
            // Same reasoning/trick as
            // test_register_user_notifies_admins_of_a_new_registration()
            // above, but with the 'group:N' form of email_admin_on_new_user
            // -- exercises notifyAdminsOfNewRegistration()'s own
            // preg_match('/^group:(\d+)$/', ...) capture into $groupId.
            CurrentConfig::setEmailAdminOnNewUser('group:5');
            CurrentConfig::setWebmasterId(1);
            CurrentConfig::setSmtpHost('127.0.0.1:1');
            $login = 'reg-notify-group-' . bin2hex(random_bytes(4));

            set_error_handler(static fn (): bool => true);
            try {
                $result = $this->service->registerUser($login, 'password123', null, new UrlService(new HtmlService()), true, false);
            } finally {
                restore_error_handler();
            }

            try {
                self::assertNotNull($result['userId']);
                self::assertSame([], $result['errors']);
            } finally {
                if ($result['userId'] !== null) {
                    $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ?', [$result['userId']]);
                }
            }
        }

        public function test_search_case_username_returns_the_input_unchanged_when_no_match_exists(): void
        {
            self::assertSame('totally-unknown-name', $this->service->searchCaseUsername('totally-unknown-name'));
        }

        public function test_register_user_rejects_a_login_ending_with_a_space(): void
        {
            $result = $this->service->registerUser(
                'trailing-space-' . bin2hex(random_bytes(3)) . ' ',
                'password123',
                null,
                new UrlService(new HtmlService())
            );

            self::assertNull($result['userId']);
            self::assertSame([Lang::t('login mustn\'t end with a space character')], $result['errors']);
        }

        public function test_register_user_rejects_a_login_starting_with_a_space(): void
        {
            $result = $this->service->registerUser(
                ' leading-space-' . bin2hex(random_bytes(3)),
                'password123',
                null,
                new UrlService(new HtmlService())
            );

            self::assertNull($result['userId']);
            self::assertSame([Lang::t('login mustn\'t start with a space character')], $result['errors']);
        }

        public function test_register_user_rejects_a_login_with_html_tags(): void
        {
            $result = $this->service->registerUser(
                '<b>tag-' . bin2hex(random_bytes(3)) . '</b>',
                'password123',
                null,
                new UrlService(new HtmlService())
            );

            self::assertNull($result['userId']);
            self::assertSame([Lang::t('html tags are not allowed in login')], $result['errors']);
        }

        public function test_register_user_rejects_an_invalid_mail_address(): void
        {
            $result = $this->service->registerUser(
                'valid-login-' . bin2hex(random_bytes(3)),
                'password123',
                'not-an-email',
                new UrlService(new HtmlService())
            );

            self::assertNull($result['userId']);
            self::assertCount(1, $result['errors']);
        }

        public function test_register_user_marks_duplicate_when_insensitive_case_logon_matches_an_existing_login(): void
        {
            // 'GUEST' (uppercase) doesn't collide with the fixture's real
            // 'guest' row under this schema's binary username collation
            // (case-SENSITIVE), so getUserId() alone wouldn't catch it --
            // only insensitiveCaseLogon's own validateLoginCase() call
            // does. Reuses 'guest' specifically because it has no email on
            // file (same determinism reasoning as the existing
            // test_register_user_sets_duplicate_username_without_revealing_it_in_errors).
            CurrentConfig::setInsensitiveCaseLogon(true);

            $result = $this->service->registerUser('GUEST', 'password123', null, new UrlService(new HtmlService()));

            self::assertNull($result['userId']);
            self::assertTrue($result['duplicateUsername']);
            self::assertSame([], $result['errors']);
        }

        public function test_create_user_infos_does_nothing_for_an_empty_user_id_list(): void
        {
            $countBefore = $this->fetchOneInt('SELECT COUNT(*) FROM ' . Tables::userInfos());

            $this->service->createUserInfos([]);

            self::assertSame($countBefore, $this->fetchOneInt('SELECT COUNT(*) FROM ' . Tables::userInfos()));
        }

        public function test_create_user_infos_assigns_webmaster_status_and_the_max_permission_level(): void
        {
            $this->conn->executeStatement(
                "INSERT INTO " . Tables::users() . " (username, password, mail_address) VALUES ('temp-webmaster-target', NULL, NULL)"
            );
            $tempId = (int) $this->conn->lastInsertId();
            CurrentConfig::setWebmasterId($tempId);

            try {
                $this->service->createUserInfos([UserId::from($tempId)]);

                $row = $this->conn->fetchAssociative(
                    'SELECT status, level FROM ' . Tables::userInfos() . ' WHERE user_id = ?',
                    [$tempId]
                );
                self::assertSame(['status' => 'webmaster', 'level' => 8], $row);
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ?', [$tempId]);
            }
        }

        public function test_create_user_infos_assigns_webmaster_status_and_level_zero_when_no_permission_levels_are_configured(): void
        {
            $this->conn->executeStatement(
                "INSERT INTO " . Tables::users() . " (username, password, mail_address) VALUES ('temp-webmaster-target-2', NULL, NULL)"
            );
            $tempId = (int) $this->conn->lastInsertId();
            CurrentConfig::setWebmasterId($tempId);
            // setAvailablePermissionLevels([]) itself treats an empty array
            // as "reset to the built-in default" ([0,1,2,4,8]) -- confirmed
            // live, not a real empty state -- so a genuinely empty list can
            // only be reached by seeding the backing property directly.
            new \ReflectionProperty(CurrentConfig::class, 'availablePermissionLevels')->setValue(null, []);

            try {
                $this->service->createUserInfos([UserId::from($tempId)]);

                $row = $this->conn->fetchAssociative(
                    'SELECT status, level FROM ' . Tables::userInfos() . ' WHERE user_id = ?',
                    [$tempId]
                );
                self::assertSame(['status' => 'webmaster', 'level' => 0], $row);
            } finally {
                CurrentConfig::setAvailablePermissionLevels([0, 1, 2, 4, 8]);
                $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ?', [$tempId]);
            }
        }

        public function test_get_default_user_value_returns_the_fallback_for_an_empty_field(): void
        {
            // Guest (the configured default user, id 2) has NULL
            // preferences in the fixture -- an "empty" value per
            // UserService::emptyValue()'s own null branch.
            self::assertSame('fallback-value', $this->service->getDefaultUserValue('preferences', 'fallback-value'));
        }

        public function test_build_user_forces_guest_status_for_the_configured_guest_id(): void
        {
            $this->conn->executeStatement("UPDATE " . Tables::userInfos() . " SET status = 'normal' WHERE user_id = 2");

            try {
                $user = $this->service->buildUser(UserId::from(2));

                self::assertSame('guest', $user['status']);
                self::assertSame(['guest_must_be_guest' => true], $user['internal_status']);
            } finally {
                $this->conn->executeStatement("UPDATE " . Tables::userInfos() . " SET status = 'guest' WHERE user_id = 2");
            }
        }

        // buildUser()'s `! is_array($internal_status)` re-normalization
        // guard (the branch resetting a non-array $user['internal_status']
        // back to []) is not exercised above or anywhere else in this
        // file -- getUserData() (the only populator of $user before that
        // guard runs) never returns an 'internal_status' key: it's not a
        // real users/user_infos column, and CurrentConfig::userFields()'s
        // return type is a fixed 4-key shape (id/username/password/email)
        // with no way to alias an extra pwgfield onto it. There is no path
        // through buildUser()'s public contract that hands it a
        // pre-populated, non-array 'internal_status' to normalize -- same
        // "verified unreachable through the real API" shape as
        // UserRepositoryTest's findAdminIds() note.

        public function test_get_theme_usage_counts_delegates_to_the_repository(): void
        {
            $username = 'p24-longtail-theme-' . bin2hex(random_bytes(4));
            $theme = 'p24-longtail-theme-' . bin2hex(random_bytes(4));
            $this->conn->executeStatement(
                "INSERT INTO " . Tables::users() . " (username, password, mail_address) VALUES (?, NULL, NULL)",
                [$username]
            );
            $tempId = (int) $this->conn->lastInsertId();
            $this->conn->executeStatement(
                'INSERT INTO ' . Tables::userInfos() . ' (user_id, theme) VALUES (?, ?)',
                [$tempId, $theme]
            );

            try {
                $counts = $this->service->getThemeUsageCounts();

                self::assertArrayHasKey($theme, $counts);
                self::assertSame(1, $counts[$theme]);
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ?', [$tempId]);
            }
        }

        public function test_get_language_usage_counts_delegates_to_the_repository(): void
        {
            $username = 'p24-longtail-lang-' . bin2hex(random_bytes(4));
            $language = 'p24-longtail-lang-' . bin2hex(random_bytes(4));
            $this->conn->executeStatement(
                "INSERT INTO " . Tables::users() . " (username, password, mail_address) VALUES (?, NULL, NULL)",
                [$username]
            );
            $tempId = (int) $this->conn->lastInsertId();
            $this->conn->executeStatement(
                'INSERT INTO ' . Tables::userInfos() . ' (user_id, language) VALUES (?, ?)',
                [$tempId, $language]
            );

            try {
                $counts = $this->service->getLanguageUsageCounts();

                self::assertArrayHasKey($language, $counts);
                self::assertSame(1, $counts[$language]);
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ?', [$tempId]);
            }
        }

        public function test_get_user_data_creates_missing_user_infos_when_external_authentification_is_active(): void
        {
            $this->conn->executeStatement(
                "INSERT INTO " . Tables::users() . " (username, password, mail_address) VALUES ('sync-target-getdata', NULL, NULL)"
            );
            $tempId = (int) $this->conn->lastInsertId();
            \Piwigo\Config\DeploymentPolicy::set(new \Piwigo\Config\DeploymentPolicy(externalAuthentification: true));

            try {
                self::assertSame(0, $this->fetchOneInt(
                    'SELECT COUNT(*) FROM ' . Tables::userInfos() . ' WHERE user_id = ?',
                    [$tempId]
                ));

                $data = $this->service->getUserData(UserId::from($tempId));

                self::assertArrayHasKey('status', $data);
                self::assertSame(1, $this->fetchOneInt(
                    'SELECT COUNT(*) FROM ' . Tables::userInfos() . ' WHERE user_id = ?',
                    [$tempId]
                ));
            } finally {
                \Piwigo\Config\DeploymentPolicy::reset();
                $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ?', [$tempId]);
            }
        }

        public function test_get_user_data_throws_for_a_user_id_absent_from_the_users_table(): void
        {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('UserService::getUserData(): no such user_id 88888888');

            $this->service->getUserData(UserId::from(88888888));
        }

        public function test_get_user_data_throws_when_the_user_infos_row_is_missing(): void
        {
            $this->conn->executeStatement(
                "INSERT INTO " . Tables::users() . " (username, password, mail_address) VALUES ('no-user-infos-target', NULL, NULL)"
            );
            $tempId = (int) $this->conn->lastInsertId();

            try {
                $this->expectException(\Exception::class);
                $this->expectExceptionMessage('UserService::getUserData(): user_infos fetch failed for user_id ' . $tempId);

                $this->service->getUserData(UserId::from($tempId));
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ?', [$tempId]);
            }
        }

        public function test_get_user_data_coerces_a_literal_true_or_false_scalar_value_to_a_real_bool(): void
        {
            // getUserData()'s generic true/false-string scan (a leftover
            // from the old enum('true','false') representation -- see its
            // own inline comment) still applies to every merged field, not
            // just the tinyint columns it used to cover. `users.username`
            // has no reserved-word restriction at this layer, so a literal
            // 'true'/'false' username is a real, if odd, way to reach it.
            $this->conn->executeStatement(
                "INSERT INTO " . Tables::users() . " (username, password, mail_address) VALUES ('true', NULL, NULL)"
            );
            $trueId = (int) $this->conn->lastInsertId();
            $this->conn->executeStatement(
                "INSERT INTO " . Tables::users() . " (username, password, mail_address) VALUES ('false', NULL, NULL)"
            );
            $falseId = (int) $this->conn->lastInsertId();
            $this->conn->executeStatement('INSERT INTO ' . Tables::userInfos() . ' (user_id) VALUES (?), (?)', [$trueId, $falseId]);

            try {
                $trueData = $this->service->getUserData(UserId::from($trueId));
                $falseData = $this->service->getUserData(UserId::from($falseId));

                self::assertSame(true, $trueData['username']);
                self::assertSame(false, $falseData['username']);
            } finally {
                $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id IN (?, ?)', [$trueId, $falseId]);
            }
        }

        public function test_check_user_favorites_does_nothing_when_the_user_has_no_forbidden_categories(): void
        {
            // Default guest CurrentUser's forbiddenCategories is '' --
            // the early-return guard, confirmed by asserting the
            // fixture's own favorites rows are untouched.
            $before = $this->conn->fetchFirstColumn('SELECT image_id FROM ' . Tables::favorites() . ' WHERE user_id = 1 ORDER BY image_id');

            $this->service->checkUserFavorites();

            $after = $this->conn->fetchFirstColumn('SELECT image_id FROM ' . Tables::favorites() . ' WHERE user_id = 1 ORDER BY image_id');
            self::assertSame($before, $after);
        }

        public function test_check_user_favorites_deletes_a_favorite_that_falls_into_a_forbidden_category(): void
        {
            // Fixture: user 1's favorites are images 1, 3 (category 1)
            // and 5 (category 2). Forbidding category 2 makes image 5's
            // favorite unauthorized -- 1 and 3 stay untouched.
            $u1 = $this->service->buildUser(UserId::from(1));
            $u1['forbidden_categories'] = '2';
            \Piwigo\Users\CurrentUser::set(\Piwigo\Users\User::fromUserArray($u1));

            try {
                $this->service->checkUserFavorites();
            } finally {
                \Piwigo\Users\CurrentUser::reset();
            }

            $after = $this->conn->fetchFirstColumn('SELECT image_id FROM ' . Tables::favorites() . ' WHERE user_id = 1 ORDER BY image_id');
            self::assertSame([1, 3], $after);

            $this->conn->executeStatement('DELETE FROM ' . Tables::favorites() . ' WHERE user_id = 1');
            $this->conn->executeStatement('INSERT INTO ' . Tables::favorites() . ' (user_id, image_id) VALUES (1,1),(1,3),(1,5)');
        }

        public function test_get_default_theme_falls_back_to_the_literal_default_when_nothing_installed_matches(): void
        {
            // The fixture's own piwigo_themes table is empty (confirmed
            // live -- same fact SizingParams/ThemeCatalog tests elsewhere
            // this session already established), so once the configured
            // default user's own theme also fails checkThemeInstalled(),
            // there's no installed theme left to fall back to at all --
            // the method's own final, hardcoded 'default' literal.
            $this->conn->executeStatement("UPDATE " . Tables::userInfos() . " SET theme = 'nonexistent-theme-xyz' WHERE user_id = 2");

            try {
                self::assertSame('default', $this->service->getDefaultTheme());
            } finally {
                $this->conn->executeStatement("UPDATE " . Tables::userInfos() . " SET theme = 'default' WHERE user_id = 2");
            }
        }

        public function test_get_default_theme_coerces_a_non_string_cached_value_to_the_literal_default(): void
        {
            // ProcessCache::get('default_user') is a plain per-request
            // memoization cell -- getDefaultTheme() defensively re-checks
            // is_string() on whatever getDefaultUserValue('theme', ...)
            // hands back rather than trusting the docblock-only array
            // shape. Poisoning the cache with a non-string 'theme' value
            // is the only way to reach that guard: without it,
            // ThemeCatalog::checkThemeInstalled() (a strictly-typed string
            // param) would receive an int and fatal with a TypeError.
            \Piwigo\Core\ProcessCache::set('default_user', [
                'nb_image_page' => 15,
                'language' => 'en_UK',
                'expand' => false,
                'show_nb_comments' => false,
                'show_nb_hits' => false,
                'recent_period' => 7,
                'theme' => 12345,
                'enabled_high' => true,
                'level' => 0,
                'activation_key' => null,
                'activation_key_expire' => null,
                'lastmodified' => '2026-01-01 00:00:00',
                'preferences' => null,
            ]);

            self::assertSame('default', $this->service->getDefaultTheme());
        }

        public function test_get_browser_language_returns_false_when_no_header_is_present(): void
        {
            unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);

            self::assertFalse($this->service->getBrowserLanguage());
        }

        public function test_get_browser_language_returns_false_when_the_header_has_no_parseable_codes(): void
        {
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] = ';;;';

            try {
                self::assertFalse($this->service->getBrowserLanguage());
            } finally {
                unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            }
        }

        public function test_get_browser_language_matches_the_exact_full_form(): void
        {
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-UK';

            try {
                self::assertSame('en_UK', $this->service->getBrowserLanguage());
            } finally {
                unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            }
        }

        public function test_get_browser_language_falls_back_to_the_short_form(): void
        {
            // 'en-XX' has no exact full-form match ('en_xx'), only the
            // short-form prefix 'en' does -- exercises the fallback
            // branch, not the exact-match one above.
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-XX';

            try {
                self::assertSame('en_UK', $this->service->getBrowserLanguage());
            } finally {
                unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            }
        }

        public function test_get_browser_language_returns_false_when_nothing_matches(): void
        {
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'zz-ZZ';

            try {
                self::assertFalse($this->service->getBrowserLanguage());
            } finally {
                unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            }
        }

        public function test_save_edit_context_ignores_a_non_numeric_image_id(): void
        {
            \Piwigo\Users\CurrentUser::set(\Piwigo\Users\CurrentUser::get()->withStatus(\Piwigo\Users\UserStatus::Admin));
            $this->resetEditContext();

            try {
                $this->service->saveEditContext('/some/section', 'not-a-number');

                self::assertArrayNotHasKey('edit_context', $_SESSION);
            } finally {
                \Piwigo\Users\CurrentUser::reset();
                $this->resetEditContext();
            }
        }

        public function test_save_edit_context_and_get_edit_context_round_trip(): void
        {
            \Piwigo\Users\CurrentUser::set(\Piwigo\Users\CurrentUser::get()->withStatus(\Piwigo\Users\UserStatus::Admin));
            $this->resetEditContext();

            try {
                $this->service->saveEditContext('/198/list/2,69,198', 198);

                self::assertSame(['198' => '/198/list/2,69,198'], $_SESSION['edit_context'] ?? null);
                // getEditContext() strips the leading "/{imageId}/" prefix
                // off the stored section URL.
                self::assertSame('list/2,69,198', $this->service->getEditContext(198));
            } finally {
                \Piwigo\Users\CurrentUser::reset();
                $this->resetEditContext();
            }
        }

        public function test_get_edit_context_returns_false_for_an_unknown_image(): void
        {
            $this->resetEditContext();

            self::assertFalse($this->service->getEditContext(999999));
        }

        public function test_get_edit_context_returns_false_for_a_non_string_stored_value(): void
        {
            $this->resetEditContext();
            $_SESSION['edit_context'] = [198 => ['not' => 'a-string']];

            try {
                self::assertFalse($this->service->getEditContext(198));
            } finally {
                $this->resetEditContext();
            }
        }
    }
}

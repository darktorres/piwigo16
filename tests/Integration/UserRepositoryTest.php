<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Common\ValueObject\Email;
use InvalidArgumentException;
use Piwigo\Permission\PermissionCriteria;
use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Users\UserListCriteria;
use Piwigo\Users\UserRepository;

final class UserRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private UserRepository $repo;

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
        $this->repo = new UserRepository(EntityManagerFactory::build($this->conn), EventDispatcher::get(), $currentConfig);
    }

    public function test_find_id_by_username_returns_a_fixture_user(): void
    {
        self::assertEquals(UserId::from(1), $this->repo->findIdByUsername(Username::from('fixture_admin')));
        self::assertNull($this->repo->findIdByUsername(Username::from('does-not-exist')));
    }

    public function test_find_username_by_id_returns_a_fixture_user(): void
    {
        self::assertEquals(Username::from('fixture_admin'), $this->repo->findUsernameById(UserId::from(1)));
    }

    public function test_find_username_by_id_returns_null_for_a_nonexistent_user(): void
    {
        self::assertNull($this->repo->findUsernameById(UserId::from(999999)));
    }

    public function test_find_id_by_email_returns_a_fixture_user(): void
    {
        self::assertEquals(UserId::from(1), $this->repo->findIdByEmail(Email::from('fixture_admin@example.test')));
        self::assertNull($this->repo->findIdByEmail(Email::from('nobody@example.test')));
    }

    public function test_find_id_by_email_is_case_insensitive(): void
    {
        self::assertEquals(UserId::from(1), $this->repo->findIdByEmail(Email::from('FIXTURE_ADMIN@EXAMPLE.TEST')));
    }

    public function test_find_by_username_case_insensitive_matches_regardless_of_case(): void
    {
        $found = $this->repo->findByUsernameCaseInsensitive('FIXTURE_ADMIN');

        self::assertNotNull($found);
        self::assertSame('1', $found['id']);
        self::assertSame('fixture_admin', $found['username']);
        self::assertSame('fixture_admin@example.test', $found['email']);
    }

    public function test_find_by_username_case_insensitive_returns_null_when_missing(): void
    {
        self::assertNull($this->repo->findByUsernameCaseInsensitive('nope'));
    }

    public function test_username_exists_case_insensitive(): void
    {
        self::assertTrue($this->repo->usernameExistsCaseInsensitive(Username::from('FIXTURE_ADMIN')));
        self::assertFalse($this->repo->usernameExistsCaseInsensitive(Username::from('nope')));
    }

    public function test_email_exists(): void
    {
        self::assertTrue($this->repo->emailExists(Email::from('fixture_admin@example.test'), null));
        self::assertFalse($this->repo->emailExists(Email::from('nobody@example.test'), null));
    }

    public function test_email_exists_excludes_the_given_user_id(): void
    {
        self::assertFalse($this->repo->emailExists(Email::from('fixture_admin@example.test'), UserId::from(1)));
        self::assertTrue($this->repo->emailExists(Email::from('fixture_admin@example.test'), UserId::from(2)));
    }

    public function test_find_all_usernames_includes_fixture_users(): void
    {
        $names = $this->repo->findAllUsernames();

        self::assertContains('fixture_admin', $names);
        self::assertContains('guest', $names);
    }

    public function test_insert_user_then_find_id_by_username_round_trips(): void
    {
        $username = 'p18-test-' . bin2hex(random_bytes(4));

        $id = $this->repo->insertUser($username, 'irrelevant-hash', null);

        self::assertEquals($id, $this->repo->findIdByUsername(Username::from($username)));

        $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ' . $id->value);
    }

    public function test_insert_user_infos_then_find_default_user_info_row_round_trips(): void
    {
        $username = 'p18-test-' . bin2hex(random_bytes(4));
        $id = $this->repo->insertUser($username, 'irrelevant-hash', null);

        $this->repo->insertUserInfos([$id], [
            'status' => 'normal',
            'registration_date' => '2026-01-01 00:00:00',
            'level' => 0,
        ]);

        $row = $this->repo->findDefaultUserInfoRow($id);

        self::assertNotNull($row);
        self::assertSame('normal', $row->status);

        $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ' . $id->value);
    }

    public function test_insert_user_infos_accepts_real_bool_for_the_tinyint_columns(): void
    {
        // User domain Stage 1a: expand/show_nb_comments/show_nb_hits/
        // enabled_high/last_visit_from_history are real tinyint(1)
        // columns now, and UserService::createUserInfos() hands
        // insertUserInfos() a row straight from
        // Projection\UserInfo::toArray() -- real PHP bool, not the old
        // enum('true','false') string. setParameter() with no explicit
        // type binds through mysqli as a plain string, and mysqli's own
        // string-cast of `false` is '' (PHP's own (string) false), which
        // STRICT_TRANS_TABLES rejects as an invalid integer -- confirmed
        // against a real tinyint(1) column before this test was written.
        // `false` (not `true`) is the reproducing value: `(string) true`
        // is '1', a valid numeric string, so only the `false` case ever
        // surfaced this.
        $username = 'p18-test-' . bin2hex(random_bytes(4));
        $id = $this->repo->insertUser($username, 'irrelevant-hash', null);

        $this->repo->insertUserInfos([$id], [
            'status' => 'normal',
            'registration_date' => '2026-01-01 00:00:00',
            'level' => 0,
            'expand' => false,
            'show_nb_comments' => false,
            'show_nb_hits' => false,
            'enabled_high' => true,
            'last_visit_from_history' => false,
        ]);

        $row = $this->repo->findDefaultUserInfoRow($id);

        self::assertNotNull($row);
        self::assertFalse($row->expand);
        self::assertFalse($row->showNbComments);
        self::assertFalse($row->showNbHits);
        self::assertTrue($row->enabledHigh);
        self::assertFalse($row->lastVisitFromHistory);

        $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ' . $id->value);
    }

    public function test_insert_user_infos_with_no_ids_is_a_noop(): void
    {
        $countBefore = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::userInfos())
            ->executeQuery()
            ->fetchOne();

        $this->repo->insertUserInfos([], ['status' => 'normal']);

        $countAfter = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::userInfos())
            ->executeQuery()
            ->fetchOne();

        self::assertSame($countBefore, $countAfter);
    }

    public function test_save_preferences_persists_the_json_encoded_value(): void
    {
        $this->repo->savePreferences(UserId::from(1), ['theme' => 'dark']);

        $value = $this->conn->createQueryBuilder()
            ->select('preferences')
            ->from(Tables::userInfos())
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertIsString($value);
        self::assertSame(['theme' => 'dark'], json_decode($value, true));

        $this->conn->executeStatement('UPDATE ' . Tables::userInfos() . " SET preferences = NULL WHERE user_id = 1");
    }

    public function test_save_preferences_is_a_noop_for_a_nonexistent_user(): void
    {
        // No user_infos row exists for this id -- find() returns null and
        // the method returns early rather than persisting a fresh entity.
        $this->repo->savePreferences(UserId::from(999999), ['theme' => 'dark']);

        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::userInfos())
            ->where('user_id = 999999')
            ->executeQuery()
            ->fetchOne();

        self::assertSame('0', is_scalar($count) ? (string) $count : '');
    }

    public function test_delete_users_from_table_with_no_ids_is_a_noop(): void
    {
        $countBefore = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::userInfos())
            ->executeQuery()
            ->fetchOne();

        $this->repo->deleteUsersFromTable(Tables::userInfos(), []);

        $countAfter = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::userInfos())
            ->executeQuery()
            ->fetchOne();

        self::assertSame($countBefore, $countAfter);
    }

    public function test_delete_favorites_for_images_with_no_image_ids_is_a_noop(): void
    {
        // A marker row distinct from the fixture's own (1,1)/(1,3)/(1,5)
        // favorites rows, so this assertion doesn't depend on what other
        // tests may already have inserted/removed against user 1's real
        // favorites. image_id must be a real fixture image (favorites.
        // image_id has a real FK constraint onto images.id) -- image 2
        // exists in the fixture and isn't one of user 1's own favorited
        // images above.
        $this->conn->executeStatement('INSERT INTO ' . Tables::favorites() . ' (user_id, image_id) VALUES (1, 2)');

        $this->repo->deleteFavoritesForImages(UserId::from(1), []);

        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::favorites())
            ->where('user_id = 1 AND image_id = 2')
            ->executeQuery()
            ->fetchOne();

        self::assertSame(1, $count);

        $this->conn->executeStatement('DELETE FROM ' . Tables::favorites() . ' WHERE user_id = 1 AND image_id = 2');
    }

    public function test_update_status_for_users_with_no_ids_is_a_noop(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('status')
            ->from(Tables::userInfos())
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        $this->repo->updateStatusForUsers([], 'guest');

        $after = $this->conn->createQueryBuilder()
            ->select('status')
            ->from(Tables::userInfos())
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame($before, $after);
    }

    public function test_update_infos_for_users_with_no_ids_is_a_noop(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('nb_image_page')
            ->from(Tables::userInfos())
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        $this->repo->updateInfosForUsers([], ['nb_image_page' => 999]);

        $after = $this->conn->createQueryBuilder()
            ->select('nb_image_page')
            ->from(Tables::userInfos())
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame($before, $after);
    }

    public function test_update_infos_for_users_with_no_updates_is_a_noop(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('nb_image_page')
            ->from(Tables::userInfos())
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        $this->repo->updateInfosForUsers([UserId::from(1)], []);

        $after = $this->conn->createQueryBuilder()
            ->select('nb_image_page')
            ->from(Tables::userInfos())
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame($before, $after);
    }

    public function test_update_infos_for_users_persists_a_non_boolean_and_a_boolean_field_together(): void
    {
        // Item 16I: converted to real DQL against UserInfoEntity --
        // had zero real behavioral coverage before (both existing
        // sibling tests only ever probed the no-op early-return
        // branches), despite nb_image_page/expand exercising genuinely
        // different code paths (plain scalar bind vs. explicit bool
        // cast, see UserInfoField::dqlPropertyAndIsBoolean()).
        $username = 'p18-test-' . bin2hex(random_bytes(4));
        $id = $this->repo->insertUser($username, 'irrelevant-hash', null);
        $this->repo->insertUserInfos([$id], ['status' => 'normal']);

        try {
            $this->repo->updateInfosForUsers([$id], [
                'nb_image_page' => 27,
                'expand' => 1,
            ]);

            $row = $this->conn->createQueryBuilder()
                ->select('nb_image_page', 'expand')
                ->from(Tables::userInfos())
                ->where('user_id = :userId')
                ->setParameter('userId', $id->value)
                ->executeQuery()
                ->fetchAssociative();
            self::assertIsArray($row);
            self::assertSame(27, is_numeric($row['nb_image_page']) ? (int) $row['nb_image_page'] : null);
            self::assertSame(1, is_bool($row['expand']) || is_numeric($row['expand']) ? (int) (bool) $row['expand'] : null);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::userInfos() . ' WHERE user_id = ' . $id->value);
            $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ' . $id->value);
        }
    }

    public function test_update_infos_for_users_rejects_an_unknown_field(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown user_infos field: not_a_real_field');

        $this->repo->updateInfosForUsers([UserId::from(1)], ['not_a_real_field' => 'x']);
    }

    public function test_find_theme_usage_counts_includes_a_freshly_inserted_user(): void
    {
        $username = 'p18-test-' . bin2hex(random_bytes(4));
        $theme = 'p18-test-theme-' . bin2hex(random_bytes(4));

        $id = $this->repo->insertUser($username, 'irrelevant-hash', null);
        $this->repo->insertUserInfos([$id], [
            'status' => 'normal',
            'theme' => $theme,
        ]);

        $counts = $this->repo->findThemeUsageCounts();

        self::assertArrayHasKey($theme, $counts);
        self::assertSame(1, $counts[$theme]);

        $this->conn->executeStatement('DELETE FROM ' . Tables::userInfos() . ' WHERE user_id = ' . $id->value);
        $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ' . $id->value);
    }

    public function test_find_language_usage_counts_includes_a_freshly_inserted_user(): void
    {
        $username = 'p18-test-' . bin2hex(random_bytes(4));
        $language = 'p18-test-lang-' . bin2hex(random_bytes(4));

        $id = $this->repo->insertUser($username, 'irrelevant-hash', null);
        $this->repo->insertUserInfos([$id], [
            'status' => 'normal',
            'language' => $language,
        ]);

        $counts = $this->repo->findLanguageUsageCounts();

        self::assertArrayHasKey($language, $counts);
        self::assertSame(1, $counts[$language]);

        $this->conn->executeStatement('DELETE FROM ' . Tables::userInfos() . ' WHERE user_id = ' . $id->value);
        $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ' . $id->value);
    }

    public function test_find_notification_recipients_by_ids_with_no_ids_returns_empty(): void
    {
        self::assertSame([], $this->repo->findNotificationRecipientsByIds([]));
    }

    public function test_find_usernames_by_ids_with_no_ids_returns_empty(): void
    {
        self::assertSame([], $this->repo->findUsernamesByIds([]));
    }

    public function test_find_all_usernames_by_id_returns_every_fixture_user(): void
    {
        $byId = $this->repo->findAllUsernamesById();

        $normalized = [];
        foreach ($byId as $id => $username) {
            $normalized[(string) $id] = $username;
        }
        ksort($normalized);

        self::assertSame([
            '1' => 'fixture_admin',
            '2' => 'guest',
            '3' => 'regular_user',
            '4' => 'power_user',
        ], $normalized);
    }

    public function test_find_distinct_registration_year_months_returns_formatted_periods(): void
    {
        // Every fixture user_infos row shares the same registration_date
        // (2026-08-01), so this only has one distinct period to prove the
        // year/month formatting -- not the DISTINCT/ORDER BY grouping
        // itself.
        self::assertSame(['2026-08'], $this->repo->findDistinctRegistrationYearMonths());
    }

    public function test_find_distinct_registration_year_months_groups_and_orders_across_periods(): void
    {
        // Real regression coverage for the DISTINCT + ORDER BY shape
        // itself, not just the per-row formatting -- give user 1 an
        // earlier period and user 2 a later one, both distinct from the
        // shared fixture period (2026-08), and confirm all three come
        // back deduplicated and chronologically ordered regardless of the
        // rows' own id order.
        try {
            $this->conn->executeStatement('UPDATE ' . Tables::userInfos() . " SET registration_date = '2024-01-15 10:00:00' WHERE user_id = 1");
            $this->conn->executeStatement('UPDATE ' . Tables::userInfos() . " SET registration_date = '2027-03-20 10:00:00' WHERE user_id = 2");

            self::assertSame(
                ['2024-01', '2026-08', '2027-03'],
                $this->repo->findDistinctRegistrationYearMonths()
            );
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::userInfos() . " SET registration_date = '2026-08-01 00:00:00' WHERE user_id IN (1, 2)");
        }
    }

    public function test_find_user_counts_by_status_excludes_the_given_user_and_groups_by_status(): void
    {
        // Excluding user 2 (guest) leaves user 1 (webmaster) and users 3/4
        // (both normal). No ORDER BY on the underlying GROUP BY query (its
        // one real caller, Admin\UserListPageRenderer, only ever reads
        // this map by key, never iterates it) -- key order isn't part of
        // the real contract, so this asserts the map's contents, not its
        // order (confirmed a real gap: MySQL and PostgreSQL return GROUP
        // BY row order differently with no ORDER BY, and this assertion
        // used to depend on that order incidentally).
        $counts = $this->repo->findUserCountsByStatus(2);
        ksort($counts);
        self::assertSame([
            'normal' => 2,
            'webmaster' => 1,
        ], $counts);
    }

    public function test_find_user_counts_by_level_excludes_the_given_user_and_groups_by_level(): void
    {
        // Excluding user 2 (guest) leaves user 1 at level 8 and users 3/4
        // both at level 0.
        self::assertSame([
            8 => 1,
            0 => 2,
        ], $this->repo->findUserCountsByLevel(2));
    }

    public function test_find_user_ids_excluding_status_omits_matching_rows(): void
    {
        $ids = $this->repo->findUserIdsExcludingStatus('guest');

        sort($ids);
        self::assertSame(['1', '3', '4'], $ids);
    }

    public function test_is_favorite_reflects_real_favorites_rows(): void
    {
        self::assertTrue($this->repo->isFavorite(UserId::from(1), 1));
        self::assertFalse($this->repo->isFavorite(UserId::from(1), 2));
        self::assertFalse($this->repo->isFavorite(UserId::from(3), 1));
    }

    public function test_add_favorite_inserts_a_row_that_is_favorite_then_reports(): void
    {
        // User 3 / image 2: a combination distinct from the fixture's own
        // user-1 favorites, so this doesn't collide with other tests.
        self::assertFalse($this->repo->isFavorite(UserId::from(3), 2));

        $this->repo->addFavorite(UserId::from(3), 2);

        self::assertTrue($this->repo->isFavorite(UserId::from(3), 2));

        $this->repo->addFavorite(UserId::from(3), 2, ignoreDuplicate: true);

        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::favorites())
            ->where('user_id = 3 AND image_id = 2')
            ->executeQuery()
            ->fetchOne();
        self::assertSame(1, $count);

        $this->conn->executeStatement('DELETE FROM ' . Tables::favorites() . ' WHERE user_id = 3 AND image_id = 2');
    }

    public function test_find_registration_date_by_id_returns_null_for_a_nonexistent_user(): void
    {
        // No user_infos row exists at all for this id (not even one with a
        // NULL registration_date) -- fetchAllAssociative() returns [],
        // reaching the `$rows === []` guard rather than the `is_string()`
        // check below it.
        self::assertNull($this->repo->findRegistrationDateById(999999));
    }

    public function test_find_status_by_ids_with_no_ids_returns_empty(): void
    {
        self::assertSame([], $this->repo->findStatusByIds([]));
    }

    // Five defensive branches in this class stay genuinely unreached by any
    // real caller, confirmed rather than assumed:
    //
    // - findAdminIds()'s `$row['userId'] instanceof UserId` throw: `user_id`
    //   is mapped through the 'user_id' custom Doctrine type (see
    //   UserInfoEntity), which converts every hydrated value to a real
    //   UserId instance unconditionally, DQL scalar selects included. There
    //   is no way to make the ORM hand back a non-UserId value through this
    //   method's public contract without bypassing the type system entirely
    //   (which findAdminIds deliberately doesn't do, precisely so this
    //   conversion always applies).
    //
    // - findUserCountsByStatus()'s `! is_string($status)` continue and
    //   findUserCountsByLevel()'s `! is_numeric($level)` continue: both
    //   read straight off `user_infos` with no JOIN, and
    //   install/piwigo_structure-mysql.sql declares `status` an
    //   `enum(...) NOT NULL` column and `level` a `tinyint unsigned NOT
    //   NULL` column -- neither can ever surface as a non-string /
    //   non-numeric value through mysqli's native int/float typing
    //   (DbConnection::params()'s MYSQLI_OPT_INT_AND_FLOAT_NATIVE), so
    //   these `continue`s have no reachable real row shape.
    //
    // - findMinRegistrationDateAfter()'s `$rows === []` guard: the query is
    //   a bare `MIN(...)` aggregate with no GROUP BY, which the SQL
    //   standard (confirmed here against the real test DB, including
    //   against a genuinely empty temporary table) guarantees always
    //   returns exactly one row -- NULL in the aggregate column, never zero
    //   rows. fetchAllAssociative() can therefore never return [] here; the
    //   null-result case is instead reached one line down, via
    //   `is_string($minRegistrationDate)` on the real NULL value.
    //
    // - findStatusByIds()'s `! $id instanceof UserId` continue: `id` comes
    //   from `u.id`, DQL array hydration through the 'user_id' custom
    //   Doctrine type (see UserEntity), which converts every hydrated
    //   value to a real UserId instance unconditionally -- there is no way
    //   to make the ORM hand back a non-UserId value through this method's
    //   public contract.

    /**
     * @return list<int>
     */
    private function findListForWsIds(UserListCriteria $criteria): array
    {
        $result = $this->repo->findListForWs(['u.id' => 'id'], false, $criteria, 'u.id ASC', false, null, 0);

        return array_map(
            static fn (array $row): int => is_numeric($row['id']) ? (int) $row['id'] : 0,
            $result->rows
        );
    }

    // Fixture: user 1 (fixture_admin, status webmaster, level 8, group 1),
    // user 2 (guest, status guest, level 0), user 3 (regular_user, status
    // normal, level 0, groups 1 and 2), user 4 (power_user, status normal,
    // level 0, group 3). All 4 share registration_date 2026-08-01 00:00:00.

    public function test_find_list_for_ws_returns_every_user_for_an_empty_criteria(): void
    {
        self::assertSame([1, 2, 3, 4], $this->findListForWsIds(new UserListCriteria()));
    }

    public function test_find_list_for_ws_filters_by_user_id(): void
    {
        self::assertSame([1, 3], $this->findListForWsIds(new UserListCriteria(userId: [1, 3])));
    }

    public function test_find_list_for_ws_filters_by_username(): void
    {
        self::assertSame([1], $this->findListForWsIds(new UserListCriteria(username: 'fixture_admin')));
    }

    public function test_find_list_for_ws_filter_matches_username_directly(): void
    {
        self::assertSame([3], $this->findListForWsIds(new UserListCriteria(filter: 'regular')));
    }

    public function test_find_list_for_ws_filter_falls_back_to_the_resolved_group_ids(): void
    {
        // filteredGroupIds is the caller-resolved GroupService::getIdsByNameLike()
        // output -- the raw $filter text itself matches no username/email
        // here, only via the OR ug.group_id IN (...) branch.
        $ids = $this->findListForWsIds(new UserListCriteria(filter: 'no-such-username-match', filteredGroupIds: [3]));

        self::assertSame([4], $ids);
    }

    public function test_find_list_for_ws_filters_by_min_register(): void
    {
        self::assertSame([], $this->findListForWsIds(new UserListCriteria(minRegister: '2026-08-02 00:00:00')));
    }

    public function test_find_list_for_ws_filters_by_max_register(): void
    {
        self::assertSame([], $this->findListForWsIds(new UserListCriteria(maxRegister: '2026-07-31 23:59:59')));
    }

    public function test_find_list_for_ws_filters_by_status(): void
    {
        self::assertSame([1], $this->findListForWsIds(new UserListCriteria(status: ['webmaster'])));
    }

    public function test_find_list_for_ws_filters_by_min_level(): void
    {
        self::assertSame([1], $this->findListForWsIds(new UserListCriteria(minLevel: 5)));
    }

    public function test_find_list_for_ws_filters_by_max_level(): void
    {
        self::assertSame([2, 3, 4], $this->findListForWsIds(new UserListCriteria(maxLevel: 0)));
    }

    public function test_find_list_for_ws_filters_by_group_id(): void
    {
        self::assertSame([4], $this->findListForWsIds(new UserListCriteria(groupId: [3])));
    }

    public function test_find_list_for_ws_filters_by_exclude(): void
    {
        self::assertSame([1, 3, 4], $this->findListForWsIds(new UserListCriteria(exclude: [2])));
    }

    public function test_find_list_for_ws_applies_the_limit_and_reports_the_total(): void
    {
        $result = $this->repo->findListForWs(['u.id' => 'id'], false, new UserListCriteria(), 'u.id ASC', true, 2, 0);

        self::assertCount(2, $result->rows);
        self::assertSame(4, $result->total);
    }

    public function test_count_user_infos_rows_is_one_for_a_real_user(): void
    {
        // Item 14 Sub-phase B2 re-audit: had zero existing coverage.
        self::assertSame(1, $this->repo->countUserInfosRows(UserId::from(1)));
    }

    public function test_count_user_infos_rows_is_zero_for_a_nonexistent_user(): void
    {
        self::assertSame(0, $this->repo->countUserInfosRows(UserId::from(999999)));
    }

    public function test_find_favorite_image_ids_returns_the_real_ids(): void
    {
        // Item 14 Sub-phase B2 re-audit: had zero existing coverage.
        // Fixture: user 1 has favorites [1, 3, 5].
        $ids = $this->repo->findFavoriteImageIds(UserId::from(1));
        sort($ids);
        self::assertSame([1, 3, 5], $ids);
    }

    public function test_find_favorite_image_ids_returns_empty_for_a_user_with_no_favorites(): void
    {
        self::assertSame([], $this->repo->findFavoriteImageIds(UserId::from(2)));
    }

    public function test_delete_all_favorites_removes_every_row_for_the_user(): void
    {
        // Item 14 Sub-phase B2 re-audit: had zero existing coverage.
        $this->conn->beginTransaction();

        try {
            $this->repo->deleteAllFavorites(UserId::from(1));

            self::assertSame([], $this->repo->findFavoriteImageIds(UserId::from(1)));
        } finally {
            $this->conn->rollBack();
        }
    }

    public function test_find_visible_favorite_image_ids_returns_the_real_ids(): void
    {
        // Item 16J: had zero existing coverage. Fixture: user 1 has
        // favorites [1, 3, 5].
        $ids = $this->repo->findVisibleFavoriteImageIds(
            UserId::from(1),
            self::noPermissionRestriction(),
            'ORDER BY id ASC'
        );

        self::assertSame(['1', '3', '5'], $ids);
    }

    public function test_find_visible_favorite_image_ids_orders_by_the_given_fragment(): void
    {
        $ids = $this->repo->findVisibleFavoriteImageIds(
            UserId::from(1),
            self::noPermissionRestriction(),
            'ORDER BY id DESC'
        );

        self::assertSame(['5', '3', '1'], $ids);
    }

    public function test_find_visible_favorite_image_ids_falls_back_to_raw_dbal_for_unparseable_order_by(): void
    {
        $ids = $this->repo->findVisibleFavoriteImageIds(
            UserId::from(1),
            self::noPermissionRestriction(),
            'ORDER BY RAND()'
        );
        sort($ids);

        self::assertSame(['1', '3', '5'], $ids);
    }

    public function test_find_visible_favorite_image_ids_returns_empty_for_a_user_with_no_favorites(): void
    {
        self::assertSame(
            [],
            $this->repo->findVisibleFavoriteImageIds(
                UserId::from(2),
                self::noPermissionRestriction(),
                'ORDER BY id ASC'
            )
        );
    }

    private static function noPermissionRestriction(): PermissionCriteria
    {
        return new PermissionCriteria(null, null, null, null, null, null);
    }

    /**
     * Item 15 audit: had zero existing coverage -- covers every one of
     * deleteUser()'s 9 target tables (user_access/user_auth_keys/
     * user_group/user_infos/users all converted to DQL; favorites newly
     * converted this item; user_mail_notification/user_feed/caddie stay
     * on raw DBAL, a real deptrac boundary, see UserRelatedTable's own
     * docblock) on a real throwaway user, never touching the shared
     * fixture users other tests in this class depend on.
     */
    public function test_delete_user_removes_every_row_across_every_related_table(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::users() . " (username, password, mail_address) VALUES ('delete_user_test', NULL, NULL)"
        );
        $newUserId = (int) $this->conn->lastInsertId();
        $userId = UserId::from($newUserId);

        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::userInfos() . ' (user_id, status, language, theme) VALUES (?, ?, ?, ?)',
            [$newUserId, 'normal', 'en_UK', 'default']
        );
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::userAccess() . ' (user_id, cat_id) VALUES (?, 1)',
            [$newUserId]
        );
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::userGroup() . ' (user_id, group_id) VALUES (?, 1)',
            [$newUserId]
        );
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::favorites() . ' (user_id, image_id) VALUES (?, 1)',
            [$newUserId]
        );
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::userMailNotification() . ' (user_id, check_key, enabled) VALUES (?, ?, ?)',
            [$newUserId, 'delusrtestkey01', 0]
        );
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::userFeed() . " (id, user_id) VALUES ('delusrtestfeed01', ?)",
            [$newUserId]
        );
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::caddie() . ' (user_id, element_id) VALUES (?, 1)',
            [$newUserId]
        );

        $this->repo->deleteUser($userId);

        self::assertSame(0, $this->countRows(Tables::users(), 'id', $newUserId));
        self::assertSame(0, $this->countRows(Tables::userInfos(), 'user_id', $newUserId));
        self::assertSame(0, $this->countRows(Tables::userAccess(), 'user_id', $newUserId));
        self::assertSame(0, $this->countRows(Tables::userGroup(), 'user_id', $newUserId));
        self::assertSame(0, $this->countRows(Tables::favorites(), 'user_id', $newUserId));
        self::assertSame(0, $this->countRows(Tables::userMailNotification(), 'user_id', $newUserId));
        self::assertSame(0, $this->countRows(Tables::userFeed(), 'user_id', $newUserId));
        self::assertSame(0, $this->countRows(Tables::caddie(), 'user_id', $newUserId));
    }

    private function countRows(string $table, string $column, int $userId): int
    {
        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($table)
            ->where($column . ' = :userId')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int) $count : -1;
    }
}

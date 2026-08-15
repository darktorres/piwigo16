<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use LogicException;
use Override;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Permission\PermissionCriteria;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Users\Projection\UserListing;
use Piwigo\Users\UserListCriteria;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserStatus;

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
        $this->repo = new UserRepository(EntityManagerFactory::build($this->conn), EventDispatcherTestFactory::get(), $currentConfig);
    }

    public function testFindIdByUsernameReturnsAFixtureUser(): void
    {
        self::assertEquals(UserId::from(1), $this->repo->findIdByUsername(Username::from('fixture_admin')));
        self::assertNull($this->repo->findIdByUsername(Username::from('does-not-exist')));
    }

    public function testFindUsernameByIdReturnsAFixtureUser(): void
    {
        self::assertEquals(Username::from('fixture_admin'), $this->repo->findUsernameById(UserId::from(1)));
    }

    public function testFindUsernameByIdReturnsNullForANonexistentUser(): void
    {
        self::assertNull($this->repo->findUsernameById(UserId::from(999999)));
    }

    public function testFindIdByEmailReturnsAFixtureUser(): void
    {
        self::assertEquals(UserId::from(1), $this->repo->findIdByEmail(Email::from('fixture_admin@example.test')));
        self::assertNull($this->repo->findIdByEmail(Email::from('nobody@example.test')));
    }

    public function testFindIdByEmailIsCaseInsensitive(): void
    {
        self::assertEquals(UserId::from(1), $this->repo->findIdByEmail(Email::from('FIXTURE_ADMIN@EXAMPLE.TEST')));
    }

    public function testFindByUsernameCaseInsensitiveMatchesRegardlessOfCase(): void
    {
        $found = $this->repo->findByUsernameCaseInsensitive('FIXTURE_ADMIN');

        self::assertNotNull($found);
        self::assertSame(1, $found->id->value);
        self::assertSame('fixture_admin', $found->username);
        self::assertSame('fixture_admin@example.test', $found->email);
    }

    public function testFindByUsernameCaseInsensitiveReturnsNullWhenMissing(): void
    {
        self::assertNull($this->repo->findByUsernameCaseInsensitive('nope'));
    }

    public function testUsernameExistsCaseInsensitive(): void
    {
        self::assertTrue($this->repo->usernameExistsCaseInsensitive(Username::from('FIXTURE_ADMIN')));
        self::assertFalse($this->repo->usernameExistsCaseInsensitive(Username::from('nope')));
    }

    public function testEmailExists(): void
    {
        self::assertTrue($this->repo->emailExists(Email::from('fixture_admin@example.test'), null));
        self::assertFalse($this->repo->emailExists(Email::from('nobody@example.test'), null));
    }

    public function testEmailExistsExcludesTheGivenUserId(): void
    {
        self::assertFalse($this->repo->emailExists(Email::from('fixture_admin@example.test'), UserId::from(1)));
        self::assertTrue($this->repo->emailExists(Email::from('fixture_admin@example.test'), UserId::from(2)));
    }

    public function testFindAllUsernamesIncludesFixtureUsers(): void
    {
        $names = $this->repo->findAllUsernames();

        self::assertContains('fixture_admin', $names);
        self::assertContains('guest', $names);
    }

    public function testInsertUserThenFindIdByUsernameRoundTrips(): void
    {
        $username = 'p18-test-' . bin2hex(random_bytes(4));

        $id = $this->repo->insertUser(Username::from($username), 'irrelevant-hash', null);

        self::assertEquals($id, $this->repo->findIdByUsername(Username::from($username)));

        $this->conn->executeStatement('DELETE FROM users WHERE id = ' . $id->value);
    }

    public function testInsertUserInfosThenFindDefaultUserInfoRowRoundTrips(): void
    {
        $username = 'p18-test-' . bin2hex(random_bytes(4));
        $id = $this->repo->insertUser(Username::from($username), 'irrelevant-hash', null);

        $this->repo->insertUserInfos([$id], [
            'status' => 'normal',
            'registration_date' => '2026-01-01 00:00:00',
            'level' => 0,
        ]);

        $row = $this->repo->findDefaultUserInfoRow($id);

        self::assertNotNull($row);
        self::assertSame('normal', $row->status);

        $this->conn->executeStatement('DELETE FROM users WHERE id = ' . $id->value);
    }

    public function testInsertUserInfosAcceptsRealBoolForTheTinyintColumns(): void
    {
        // expand/show_nb_comments/show_nb_hits/
        // enabled_high/last_visit_from_history are real tinyint(1)
        // columns, and UserService::createUserInfos() hands
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
        $id = $this->repo->insertUser(Username::from($username), 'irrelevant-hash', null);

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

        $this->conn->executeStatement('DELETE FROM users WHERE id = ' . $id->value);
    }

    public function testInsertUserInfosWithNoIdsIsANoop(): void
    {
        $countBefore = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('user_infos')
            ->executeQuery()
            ->fetchOne();

        $this->repo->insertUserInfos([], [
            'status' => 'normal',
        ]);

        $countAfter = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('user_infos')
            ->executeQuery()
            ->fetchOne();

        self::assertSame($countBefore, $countAfter);
    }

    public function testSavePreferencesPersistsTheJsonEncodedValue(): void
    {
        $this->repo->savePreferences(UserId::from(1), [
            'theme' => 'dark',
        ]);

        $value = $this->conn->createQueryBuilder()
            ->select('preferences')
            ->from('user_infos')
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertIsString($value);
        self::assertSame([
            'theme' => 'dark',
        ], json_decode($value, true));

        $this->conn->executeStatement('UPDATE user_infos SET preferences = NULL WHERE user_id = 1');
    }

    public function testSavePreferencesIsANoopForANonexistentUser(): void
    {
        // No user_infos row exists for this id -- find() returns null and
        // the method returns early rather than persisting a fresh entity.
        $this->repo->savePreferences(UserId::from(999999), [
            'theme' => 'dark',
        ]);

        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('user_infos')
            ->where('user_id = 999999')
            ->executeQuery()
            ->fetchOne();

        self::assertSame('0', is_scalar($count) ? (string) $count : '');
    }

    public function testDeleteUsersFromTableWithNoIdsIsANoop(): void
    {
        $countBefore = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('user_infos')
            ->executeQuery()
            ->fetchOne();

        $this->repo->deleteUsersFromTable('user_infos', []);

        $countAfter = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('user_infos')
            ->executeQuery()
            ->fetchOne();

        self::assertSame($countBefore, $countAfter);
    }

    public function testDeleteFavoritesForImagesWithNoImageIdsIsANoop(): void
    {
        // A marker row distinct from the fixture's own (1,1)/(1,3)/(1,5)
        // favorites rows, so this assertion doesn't depend on what other
        // tests may already have inserted/removed against user 1's real
        // favorites. image_id must be a real fixture image (favorites.
        // image_id has a real FK constraint onto images.id) -- image 2
        // exists in the fixture and isn't one of user 1's own favorited
        // images above.
        $this->conn->executeStatement('INSERT INTO favorites (user_id, image_id) VALUES (1, 2)');

        $this->repo->deleteFavoritesForImages(UserId::from(1), []);

        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('favorites')
            ->where('user_id = 1 AND image_id = 2')
            ->executeQuery()
            ->fetchOne();

        self::assertSame(1, $count);

        $this->conn->executeStatement('DELETE FROM favorites WHERE user_id = 1 AND image_id = 2');
    }

    public function testUpdateStatusForUsersWithNoIdsIsANoop(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('status')
            ->from('user_infos')
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        $this->repo->updateStatusForUsers([], 'guest');

        $after = $this->conn->createQueryBuilder()
            ->select('status')
            ->from('user_infos')
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame($before, $after);
    }

    public function testUpdateInfosForUsersWithNoIdsIsANoop(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('nb_image_page')
            ->from('user_infos')
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        $this->repo->updateInfosForUsers([], [
            'nb_image_page' => 999,
        ]);

        $after = $this->conn->createQueryBuilder()
            ->select('nb_image_page')
            ->from('user_infos')
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame($before, $after);
    }

    public function testUpdateInfosForUsersWithNoUpdatesIsANoop(): void
    {
        $before = $this->conn->createQueryBuilder()
            ->select('nb_image_page')
            ->from('user_infos')
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        $this->repo->updateInfosForUsers([UserId::from(1)], []);

        $after = $this->conn->createQueryBuilder()
            ->select('nb_image_page')
            ->from('user_infos')
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertSame($before, $after);
    }

    public function testUpdateInfosForUsersPersistsANonBooleanAndABooleanFieldTogether(): void
    {
        // Covers a persist against UserInfoEntity exercising two
        // genuinely different code paths together: nb_image_page (plain
        // scalar bind) and expand (explicit bool cast) -- see
        // UserInfoField::dqlPropertyAndIsBoolean().
        $username = 'p18-test-' . bin2hex(random_bytes(4));
        $id = $this->repo->insertUser(Username::from($username), 'irrelevant-hash', null);
        $this->repo->insertUserInfos([$id], [
            'status' => 'normal',
        ]);

        try {
            $this->repo->updateInfosForUsers([$id], [
                'nb_image_page' => 27,
                'expand' => 1,
            ]);

            $row = $this->conn->createQueryBuilder()
                ->select('nb_image_page', 'expand')
                ->from('user_infos')
                ->where('user_id = :userId')
                ->setParameter('userId', $id->value)
                ->executeQuery()
                ->fetchAssociative();
            self::assertIsArray($row);
            self::assertSame(27, is_numeric($row['nb_image_page']) ? (int) $row['nb_image_page'] : null);
            self::assertSame(1, is_bool($row['expand']) || is_numeric($row['expand']) ? (int) (bool) $row['expand'] : null);
        } finally {
            $this->conn->executeStatement('DELETE FROM user_infos WHERE user_id = ' . $id->value);
            $this->conn->executeStatement('DELETE FROM users WHERE id = ' . $id->value);
        }
    }

    public function testUpdateInfosForUsersRejectsAnUnknownField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown user_infos field: not_a_real_field');

        $this->repo->updateInfosForUsers([UserId::from(1)], [
            'not_a_real_field' => 'x',
        ]);
    }

    public function testFindThemeUsageCountsIncludesAFreshlyInsertedUser(): void
    {
        $username = 'p18-test-' . bin2hex(random_bytes(4));
        $theme = 'p18-test-theme-' . bin2hex(random_bytes(4));

        $id = $this->repo->insertUser(Username::from($username), 'irrelevant-hash', null);
        $this->repo->insertUserInfos([$id], [
            'status' => 'normal',
            'theme' => $theme,
        ]);

        $counts = $this->repo->findThemeUsageCounts();

        self::assertArrayHasKey($theme, $counts);
        self::assertSame(1, $counts[$theme]);

        $this->conn->executeStatement('DELETE FROM user_infos WHERE user_id = ' . $id->value);
        $this->conn->executeStatement('DELETE FROM users WHERE id = ' . $id->value);
    }

    public function testFindLanguageUsageCountsIncludesAFreshlyInsertedUser(): void
    {
        // user_infos.language is LangCode-typed (strict `ll_RR` shape) --
        // a random-but-shape-valid code avoids colliding with real
        // fixture data.
        $username = 'p18-test-' . bin2hex(random_bytes(4));
        $language = chr(random_int(97, 122)) . chr(random_int(97, 122)) . '_' . chr(random_int(65, 90)) . chr(random_int(65, 90));

        $id = $this->repo->insertUser(Username::from($username), 'irrelevant-hash', null);
        $this->repo->insertUserInfos([$id], [
            'status' => 'normal',
            'language' => $language,
        ]);

        try {
            $counts = $this->repo->findLanguageUsageCounts();

            self::assertArrayHasKey($language, $counts);
            self::assertSame(1, $counts[$language]);
        } finally {
            $this->conn->executeStatement('DELETE FROM user_infos WHERE user_id = ' . $id->value);
            $this->conn->executeStatement('DELETE FROM users WHERE id = ' . $id->value);
        }
    }

    public function testFindNotificationRecipientsByIdsWithNoIdsReturnsEmpty(): void
    {
        self::assertSame([], $this->repo->findNotificationRecipientsByIds([]));
    }

    public function testFindUsernamesByIdsWithNoIdsReturnsEmpty(): void
    {
        self::assertSame([], $this->repo->findUsernamesByIds([]));
    }

    public function testFindAllUsernamesByIdReturnsEveryFixtureUser(): void
    {
        $byId = $this->repo->findAllUsernamesById();

        $normalized = [];
        foreach ($byId as $id => $username) {
            $normalized[$id] = $username;
        }
        ksort($normalized);

        self::assertSame([
            '1' => 'fixture_admin',
            '2' => 'guest',
            '3' => 'regular_user',
            '4' => 'power_user',
        ], $normalized);
    }

    public function testFindDistinctRegistrationYearMonthsReturnsFormattedPeriods(): void
    {
        // Every fixture user_infos row shares the same registration_date
        // (2026-08-01), so this only has one distinct period to prove the
        // year/month formatting -- not the DISTINCT/ORDER BY grouping
        // itself.
        self::assertSame(['2026-08'], $this->repo->findDistinctRegistrationYearMonths());
    }

    public function testFindDistinctRegistrationYearMonthsGroupsAndOrdersAcrossPeriods(): void
    {
        // Real regression coverage for the DISTINCT + ORDER BY shape
        // itself, not just the per-row formatting -- give user 1 an
        // earlier period and user 2 a later one, both distinct from the
        // shared fixture period (2026-08), and confirm all three come
        // back deduplicated and chronologically ordered regardless of the
        // rows' own id order.
        try {
            $this->conn->executeStatement("UPDATE user_infos SET registration_date = '2024-01-15 10:00:00' WHERE user_id = 1");
            $this->conn->executeStatement("UPDATE user_infos SET registration_date = '2027-03-20 10:00:00' WHERE user_id = 2");

            self::assertSame(
                ['2024-01', '2026-08', '2027-03'],
                $this->repo->findDistinctRegistrationYearMonths()
            );
        } finally {
            $this->conn->executeStatement("UPDATE user_infos SET registration_date = '2026-08-01 00:00:00' WHERE user_id IN (1, 2)");
        }
    }

    public function testFindUserCountsByStatusExcludesTheGivenUserAndGroupsByStatus(): void
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

    public function testFindUserCountsByLevelExcludesTheGivenUserAndGroupsByLevel(): void
    {
        // Excluding user 2 (guest) leaves user 1 at level 8 and users 3/4
        // both at level 0.
        self::assertSame([
            8 => 1,
            0 => 2,
        ], $this->repo->findUserCountsByLevel(2));
    }

    public function testFindUserIdsExcludingStatusOmitsMatchingRows(): void
    {
        $ids = $this->repo->findUserIdsExcludingStatus('guest');

        sort($ids);
        self::assertSame(['1', '3', '4'], $ids);
    }

    public function testIsFavoriteReflectsRealFavoritesRows(): void
    {
        self::assertTrue($this->repo->isFavorite(UserId::from(1), 1));
        self::assertFalse($this->repo->isFavorite(UserId::from(1), 2));
        self::assertFalse($this->repo->isFavorite(UserId::from(3), 1));
    }

    public function testAddFavoriteInsertsARowThatIsFavoriteThenReports(): void
    {
        // User 3 / image 2: a combination distinct from the fixture's own
        // user-1 favorites, so this doesn't collide with other tests.
        self::assertFalse($this->repo->isFavorite(UserId::from(3), 2));

        $this->repo->addFavorite(UserId::from(3), 2);

        self::assertTrue($this->repo->isFavorite(UserId::from(3), 2));

        $this->repo->addFavorite(UserId::from(3), 2, ignoreDuplicate: true);

        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('favorites')
            ->where('user_id = 3 AND image_id = 2')
            ->executeQuery()
            ->fetchOne();
        self::assertSame(1, $count);

        $this->conn->executeStatement('DELETE FROM favorites WHERE user_id = 3 AND image_id = 2');
    }

    public function testFindRegistrationDateByIdReturnsNullForANonexistentUser(): void
    {
        // No user_infos row exists at all for this id (not even one with a
        // NULL registration_date) -- fetchAllAssociative() returns [],
        // reaching the `$rows === []` guard rather than the `is_string()`
        // check below it.
        self::assertNull($this->repo->findRegistrationDateById(999999));
    }

    public function testFindStatusByIdsWithNoIdsReturnsEmpty(): void
    {
        self::assertSame([], $this->repo->findStatusByIds([]));
    }

    /**
     * Fixture: user 1 (fixture_admin, status webmaster), user 2 (guest,
     * status guest), user 3 (regular_user, status normal), user 4
     * (power_user, status normal) -- see this class's own "Fixture:"
     * comment further down for the fuller row shape.
     */
    public function testFindAllBasicInfoReturnsEveryUserOrderedByUsername(): void
    {
        $summaries = array_map(
            static fn (UserListing $user): array => [$user->id->value, $user->username, $user->status],
            $this->repo->findAllBasicInfo(),
        );

        self::assertSame([
            [1, 'fixture_admin', UserStatus::Webmaster],
            [2, 'guest', UserStatus::Guest],
            [4, 'power_user', UserStatus::Normal],
            [3, 'regular_user', UserStatus::Normal],
        ], $summaries);
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
        $result = $this->repo->findListForWs([
            'u.id' => 'id',
        ], false, $criteria, 'u.id ASC', false, null, 0);

        return array_map(
            static fn (array $row): int => is_numeric($row['id']) ? (int) $row['id'] : 0,
            $result->rows
        );
    }

    // Fixture: user 1 (fixture_admin, status webmaster, level 8, group 1),
    // user 2 (guest, status guest, level 0), user 3 (regular_user, status
    // normal, level 0, groups 1 and 2), user 4 (power_user, status normal,
    // level 0, group 3). All 4 share registration_date 2026-08-01 00:00:00.

    public function testFindListForWsReturnsEveryUserForAnEmptyCriteria(): void
    {
        self::assertSame([1, 2, 3, 4], $this->findListForWsIds(new UserListCriteria()));
    }

    public function testFindListForWsFiltersByUserId(): void
    {
        self::assertSame([1, 3], $this->findListForWsIds(new UserListCriteria(userId: [UserId::from(1), UserId::from(3)])));
    }

    public function testFindListForWsFiltersByUsername(): void
    {
        self::assertSame([1], $this->findListForWsIds(new UserListCriteria(username: 'fixture_admin')));
    }

    public function testFindListForWsFilterMatchesUsernameDirectly(): void
    {
        self::assertSame([3], $this->findListForWsIds(new UserListCriteria(filter: 'regular')));
    }

    public function testFindListForWsFilterFallsBackToTheResolvedGroupIds(): void
    {
        // filteredGroupIds is the caller-resolved GroupService::getIdsByNameLike()
        // output -- the raw $filter text itself matches no username/email
        // here, only via the OR ug.group_id IN (...) branch.
        $ids = $this->findListForWsIds(new UserListCriteria(filter: 'no-such-username-match', filteredGroupIds: [3]));

        self::assertSame([4], $ids);
    }

    public function testFindListForWsFiltersByMinRegister(): void
    {
        self::assertSame([], $this->findListForWsIds(new UserListCriteria(minRegister: SqlDateTime::from('2026-08-02 00:00:00'))));
    }

    public function testFindListForWsFiltersByMaxRegister(): void
    {
        self::assertSame([], $this->findListForWsIds(new UserListCriteria(maxRegister: SqlDateTime::from('2026-07-31 23:59:59'))));
    }

    public function testFindListForWsFiltersByStatus(): void
    {
        self::assertSame([1], $this->findListForWsIds(new UserListCriteria(status: ['webmaster'])));
    }

    public function testFindListForWsFiltersByMinLevel(): void
    {
        self::assertSame([1], $this->findListForWsIds(new UserListCriteria(minLevel: 5)));
    }

    public function testFindListForWsFiltersByMaxLevel(): void
    {
        self::assertSame([2, 3, 4], $this->findListForWsIds(new UserListCriteria(maxLevel: 0)));
    }

    public function testFindListForWsFiltersByGroupId(): void
    {
        self::assertSame([4], $this->findListForWsIds(new UserListCriteria(groupId: [3])));
    }

    public function testFindListForWsFiltersByExclude(): void
    {
        self::assertSame([1, 3, 4], $this->findListForWsIds(new UserListCriteria(exclude: [2])));
    }

    public function testFindListForWsAppliesTheLimitAndReportsTheTotal(): void
    {
        $result = $this->repo->findListForWs([
            'u.id' => 'id',
        ], false, new UserListCriteria(), 'u.id ASC', true, 2, 0);

        self::assertCount(2, $result->rows);
        self::assertSame(4, $result->total);
    }

    public function testCountUserInfosRowsIsOneForARealUser(): void
    {
        self::assertSame(1, $this->repo->countUserInfosRows(UserId::from(1)));
    }

    public function testCountUserInfosRowsIsZeroForANonexistentUser(): void
    {
        self::assertSame(0, $this->repo->countUserInfosRows(UserId::from(999999)));
    }

    public function testFindFavoriteImageIdsReturnsTheRealIds(): void
    {
        // Fixture: user 1 has favorites [1, 3, 5].
        $ids = $this->repo->findFavoriteImageIds(UserId::from(1));
        sort($ids);
        self::assertSame([1, 3, 5], $ids);
    }

    public function testFindFavoriteImageIdsReturnsEmptyForAUserWithNoFavorites(): void
    {
        self::assertSame([], $this->repo->findFavoriteImageIds(UserId::from(2)));
    }

    public function testDeleteAllFavoritesRemovesEveryRowForTheUser(): void
    {
        $this->conn->beginTransaction();

        try {
            $this->repo->deleteAllFavorites(UserId::from(1));

            self::assertSame([], $this->repo->findFavoriteImageIds(UserId::from(1)));
        } finally {
            $this->conn->rollBack();
        }
    }

    public function testFindVisibleFavoriteImageIdsReturnsTheRealIds(): void
    {
        // Fixture: user 1 has favorites [1, 3, 5].
        $ids = $this->repo->findVisibleFavoriteImageIds(
            UserId::from(1),
            self::noPermissionRestriction(),
            'ORDER BY id ASC'
        );

        self::assertSame(['1', '3', '5'], $ids);
    }

    public function testFindVisibleFavoriteImageIdsOrdersByTheGivenFragment(): void
    {
        $ids = $this->repo->findVisibleFavoriteImageIds(
            UserId::from(1),
            self::noPermissionRestriction(),
            'ORDER BY id DESC'
        );

        self::assertSame(['5', '3', '1'], $ids);
    }

    public function testFindVisibleFavoriteImageIdsFallsBackToRawDbalForUnparseableOrderBy(): void
    {
        // `comment` is a real images column but not one of the bounded
        // $sort_fields tokens, so resolveDqlOrderBy() returns null and this
        // must still return the right members via the raw-DBAL path.
        $ids = $this->repo->findVisibleFavoriteImageIds(
            UserId::from(1),
            self::noPermissionRestriction(),
            'ORDER BY comment ASC'
        );
        sort($ids);

        self::assertSame(['1', '3', '5'], $ids);
    }

    public function testFindVisibleFavoriteImageIdsRunsARandomOrderAsDql(): void
    {
        // RAND() resolves to the registered RandFunction custom DQL function
        // now, so this takes the DQL path against a real server -- which is
        // what proves the generated SQL is valid on this provider. The order
        // is random, so only membership can be asserted.
        $ids = $this->repo->findVisibleFavoriteImageIds(
            UserId::from(1),
            self::noPermissionRestriction(),
            'ORDER BY RAND()'
        );
        sort($ids);

        self::assertSame(['1', '3', '5'], $ids);
    }

    public function testFindVisibleFavoriteImageIdsReturnsEmptyForAUserWithNoFavorites(): void
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
     * Covers every one of deleteUser()'s 9 target tables
     * (user_access/user_auth_keys/user_group/user_infos/users/favorites
     * all DQL; user_mail_notification/user_feed/caddie stay on raw DBAL,
     * a real deptrac boundary, see UserRelatedTable's own docblock) on a
     * real throwaway user, never touching the shared fixture users other
     * tests in this class depend on.
     */
    public function testDeleteUserRemovesEveryRowAcrossEveryRelatedTable(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO users (username, password, mail_address) VALUES ('delete_user_test', NULL, NULL)"
        );
        $newUserId = (int) $this->conn->lastInsertId();
        $userId = UserId::from($newUserId);

        $this->conn->executeStatement(
            'INSERT INTO user_infos (user_id, status, language, theme) VALUES (?, ?, ?, ?)',
            [$newUserId, 'normal', 'en_UK', 'default']
        );
        $this->conn->executeStatement(
            'INSERT INTO user_access (user_id, cat_id) VALUES (?, 1)',
            [$newUserId]
        );
        $this->conn->executeStatement(
            'INSERT INTO user_group (user_id, group_id) VALUES (?, 1)',
            [$newUserId]
        );
        $this->conn->executeStatement(
            'INSERT INTO favorites (user_id, image_id) VALUES (?, 1)',
            [$newUserId]
        );
        $this->conn->executeStatement(
            'INSERT INTO user_mail_notification (user_id, check_key, enabled) VALUES (?, ?, ?)',
            [$newUserId, 'delusrtestkey01', 0]
        );
        $this->conn->executeStatement(
            "INSERT INTO user_feed (id, user_id) VALUES ('delusrtestfeed01', ?)",
            [$newUserId]
        );
        $this->conn->executeStatement(
            'INSERT INTO caddie (user_id, element_id) VALUES (?, 1)',
            [$newUserId]
        );

        $this->repo->deleteUser($userId);

        self::assertSame(0, $this->countRows('users', 'id', $newUserId));
        self::assertSame(0, $this->countRows('user_infos', 'user_id', $newUserId));
        self::assertSame(0, $this->countRows('user_access', 'user_id', $newUserId));
        self::assertSame(0, $this->countRows('user_group', 'user_id', $newUserId));
        self::assertSame(0, $this->countRows('favorites', 'user_id', $newUserId));
        self::assertSame(0, $this->countRows('user_mail_notification', 'user_id', $newUserId));
        self::assertSame(0, $this->countRows('user_feed', 'user_id', $newUserId));
        self::assertSame(0, $this->countRows('caddie', 'user_id', $newUserId));
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

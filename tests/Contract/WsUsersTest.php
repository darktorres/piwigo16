<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsUsersTest extends ContractTestCase
{
    public function testGetListResponseMatchesSchema(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', [
            'display' => 'basics',
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('users.getList', $response);
    }

    public function testGetListIncludesAdminUser(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', [
            'display' => 'basics',
        ]);

        $result = $response['result'];
        self::assertIsArray($result);
        $users = $result['users'] ?? null;
        self::assertIsArray($users);

        $usernames = array_column($users, 'username');
        self::assertContains('fixture_admin', $usernames, 'fixture_admin must appear in user list');
    }

    public function testGetListIsForbiddenForGuest(): void
    {
        $response = $this->ws('pwg.users.getList');

        self::assertSame('fail', $response['stat']);
        self::assertArrayHasKey('err', $response);
    }

    public function testFavoritesGetListResponseMatchesSchema(): void
    {
        $response = $this->wsAdmin('pwg.users.favorites.getList');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.users.favorites.getList', $response);
    }

    public function testFavoritesGetListReturnsPagingAndImages(): void
    {
        $response = $this->wsAdmin('pwg.users.favorites.getList');

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayHasKey('paging', $result);
        self::assertArrayHasKey('images', $result);
    }

    public function testFavoritesGetListFailsForGuest(): void
    {
        $response = $this->ws('pwg.users.favorites.getList');

        self::assertSame('ok', $response['stat']);
        self::assertFalse($response['result'], 'a guest gets a bare `false` result, not an error envelope');
    }

    // -------------------------------------------------------------- getList

    public function testGetListInvalidOrderReturnsError(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', [
            'order' => 'DROP TABLE users',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid input parameter order', $response['message']);
    }

    public function testGetListOrderByUsernameSortsCaseInsensitively(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', [
            'display' => 'basics',
            'order' => 'username ASC',
        ]);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        $sorted = $usernames;
        usort($sorted, strcasecmp(...));
        self::assertSame($sorted, $usernames);
    }

    public function testGetListFiltersByUsername(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', [
            'display' => 'basics',
            'username' => 'regular_user',
        ]);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        self::assertSame(['regular_user'], $usernames);
    }

    public function testGetListFilterMatchesUsernameOrEmail(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', [
            'display' => 'basics',
            'filter' => 'regular_user',
        ]);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        self::assertSame(['regular_user'], $usernames);
    }

    public function testGetListFilterAlsoMatchesByGroupName(): void
    {
        // 'Reviewers' is a real fixture group (id 2) whose only member is
        // regular_user (id 3, own email is NULL) -- per the fixture's
        // user_group rows. Neither regular_user's username nor its
        // (null) email contains "Reviewers", so a match here only comes
        // through getList()'s own self::groupService()->getIdsByNameLike()
        // + "OR ug.group_id IN (...)" branch, not the plain username/email
        // LIKE clauses.
        $response = $this->wsAdmin('pwg.users.getList', [
            'display' => 'basics',
            'filter' => 'Reviewers',
        ]);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        self::assertSame(['regular_user'], $usernames);
    }

    public function testGetListMinRegisterValidYearOnlyMatchesAllFixtureUsers(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', [
            'display' => 'basics',
            'min_register' => '2026',
        ]);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        self::assertContains('fixture_admin', $usernames);
    }

    public function testGetListMinRegisterInvalidFormatReturnsError(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', [
            'min_register' => 'not-a-date',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid input parameter min_register', $response['message']);
    }

    /**
     * '2026-13-99' passes the shape-only regex (4 digits, then 1-2 more
     * numeric groups) but is not a real calendar date -- the real
     * validator is SqlDateTime::from()'s own round-trip check.
     */
    public function testGetListMinRegisterShapeValidButCalendarInvalidReturnsError(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', [
            'min_register' => '2026-13-99',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid input parameter min_register', $response['message']);
    }

    public function testGetListMaxRegisterYearMonthOnlyComputesLastDayOfMonth(): void
    {
        // Fixture users all registered on 2026-08-01 -- max_register capped
        // to 2026-07 (the month before) must exclude every one of them,
        // proving the day-of-month was really computed (date('t')) rather
        // than defaulting to something that would still include 08-01.
        $response = $this->wsAdmin('pwg.users.getList', [
            'display' => 'basics',
            'max_register' => '2026-07',
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame([], $this->extractUsers($response));
    }

    public function testGetListMaxRegisterInvalidFormatReturnsError(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', [
            'max_register' => 'not-a-date',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid input parameter max_register', $response['message']);
    }

    /**
     * Sibling of the min_register shape-valid-but-calendar-invalid test
     * above -- max_register's own day-of-month computation branch
     * (isset($max_date_tokens[2])) uses the caller-supplied day directly,
     * so an out-of-range day ('2026-01-99') reaches SqlDateTime::from()
     * unmodified.
     */
    public function testGetListMaxRegisterShapeValidButCalendarInvalidReturnsError(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', [
            'max_register' => '2026-01-99',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid input parameter max_register', $response['message']);
    }

    public function testGetListMaxRegisterWithExplicitDayIsUsedDirectly(): void
    {
        // Sibling of the month-only test above, which takes the *else*
        // branch (day computed via date('t')) -- supplying all three date
        // tokens exercises getList()'s own isset($max_date_tokens[2])
        // branch instead. Fixture users all registered on 2026-08-01, so
        // capping max_register at that exact date must still include them
        // (<= 2026-08-01 23:59:59).
        $response = $this->wsAdmin('pwg.users.getList', [
            'display' => 'basics',
            'max_register' => '2026-08-01',
        ]);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        self::assertContains('fixture_admin', $usernames);
    }

    public function testGetListFiltersByStatus(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', [
            'display' => 'basics',
            'status' => ['webmaster'],
        ]);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        self::assertSame(['fixture_admin'], $usernames);
    }

    public function testGetListMinLevelInvalidReturnsError(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', [
            'min_level' => 3,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid level', $response['message']);
    }

    public function testGetListMinLevelFiltersByLevel(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', [
            'display' => 'basics',
            'min_level' => 8,
        ]);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        self::assertSame(['fixture_admin'], $usernames);
    }

    public function testGetListMaxLevelInvalidReturnsError(): void
    {
        // max_level is not a registered ws.php param (reachable only via the
        // shape's open tail -- see Users::getList()'s own docblock).
        $response = $this->wsAdmin('pwg.users.getList', [
            'max_level' => 3,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid level', $response['message']);
    }

    public function testGetListMaxLevelFiltersByLevel(): void
    {
        // max_level=0 is itself a sentinel meaning "no filter" (see the
        // in_array([null, false, 0, '0', '', []]) guard) -- 1 is the next
        // valid level in availablePermissionLevels() and still excludes
        // fixture_admin (level 8).
        $response = $this->wsAdmin('pwg.users.getList', [
            'display' => 'basics',
            'max_level' => 1,
        ]);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        self::assertNotContains('fixture_admin', $usernames);
        self::assertContains('regular_user', $usernames);
    }

    public function testGetListFiltersByGroupId(): void
    {
        // Group 3 ('Guests') has only power_user (id 4) as a member, per
        // the fixture's user_group rows -- exercises getList()'s
        // own 'ug.group_id IN(...)' where-clause branch (distinct from the
        // filter-by-group-name test above, which reaches the *same* SQL
        // alias through a different param).
        $response = $this->wsAdmin('pwg.users.getList', [
            'display' => 'basics',
            'group_id' => [3],
        ]);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        self::assertSame(['power_user'], $usernames);
    }

    // getList()'s own group-membership merge loop (populating each
    // returned user's 'groups' array from
    // self::groupService()->getMembershipsForUserIds()) has a defensive
    // `continue` guarded by `$group_user_id === null || $group_id === null
    // || ! isset($users[$group_user_id]) || ! is_array($users[$group_user_id]['groups'] ?? null)`.
    // Every 'basics'-display test above (which requests 'groups' and so
    // sets $want_groups = true) already exercises this loop's *happy*
    // path. The `continue` branch itself is provably unreachable through
    // any real request: getMembershipsForUserIds() is called with exactly
    // array_keys($users) as its own SQL `WHERE user_id IN (...)` filter
    // (GroupRepository::findMembershipsForUserIds()), so every returned
    // row's user_id is guaranteed to already be a key of $users, and
    // user_id/group_id are real NOT NULL int columns (never non-numeric,
    // the only way is_numeric(...) ? (int) ... : null yields null here);
    // and every $users entry gets 'groups' => [] seeded up-front (line
    // ~314) whenever $want_groups is true, so 'groups' is always already
    // an array by the time this loop runs. A static-analysis-only
    // narrowing guard, not a reachable real-usage gap -- no test added for
    // it here.

    public function testGetListExcludesGivenUserIds(): void
    {
        $before = $this->wsAdmin('pwg.users.getList', [
            'display' => 'basics',
        ]);
        $adminId = null;
        foreach ($this->extractUsers($before) as $user) {
            if ($user['username'] === 'fixture_admin') {
                $adminId = $user['id'];
            }
        }
        self::assertIsInt($adminId);

        $response = $this->wsAdmin('pwg.users.getList', [
            'display' => 'basics',
            'exclude' => [$adminId],
        ]);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        self::assertNotContains('fixture_admin', $usernames);
    }

    // getList()'s own `$ui_fields = ['status', 'level', 'language', ...]`
    // array-literal's opening lines show as "uncovered" in raw line
    // coverage despite every 'basics'/'all'-display test in this file
    // reaching and exercising it (that branch is unconditional once
    // `$params['display'] !== 'none'`, which every such test satisfies) --
    // a known OPcache constant-array-folding artifact (the identical
    // pattern already documented in
    // WsImagesSetInfoTest::test_setInfo_file_param_is_forbidden_on_synchronized_photos's
    // own preceding comment): a pure-literal array with no variables gets
    // folded at compile time, so line-based coverage can't attribute a
    // real hit to those specific source lines. Not a real gap; no
    // additional test added for it here.

    public function testGetListDisplayOnlyIdReturnsBareIdField(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', [
            'display' => 'only_id',
        ]);

        self::assertSame('ok', $response['stat']);
        $users = $this->extractUsers($response);
        self::assertNotEmpty($users);
        foreach ($users as $user) {
            self::assertSame(['id'], array_keys($user));
        }
    }

    public function testGetListDisplayNoneWithZeroPerPageReturnsPlainIdList(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', [
            'display' => 'none',
            'per_page' => 0,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayNotHasKey('paging', $result, 'display=none with per_page=0 must return a bare id list, not a paged struct');
    }

    public function testGetListTotalCountDisplayFlagReportsTheFullCount(): void
    {
        $limited = $this->wsAdmin('pwg.users.getList', [
            'display' => 'basics,total_count',
            'per_page' => 1,
        ]);

        self::assertSame('ok', $limited['stat']);
        $result = $limited['result'];
        self::assertIsArray($result);
        self::assertCount(1, $this->extractUsers($limited));
        $paging = $result['paging'] ?? null;
        self::assertIsArray($paging);
        self::assertArrayHasKey('total_count', $paging);
        $totalCount = $paging['total_count'];
        self::assertIsInt($totalCount);
        self::assertGreaterThanOrEqual(4, $totalCount, 'the 4 fixture users must all be counted regardless of per_page');
    }

    /**
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    private function extractUsers(array $response): array
    {
        $result = $response['result'] ?? null;
        self::assertIsArray($result);
        $users = $result['users'] ?? null;
        self::assertIsArray($users);

        $rows = [];
        foreach ($users as $user) {
            self::assertIsArray($user);
            /** @var array<string, mixed> $user */
            $rows[] = $user;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $response
     * @return list<string>
     */
    private function usernameColumn(array $response): array
    {
        $names = [];
        foreach ($this->extractUsers($response) as $user) {
            $username = $user['username'] ?? null;
            self::assertIsString($username);
            $names[] = $username;
        }

        return $names;
    }
}

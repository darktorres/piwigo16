<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsUsersTest extends ContractTestCase
{
    public function test_getList_response_matches_schema(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', ['display' => 'basics']);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('users.getList', $response);
    }

    public function test_getList_includes_admin_user(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', ['display' => 'basics']);

        $result = $response['result'];
        self::assertIsArray($result);
        $users = $result['users'] ?? null;
        self::assertIsArray($users);

        $usernames = array_column($users, 'username');
        self::assertContains('fixture_admin', $usernames, 'fixture_admin must appear in user list');
    }

    public function test_getList_is_forbidden_for_guest(): void
    {
        $response = $this->ws('pwg.users.getList');

        self::assertSame('fail', $response['stat']);
        self::assertArrayHasKey('err', $response);
    }

    public function test_favorites_getList_response_matches_schema(): void
    {
        $response = $this->wsAdmin('pwg.users.favorites.getList');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.users.favorites.getList', $response);
    }

    public function test_favorites_getList_returns_paging_and_images(): void
    {
        $response = $this->wsAdmin('pwg.users.favorites.getList');

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayHasKey('paging', $result);
        self::assertArrayHasKey('images', $result);
    }

    public function test_favorites_getList_fails_for_guest(): void
    {
        $response = $this->ws('pwg.users.favorites.getList');

        self::assertSame('ok', $response['stat']);
        self::assertFalse($response['result'], 'a guest gets a bare `false` result, not an error envelope');
    }

    // -------------------------------------------------------------- getList

    public function test_getList_invalid_order_returns_error(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', ['order' => 'DROP TABLE users']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid input parameter order', $response['message']);
    }

    public function test_getList_order_by_username_sorts_case_insensitively(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', ['display' => 'basics', 'order' => 'username ASC']);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        $sorted = $usernames;
        usort($sorted, static fn (string $a, string $b): int => strcasecmp($a, $b));
        self::assertSame($sorted, $usernames);
    }

    public function test_getList_filters_by_username(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', ['display' => 'basics', 'username' => 'regular_user']);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        self::assertSame(['regular_user'], $usernames);
    }

    public function test_getList_filter_matches_username_or_email(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', ['display' => 'basics', 'filter' => 'regular_user']);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        self::assertSame(['regular_user'], $usernames);
    }

    public function test_getList_min_register_valid_year_only_matches_all_fixture_users(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', ['display' => 'basics', 'min_register' => '2026']);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        self::assertContains('fixture_admin', $usernames);
    }

    public function test_getList_min_register_invalid_format_returns_error(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', ['min_register' => 'not-a-date']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid input parameter min_register', $response['message']);
    }

    public function test_getList_max_register_year_month_only_computes_last_day_of_month(): void
    {
        // Fixture users all registered on 2026-08-01 -- max_register capped
        // to 2026-07 (the month before) must exclude every one of them,
        // proving the day-of-month was really computed (date('t')) rather
        // than defaulting to something that would still include 08-01.
        $response = $this->wsAdmin('pwg.users.getList', ['display' => 'basics', 'max_register' => '2026-07']);

        self::assertSame('ok', $response['stat']);
        self::assertSame([], $this->extractUsers($response));
    }

    public function test_getList_max_register_invalid_format_returns_error(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', ['max_register' => 'not-a-date']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid input parameter max_register', $response['message']);
    }

    public function test_getList_filters_by_status(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', ['display' => 'basics', 'status' => ['webmaster']]);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        self::assertSame(['fixture_admin'], $usernames);
    }

    public function test_getList_min_level_invalid_returns_error(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', ['min_level' => 3]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid level', $response['message']);
    }

    public function test_getList_min_level_filters_by_level(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', ['display' => 'basics', 'min_level' => 8]);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        self::assertSame(['fixture_admin'], $usernames);
    }

    public function test_getList_max_level_invalid_returns_error(): void
    {
        // max_level is not a registered ws.php param (reachable only via the
        // shape's open tail -- see PwgUsers::getList()'s own docblock).
        $response = $this->wsAdmin('pwg.users.getList', ['max_level' => 3]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid level', $response['message']);
    }

    public function test_getList_max_level_filters_by_level(): void
    {
        // max_level=0 is itself a sentinel meaning "no filter" (see the
        // in_array([null, false, 0, '0', '', []]) guard) -- 1 is the next
        // valid level in availablePermissionLevels() and still excludes
        // fixture_admin (level 8).
        $response = $this->wsAdmin('pwg.users.getList', ['display' => 'basics', 'max_level' => 1]);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        self::assertNotContains('fixture_admin', $usernames);
        self::assertContains('regular_user', $usernames);
    }

    public function test_getList_excludes_given_user_ids(): void
    {
        $before = $this->wsAdmin('pwg.users.getList', ['display' => 'basics']);
        $adminId = null;
        foreach ($this->extractUsers($before) as $user) {
            if ($user['username'] === 'fixture_admin') {
                $adminId = $user['id'];
            }
        }
        self::assertIsInt($adminId);

        $response = $this->wsAdmin('pwg.users.getList', ['display' => 'basics', 'exclude' => [$adminId]]);

        self::assertSame('ok', $response['stat']);
        $usernames = $this->usernameColumn($response);
        self::assertNotContains('fixture_admin', $usernames);
    }

    public function test_getList_display_only_id_returns_bare_id_field(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', ['display' => 'only_id']);

        self::assertSame('ok', $response['stat']);
        $users = $this->extractUsers($response);
        self::assertNotEmpty($users);
        foreach ($users as $user) {
            self::assertSame(['id'], array_keys($user));
        }
    }

    public function test_getList_display_none_with_zero_per_page_returns_plain_id_list(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', ['display' => 'none', 'per_page' => 0]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayNotHasKey('paging', $result, 'display=none with per_page=0 must return a bare id list, not a paged struct');
    }

    public function test_getList_total_count_display_flag_reports_the_full_count(): void
    {
        $limited = $this->wsAdmin('pwg.users.getList', ['display' => 'basics,total_count', 'per_page' => 1]);

        self::assertSame('ok', $limited['stat']);
        $result = $limited['result'];
        self::assertIsArray($result);
        self::assertSame(1, count($this->extractUsers($limited)));
        self::assertArrayHasKey('total_count', $result);
        $totalCount = $result['total_count'];
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

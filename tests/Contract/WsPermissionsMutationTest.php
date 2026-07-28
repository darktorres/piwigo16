<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsPermissionsMutationTest extends ContractTestCase
{
    private ?int $privateCatId = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->privateCatId !== null) {
            $token = $this->getPwgToken();
            $this->callWs('pwg.categories.delete', [
                'category_id'         => $this->privateCatId,
                'photo_deletion_mode' => 'no_delete',
                'pwg_token'           => $token,
            ]);
            $this->privateCatId = null;
        }

        parent::tearDown();
    }

    /**
     * Narrows a decoded WS response down to result.id, asserting the shape
     * at every level. callWs() returns array<string, mixed>, so nothing
     * below the top level is known without explicit checks.
     *
     * @param array<string, mixed> $response
     */
    private static function resultId(array $response): int
    {
        $result = $response['result'] ?? null;
        self::assertIsArray($result, 'WS response "result" is not an array');

        $id = $result['id'] ?? null;
        self::assertTrue(
            is_int($id) || (is_string($id) && is_numeric($id)),
            'result.id is missing or not numeric'
        );

        return (int) $id;
    }

    /**
     * Narrows a decoded WS response down to result.users[0].id, asserting
     * the shape at every level.
     *
     * @param array<string, mixed> $response
     */
    private static function firstUserId(array $response): int
    {
        $result = $response['result'] ?? null;
        self::assertIsArray($result, 'WS response "result" is not an array');

        $users = $result['users'] ?? null;
        self::assertIsArray($users, 'WS response "result.users" is not an array');

        $user = $users[0] ?? null;
        self::assertIsArray($user, 'WS response "result.users[0]" is not an array');

        $id = $user['id'] ?? null;
        self::assertTrue(
            is_int($id) || (is_string($id) && is_numeric($id)),
            'result.users[0].id is missing or not numeric'
        );

        return (int) $id;
    }

    public function test_add_permission_returns_ok(): void
    {
        $token = $this->getPwgToken();
        $cat   = $this->callWs('pwg.categories.add', [
            'name'   => 'ct_private_' . uniqid(),
            'status' => 'private',
        ]);
        $this->privateCatId = self::resultId($cat);

        $users  = $this->callWs('pwg.users.getList', []);
        $userId = self::firstUserId($users);

        $response = $this->callWs('pwg.permissions.add', [
            'cat_id'    => [$this->privateCatId],
            'user_id'   => [$userId],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_remove_permission_returns_ok(): void
    {
        $token = $this->getPwgToken();
        $cat   = $this->callWs('pwg.categories.add', [
            'name'   => 'ct_private_' . uniqid(),
            'status' => 'private',
        ]);
        $this->privateCatId = self::resultId($cat);

        $users  = $this->callWs('pwg.users.getList', []);
        $userId = self::firstUserId($users);

        $this->callWs('pwg.permissions.add', [
            'cat_id'    => [$this->privateCatId],
            'user_id'   => [$userId],
            'pwg_token' => $token,
        ]);

        $response = $this->callWs('pwg.permissions.remove', [
            'cat_id'    => [$this->privateCatId],
            'user_id'   => [$userId],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    /**
     * add()'s own group_id branch (getUppercatIds()/getSubcatIds() +
     * groupAccess mass-insert) -- WsPermissionsMutationTest's other tests
     * only ever pass user_id, never group_id.
     */
    public function test_add_group_permission_grants_group_access_to_the_category(): void
    {
        $token = $this->getPwgToken();
        $cat = $this->callWs('pwg.categories.add', [
            'name' => 'ct_private_group_' . uniqid(),
            'status' => 'private',
        ]);
        $this->privateCatId = self::resultId($cat);

        $response = $this->callWs('pwg.permissions.add', [
            'cat_id' => [$this->privateCatId],
            'group_id' => [1],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $categories = $result['categories'];
        self::assertIsArray($categories);
        self::assertNotEmpty($categories);
        $entry = $categories[0];
        self::assertIsArray($entry);
        self::assertSame($this->privateCatId, $entry['id']);
        self::assertIsArray($entry['groups']);
        self::assertContains(1, $entry['groups']);
    }

    public function test_remove_group_permission_revokes_group_access(): void
    {
        $token = $this->getPwgToken();
        $cat = $this->callWs('pwg.categories.add', [
            'name' => 'ct_private_group_' . uniqid(),
            'status' => 'private',
        ]);
        $this->privateCatId = self::resultId($cat);

        $this->callWs('pwg.permissions.add', [
            'cat_id' => [$this->privateCatId],
            'group_id' => [1],
            'pwg_token' => $token,
        ]);

        $response = $this->callWs('pwg.permissions.remove', [
            'cat_id' => [$this->privateCatId],
            'group_id' => [1],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $categories = $result['categories'];
        self::assertIsArray($categories);
        $entry = $categories[0] ?? null;
        if ($entry !== null) {
            self::assertIsArray($entry);
            self::assertIsArray($entry['groups']);
            self::assertNotContains(1, $entry['groups']);
        }
    }

    public function test_getList_too_many_filters_returns_error(): void
    {
        $response = $this->callWs('pwg.permissions.getList', [
            'cat_id' => [1],
            'group_id' => [1],
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Too many parameters, provide cat_id OR user_id OR group_id', $response['message']);
    }

    public function test_getList_filters_by_group_id(): void
    {
        $token = $this->getPwgToken();
        $cat = $this->callWs('pwg.categories.add', [
            'name' => 'ct_private_group_filter_' . uniqid(),
            'status' => 'private',
        ]);
        $this->privateCatId = self::resultId($cat);
        $this->callWs('pwg.permissions.add', [
            'cat_id' => [$this->privateCatId],
            'group_id' => [1],
            'pwg_token' => $token,
        ]);

        $matching = $this->callWs('pwg.permissions.getList', ['group_id' => [1]]);
        self::assertSame('ok', $matching['stat']);
        $matchingResult = $matching['result'];
        self::assertIsArray($matchingResult);
        self::assertIsArray($matchingResult['categories']);
        $matchingIds = array_column($matchingResult['categories'], 'id');
        self::assertContains($this->privateCatId, $matchingIds);

        $nonMatching = $this->callWs('pwg.permissions.getList', ['group_id' => [999999]]);
        self::assertSame('ok', $nonMatching['stat']);
        $nonMatchingResult = $nonMatching['result'];
        self::assertIsArray($nonMatchingResult);
        self::assertSame([], $nonMatchingResult['categories']);
    }
}

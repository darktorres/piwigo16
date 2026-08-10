<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Override;
use Piwigo\Cache\CachePools;
use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;

/**
 * Ws\PwgImages::getInfo()/addComment() -- branches WsImagesTest/
 * WsImagesMutationTest don't reach: getInfo()'s access-denied guard for a
 * photo with no visible category, its unvalidated-comments filter for
 * non-admins, and its format=rest response shape; addComment()'s
 * comments-disabled guard, invalid-image_id guard, and the 'moderate'
 * switch case (a registered, non-admin user posting while
 * comments_validation is enabled -- the fixture's own default, confirmed
 * live via tests/Fixtures/piwigo-17.0.sql's `comments_validation`='true'
 * row).
 *
 * addComment()'s `default:` switch case ("Unknown comment action") is not
 * chased here: CommentService::insertComment() only ever returns
 * 'reject'/'validate'/'moderate' (confirmed by reading its full body) --
 * genuinely unreachable dead code, not a gap in test coverage.
 */
final class WsImagesGetInfoAndCommentTest extends ContractTestCase
{
    private Connection $conn;

    /** @var list<int> */
    private array $imageIdsToDelete = [];

    /** @var list<int> */
    private array $userIdsToDelete = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->imageIdsToDelete as $imageId) {
            $this->conn->executeStatement('DELETE FROM ' . 'comments' . ' WHERE image_id = ?', [$imageId]);
            $this->conn->executeStatement('DELETE FROM ' . 'images' . ' WHERE id = ?', [$imageId]);
        }
        if ($this->userIdsToDelete !== []) {
            $this->loginAsAdmin();
            $token = $this->getPwgToken();
            $this->callWs('pwg.users.delete', ['user_id' => $this->userIdsToDelete, 'pwg_token' => $token]);
        }
        $this->conn->executeStatement("UPDATE " . 'config' . " SET value = 'true' WHERE param = 'activate_comments'");
        CachePools::config()->clear();
        parent::tearDown();
    }

    private function insertOrphanImage(): int
    {
        $filename = 'getinfo-orphan-' . uniqid() . '.jpg';
        $this->conn->executeStatement(
            'INSERT INTO ' . 'images' . ' (file, path, md5sum) VALUES (?, ?, ?)',
            [$filename, 'upload/2026/08/01/' . $filename, md5($filename)]
        );
        $id = (int) $this->conn->lastInsertId();
        $this->imageIdsToDelete[] = $id;

        return $id;
    }

    // --------------------------------------------------------------- getInfo

    public function test_getInfo_on_an_orphan_photo_is_access_denied_for_a_guest(): void
    {
        $imageId = $this->insertOrphanImage();

        // An orphan photo (DB row, no backing file) is the whole point of
        // this fixture -- SrcImage::get_size()'s getimagesize() call warns
        // "Failed to open stream" on the deliberately-missing file
        // (confirmed live, before the permission check below even runs).
        $response = $this->ws('pwg.images.getInfo', ['image_id' => $imageId], allowPhpWarnings: true);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Access denied', $response['message']);
    }

    public function test_getInfo_on_an_orphan_photo_still_works_for_an_admin(): void
    {
        $imageId = $this->insertOrphanImage();

        // Same deliberately-missing-file getimagesize() warning as the
        // guest variant above.
        $response = $this->wsAdmin('pwg.images.getInfo', ['image_id' => $imageId], allowPhpWarnings: true);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame($imageId, $result['id']);
        self::assertSame([], $result['categories']);
    }

    public function test_getInfo_hides_unvalidated_comments_from_a_guest_but_shows_them_to_an_admin(): void
    {
        // Fixture comment id 5 on image 4 has validated=0 ("Pending comment
        // for moderation") -- confirmed live via a direct DB read before
        // writing this assertion, and it's the only comment on image 4.
        $guestResponse = $this->ws('pwg.images.getInfo', ['image_id' => 4]);
        self::assertSame('ok', $guestResponse['stat']);
        $guestResult = $guestResponse['result'];
        self::assertIsArray($guestResult);
        self::assertSame(['page' => 0, 'per_page' => 10, 'count' => 0, 'total_count' => 0], $guestResult['comments_paging']);

        $adminResponse = $this->wsAdmin('pwg.images.getInfo', ['image_id' => 4]);
        self::assertSame('ok', $adminResponse['stat']);
        $adminResult = $adminResponse['result'];
        self::assertIsArray($adminResult);
        self::assertSame(['page' => 0, 'per_page' => 10, 'count' => 1, 'total_count' => 1], $adminResult['comments_paging']);
    }

    public function test_getInfo_rest_format_wraps_the_result_in_an_image_struct(): void
    {
        $url = $this->baseUrl . '/ws.php?format=rest';
        $ch  = curl_init($url);
        self::assertNotFalse($ch);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_POSTFIELDS     => http_build_query(['method' => 'pwg.images.getInfo', 'image_id' => 1]),
            CURLOPT_HTTPHEADER     => $this->testHeader(),
        ]);

        $body = curl_exec($ch);
        unset($ch);

        self::assertIsString($body);
        self::assertStringContainsString('<?xml', $body);
        // JSON's top-level result is a flat object; REST wraps the very same
        // fields one level deeper under a named <image> element (the
        // `['image' => new PwgNamedStruct($ret, ...)]` branch, format='rest'
        // only).
        self::assertMatchesRegularExpression('/<image\s+id="1"/', $body);
        self::assertStringContainsString('file="fixture-photo-1.jpg"', $body);
    }

    // ------------------------------------------------------------ addComment

    public function test_addComment_when_comments_are_disabled_returns_error(): void
    {
        $this->conn->executeStatement("UPDATE " . 'config' . " SET value = 'false' WHERE param = 'activate_comments'");
        CachePools::config()->clear();

        $response = $this->ws('pwg.images.addComment', [
            'image_id' => 1,
            'author' => 'ContractTest',
            'content' => 'should never be inserted',
            'key' => 'irrelevant',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Comments are disabled', $response['message']);
    }

    public function test_addComment_on_a_nonexistent_image_returns_invalid_param(): void
    {
        $response = $this->ws('pwg.images.addComment', [
            'image_id' => 999999,
            'author' => 'ContractTest',
            'content' => 'should never be inserted',
            'key' => 'irrelevant',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid image_id', $response['message']);
    }

    /**
     * comments_validation is 'true' in the fixture (administrators must
     * validate comments) -- a non-admin, non-guest (registered "classic")
     * user's comment is neither auto-validated (that requires admin) nor
     * auto-rejected, landing on the 'moderate' switch case.
     */
    public function test_addComment_from_a_registered_non_admin_user_is_moderated(): void
    {
        $this->loginAsAdmin();
        $adminToken = $this->getPwgToken();
        $username = 'ct_moderated_' . uniqid();
        $password = 'Test1234!';
        $add = $this->callWs('pwg.users.add', [
            'username' => $username,
            'password' => $password,
            'pwg_token' => $adminToken,
        ]);
        self::assertSame('ok', $add['stat']);
        $result = $add['result'];
        self::assertIsArray($result);
        $users = $result['users'];
        self::assertIsArray($users);
        $user = $users[0];
        self::assertIsArray($user);
        $userId = $user['id'];
        self::assertIsInt($userId);
        $this->userIdsToDelete[] = $userId;

        $login = $this->callWs('pwg.session.login', ['username' => $username, 'password' => $password]);
        self::assertSame('ok', $login['stat']);

        $info = $this->callWs('pwg.images.getInfo', ['image_id' => 1]);
        $infoResult = $info['result'];
        self::assertIsArray($infoResult);
        $commentPost = $infoResult['comment_post'];
        self::assertIsArray($commentPost);
        $key = $commentPost['key'];
        self::assertIsString($key);

        sleep(3);

        // A moderated comment triggers an admin-notification email; this
        // sandbox has no real MTA configured, so Symfony Mailer's sendmail
        // transport times out (confirmed live) -- not a code bug.
        $content = 'Moderated comment ' . uniqid();
        $response = $this->callWs('pwg.images.addComment', [
            'image_id' => 1,
            'author' => $username,
            'content' => $content,
            'key' => $key,
        ], allowPhpWarnings: true);

        self::assertSame('ok', $response['stat']);
        $commentResult = $response['result'];
        self::assertIsArray($commentResult);
        $comment = $commentResult['comment'];
        self::assertIsArray($comment);
        self::assertFalse($comment['validation']);

        $validated = $this->conn->fetchOne(
            'SELECT validated FROM ' . 'comments' . ' WHERE content = ?',
            [$content]
        );
        self::assertSame(0, is_bool($validated) || is_numeric($validated) ? (int) (bool) $validated : -1);

        $this->conn->executeStatement('DELETE FROM ' . 'comments' . ' WHERE content = ?', [$content]);
    }
}

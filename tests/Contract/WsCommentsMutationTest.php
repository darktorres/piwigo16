<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsCommentsMutationTest extends ContractTestCase
{
    /** Image id that has comments in the fixture. */
    private const int FIXTURE_IMAGE_ID = 1;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    private function addComment(): int
    {
        // The ephemeral key must come from the server (HMAC over IP + secret_key).
        // pwg.images.getInfo returns a ready-to-use key in result.comment_post_data.key
        // generated with valid_after_seconds=2, so we must sleep before submitting.
        $info         = $this->callWs('pwg.images.getInfo', ['image_id' => self::FIXTURE_IMAGE_ID]);
        $infoResult   = $info['result'] ?? null;
        $commentPost  = is_array($infoResult) ? ($infoResult['comment_post'] ?? null) : null;
        $rawKey       = is_array($commentPost) ? ($commentPost['key'] ?? null) : null;
        $key          = is_string($rawKey) ? $rawKey : '';
        sleep(3);

        $response = $this->callWs('pwg.images.addComment', [
            'image_id' => self::FIXTURE_IMAGE_ID,
            'author'   => 'ContractTest',
            'content'  => 'CT comment for mutation test ' . uniqid(),
            'key'      => $key,
        ]);
        self::assertSame('ok', $response['stat']);

        $result  = $response['result'] ?? null;
        $comment = is_array($result) ? ($result['comment'] ?? null) : null;
        $id      = is_array($comment) ? ($comment['id'] ?? null) : null;

        if (! is_numeric($id)) {
            self::fail('comment.id must be numeric');
        }

        return (int) $id;
    }

    public function test_validate_comment_returns_ok(): void
    {
        $commentId = $this->addComment();
        $token     = $this->getPwgToken();

        $response = $this->callWs('pwg.userComments.validate', [
            'comment_id' => [$commentId],
            'pwg_token'  => $token,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_delete_comment_returns_ok(): void
    {
        $commentId = $this->addComment();
        $token     = $this->getPwgToken();

        $response = $this->callWs('pwg.userComments.delete', [
            'comment_id' => [$commentId],
            'pwg_token'  => $token,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_validate_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.userComments.validate', [
            'comment_id' => [1],
            'pwg_token'  => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid security token', $response['message']);
    }

    public function test_delete_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.userComments.delete', [
            'comment_id' => [1],
            'pwg_token'  => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid security token', $response['message']);
    }
}

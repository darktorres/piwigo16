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
        $info = $this->callWs('pwg.images.getInfo', ['image_id' => self::FIXTURE_IMAGE_ID]);
        $key  = (string) ($info['result']['comment_post']['key'] ?? '');
        sleep(3);

        $response = $this->callWs('pwg.images.addComment', [
            'image_id' => self::FIXTURE_IMAGE_ID,
            'author'   => 'ContractTest',
            'content'  => 'CT comment for mutation test ' . uniqid(),
            'key'      => $key,
        ]);
        self::assertSame('ok', $response['stat']);
        return (int) $response['result']['comment']['id'];
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
}

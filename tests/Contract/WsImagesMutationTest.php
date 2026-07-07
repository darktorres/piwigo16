<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsImagesMutationTest extends ContractTestCase
{
    /** Fixture image id — must not be deleted by these tests. */
    private const int FIXTURE_IMAGE_ID = 1;

    /** Fixture category id. */
    private const int FIXTURE_CAT_ID = 1;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    public function test_setPrivacyLevel_returns_ok(): void
    {
        $response = $this->callWs('pwg.images.setPrivacyLevel', [
            'image_id' => [self::FIXTURE_IMAGE_ID],
            'level'    => 0,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_setRank_returns_ok(): void
    {
        $response = $this->callWs('pwg.images.setRank', [
            'image_id'    => self::FIXTURE_IMAGE_ID,
            'category_id' => self::FIXTURE_CAT_ID,
            'rank'        => 1,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_setCategory_returns_ok(): void
    {
        $token    = $this->getPwgToken();
        $response = $this->callWs('pwg.images.setCategory', [
            'image_id'    => [self::FIXTURE_IMAGE_ID],
            'category_id' => self::FIXTURE_CAT_ID,
            'pwg_token'   => $token,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_addComment_response_matches_schema(): void
    {
        // The ephemeral key must come from the server; getInfo returns one in
        // result.comment_post_data.key (valid_after_seconds=2, so sleep first).
        $info = $this->callWs('pwg.images.getInfo', ['image_id' => self::FIXTURE_IMAGE_ID]);
        $key  = (string) ($info['result']['comment_post']['key'] ?? '');
        sleep(3);

        $response = $this->callWs('pwg.images.addComment', [
            'image_id' => self::FIXTURE_IMAGE_ID,
            'author'   => 'ContractTest',
            'content'  => 'Automated contract test comment ' . uniqid(),
            'key'      => $key,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.images.addComment', $response);
        self::assertArrayHasKey('id', $response['result']['comment']);
    }

    public function test_emptyLounge_returns_rows(): void
    {
        $response = $this->callWs('pwg.images.emptyLounge', []);

        self::assertSame('ok', $response['stat']);
        self::assertArrayHasKey('rows', $response['result']);
    }

    public function test_rate_invalid_value_returns_fail(): void
    {
        // rate value 99 is not in rate_items ([1,2,3,4,5]); contract: returns stat=fail with err 403
        $response = $this->callWs('pwg.images.rate', [
            'image_id' => self::FIXTURE_IMAGE_ID,
            'rate'     => 99,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }
}

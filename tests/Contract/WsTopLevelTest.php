<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

final class WsTopLevelTest extends ContractTestCase
{
    private Connection $conn;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
    }
    public function test_getVersion_returns_version_string(): void
    {
        $response = $this->wsAdmin('pwg.getVersion');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.getVersion', $response);

        $result = $response['result'];
        self::assertIsString($result);
        self::assertMatchesRegularExpression('/^\d+\.\d+/', $result);
    }

    public function test_getInfos_returns_install_statistics(): void
    {
        $response = $this->wsAdmin('pwg.getInfos');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.getInfos', $response);

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayHasKey('infos', $result);
        $infos = $result['infos'];
        self::assertIsArray($infos);

        $names = array_column($infos, 'name');
        self::assertContains('version', $names);
        self::assertContains('nb_elements', $names);
        self::assertContains('nb_categories', $names);
    }

    public function test_getCacheSize_returns_size_info(): void
    {
        $response = $this->wsAdmin('pwg.getCacheSize');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.getCacheSize', $response);
    }

    public function test_getCacheSize_forbidden_for_guest(): void
    {
        $response = $this->ws('pwg.getCacheSize');

        self::assertSame('fail', $response['stat']);
    }

    /**
     * getCacheSize() shells out to `du -sk <path>` and, when it parses a
     * leading numeric KB count from the output, converts it to bytes via
     * `(int) $matches[1] * 1024` -- for both the top-level cache dir
     * ('cache_size') and _data/templates_c ('tsizes'). This repo's own
     * real _data/ directory (and _data/templates_c within it) always has
     * real content by the time this Contract suite runs, so both real `du`
     * invocations always succeed and parse -- confirmed live (a real WS
     * call against the real Apache-served app, not a unit test poking
     * internals) before writing this assertion.
     *
     * Real production bug fixed alongside this test, not just a test
     * addition: getCacheSize() used $data_location ('_data/') completely
     * bare, unlike every other real call site of
     * CurrentConfig::dataLocation() in this codebase (PersistentFileCache,
     * FeedController, RequestBootstrap, Template, IntroSubController,
     * MailService, CoreUpdateService), all of which correctly prefix it
     * with CurrentPaths::get()->root per Paths' own class-level contract.
     * Under this rewrite's public/ webroot, the real request-time CWD is
     * public/, not the install root, so the bare relative path silently
     * resolved `du` against public/_data/ -- an unrelated, near-empty stub
     * containing only a `combined` symlink -- instead of the real _data/
     * directory. That made cache_size report a bogus ~4KB and tsizes
     * always null (public/_data has no templates_c/ at all), confirmed
     * live before the fix by the same manual WS call above.
     */
    public function test_getCacheSize_computes_real_byte_sizes_from_du(): void
    {
        $response = $this->wsAdmin('pwg.getCacheSize');

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $infos = $result['infos'];
        self::assertIsArray($infos);

        $values = array_column($infos, 'value', 'name');
        self::assertArrayHasKey('cache_size', $values);
        self::assertArrayHasKey('tsizes', $values);

        self::assertIsInt($values['cache_size']);
        self::assertGreaterThan(0, $values['cache_size']);
        self::assertSame(0, $values['cache_size'] % 1024, 'cache_size must be a whole multiple of 1024 (KB parsed from du, times 1024)');

        self::assertIsInt($values['tsizes']);
        self::assertGreaterThan(0, $values['tsizes']);
        self::assertSame(0, $values['tsizes'] % 1024, 'tsizes must be a whole multiple of 1024 (KB parsed from du, times 1024)');
    }

    public function test_getMissingDerivatives_returns_url_list(): void
    {
        $response = $this->wsAdmin('pwg.getMissingDerivatives', ['max_urls' => 10]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.getMissingDerivatives', $response);

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayHasKey('urls', $result);
        self::assertIsArray($result['urls']);
    }

    public function test_getMissingDerivatives_invalid_types_returns_error(): void
    {
        $response = $this->wsAdmin('pwg.getMissingDerivatives', ['types' => ['not-a-real-derivative-type']]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid types', $response['message']);
    }

    public function test_getMissingDerivatives_ids_filter_only_considers_the_given_image(): void
    {
        // fixture image 1's real on-disk file (dedicated fixture asset,
        // never touched by other Contract test files) is missing its
        // 'square' derivative on disk -- confirmed live before writing this
        // assertion (`i.php?/.../20260801000000-2e7e64c7-sq.jpg` shows up
        // in the un-filtered call's result too).
        $response = $this->wsAdmin('pwg.getMissingDerivatives', ['ids' => [1], 'max_urls' => 5000]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $urls = $result['urls'];
        self::assertIsArray($urls);
        self::assertNotEmpty($urls);
        foreach ($urls as $url) {
            self::assertIsString($url);
            self::assertStringContainsString('2e7e64c7', $url);
        }
    }

    public function test_getMissingDerivatives_paginates_when_max_urls_is_reached(): void
    {
        // A max_urls this low forces the do/while loop to stop mid-scan
        // (real fixture data has more than one image with a missing
        // derivative) and report a next_page cursor to resume from.
        $response = $this->wsAdmin('pwg.getMissingDerivatives', ['max_urls' => 1]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayHasKey('next_page', $result);
        self::assertIsInt($result['next_page']);
        self::assertGreaterThan(0, $result['next_page']);
        self::assertNotEmpty($result['urls']);
    }

    public function test_ratesDelete_removes_all_rates_for_a_user(): void
    {
        $status = $this->wsAdmin('pwg.session.getStatus');
        $statusResult = $status['result'];
        self::assertIsArray($statusResult);
        $userId = $this->conn->fetchOne(
            'SELECT id FROM ' . Tables::users() . ' WHERE username = ?',
            ['fixture_admin']
        );
        self::assertIsNumeric($userId);

        $rateResponse = $this->wsAdmin('pwg.images.rate', ['image_id' => 1, 'rate' => 5]);
        self::assertSame('ok', $rateResponse['stat']);

        $before = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ' . Tables::rate() . ' WHERE user_id = ? AND element_id = 1',
            [$userId]
        );
        self::assertIsNumeric($before);
        self::assertSame(1, (int) $before);

        $response = $this->wsAdmin('pwg.rates.delete', ['user_id' => (int) $userId, 'image_id' => 1]);

        self::assertSame('ok', $response['stat']);
        self::assertSame(1, $response['result']);

        $after = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ' . Tables::rate() . ' WHERE user_id = ? AND element_id = 1',
            [$userId]
        );
        self::assertIsNumeric($after);
        self::assertSame(0, (int) $after);
    }

    public function test_ratesDelete_with_no_matching_rate_returns_zero(): void
    {
        $userId = $this->conn->fetchOne(
            'SELECT id FROM ' . Tables::users() . ' WHERE username = ?',
            ['fixture_admin']
        );
        self::assertIsNumeric($userId);

        $response = $this->wsAdmin('pwg.rates.delete', ['user_id' => (int) $userId, 'image_id' => 999999]);

        self::assertSame('ok', $response['stat']);
        self::assertSame(0, $response['result']);
    }

    /**
     * ratesDelete()'s anonymous_id branch is only reachable when the
     * request supplies a non-empty, non-null anonymous_id -- every other
     * test above omits it (the WS default), which never touches this AND
     * anonymous_id=... SQL fragment at all. Uses a throwaway image (not a
     * fixture image 1-5) and cleans up via a real pwg.rates.delete call
     * before deleting it, same "avoid nudging the shared fixture's
     * rating_score, which other test files also read" rationale as
     * WsImagesMutationTest's own insertThrowawayImage() helper --
     * RateService::updateRatingScore() (triggered by every real delete)
     * recomputes every image's rating_score from a global average over the
     * whole piwigo_rate table, not just the touched row.
     */
    public function test_ratesDelete_with_anonymous_id_only_removes_the_matching_rate(): void
    {
        $guestId = $this->conn->fetchOne(
            'SELECT id FROM ' . Tables::users() . ' WHERE username = ?',
            ['guest']
        );
        self::assertIsNumeric($guestId);

        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::images() . ' (file, path) VALUES (?, ?)',
            ['pwgcore-throwaway-' . uniqid() . '.jpg', 'upload/pwgcore-throwaway.jpg']
        );
        $imageId = (int) $this->conn->lastInsertId();

        try {
            $this->conn->executeStatement(
                'INSERT INTO ' . Tables::rate() . ' (user_id, element_id, anonymous_id, rate, date) VALUES (?, ?, ?, 4, CURDATE())',
                [(int) $guestId, $imageId, 'anon-a']
            );
            $this->conn->executeStatement(
                'INSERT INTO ' . Tables::rate() . ' (user_id, element_id, anonymous_id, rate, date) VALUES (?, ?, ?, 2, CURDATE())',
                [(int) $guestId, $imageId, 'anon-b']
            );

            $response = $this->wsAdmin('pwg.rates.delete', [
                'user_id' => (int) $guestId,
                'image_id' => $imageId,
                'anonymous_id' => 'anon-a',
            ]);

            self::assertSame('ok', $response['stat']);
            self::assertSame(1, $response['result']);

            $remaining = $this->conn->fetchAllAssociative(
                'SELECT anonymous_id FROM ' . Tables::rate() . ' WHERE user_id = ? AND element_id = ?',
                [(int) $guestId, $imageId]
            );
            self::assertSame(['anon-b'], array_column($remaining, 'anonymous_id'));
        } finally {
            // Deletes the still-remaining 'anon-b' rate via the real WS
            // method (not raw SQL) so RateService::updateRatingScore() gets
            // one final recompute against the fully-restored piwigo_rate
            // table before the throwaway image itself is removed.
            $this->wsAdmin('pwg.rates.delete', ['user_id' => (int) $guestId, 'image_id' => $imageId]);
            $this->conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id = ?', [$imageId]);
        }
    }
}

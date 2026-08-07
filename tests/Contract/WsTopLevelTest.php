<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Override;
use Piwigo\Tests\Support\ImageStdParamsTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Bootstrap\InfrastructureAccessor;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\PwgCore;
use Doctrine\DBAL\Connection;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

final class WsTopLevelTest extends ContractTestCase
{
    private Connection $conn;

    #[Override]
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
     * with the live, container-bound Paths->root per Paths' own class-level contract.
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

    /**
     * getCacheSize()'s own per-type `msizes` loop: `$added_size =
     * @$msizes[DerivativeUrlCodec::derivativeToUrl($size_type)]; $added_size
     * = is_int($added_size) ? $added_size : 0;` -- the method's own
     * docblock (see PwgCore::getCacheSize()) explains this undefined-key
     * fallback is a *real* runtime guard: FilesystemHelper::
     * getCacheSizeDerivatives() only returns a key for a derivative size
     * that genuinely has cached files on disk, so a defined type with no
     * cached files at all hits the `: 0` fallback for real. The Contract
     * suite's own real _data/i/ cache (grown by every other Contract/
     * Browser test that generates a derivative) always has *some* but
     * never *every* defined type cached, so both the int (cache hit) and
     * `0` (fallback) arms of the ternary run across the loop's iterations
     * (every currently-*enabled* defined type, plus 'custom') -- 'all' is
     * asserted as their exact sum, which only holds if every iteration
     * actually wrote a real, correctly-typed int into $infos['msizes'].
     * '3xlarge'/'4xlarge' are deliberately NOT asserted here even though
     * ImageStdParams defines them: both are disabled by default on a
     * fresh install (ImageStdParams::$disabled_types_by_default), so a
     * default fixture's own msizes genuinely never reports them -- assert
     * against the real, current enabled set instead of a hardcoded list
     * that assumes every defined type is active.
     */
    public function test_getCacheSize_msizes_breaks_down_by_derivative_type_with_a_correct_total(): void
    {
        $response = $this->wsAdmin('pwg.getCacheSize');

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $infos = $result['infos'];
        self::assertIsArray($infos);

        $values = array_column($infos, 'value', 'name');
        self::assertArrayHasKey('msizes', $values);
        $msizes = $values['msizes'];
        self::assertIsArray($msizes);

        $expectedKeys = [...array_keys(ImageStdParamsTestFactory::get()->get_defined_type_map()), 'custom', 'all'];
        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $msizes, "msizes must always report a '{$key}' entry");
            self::assertIsInt($msizes[$key]);
            self::assertGreaterThanOrEqual(0, $msizes[$key]);
        }

        $sumOfParts = 0;
        foreach ($msizes as $key => $value) {
            if ($key === 'all') {
                continue;
            }
            self::assertIsInt($value);
            $sumOfParts += $value;
        }
        self::assertSame($sumOfParts, $msizes['all'], "'all' must equal the sum of every other msizes entry");
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
        // assertion (`i.php?/.../20260801000000-2e7ed018-sq.jpg` shows up
        // in the un-filtered call's result too).
        //
        // The upload path's own hash suffix is baked into each fixture
        // file at regen time and genuinely differs between
        // piwigo-17.0.sql and piwigo-17.0-pgsql.sql (separate,
        // independent install+upload runs) -- same real gap already
        // fixed in NotificationRepositoryTest.
        $expectedHash = $this->dbDriver === 'pgsql' ? '2e7eba7a' : '2e7ed018';
        $response = $this->wsAdmin('pwg.getMissingDerivatives', ['ids' => [1], 'max_urls' => 5000]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $urls = $result['urls'];
        self::assertIsArray($urls);
        self::assertNotEmpty($urls);
        foreach ($urls as $url) {
            self::assertIsString($url);
            self::assertStringContainsString($expectedHash, $url);
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

    /**
     * getMissingDerivatives()'s `if ($image_count === 0) { return []; }`
     * early-out -- unreachable through the real WS route while the shared
     * Contract fixture (5 images, read by dozens of other test files) is
     * loaded, and deleting/restoring it across a real HTTP round-trip
     * would risk corrupting every other Contract file's own state (the
     * images table cascades into image_category/image_tag/comments/rate/
     * favorites/lounge/image_format/caddie -- see each table's own
     * ON DELETE CASCADE). Instead, this calls PwgCore::getMissingDerivatives()
     * directly (bypassing the WS/HTTP layer entirely) inside a transaction
     * on the exact same Doctrine Connection its own container-resolved
     * ImageService/ImageRepository will use
     * (Bootstrap\InfrastructureAccessor::entityManager()'s own docblock:
     * "the container's own single, per-request EntityManagerInterface
     * instance -- the one every container-resolved repository shares"),
     * then rolls the delete back in `finally` -- the identical
     * "wrap the fixture's own images-table deletion in a transaction on
     * the exact connection the code under test reads through, always
     * rolling it back" technique tests/Integration/
     * NoPhotoYetRendererTest.php already established for this exact
     * "empty images table" scenario. `Kernel::boot()`/`Kernel::reset()`
     * from a test file are architecturally allowed (tests/ is exempted
     * from the "Kernel::container() only from Bootstrap/" arch test, and
     * `Kernel::reset()` is explicitly test-only) -- same pattern already
     * used by tests/Integration/CategoryAdminServiceTest.php to reach a
     * container-resolved service without a full RequestBootstrap.
     *
     * A real Paths is required, not a bare boot: self::imageService() is
     * called unconditionally before the $image_count === 0 check below (see
     * PwgCore::getMissingDerivatives()'s own body), so it always resolves
     * ImageService -> Lang -> Paths, whose value the container can't
     * guess without one.
     */
    public function test_getMissingDerivatives_with_an_empty_gallery_returns_an_empty_array_early(): void
    {
        Kernel::boot(Paths::fromRoot(dirname(__DIR__, 2)));

        try {
            $conn = InfrastructureAccessor::entityManager()->getConnection();
            $conn->beginTransaction();

            try {
                $conn->executeStatement('DELETE FROM ' . Tables::images());

                $service = Kernel::container()->get(PwgServer::class);
                self::assertInstanceOf(PwgServer::class, $service);
                $params = [
                    'types' => [],
                    'ids' => [],
                    'max_urls' => 200,
                    'prev_page' => null,
                    'f_min_rate' => null,
                    'f_max_rate' => null,
                    'f_min_hit' => null,
                    'f_max_hit' => null,
                    'f_min_ratio' => null,
                    'f_max_ratio' => null,
                    'f_max_level' => null,
                    'f_min_date_available' => null,
                    'f_max_date_available' => null,
                    'f_min_date_created' => null,
                    'f_max_date_created' => null,
                ];
                $pwgCore = Kernel::container()->get(PwgCore::class);
                self::assertInstanceOf(PwgCore::class, $pwgCore);
                $result = $pwgCore->getMissingDerivatives($params, $service);

                self::assertSame([], $result);
            } finally {
                $conn->rollBack();
            }
        } finally {
            Kernel::reset();
        }
    }

    /**
     * getMissingDerivatives()'s inner `if ($src_image->is_mimetype()) {
     * continue; }` -- skips a row entirely (no type is even considered)
     * when SrcImage falls back to a generic theme mimetype icon instead
     * of a real derivable photo, which only happens for a non-picture
     * extension (outside CurrentConfig::pictureExtensions(), default
     * jpg/jpeg/png/gif/webp) with no representative_ext of its own. Same
     * "insert a throwaway images row directly, never touched by any other
     * test" technique as WsTopLevelTest's own anonymous-rate test / the
     * WsHistoryTest dangling-image-id tests -- no real PDF file needs to
     * exist on disk: SrcImage falls back to the theme's own bundled
     * mimetypes/pdf.png icon (or unknown.png) purely from the extension.
     */
    public function test_getMissingDerivatives_skips_a_non_picture_file_rendered_as_a_mimetype_icon(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::images() . ' (file, path) VALUES (?, ?)',
            ['pwgcore-mimetype-' . uniqid() . '.pdf', 'upload/pwgcore-mimetype-throwaway.pdf']
        );
        $imageId = (int) $this->conn->lastInsertId();

        try {
            $response = $this->wsAdmin('pwg.getMissingDerivatives', ['ids' => [$imageId], 'max_urls' => 5000]);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            self::assertSame([], $result['urls']);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id = ?', [$imageId]);
        }
    }

    /**
     * getMissingDerivatives()'s inner `if ($type !== $derivative->get_type())
     * { continue; }` -- DerivativeImage::build() resolves a requested type
     * whose ideal size isn't bigger than the source image's own real size
     * to `null` params (no upsampling), making get_type() return
     * 'Original' instead of the requested type string. Every fixture
     * image is 200x150 (real, deliberately small px), well under
     * 'xxlarge' (1656x1242) -- confirmed via DerivativeParams::is_identity().
     * Deliberately not '3xlarge'/'4xlarge': both are disabled by default
     * (ImageStdParams::$disabled_types_by_default), so
     * getMissingDerivatives()'s own array_intersect() against the enabled
     * type map would reject either as an "Invalid types" PwgError before
     * ever reaching the skip logic this test targets -- 'xxlarge' is the
     * largest type that's still enabled by default, and 200x150 is well
     * under it too, so it exercises the exact same code path.
     */
    public function test_getMissingDerivatives_skips_a_type_the_source_is_already_big_enough_for(): void
    {
        $response = $this->wsAdmin('pwg.getMissingDerivatives', [
            'ids' => [1],
            'types' => ['xxlarge'],
            'max_urls' => 5000,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame([], $result['urls']);
    }

    /**
     * getMissingDerivatives()'s `if (count($urls) >= $max_urls and !
     * $is_last) { break; }` -- stops scanning the *current* fetched batch
     * of rows mid-`foreach` once max_urls is reached, distinct from the
     * do/while loop's own top-of-loop exit (already exercised by
     * test_getMissingDerivatives_paginates_when_max_urls_is_reached
     * above). A dedicated throwaway image (6000x4500 -- bigger than every
     * defined type up to '4xlarge', so DerivativeParams::is_identity()
     * is false for all of them and every one of the 11 default types
     * genuinely needs a derivative) makes a *single* processed row
     * contribute far more URLs than max_urls=2 by itself, guaranteeing
     * the break fires with real fixture images still left below it (this
     * doesn't depend on any other test file's own on-disk derivative
     * cache state, unlike the plain max_urls=1 pagination test above).
     */
    public function test_getMissingDerivatives_stops_mid_batch_once_max_urls_is_reached(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::images() . ' (file, path, width, height) VALUES (?, ?, ?, ?)',
            ['pwgcore-huge-' . uniqid() . '.jpg', 'upload/pwgcore-huge-throwaway.jpg', 6000, 4500]
        );
        $imageId = (int) $this->conn->lastInsertId();

        try {
            $response = $this->wsAdmin('pwg.getMissingDerivatives', ['max_urls' => 2]);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            self::assertArrayHasKey('next_page', $result);
            self::assertIsInt($result['next_page']);
            $urls = $result['urls'];
            self::assertIsArray($urls);
            self::assertGreaterThanOrEqual(2, count($urls));
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id = ?', [$imageId]);
        }
    }

    /**
     * Real bug found live: this used to rate fixture image 1 directly.
     * RateService::updateRatingScore() (triggered by every real
     * pwg.images.rate/pwg.rates.delete call) recomputes *every* image's
     * rating_score from a bayesian average over the whole piwigo_rate
     * table, not just the touched row -- confirmed live that adding then
     * deleting a rate on image 1 leaves every other fixture image's
     * rating_score numerically different from its pre-test value (a
     * bayesian/shrinkage estimator's own global-mean input shifts while
     * the extra rate briefly exists, and doesn't reliably land back on
     * the exact original float afterward), corrupting later tests that
     * read fixture rating_score values (WsHelperTest's own f_min_rate/
     * f_max_rate assertions). Same "avoid nudging the shared fixture's
     * rating_score, which other test files also read" rationale this
     * class's own test_ratesDelete_with_anonymous_id_only_removes_the_matching_rate()
     * and WsImagesMutationTest::insertThrowawayImage() already established
     * -- uses a throwaway image instead of fixture image 1.
     */
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

        $filename = 'ratesdelete-throwaway-' . uniqid() . '.jpg';
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::images() . ' (file, path, md5sum) VALUES (?, ?, ?)',
            [$filename, 'upload/' . $filename, md5($filename)]
        );
        $imageId = (int) $this->conn->lastInsertId();

        try {
            $this->conn->executeStatement(
                'INSERT INTO ' . Tables::imageCategory() . ' (image_id, category_id) VALUES (?, ?)',
                [$imageId, 1]
            );

            $rateResponse = $this->wsAdmin('pwg.images.rate', ['image_id' => $imageId, 'rate' => 5]);
            self::assertSame('ok', $rateResponse['stat']);

            $before = $this->conn->fetchOne(
                'SELECT COUNT(*) FROM ' . Tables::rate() . ' WHERE user_id = ? AND element_id = ?',
                [$userId, $imageId]
            );
            self::assertIsNumeric($before);
            self::assertSame(1, (int) $before);

            $response = $this->wsAdmin('pwg.rates.delete', ['user_id' => (int) $userId, 'image_id' => $imageId]);

            self::assertSame('ok', $response['stat']);
            self::assertSame(1, $response['result']);

            $after = $this->conn->fetchOne(
                'SELECT COUNT(*) FROM ' . Tables::rate() . ' WHERE user_id = ? AND element_id = ?',
                [$userId, $imageId]
            );
            self::assertIsNumeric($after);
            self::assertSame(0, (int) $after);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id = ?', [$imageId]);
        }
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
                'INSERT INTO ' . Tables::rate() . ' (user_id, element_id, anonymous_id, rate, date) VALUES (?, ?, ?, 4, CURRENT_DATE)',
                [(int) $guestId, $imageId, 'anon-a']
            );
            $this->conn->executeStatement(
                'INSERT INTO ' . Tables::rate() . ' (user_id, element_id, anonymous_id, rate, date) VALUES (?, ?, ?, 2, CURRENT_DATE)',
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

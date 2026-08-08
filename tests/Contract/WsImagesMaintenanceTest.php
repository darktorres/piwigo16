<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Override;
use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Ws\PwgImages maintenance/format methods (Wave 1 of the coverage-gap
 * closure plan, see /home/torres/.claude/plans/piped-enchanting-spark.md):
 * setMd5sum (0/13), syncMetadata (0/34), deleteOrphans (0/11),
 * formats.delete (0/57), formats.searchImage (0/59), checkFiles (0/25) --
 * all admin_only + post_only, all reachable through the real WS route.
 *
 * checkFiles() had a real bug (found while writing these tests, fixed in
 * the same commit): it called md5_file() on the bare `piwigo_images.path`
 * column value, never prefixing the live, container-bound Paths->root the way
 * ImagePathHelper::getElementPath() (used correctly by formatsDelete() a
 * few methods down in the same file) does -- so md5_file() always failed
 * (false, never equal to any real hash) and the method always reported
 * "differs", even for an unmodified photo. Confirmed live via a direct WS
 * call with the fixture's own real md5 before fixing.
 */
final class WsImagesMaintenanceTest extends ContractTestCase
{
    private Connection $conn;

    /** @var list<int> image ids inserted by a test, deleted in tearDown if the test itself didn't already remove them. */
    private array $insertedImageIds = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
        $this->loginAsAdmin();
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->insertedImageIds as $id) {
            $this->conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    private function pwgToken(): string
    {
        $this->loginAsAdmin();

        return $this->getPwgToken();
    }

    // ------------------------------------------------------------- setMd5sum

    public function test_setMd5sum_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.images.setMd5sum', ['pwg_token' => 'not-the-real-token']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_setMd5sum_computes_missing_checksums(): void
    {
        // Fixture image 1 always has a real md5sum already -- null it out for
        // this test only, then restore it, so setMd5sum() has real work to do
        // without permanently mutating fixture state for later tests.
        $original = $this->conn->fetchOne('SELECT md5sum FROM ' . Tables::images() . ' WHERE id = 1');
        self::assertIsString($original);

        try {
            $this->conn->executeStatement("UPDATE " . Tables::images() . ' SET md5sum = NULL WHERE id = 1');

            $response = $this->callWs('pwg.images.setMd5sum', [
                'block_size' => 50,
                'pwg_token' => $this->pwgToken(),
            ]);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            self::assertSame(1, $result['nb_added']);
            self::assertSame(0, $result['nb_no_md5sum']);

            $recomputed = $this->conn->fetchOne('SELECT md5sum FROM ' . Tables::images() . ' WHERE id = 1');
            self::assertSame($original, $recomputed);
        } finally {
            $this->conn->executeStatement(
                'UPDATE ' . Tables::images() . ' SET md5sum = ? WHERE id = 1',
                [$original]
            );
        }
    }

    // ---------------------------------------------------------- syncMetadata
    //
    // syncMetadata()'s own preg_split() failure guard (image_id, same
    // `/[\s,;\|]/` pattern as formats.delete()'s below) is unreachable here
    // for the same reason -- see the note at the bottom of the
    // formats.delete section of this file.

    public function test_syncMetadata_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.images.syncMetadata', ['image_id' => '1', 'pwg_token' => 'wrong']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_syncMetadata_invalid_image_id_returns_error(): void
    {
        $response = $this->callWs('pwg.images.syncMetadata', [
            'image_id' => 'not-a-number',
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid image_id "not-a-number"', $response['message']);
    }

    public function test_syncMetadata_nonexistent_image_id_returns_error(): void
    {
        $response = $this->callWs('pwg.images.syncMetadata', [
            'image_id' => '999999',
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('No image found', $response['message']);
    }

    public function test_syncMetadata_image_id_with_no_value_after_splitting_returns_error(): void
    {
        // ", ," splits (on [\s,;\|], PREG_SPLIT_NO_EMPTY) into zero tokens --
        // a distinct branch from "not-a-number" above: the per-token
        // ValidationPattern::ID loop never runs at all, so $image_ids stays
        // [] by the time the *post-loop* emptiness check runs.
        $response = $this->callWs('pwg.images.syncMetadata', [
            'image_id' => ' , ,',
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid image_id (no value after filters)', $response['message']);
    }

    public function test_syncMetadata_valid_image_id_returns_synchronized_count(): void
    {
        $response = $this->callWs('pwg.images.syncMetadata', [
            'image_id' => '1,2',
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame(2, $result['nb_synchronized']);
    }

    // --------------------------------------------------------- deleteOrphans

    private function insertOrphanImage(): int
    {
        $tmpDir = dirname(__DIR__, 2) . '/upload/2026/08/01';
        $filename = 'orphan-test-' . uniqid() . '.jpg';
        file_put_contents($tmpDir . '/' . $filename, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==',
            true
        ));

        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::images() . ' (file, path, md5sum) VALUES (?, ?, ?)',
            [$filename, 'upload/2026/08/01/' . $filename, md5($filename)]
        );
        $id = (int) $this->conn->lastInsertId();
        $this->insertedImageIds[] = $id;

        return $id;
    }

    public function test_deleteOrphans_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.images.deleteOrphans', ['pwg_token' => 'wrong']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_deleteOrphans_deletes_a_photo_with_no_album(): void
    {
        $orphanId = $this->insertOrphanImage();

        $before = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::images() . ' WHERE id = ?', [$orphanId]);
        self::assertIsNumeric($before);
        self::assertSame(1, (int) $before);

        $response = $this->callWs('pwg.images.deleteOrphans', [
            'block_size' => 1000,
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertGreaterThanOrEqual(1, $result['nb_deleted']);
        self::assertSame(0, $result['nb_orphans']);

        $after = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::images() . ' WHERE id = ?', [$orphanId]);
        self::assertIsNumeric($after);
        self::assertSame(0, (int) $after);
    }

    // ------------------------------------------------------------ checkFiles

    public function test_checkFiles_missing_image_id_returns_404(): void
    {
        $response = $this->callWs('pwg.images.checkFiles', ['image_id' => 999999]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
    }

    public function test_checkFiles_reports_thumbnail_always_equal(): void
    {
        $response = $this->callWs('pwg.images.checkFiles', ['image_id' => 1, 'thumbnail_sum' => 'anything']);

        self::assertSame('ok', $response['stat']);
        self::assertSame(['thumbnail' => 'equals'], $response['result']);
    }

    public function test_checkFiles_reports_equals_for_the_real_file_hash(): void
    {
        $realPath = dirname(__DIR__, 2) . '/upload/2026/08/01/20260801000000-2e7ed018.jpg';
        self::assertFileExists($realPath);
        $realMd5 = md5_file($realPath);

        $response = $this->callWs('pwg.images.checkFiles', ['image_id' => 1, 'file_sum' => $realMd5]);

        self::assertSame('ok', $response['stat']);
        self::assertSame(['file' => 'equals'], $response['result']);
    }

    public function test_checkFiles_reports_differs_for_a_wrong_hash(): void
    {
        $response = $this->callWs('pwg.images.checkFiles', [
            'image_id' => 1,
            'file_sum' => 'deadbeefdeadbeefdeadbeefdeadbeef',
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame(['file' => 'differs'], $response['result']);
    }

    public function test_checkFiles_high_sum_reports_file_equals_unconditionally_plus_the_high_comparison(): void
    {
        // isset($params['high_sum']) sets $ret['file'] = 'equals' up front
        // unconditionally (legacy compat), *then* runs the real comparison
        // keyed 'high' (not 'file') -- both keys end up in the result.
        $realPath = dirname(__DIR__, 2) . '/upload/2026/08/01/20260801000000-2e7ed018.jpg';
        $realMd5 = md5_file($realPath);

        $response = $this->callWs('pwg.images.checkFiles', ['image_id' => 1, 'high_sum' => $realMd5]);

        self::assertSame('ok', $response['stat']);
        self::assertSame(['file' => 'equals', 'high' => 'equals'], $response['result']);
    }

    // ---------------------------------------------------- formats.searchImage

    public function test_formatsSearchImage_reports_no_candidates_for_invalid_json(): void
    {
        // json_decode() failure (not the "valid JSON, but a non-string
        // entry" branch covered below) -- $candidates falls back to [], so
        // the whole per-candidate loop never runs and the result is empty.
        $response = $this->callWs('pwg.images.formats.searchImage', [
            'filename_list' => 'this is not valid json at all {{{',
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame([], $response['result']);
    }

    public function test_formatsSearchImage_reports_not_found_for_a_recognized_extension_with_no_matching_photo(): void
    {
        // Distinct from test_formatsSearchImage_reports_not_found_for_unrecognized_extension()
        // above: this filename's extension *is* one of
        // CurrentConfig::formatExtensions()'s known format extensions (so
        // the preg_match() that strips it succeeds and
        // $candidate_filename_wo_ext is a real, non-empty string), but no
        // photo in the fixture has that basename at all -- the final
        // fallback branch, past the isset($unique_filenames_db[...]) check.
        $response = $this->callWs('pwg.images.formats.searchImage', [
            'filename_list' => json_encode(['a' => 'totally-nonexistent-basename-' . uniqid() . '.tif']),
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame(['a' => ['status' => 'not found']], $response['result']);
    }

    public function test_formatsSearchImage_reports_not_found_for_non_string_entry(): void
    {
        $response = $this->callWs('pwg.images.formats.searchImage', [
            'filename_list' => json_encode(['a' => 123]),
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame(['a' => ['status' => 'not found']], $response['result']);
    }

    public function test_formatsSearchImage_reports_not_found_for_unrecognized_extension(): void
    {
        $response = $this->callWs('pwg.images.formats.searchImage', [
            'filename_list' => json_encode(['a' => 'fixture-photo-1.bogusext']),
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame(['a' => ['status' => 'not found']], $response['result']);
    }

    public function test_formatsSearchImage_finds_a_matching_photo_without_existing_format(): void
    {
        $formatExtensions = $this->conn->fetchOne(
            "SELECT value FROM " . Tables::config() . " WHERE param = 'format_ext'"
        );
        // format_ext isn't seeded by the fixture -- CurrentConfig's own
        // non-null static default (includes 'tif') is what's actually in
        // effect for this WS request.
        self::assertFalse($formatExtensions);

        $response = $this->callWs('pwg.images.formats.searchImage', [
            'filename_list' => json_encode(['a' => 'fixture-photo-1.tif']),
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame(['a' => ['status' => 'found', 'image_id' => 1, 'format_exist' => false]], $response['result']);
    }

    public function test_formatsSearchImage_reports_format_exist_true_when_a_matching_format_row_exists(): void
    {
        $formatId = $this->insertImageFormat(1, 'tif');

        try {
            $response = $this->callWs('pwg.images.formats.searchImage', [
                'filename_list' => json_encode(['a' => 'fixture-photo-1.tif']),
            ]);

            self::assertSame('ok', $response['stat']);
            self::assertSame(
                ['a' => ['status' => 'found', 'image_id' => 1, 'format_exist' => true]],
                $response['result']
            );
        } finally {
            $this->conn->executeStatement(
                'DELETE FROM ' . Tables::imageFormat() . ' WHERE format_id = ?',
                [$formatId]
            );
        }
    }

    // ------------------------------------------------------- formats.delete

    private function insertImageFormat(int $imageId, string $ext): int
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::imageFormat() . ' (image_id, ext, filesize) VALUES (?, ?, ?)',
            [$imageId, $ext, 100]
        );

        return (int) $this->conn->lastInsertId();
    }

    public function test_formatsDelete_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.images.formats.delete', ['format_id' => 1, 'pwg_token' => 'wrong']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_formatsDelete_nonexistent_format_returns_404(): void
    {
        $response = $this->callWs('pwg.images.formats.delete', [
            'format_id' => 999999,
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
        self::assertSame('No format found for the id(s) given', $response['message']);
    }

    public function test_formatsDelete_removes_the_format_row(): void
    {
        $formatId = $this->insertImageFormat(1, 'zip');

        $response = $this->callWs('pwg.images.formats.delete', [
            'format_id' => $formatId,
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame(true, $response['result']);

        $remaining = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ' . Tables::imageFormat() . ' WHERE format_id = ?',
            [$formatId]
        );
        self::assertIsNumeric($remaining);
        self::assertSame(0, (int) $remaining);
    }

    public function test_formatsDelete_skips_physical_deletion_for_a_remote_path(): void
    {
        $filename = 'remote-format-' . uniqid() . '.jpg';
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::images() . ' (file, path, md5sum) VALUES (?, ?, ?)',
            [$filename, 'https://example.test/remote/' . $filename, md5($filename)]
        );
        $imageId = (int) $this->conn->lastInsertId();
        $this->insertedImageIds[] = $imageId;

        $formatId = $this->insertImageFormat($imageId, 'zip');

        $response = $this->callWs('pwg.images.formats.delete', [
            'format_id' => $formatId,
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('ok', $response['stat']);
        // UrlService::urlIsRemote() 'continue's past the physical-unlink
        // attempt for this row entirely -- $ok stays true, and the format
        // row is still deleted from the DB below regardless.
        self::assertSame(true, $response['result']);

        $remaining = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ' . Tables::imageFormat() . ' WHERE format_id = ?',
            [$formatId]
        );
        self::assertSame(0, is_numeric($remaining) ? (int) $remaining : -1);
    }

    public function test_formatsDelete_reports_failure_when_a_format_file_cannot_be_unlinked(): void
    {
        // A dedicated, throwaway directory tree this test process itself
        // creates and owns (unlike the shared upload/ tree, which is
        // www-data-owned in this environment and can't be chmod()'d from a
        // CLI test process -- see WsImagesUploadGapsTest's own doc note),
        // so locking it down to force a real unlink() failure is safe and
        // fully isolated.
        $slug = 'unlink-fail-' . uniqid();
        $root = dirname(__DIR__, 2);
        $baseDir = $root . '/upload/' . $slug;
        $formatDir = $baseDir . '/pwg_format';
        mkdir($formatDir, 0o777, true);

        $filename = 'photo.jpg';
        $imagePath = 'upload/' . $slug . '/' . $filename;
        $formatFile = $formatDir . '/photo.zip';
        file_put_contents($formatFile, 'stand-in format file content');

        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::images() . ' (file, path, md5sum) VALUES (?, ?, ?)',
            [$filename, $imagePath, md5($slug)]
        );
        $imageId = (int) $this->conn->lastInsertId();
        $this->insertedImageIds[] = $imageId;

        $formatId = $this->insertImageFormat($imageId, 'zip');

        // unlink() needs write+execute on the *containing* directory, not
        // on the file itself -- locking pwg_format/ down to r-x for
        // everyone (owner included) makes the real delete attempt below
        // fail deterministically for whichever user runs the WS request.
        chmod($formatDir, 0o555);

        try {
            // formatsDelete()'s own unlink() failure path deliberately
            // trigger_error()s an E_USER_WARNING as its own failure-signaling
            // mechanism (see PwgImages.php) -- that's this test's whole
            // point, not a bug.
            $response = $this->callWs('pwg.images.formats.delete', [
                'format_id' => $formatId,
                'pwg_token' => $this->pwgToken(),
            ], allowPhpWarnings: true);

            self::assertSame('ok', $response['stat']);
            self::assertSame(false, $response['result']);

            // The format row is still deleted from the DB even though the
            // physical file deletion failed -- formatsDelete()'s own
            // unconditional deleteFormatsByIds() call, same as every other
            // test in this section.
            $remaining = $this->conn->fetchOne(
                'SELECT COUNT(*) FROM ' . Tables::imageFormat() . ' WHERE format_id = ?',
                [$formatId]
            );
            self::assertSame(0, is_numeric($remaining) ? (int) $remaining : -1);
        } finally {
            chmod($formatDir, 0o755);
            @unlink($formatFile);
            @rmdir($formatDir);
            @rmdir($baseDir);
        }
    }

    // formatsDelete()'s own preg_split() failure guard (format_id, same
    // `/[\s,;\|]/` pattern) is unreachable from a black-box Contract test
    // for the same reason documented at the top of WsImagesTest.php's
    // exist() section -- a plain non-backtracking character class can't be
    // made to exceed the default PCRE backtrack/recursion budget, and this
    // test process can't reach the separate Apache process's php.ini to
    // lower that budget either.
}

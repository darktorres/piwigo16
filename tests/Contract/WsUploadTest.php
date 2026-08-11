<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use CURLFile;
use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Cache\CachePools;
use Piwigo\Db\DbConnection;

final class WsUploadTest extends ContractTestCase
{
    /**
     * 1×1 white PNG, base64-decoded at runtime to avoid binary in source.
     */
    private const string TINY_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';

    private ?int $uploadedImageId = null;

    private Connection $conn;

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
        if ($this->uploadedImageId !== null) {
            $token = $this->getPwgToken();
            $this->callWs('pwg.images.delete', [
                'image_id' => $this->uploadedImageId,
                'pwg_token' => $token,
            ]);
            $this->uploadedImageId = null;
        }

        parent::tearDown();
    }

    private function pngBytes(): string
    {
        $bytes = base64_decode(self::TINY_PNG_B64, true);
        self::assertNotFalse($bytes);

        return $bytes;
    }

    /**
     * Multipart POST for pwg.images.upload -- $_FILES['file'] is mandatory,
     * so http_build_query()-based callWs() can't express this request.
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function uploadMultipart(array $fields): array
    {
        $tmpName = tempnam(sys_get_temp_dir(), 'pwg_ct_upload_');
        self::assertNotFalse($tmpName);
        $tmpFile = $tmpName . '.png';
        file_put_contents($tmpFile, $this->pngBytes());

        try {
            $url = $this->baseUrl . '/ws.php?format=json';
            $ch = curl_init($url);
            self::assertNotFalse($ch);

            $cookieJar = $this->cookieJar();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
            curl_setopt($ch, CURLOPT_POSTFIELDS, array_merge(
                [
                    'method' => 'pwg.images.upload',
                    'file' => new CURLFile($tmpFile, 'image/png', 'upload.png'),
                ],
                $fields
            ));
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

            $body = curl_exec($ch);
            unset($ch);
        } finally {
            @unlink($tmpFile);
        }

        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testAddSimpleUploadsImageAndReturnsImageId(): void
    {
        // Write the tiny PNG to a temp file
        $tmpName = tempnam(sys_get_temp_dir(), 'pwg_ct_png_');
        self::assertNotFalse($tmpName);
        $tmpFile = $tmpName . '.png';
        $pngBytes = base64_decode(self::TINY_PNG_B64, true);
        self::assertNotFalse($pngBytes);
        file_put_contents($tmpFile, $pngBytes);

        try {
            $url = $this->baseUrl . '/ws.php?format=json';
            $ch = curl_init($url);
            self::assertNotFalse($ch, 'curl_init failed');

            $userAgent = self::USER_AGENT;
            $cookieJar = $this->cookieJar();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'method' => 'pwg.images.addSimple',
                'category' => 1,
                'name' => 'Contract Test Upload ' . uniqid(),
                'image' => new CURLFile($tmpFile, 'image/png', 'ct_upload.png'),
            ]);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);
        } finally {
            @unlink($tmpFile);
        }

        self::assertIsString($body);
        self::assertLessThan(500, $status, sprintf('addSimple returned HTTP %d: %s', $status, $body));

        $response = json_decode($body, true);
        self::assertIsArray($response, 'addSimple response is not valid JSON: ' . $body);
        /** @var array<string, mixed> $response */
        self::assertSame('ok', $response['stat'], 'addSimple failed: ' . $body);

        self::assertMatchesSchema('pwg.images.addSimple', $response);

        $result = $response['result'] ?? null;
        self::assertIsArray($result, 'addSimple result is not an array: ' . $body);
        $imageId = $result['image_id'] ?? null;
        self::assertIsNumeric($imageId, 'addSimple result.image_id is not numeric: ' . $body);
        self::assertGreaterThan(0, (int) $imageId);

        $this->uploadedImageId = (int) $imageId;
    }

    /**
     * Multipart POST for pwg.images.addSimple -- $_FILES['image'] is
     * mandatory, so http_build_query()-based callWs() can't express this
     * request.
     * @param array<string, mixed> $fields
     * @param ?string $fileContents overrides the default tiny-PNG fixture --
     *   used to build an intentionally-oversized file for the
     *   UPLOAD_ERR_INI_SIZE test below
     * @return array<string, mixed>
     */
    private function addSimpleMultipart(array $fields, bool $withFile = true, ?string $fileContents = null): array
    {
        $tmpFile = null;
        $postFields = $fields;
        if ($withFile) {
            $tmpName = tempnam(sys_get_temp_dir(), 'pwg_ct_addsimple_');
            self::assertNotFalse($tmpName);
            $tmpFile = $tmpName . '.png';
            file_put_contents($tmpFile, $fileContents ?? $this->pngBytes());
            $postFields['image'] = new CURLFile($tmpFile, 'image/png', 'addsimple.png');
        }
        $postFields['method'] = 'pwg.images.addSimple';

        try {
            $url = $this->baseUrl . '/ws.php?format=json';
            $ch = curl_init($url);
            self::assertNotFalse($ch);

            $cookieJar = $this->cookieJar();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

            $body = curl_exec($ch);
            unset($ch);
        } finally {
            if ($tmpFile !== null) {
                @unlink($tmpFile);
            }
        }

        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testAddSimpleMissingFileReturnsError(): void
    {
        $response = $this->addSimpleMultipart([
            'category' => 1,
        ], withFile: false);

        self::assertSame('fail', $response['stat']);
        self::assertSame(405, $response['err']);
    }

    public function testAddSimpleWithUnknownImageIdReturns404(): void
    {
        $response = $this->addSimpleMultipart([
            'category' => 1,
            'image_id' => 999999,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
    }

    /**
     * Raw multipart POST body, hand-built (not via CURLOPT_POSTFIELDS/
     * CURLFile) so field order and content are fully controlled -- needed
     * for the MAX_FILE_SIZE and empty-file-selection UPLOAD_ERR_* triggers
     * below, neither of which curl's own -F/CURLFile machinery can express.
     * @param list<string> $rawParts pre-built "Content-Disposition: ..." (+
     *   optional Content-Type + blob) part bodies, without their own
     *   boundary delimiters
     * @return array<string, mixed>
     */
    private function rawMultipart(array $rawParts): array
    {
        $boundary = 'PiwigoContractTestBoundary' . uniqid();
        $body = '';
        foreach ($rawParts as $part) {
            $body .= '--' . $boundary . "\r\n" . $part . "\r\n";
        }
        $body .= '--' . $boundary . "--\r\n";

        $url = $this->baseUrl . '/ws.php?format=json';
        $ch = curl_init($url);
        self::assertNotFalse($ch);

        $cookieJar = $this->cookieJar();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($this->testHeader(), [
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ]));

        $body = curl_exec($ch);
        unset($ch);

        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function formDataPart(string $name, string $value): string
    {
        return 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n" . $value;
    }

    /**
     * addSimple()'s own UPLOAD_ERR_* `match` -- PHP itself sets $_FILES['image']['error']
     * from real upload-processing outcomes, so each arm needs a genuinely
     * different trigger, not an attacker-suppliable value:
     *   - UPLOAD_ERR_PARTIAL (a real interrupted transfer) and
     *     UPLOAD_ERR_NO_TMP_DIR/UPLOAD_ERR_CANT_WRITE/UPLOAD_ERR_EXTENSION
     *     (all real server misconfiguration/environment conditions) aren't
     *     reachable from a black-box HTTP test without an actually broken
     *     server -- not chased here.
     *   - the `default` arm requires an $_FILES['error'] PHP itself never
     *     sets (0-4, 6-8 are the only real values) -- also not chased.
     */
    public function testAddSimpleUploadExceedingIniSizeLimitReturnsError(): void
    {
        // php.ini's upload_max_filesize is 2M in this environment (confirmed
        // live) -- comfortably smaller than post_max_size (8M), so the POST
        // itself isn't rejected outright, only this one file field.
        $response = $this->addSimpleMultipart([
            'category' => 1,
        ], fileContents: random_bytes(2_200_000));

        self::assertSame('fail', $response['stat']);
        self::assertSame(500, $response['err']);
        self::assertSame('The uploaded file exceeds the upload_max_filesize directive in php.ini.', $response['message']);
    }

    public function testAddSimpleUploadExceedingDeclaredMaxFileSizeReturnsError(): void
    {
        // A "MAX_FILE_SIZE" field *before* the file field in the multipart
        // body is a real, PHP-enforced convention (rfc1867) -- PHP itself
        // sets UPLOAD_ERR_FORM_SIZE when the file that follows exceeds it,
        // independent of upload_max_filesize.
        $response = $this->rawMultipart([
            self::formDataPart('method', 'pwg.images.addSimple'),
            self::formDataPart('category', '1'),
            self::formDataPart('MAX_FILE_SIZE', '10'),
            'Content-Disposition: form-data; name="image"; filename="toolarge.png"' . "\r\n"
                . "Content-Type: image/png\r\n\r\n" . $this->pngBytes(),
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(500, $response['err']);
        self::assertSame(
            'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
            $response['message']
        );
    }

    public function testAddSimpleEmptyFileSelectionReturnsNoFileWasUploadedError(): void
    {
        // A file part with an empty filename -- the exact shape a browser
        // sends for a <input type=file> submitted with nothing chosen --
        // gives $_FILES['image'] with error=UPLOAD_ERR_NO_FILE, not an
        // absent key: ->present is still true (present checks *key*
        // existence only), so this reaches the match arm rather than the
        // earlier "(file) is missing" guard.
        $response = $this->rawMultipart([
            self::formDataPart('method', 'pwg.images.addSimple'),
            self::formDataPart('category', '1'),
            'Content-Disposition: form-data; name="image"; filename=""' . "\r\n"
                . "Content-Type: application/octet-stream\r\n\r\n",
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(500, $response['err']);
        self::assertSame('No file was uploaded.', $response['message']);
    }

    public function testAddSimpleArrayStyleFileFieldHasNoTempName(): void
    {
        // image[]=@... makes PHP populate $_FILES['image']['error']/
        // ['tmp_name'] as *arrays* (multi-file bracket syntax), not scalars
        // -- UploadedFileRequest::fromArray()'s is_int()/is_string() guards
        // then store both as null, which bypasses the UPLOAD_ERR_* match
        // entirely (error stays null) and instead hits the dedicated
        // "missing uploaded file temp name" guard just past it.
        $tmpName = tempnam(sys_get_temp_dir(), 'pwg_ct_arrayfile_');
        self::assertNotFalse($tmpName);
        $tmpFile = $tmpName . '.png';
        file_put_contents($tmpFile, $this->pngBytes());

        try {
            $url = $this->baseUrl . '/ws.php?format=json';
            $ch = curl_init($url);
            self::assertNotFalse($ch);
            $cookieJar = $this->cookieJar();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'method' => 'pwg.images.addSimple',
                'category' => 1,
                'image[]' => new CURLFile($tmpFile, 'image/png', 'arraystyle.png'),
            ]);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
            $body = curl_exec($ch);
            unset($ch);
        } finally {
            @unlink($tmpFile);
        }

        self::assertIsString($body);
        $response = json_decode($body, true);
        self::assertIsArray($response);
        self::assertSame('fail', $response['stat']);
        self::assertSame(500, $response['err']);
        self::assertSame('[ws_images_addSimple] missing uploaded file temp name', $response['message']);
    }

    /**
     * addSimple()'s own `preg_split() === false` guard (a hand-typed
     * `throw new Exception(...)`, for the comma-separated-string `tags`
     * branch) is NOT chased here: the pattern is a fixed, cheap negative
     * lookbehind assertion with no realistic catastrophic-backtracking
     * shape, so triggering a genuine PCRE engine failure (the only way
     * preg_split() returns false) would need pcre.backtrack_limit/
     * pcre.recursion_limit tuned down on the *server* process -- not
     * reachable from a black-box HTTP request.
     */
    public function testAddSimpleTagsAsAnArrayAreResolvedAndAssociated(): void
    {
        // Real bug, found live: curl_setopt(CURLOPT_POSTFIELDS, $array)
        // does NOT bracket-encode a nested-array field value for a
        // multipart request the way http_build_query() would -- it just
        // sends two literal, identically-named 'tags' fields, which PHP's
        // own $_POST parser collapses to whichever arrives last as a
        // plain string (confirmed live via a server-side dump: $params
        // ['tags'] came back as the bare string 'addsimple-array-tag-two',
        // silently dropping the first tag entirely). Explicit bracket-
        // indexed keys are this suite's own established convention for a
        // real multipart array field (see 'image[]'/'file[]' elsewhere in
        // this file and WsImagesUploadStartTest.php).
        $response = $this->addSimpleMultipart([
            'category' => 1,
            'name' => 'Contract Test addSimple array tags ' . uniqid(),
            'tags[0]' => 'addsimple-array-tag-one',
            'tags[1]' => 'addsimple-array-tag-two',
        ]);

        self::assertSame('ok', $response['stat'], (string) json_encode($response));
        $result = $response['result'];
        self::assertIsArray($result);
        $imageId = $result['image_id'];
        self::assertIsNumeric($imageId);
        $this->uploadedImageId = (int) $imageId;

        $tagNames = $this->conn->fetchFirstColumn(
            'SELECT t.name FROM tags' . ' t
             INNER JOIN image_tag' . ' it ON it.tag_id = t.id
             WHERE it.image_id = ?',
            [(int) $imageId]
        );
        self::assertContains('addsimple-array-tag-one', $tagNames);
        self::assertContains('addsimple-array-tag-two', $tagNames);
    }

    public function testAddSimpleSetsInfoColumnsAndTagsAndReturnsTheCategoryUrl(): void
    {
        $uniqueName = 'Contract Test addSimple ' . uniqid();
        $response = $this->addSimpleMultipart([
            'category' => 1,
            'name' => $uniqueName,
            'author' => 'addSimple Author',
            'comment' => 'addSimple Comment',
            'tags' => 'addsimple-tag-one,addsimple-tag-two',
        ]);

        self::assertSame('ok', $response['stat'], (string) json_encode($response));
        $result = $response['result'];
        self::assertIsArray($result);
        $imageId = $result['image_id'];
        self::assertIsNumeric($imageId);
        $this->uploadedImageId = (int) $imageId;

        self::assertIsString($result['url']);
        self::assertStringContainsString('category', $result['url']);

        $row = $this->conn->fetchAssociative(
            'SELECT name, author, comment FROM images WHERE id = ?',
            [(int) $imageId]
        );
        self::assertIsArray($row);
        self::assertSame($uniqueName, $row['name']);
        self::assertSame('addSimple Author', $row['author']);
        self::assertSame('addSimple Comment', $row['comment']);

        $tagNames = $this->conn->fetchFirstColumn(
            'SELECT t.name FROM tags' . ' t
             INNER JOIN image_tag' . ' it ON it.tag_id = t.id
             WHERE it.image_id = ?',
            [(int) $imageId]
        );
        self::assertContains('addsimple-tag-one', $tagNames);
        self::assertContains('addsimple-tag-two', $tagNames);
    }

    public function testAddSimpleWithoutACategoryOmitsTheCategoryFromTheUrl(): void
    {
        $response = $this->addSimpleMultipart([
            'name' => 'Contract Test addSimple No Category ' . uniqid(),
        ]);

        self::assertSame('ok', $response['stat'], (string) json_encode($response));
        $result = $response['result'];
        self::assertIsArray($result);
        $imageId = $result['image_id'];
        self::assertIsNumeric($imageId);
        $this->uploadedImageId = (int) $imageId;

        self::assertIsString($result['url']);
        self::assertStringNotContainsString('category', $result['url']);
    }

    /**
     * A multipart request with no $_FILES['file'] entry returns a
     * `WsErrorResponse(103, ...)`, the same mechanism every other error in this
     * method uses, honoring the request's real format= for both json and
     * rest (RestEncoder's XML output; 'xml' itself isn't a recognized
     * format= value, see WsInitializer's switch).
     */
    public function testUploadMissingFileFieldReturnsAProperlyEncodedError(): void
    {
        $token = $this->getPwgToken();

        $tmpName = tempnam(sys_get_temp_dir(), 'pwg_ct_wrongfield_');
        self::assertNotFalse($tmpName);
        file_put_contents($tmpName, 'not a real upload');

        try {
            foreach (['json', 'rest'] as $format) {
                $url = $this->baseUrl . '/ws.php?format=' . $format;
                $ch = curl_init($url);
                self::assertNotFalse($ch, 'curl_init failed');

                $cookieJar = $this->cookieJar();
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
                curl_setopt($ch, CURLOPT_POSTFIELDS, [
                    'method' => 'pwg.images.upload',
                    'pwg_token' => $token,
                    'name' => 'wrong-field.png',
                    // Deliberately NOT named 'file' -- $_FILES is non-empty
                    // but $_FILES['file'] doesn't exist, the exact
                    // condition the former die() guarded.
                    'wrongfield' => new CURLFile($tmpName, 'image/png', 'wrong-field.png'),
                ]);
                curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
                curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

                $body = curl_exec($ch);
                $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                unset($ch);

                self::assertIsString($body, "upload ({$format}) curl_exec failed");
                self::assertLessThan(500, $status, sprintf('upload (%s) returned HTTP %d: %s', $format, $status, $body));

                if ($format === 'json') {
                    $response = json_decode($body, true);
                    self::assertIsArray($response, 'upload (json) response is not valid JSON: ' . $body);
                    self::assertSame('fail', $response['stat'] ?? null, 'upload (json) did not report an error: ' . $body);
                    self::assertSame(103, $response['err'] ?? null);
                } else {
                    self::assertStringContainsString('<?xml', $body, 'upload (rest) response is not XML: ' . $body);
                    self::assertStringNotContainsString('jsonrpc', $body, 'upload (rest) leaked the old hardcoded JSON body');
                    self::assertStringContainsString('code="103"', $body, 'upload (rest) error body missing the expected code: ' . $body);
                }
            }
        } finally {
            @unlink($tmpName);
        }
    }

    public function testUploadInvalidTokenReturnsError(): void
    {
        $response = $this->uploadMultipart([
            'pwg_token' => 'wrong',
            'category' => 1,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function testUploadCreatesANewPhotoInTheCategory(): void
    {
        $response = $this->uploadMultipart([
            'pwg_token' => $this->getPwgToken(),
            'category' => 1,
            'name' => 'Contract Test upload() ' . uniqid(),
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame('add', $result['add_status']);
        $imageId = $result['image_id'];
        self::assertIsNumeric($imageId);
        $this->uploadedImageId = (int) $imageId;

        $category = $result['category'];
        self::assertIsArray($category);
        self::assertSame(1, $category['id']);
        self::assertIsInt($category['nb_photos']);
        self::assertGreaterThanOrEqual(1, $category['nb_photos']);
    }

    /**
     * upload()'s update_mode=true branch (Ws\PwgImages.php ~1654-1660):
     * looks up an existing photo by its stored `file` value within the
     * target category and, if found, replaces that photo's file in place
     * (add_status='update', same image_id) instead of inserting a new row.
     *
     * One line just past this branch (upload()'s own "image fetch failed
     * right after inserting it" throw, ~1676) stays genuinely unreachable
     * from a black-box HTTP test either way: getUploadResultInfoById() re-
     * reads the very row addUploadedFile() just inserted/updated by primary
     * key, in the same request/same connection -- there is no legitimate,
     * non-racy way to make that SELECT miss without either a second
     * concurrent request racing the delete of that exact row (unprovable
     * without real OS-level concurrency) or patching production code to
     * fake it, which would just encode a synthetic condition as expected
     * behavior.
     */
    public function testUploadUpdateModeReplacesAnExistingPhotoByFilenameInCategory(): void
    {
        // Real bug, found live: update_mode's own match
        // (Ws\PwgImages::getIdsByFilenameInCategory()'s `WHERE i.file =
        // :filename AND ic.category_id = :categoryId`, an INNER JOIN onto
        // image_category) can only ever find a photo that's genuinely
        // associated with the target category through image_category --
        // but this fixture's own 'lounge_active' config defaults to true,
        // and UploadService::addUploadedFileAddToCategories() routes every
        // upload through fillLounge() instead of
        // associateImagesToCategories() whenever it's on, which never
        // touches image_category at all (confirmed live: a real upload
        // with lounge_active=true left zero image_category rows for the
        // new photo). Disabling it for this test's duration, same raw-
        // write + CachePools::config()->clear() pattern
        // BrowserTestHelpers::setConfigValue() already established for
        // Browser tests reaching across this same CLI-to-Apache boundary.
        //
        // Second real bug found live, same method: whenever lounge_active
        // is false, addUploadedFileAddToCategories() re-checks the gallery's
        // total photo count against 'lounge_activate_threshold' (default 1,
        // CurrentConfig::$loungeActivateThreshold) on *every* upload, and
        // auto-flips lounge_active back to true in the DB the moment the
        // count meets it -- a real, legitimate auto-activation feature.
        // This fixture's DB already holds far more than 1 photo, so the
        // very first uploadMultipart() call below silently re-enables
        // lounge_active before the second (update_mode) call ever runs,
        // undoing the override above. Pinning the threshold absurdly high
        // for the test's duration keeps that legitimate feature from firing
        // without disabling or working around it.
        $originalLoungeActive = $this->conn->fetchOne("SELECT value FROM config WHERE param = 'lounge_active'");
        $originalLoungeThreshold = $this->conn->fetchOne("SELECT value FROM config WHERE param = 'lounge_activate_threshold'");
        $this->upsertConfig('lounge_active', 'false');
        $this->upsertConfig('lounge_activate_threshold', '999999999');
        CachePools::config()->clear();

        // update_mode's 'name' param is matched against the stored `file`
        // column (confirmed live: UploadService::addUploadedFile() does
        // store the client-supplied $original_filename there, not a
        // server-generated name) -- both calls must use the exact same
        // 'name' value for the match to succeed, by design: this is how a
        // real client signals "replace the photo I uploaded under this
        // exact name".
        $name = 'Contract Test update_mode ' . uniqid();

        try {
            $created = $this->uploadMultipart([
                'pwg_token' => $this->getPwgToken(),
                'category' => 1,
                'name' => $name,
            ]);
            self::assertSame('ok', $created['stat'], (string) json_encode($created));
            $createdResult = $created['result'];
            self::assertIsArray($createdResult);
            $originalImageId = $createdResult['image_id'];
            self::assertIsNumeric($originalImageId);
            $this->uploadedImageId = (int) $originalImageId;

            $response = $this->uploadMultipart([
                'pwg_token' => $this->getPwgToken(),
                'category' => 1,
                'name' => $name,
                'update_mode' => true,
            ]);
        } finally {
            if (is_string($originalLoungeActive)) {
                $this->conn->executeStatement(
                    "UPDATE config SET value = ? WHERE param = 'lounge_active'",
                    [$originalLoungeActive]
                );
            } else {
                $this->conn->executeStatement("DELETE FROM config WHERE param = 'lounge_active'");
            }
            if (is_string($originalLoungeThreshold)) {
                $this->conn->executeStatement(
                    "UPDATE config SET value = ? WHERE param = 'lounge_activate_threshold'",
                    [$originalLoungeThreshold]
                );
            } else {
                $this->conn->executeStatement("DELETE FROM config WHERE param = 'lounge_activate_threshold'");
            }
            CachePools::config()->clear();
        }

        self::assertSame('ok', $response['stat'], (string) json_encode($response));
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame('update', $result['add_status']);
        $updatedImageId = $result['image_id'];
        self::assertIsNumeric($updatedImageId);
        self::assertSame((int) $originalImageId, (int) $updatedImageId);
    }

    public function testUploadFormatOfDisabledReturnsError(): void
    {
        // CurrentConfig::isFormatsEnabled()'s default (false, no 'enable_formats'
        // row in the fixture) is what's in effect for this WS request.
        $response = $this->uploadMultipart([
            'pwg_token' => $this->getPwgToken(),
            'format_of' => 1,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('formats are disabled', $response['message']);
    }

    public function testUploadFormatOfWithAnUnauthorizedExtensionReturnsError(): void
    {
        $this->upsertConfig('enable_formats', 'true');
        CachePools::config()->clear();

        try {
            // CurrentConfig::formatExtensions()'s default doesn't include
            // 'png' -- the extension pulled from the (fake) upload filename
            // below via $params['name'].
            $response = $this->uploadMultipart([
                'pwg_token' => $this->getPwgToken(),
                'format_of' => 1,
                'name' => 'photo.png',
            ]);

            self::assertSame('fail', $response['stat']);
            self::assertSame(401, $response['err']);
        } finally {
            $this->conn->executeStatement("DELETE FROM config WHERE param = 'enable_formats'");
            CachePools::config()->clear();
        }
    }

    public function testUploadFormatOfAddsAFormatToAnExistingPhoto(): void
    {
        $this->upsertConfig('enable_formats', 'true');
        CachePools::config()->clear();

        $formatId = null;
        try {
            $response = $this->uploadMultipart([
                'pwg_token' => $this->getPwgToken(),
                'format_of' => 1,
                'name' => 'photo.tif',
            ]);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            self::assertSame(1, $result['image_id']);
            self::assertSame('add', $result['add_status']);

            $formatId = $this->conn->fetchOne(
                'SELECT format_id FROM image_format WHERE image_id = 1 AND ext = ?',
                ['tif']
            );
            self::assertIsNumeric($formatId);
        } finally {
            if (is_numeric($formatId)) {
                // formats.delete (running as the same Apache/www-data process
                // that wrote the format file) unlinks the real pwg_format/
                // file on disk too -- a raw SQL DELETE would leave it behind,
                // owned by www-data and unremovable by this CLI test process.
                $this->callWs('pwg.images.formats.delete', [
                    'format_id' => $formatId,
                    'pwg_token' => $this->getPwgToken(),
                ]);
            }
            $this->conn->executeStatement("DELETE FROM config WHERE param = 'enable_formats'");
            CachePools::config()->clear();
        }
    }
}

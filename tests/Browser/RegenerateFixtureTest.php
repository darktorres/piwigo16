<?php

declare(strict_types=1);

namespace Piwigo\Tests\Browser;

use PHPUnit\Framework\Attributes\Group;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Regenerates tests/Fixtures/piwigo-16.x.sql by driving a fresh install +
 * content seed against the test database, then dumping it.
 *
 * Usage:
 *   vendor/bin/pest --group=fixture-regen
 *
 * Excluded from the default Browser suite run (composer test:browser passes
 * --exclude-group=fixture-regen) — this wipes the test DB and overwrites a
 * committed fixture file. It is not a regression test.
 *
 * Credentials come from .env.test (PIWIGO_DB_HOST/PORT/USER/PASSWORD/BASE),
 * loaded via IntegrationTestCase::setUpConnectionFromEnv().
 */
#[Group('fixture-regen')]
final class RegenerateFixtureTest extends IntegrationTestCase
{
    private const string ADMIN_USER = 'fixture_admin';

    private const string ADMIN_PASS = 'fixture_admin';

    /** Real HTTP clients always send one; some legacy code paths assume it. */
    private const string USER_AGENT = 'PiwigoFixtureRegen/1.0';

    private string $cookieJar = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->requireBaseUrl();

        $tmp = tempnam(sys_get_temp_dir(), 'pwg_fixture_');
        self::assertIsString($tmp);
        $this->cookieJar = $tmp;
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->cookieJar !== '' && file_exists($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    public function test_regenerate_fixture(): void
    {
        if ($this->dbName === '' || $this->dbName === 'piwigo') {
            self::fail(sprintf(
                "PIWIGO_DB_BASE is '%s'. Set it in .env.test to a throw-away test database.",
                $this->dbName
            ));
        }

        // 1. Wipe the test DB so the installer sees a blank slate, and clear
        // the install sentinel so common.inc.php/install.php show the form.
        $this->resetDatabase();
        $this->removeTestStamp();

        // 2. Drive install.php with fixture admin credentials.
        $installBody = $this->postForm('install.php', [
            'install'       => '1',
            'dbhost'        => $this->dbHost,
            'dbuser'        => $this->dbUser,
            'dbpasswd'      => $this->dbPass,
            'dbname'        => $this->dbName,
            'prefix'        => $this->dbPrefix,
            'admin_name'    => self::ADMIN_USER,
            'admin_pass1'   => self::ADMIN_PASS,
            'admin_pass2'   => self::ADMIN_PASS,
            'admin_mail'    => 'fixture_admin@example.test',
        ]);
        self::assertStringContainsString('Congratulations', $installBody, 'install.php must report success');

        // 3. Log in as fixture_admin via WS (same code path Piwigo uses
        // internally; avoids flaky form-login on a freshly installed gallery).
        $login = $this->callWs('pwg.session.login', [
            'username' => self::ADMIN_USER,
            'password' => self::ADMIN_PASS,
        ]);
        self::assertSame('ok', $login['stat'], 'fixture_admin login must succeed');
        $pwgToken = (string) $this->callWs('pwg.session.getStatus', [])['result']['pwg_token'];

        // 4. Two albums: one root, one nested sub-album.
        $rootAlbumId = (int) $this->callWs('pwg.categories.add', ['name' => 'Sample Album'])['result']['id'];
        $subAlbum    = $this->callWs('pwg.categories.add', [
            'name'   => 'Nested Sub Album',
            'parent' => (string) $rootAlbumId,
        ]);
        self::assertSame('ok', $subAlbum['stat']);
        $subAlbumId = (int) $subAlbum['result']['id'];

        // 5. Five photos, generated via GD (solid color + label), uploaded
        // through the real pwg.images.addSimple pipeline.
        $tmpDir = sys_get_temp_dir() . '/piwigo-fixture-' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        $photoIds = [];
        try {
            $colors = [[220, 50, 50], [50, 180, 80], [50, 100, 220], [230, 200, 50], [150, 60, 200]];
            for ($i = 1; $i <= 5; $i++) {
                [$r, $g, $b] = $colors[$i - 1];
                $img = imagecreatetruecolor(200, 150);
                self::assertNotFalse($img);

                $bgColor = imagecolorallocate($img, $r, $g, $b);
                self::assertNotFalse($bgColor);
                imagefill($img, 0, 0, $bgColor);

                $textColor = imagecolorallocate($img, 255, 255, 255);
                self::assertNotFalse($textColor);
                imagestring($img, 5, 60, 70, 'Photo ' . $i, $textColor);

                $filePath = $tmpDir . '/fixture-photo-' . $i . '.jpg';
                imagejpeg($img, $filePath, 80);

                $albumId  = $i <= 3 ? $rootAlbumId : $subAlbumId;
                $photoIds[] = $this->uploadPhoto($filePath, $albumId, 'Photo ' . $i);
            }
        } finally {
            $tmpFiles = glob($tmpDir . '/*');
            foreach ($tmpFiles !== false ? $tmpFiles : [] as $f) {
                unlink($f);
            }
            rmdir($tmpDir);
        }

        // 6. Three tags, attached to images so pwg.tags.getList sees them
        // (the WS only returns tags actually used by >=1 image).
        $tagIds = [];
        foreach (['nature', 'travel', 'family'] as $name) {
            $res = $this->callWs('pwg.tags.add', ['name' => $name, 'pwg_token' => $pwgToken]);
            self::assertSame('ok', $res['stat'], "tag $name should be created");
            $tagIds[] = (int) $res['result']['id'];
        }
        $db = $this->newMysqli($this->dbName);
        $db->query(sprintf(
            'INSERT INTO %simage_tag (image_id, tag_id) VALUES (%d,%d),(%d,%d),(%d,%d),(%d,%d),(%d,%d)',
            $this->dbPrefix,
            $photoIds[0], $tagIds[0],
            $photoIds[0], $tagIds[1],
            $photoIds[0], $tagIds[2],
            $photoIds[1], $tagIds[0],
            $photoIds[2], $tagIds[0]
        ));

        // 7. Two additional users with different permission levels.
        $userIds = [];
        foreach (['regular_user', 'power_user'] as $username) {
            $res = $this->callWs('pwg.users.add', [
                'username'  => $username,
                'password'  => $username . '_pass',
                'pwg_token' => $pwgToken,
            ]);
            self::assertSame('ok', $res['stat'], "user $username should be created");
            $userIds[$username] = (int) $res['result']['users'][0]['id'];
        }

        // 8. Five comments across different users/photos (1 unvalidated).
        // Inserted directly — pwg.images.addComment requires commentable=true
        // on the parent album, which fresh albums don't have.
        $db->query(sprintf(
            "INSERT INTO %scomments (image_id, date, author, anonymous_id, author_id, content, validated, validation_date) VALUES "
            . "(%d, NOW(), 'fixture_admin', '127.0.0.1', 1, 'Fixture comment for integration tests.', 1, NOW()), "
            . "(%d, NOW(), 'regular_user', '127.0.0.2', %d, 'Another perspective on this photo.', 1, NOW()), "
            . "(%d, NOW(), 'power_user', '127.0.0.3', %d, 'Great composition and colors!', 1, NOW()), "
            . "(%d, NOW(), 'power_user', '127.0.0.3', %d, 'I keep coming back to this one.', 1, NOW()), "
            . "(%d, NOW(), 'fixture_admin', '127.0.0.1', 1, 'Pending comment for moderation.', 'false', NULL)",
            $this->dbPrefix,
            $photoIds[0],
            $photoIds[1], $userIds['regular_user'],
            $photoIds[2], $userIds['power_user'],
            $photoIds[0], $userIds['power_user'],
            $photoIds[3]
        ));

        // 9. Three groups with user memberships.
        $groupIds = [];
        foreach (['Editors', 'Reviewers', 'Guests'] as $name) {
            $res = $this->callWs('pwg.groups.add', ['name' => $name, 'pwg_token' => $pwgToken]);
            self::assertSame('ok', $res['stat'], "group $name should be created");
            $groupIds[] = (int) $res['result']['groups'][0]['id'];
        }
        $db->query(sprintf(
            'INSERT INTO %suser_group (user_id, group_id) VALUES (1,%d),(%d,%d),(%d,%d),(%d,%d)',
            $this->dbPrefix,
            $groupIds[0],
            $userIds['regular_user'], $groupIds[0],
            $userIds['regular_user'], $groupIds[1],
            $userIds['power_user'], $groupIds[2]
        ));
        $db->query(sprintf(
            'INSERT INTO %sgroup_access (group_id, cat_id) VALUES (%d,%d),(%d,%d),(%d,%d),(%d,%d)',
            $this->dbPrefix,
            $groupIds[0], $rootAlbumId,
            $groupIds[0], $subAlbumId,
            $groupIds[1], $rootAlbumId,
            $groupIds[2], $rootAlbumId
        ));

        // 10. Five ratings across users/photos.
        $db->query(sprintf(
            "INSERT INTO %srate (user_id, element_id, anonymous_id, rate, date) VALUES "
            . "(1,%d,'',5,CURDATE()), (%d,%d,'',4,CURDATE()), (%d,%d,'',3,CURDATE()), "
            . "(1,%d,'',5,CURDATE()), (%d,%d,'',2,CURDATE())",
            $this->dbPrefix,
            $photoIds[0],
            $userIds['regular_user'], $photoIds[0],
            $userIds['power_user'], $photoIds[1],
            $photoIds[2],
            $userIds['regular_user'], $photoIds[3]
        ));
        $db->query(sprintf(
            'UPDATE %simages SET rating_score = 4.50 WHERE id = %d',
            $this->dbPrefix,
            $photoIds[0]
        ));
        $db->query(sprintf(
            'UPDATE %simages SET rating_score = 3.00 WHERE id = %d',
            $this->dbPrefix,
            $photoIds[1]
        ));
        $db->query(sprintf(
            'UPDATE %simages SET rating_score = 5.00 WHERE id = %d',
            $this->dbPrefix,
            $photoIds[2]
        ));
        $db->query(sprintf(
            'UPDATE %simages SET rating_score = 2.00 WHERE id = %d',
            $this->dbPrefix,
            $photoIds[3]
        ));

        // 11. Three favorites for the admin user.
        $db->query(sprintf(
            'INSERT INTO %sfavorites (user_id, image_id) VALUES (1,%d),(1,%d),(1,%d)',
            $this->dbPrefix,
            $photoIds[0],
            $photoIds[2],
            $photoIds[4]
        ));

        // 12. Two mail notification entries.
        $db->query(sprintf(
            "INSERT INTO %suser_mail_notification (user_id, check_key, enabled, last_send) VALUES "
            . "(1, 'abcdef1234567890', 'true', NOW()), (%d, 'ghijkl9876543210', 'false', NULL)",
            $this->dbPrefix,
            $userIds['regular_user']
        ));

        // 13. One old permalink.
        $db->query(sprintf(
            "INSERT INTO %sold_permalinks (cat_id, permalink, date_deleted, last_hit, hit) VALUES "
            . "(%d, 'old-sample-album', NOW(), NOW(), 42)",
            $this->dbPrefix,
            $rootAlbumId
        ));

        // 14. A few config tweaks (piwigo_config.value is a plain TEXT column
        // in this schema — no JSON encoding needed).
        $configEntries = [
            'gallery_title'       => 'Fixture Gallery',
            'activate_comments'   => 'true',
            'comments_validation' => 'true',
            'nb_categories_page'  => '12',
            'rate'                => 'true',
        ];
        foreach ($configEntries as $param => $value) {
            $db->query(sprintf(
                "UPDATE %sconfig SET value='%s' WHERE param='%s'",
                $this->dbPrefix,
                $db->real_escape_string($value),
                $db->real_escape_string($param)
            ));
        }
        $db->close();

        // 15. Dump the scratch DB to tests/Fixtures/piwigo-16.x.sql.
        $fixturePath = dirname(__DIR__) . '/Fixtures/piwigo-16.x.sql';
        $cmd = ['mysqldump', '-u' . $this->dbUser];
        if ($this->dbPass !== '') {
            $cmd[] = '-p' . $this->dbPass;
        }

        $cmd[] = str_starts_with($this->dbHost, '/') ? '--socket=' . $this->dbHost : '-h' . $this->dbHost;
        $cmd[] = '--no-tablespaces';
        $cmd[] = $this->dbName;

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        self::assertIsResource($proc, 'proc_open failed for mysqldump');
        $dump   = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        self::assertIsString($stderr);
        self::assertSame(0, $exit, 'mysqldump failed: ' . $stderr);
        self::assertIsString($dump, 'mysqldump produced no output');

        file_put_contents($fixturePath, $dump);
        $fixtureSize = filesize($fixturePath);
        self::assertNotFalse($fixtureSize, 'could not stat the written fixture file');
        $sizeKB = (int) round($fixtureSize / 1024);
        self::assertGreaterThan(40, $sizeKB, "fixture should be > 40 KB (got {$sizeKB} KB)");
    }

    /** POSTs a form to a script served through Apache, with the test-mode header. */
    private function postForm(string $scriptName, array $fields): string
    {
        $url = $this->baseUrl . '/' . $scriptName;
        $ch  = curl_init($url);
        self::assertNotFalse($ch);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_HTTPHEADER     => $this->testHeader(),
        ]);
        $body = curl_exec($ch);
        self::assertIsString($body, 'postForm returned no body');
        unset($ch);

        return $body;
    }

    private function uploadPhoto(string $imagePath, int $albumId, string $name): int
    {
        $url = $this->baseUrl . '/ws.php?format=json';
        $ch  = curl_init($url);
        self::assertNotFalse($ch);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_POSTFIELDS     => [
                'method'   => 'pwg.images.addSimple',
                'category' => (string) $albumId,
                'name'     => $name,
                'image'    => new \CURLFile($imagePath, 'image/jpeg', basename($imagePath)),
            ],
            CURLOPT_COOKIEJAR  => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_HTTPHEADER => $this->testHeader(),
        ]);
        $body = curl_exec($ch);
        unset($ch);
        self::assertIsString($body, 'photo upload returned no body');
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, 'photo upload response is not valid JSON: ' . $body);
        self::assertSame('ok', $decoded['stat'], 'photo upload failed: ' . $body);

        // After enough photos accumulate, Piwigo's "lounge" holds uploads
        // pending until released — flush it so fixtures see what was just
        // uploaded.
        $this->callWs('pwg.images.emptyLounge', []);

        return (int) $decoded['result']['image_id'];
    }

    private function callWs(string $method, array $params): array
    {
        $url = $this->baseUrl . '/ws.php?format=json';
        $ch  = curl_init($url);
        self::assertNotFalse($ch);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_POSTFIELDS     => http_build_query(array_merge(['method' => $method], $params)),
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_HTTPHEADER     => $this->testHeader(),
        ]);
        $body = curl_exec($ch);
        unset($ch);
        self::assertIsString($body, "WS call to $method returned no body");
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, "WS $method response is not valid JSON: $body");

        return $decoded;
    }
}

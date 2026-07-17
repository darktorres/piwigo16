<?php

declare(strict_types=1);

namespace Piwigo\Tests\Browser;

use PHPUnit\Framework\Attributes\Group;
use Piwigo\Core\Env;
use Piwigo\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Process\Process;

/**
 * Regenerates tests/Fixtures/piwigo-17.0.sql by driving a fresh install,
 * running the real Doctrine Migrations (P15) to bring it up to the
 * current schema, seeding content, then dumping it.
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

    /** Returns the path to the per-test cookie jar (for raw curl calls). */
    private function cookieJar(): string
    {
        // setUp() always populates this from tempnam() before any test body
        // runs
        assert($this->cookieJar !== '');
        return $this->cookieJar;
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

        // 2. Drive install.php with fixture admin credentials. install.php
        // itself preserves any pre-existing custom line (e.g. PIWIGO_TEST_NOW
        // — see \Piwigo\Core\Env::now()) when it rewrites .env.test,
        // so nothing extra is needed here to keep it across a regen.
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

        // 2b. install.php still creates the pre-P15 origin schema (MyISAM,
        // no charset, no FKs) -- it hard-references
        // install/piwigo_structure-mysql.sql via execute_sqlfile(), whose
        // naive parser and hardcoded utf8 rewrite can't consume the
        // migrations-generated schema yet (real "install flow rework"
        // scope, deliberately not folded into P15 -- see
        // Piwigo\Db\SchemaDumpService's docblock). Run the real migrations
        // here instead, against the just-installed DB, so the fixture this
        // test produces reflects the current v17 schema (InnoDB+utf8mb4,
        // FKs, the 7 new tables) even though the live installer doesn't
        // yet.
        $migrateProcess = new Process(
            ['php', 'bin/piwigo', 'migrations:migrate', '--no-interaction'],
            cwd: dirname(__DIR__, 2),
        );
        $migrateProcess->setTimeout(60);
        $migrateProcess->run();
        self::assertTrue(
            $migrateProcess->isSuccessful(),
            'bin/piwigo migrations:migrate must succeed against the freshly installed DB: ' . $migrateProcess->getErrorOutput()
        );

        // 3. Log in as fixture_admin via WS (same code path Piwigo uses
        // internally; avoids flaky form-login on a freshly installed gallery).
        $login = $this->callWs('pwg.session.login', [
            'username' => self::ADMIN_USER,
            'password' => self::ADMIN_PASS,
        ]);
        self::assertSame('ok', $login['stat'], 'fixture_admin login must succeed');
        $statusResult = $this->callWs('pwg.session.getStatus', [])['result'];
        self::assertIsArray($statusResult, 'pwg.session.getStatus result must be an array');
        $pwgTokenValue = $statusResult['pwg_token'];
        self::assertIsString($pwgTokenValue, 'pwg.session.getStatus result must include a string pwg_token');
        $pwgToken = $pwgTokenValue;

        // 4. Two albums: one root, one nested sub-album.
        $rootAlbumResult = $this->callWs('pwg.categories.add', ['name' => 'Sample Album'])['result'];
        self::assertIsArray($rootAlbumResult, 'pwg.categories.add result must be an array');
        $rootAlbumId = self::idFromWsValue($rootAlbumResult['id'], 'pwg.categories.add');
        $subAlbum    = $this->callWs('pwg.categories.add', [
            'name'   => 'Nested Sub Album',
            'parent' => (string) $rootAlbumId,
        ]);
        self::assertSame('ok', $subAlbum['stat']);
        $subAlbumResult = $subAlbum['result'];
        self::assertIsArray($subAlbumResult, 'pwg.categories.add result must be an array');
        $subAlbumId = self::idFromWsValue($subAlbumResult['id'], 'pwg.categories.add');

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
            $tagAddResult = $res['result'];
            self::assertIsArray($tagAddResult, 'pwg.tags.add result must be an array');
            $tagIds[] = self::idFromWsValue($tagAddResult['id'], 'pwg.tags.add');
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
            $userAddResult = $res['result'];
            self::assertIsArray($userAddResult, 'pwg.users.add result must be an array');
            $addedUsers = $userAddResult['users'];
            self::assertIsArray($addedUsers, 'pwg.users.add result must include a users list');
            $firstAddedUser = $addedUsers[0];
            self::assertIsArray($firstAddedUser, 'pwg.users.add users[0] must be an array');
            $userIds[$username] = self::idFromWsValue($firstAddedUser['id'], 'pwg.users.add');
        }

        // 8. Five comments across different users/photos (1 unvalidated).
        // Inserted directly — pwg.images.addComment requires commentable=true
        // on the parent album, which fresh albums don't have.
        //
        // date/validation_date/last_send/date_deleted/last_hit below all use
        // $now (and rate.date uses $today) instead of raw SQL NOW()/CURDATE()
        // -- those run on the MySQL server's real clock, invisible to
        // Env::now()'s PIWIGO_TEST_NOW freeze, so every regeneration produced
        // a fresh, non-reproducible timestamp in the committed fixture.
        $now = Env::now()->format('Y-m-d H:i:s');
        $db->query(sprintf(
            "INSERT INTO %scomments (image_id, date, author, anonymous_id, author_id, content, validated, validation_date) VALUES "
            . "(%d, '%s', 'fixture_admin', '127.0.0.1', 1, 'Fixture comment for integration tests.', 1, '%s'), "
            . "(%d, '%s', 'regular_user', '127.0.0.2', %d, 'Another perspective on this photo.', 1, '%s'), "
            . "(%d, '%s', 'power_user', '127.0.0.3', %d, 'Great composition and colors!', 1, '%s'), "
            . "(%d, '%s', 'power_user', '127.0.0.3', %d, 'I keep coming back to this one.', 1, '%s'), "
            . "(%d, '%s', 'fixture_admin', '127.0.0.1', 1, 'Pending comment for moderation.', 'false', NULL)",
            $this->dbPrefix,
            $photoIds[0], $now, $now,
            $photoIds[1], $now, $userIds['regular_user'], $now,
            $photoIds[2], $now, $userIds['power_user'], $now,
            $photoIds[0], $now, $userIds['power_user'], $now,
            $photoIds[3], $now
        ));

        // 9. Three groups with user memberships.
        $groupIds = [];
        foreach (['Editors', 'Reviewers', 'Guests'] as $name) {
            $res = $this->callWs('pwg.groups.add', ['name' => $name, 'pwg_token' => $pwgToken]);
            self::assertSame('ok', $res['stat'], "group $name should be created");
            $groupAddResult = $res['result'];
            self::assertIsArray($groupAddResult, 'pwg.groups.add result must be an array');
            $addedGroups = $groupAddResult['groups'];
            self::assertIsArray($addedGroups, 'pwg.groups.add result must include a groups list');
            $firstAddedGroup = $addedGroups[0];
            self::assertIsArray($firstAddedGroup, 'pwg.groups.add groups[0] must be an array');
            $groupIds[] = self::idFromWsValue($firstAddedGroup['id'], 'pwg.groups.add');
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
        $today = Env::now()->format('Y-m-d');
        $db->query(sprintf(
            "INSERT INTO %srate (user_id, element_id, anonymous_id, rate, date) VALUES "
            . "(1,%d,'',5,'%s'), (%d,%d,'',4,'%s'), (%d,%d,'',3,'%s'), "
            . "(1,%d,'',5,'%s'), (%d,%d,'',2,'%s')",
            $this->dbPrefix,
            $photoIds[0], $today,
            $userIds['regular_user'], $photoIds[0], $today,
            $userIds['power_user'], $photoIds[1], $today,
            $photoIds[2], $today,
            $userIds['regular_user'], $photoIds[3], $today
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
            . "(1, 'abcdef1234567890', 'true', '%s'), (%d, 'ghijkl9876543210', 'false', NULL)",
            $this->dbPrefix,
            $now,
            $userIds['regular_user']
        ));

        // 13. One old permalink.
        $db->query(sprintf(
            "INSERT INTO %sold_permalinks (cat_id, permalink, date_deleted, last_hit, hit) VALUES "
            . "(%d, 'old-sample-album', '%s', '%s', 42)",
            $this->dbPrefix,
            $rootAlbumId,
            $now,
            $now
        ));

        // 14. A few config tweaks (piwigo_config.value is a plain TEXT column
        // in this schema — no JSON encoding needed). show_piwigo_latest_news/
        // dashboard_check_for_updates default to true (config_default.inc.php)
        // and have no row from a fresh install, so this must be an upsert, not
        // an UPDATE — matching conf_update_param()'s own real INSERT ... ON
        // DUPLICATE KEY UPDATE pattern (piwigo_config.param is the PRIMARY
        // KEY). Both gate live HTTP calls to piwigo.org from the admin
        // dashboard (a real news-feed fetch and a core/extension update
        // check) that have nothing to do with test-mode's frozen clock and
        // must never fire from a test run.
        $configEntries = [
            'gallery_title'                => 'Fixture Gallery',
            'activate_comments'            => 'true',
            'comments_validation'          => 'true',
            'nb_categories_page'           => '12',
            'rate'                         => 'true',
            'show_piwigo_latest_news'      => 'false',
            'dashboard_check_for_updates'  => 'false',
        ];
        foreach ($configEntries as $param => $value) {
            $db->query(sprintf(
                "INSERT INTO %sconfig (param, value) VALUES ('%s', '%s') ON DUPLICATE KEY UPDATE value = '%s'",
                $this->dbPrefix,
                $db->real_escape_string($param),
                $db->real_escape_string($value),
                $db->real_escape_string($value)
            ));
        }
        $db->close();

        // 15. Dump the scratch DB to tests/Fixtures/piwigo-17.0.sql.
        // --ignore-table excludes Doctrine's own migration-execution
        // ledger (real and necessary for migrations:migrate, out of place
        // in a fixture) and --set-gtid-purged=OFF strips a host-specific
        // GTID set that would otherwise make every regeneration produce a
        // spurious diff -- same fixes as Piwigo\Db\SchemaDumpService,
        // found empirically the same way.
        $fixturePath = dirname(__DIR__) . '/Fixtures/piwigo-17.0.sql';
        $cmd = ['mysqldump', '-u' . $this->dbUser];
        if ($this->dbPass !== '') {
            $cmd[] = '-p' . $this->dbPass;
        }

        $cmd[] = str_starts_with($this->dbHost, '/') ? '--socket=' . $this->dbHost : '-h' . $this->dbHost;
        $cmd[] = '--no-tablespaces';
        $cmd[] = '--set-gtid-purged=OFF';
        $cmd[] = '--ignore-table=' . $this->dbName . '.doctrine_migration_versions';
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

    /**
     * POSTs a form to a script served through Apache, with the test-mode header.
     * @param array<string, mixed> $fields
     */
    private function postForm(string $scriptName, array $fields): string
    {
        $url = $this->baseUrl . '/' . $scriptName;
        $ch  = curl_init($url);
        self::assertNotFalse($ch);
        $userAgent = self::USER_AGENT;
        $cookieJar = $this->cookieJar();
        assert($cookieJar !== '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
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
        $userAgent = self::USER_AGENT;
        $cookieJar = $this->cookieJar();
        assert($cookieJar !== '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'method'   => 'pwg.images.addSimple',
            'category' => (string) $albumId,
            'name'     => $name,
            'image'    => new \CURLFile($imagePath, 'image/jpeg', basename($imagePath)),
        ]);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
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

        $uploadResult = $decoded['result'];
        self::assertIsArray($uploadResult, 'photo upload result must be an array');

        return self::idFromWsValue($uploadResult['image_id'], 'pwg.images.addSimple');
    }

    /**
     * Narrows a WS response id field down to a real int. Piwigo's WS layer
     * surfaces ids in two different but equally real shapes depending on
     * the call: \Piwigo\Db\MysqliDb::insertId()'s own return type is int|string (see
     * create_virtual_category()/create_tag(), backing pwg.categories.add/
     * pwg.tags.add, and ws_images_addSimple()'s own declared return shape
     * of PwgError or array{image_id: int|string, url: string}), while ids
     * that come back through a getList-style response (pwg.groups.add ->
     * pwg.groups.getList, pwg.users.add -> pwg.users.getList) are either
     * intval()'d server-side (real int) or, for pwg.groups.add, surfaced
     * verbatim from a raw DB row (numeric string, per this codebase's DB
     * layer invariant that every column value is string|null). Either way,
     * a WS JSON id is always an int or a numeric string, never anything
     * else.
     */
    private static function idFromWsValue(mixed $value, string $context): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        self::fail(sprintf('%s result id must be an int or a numeric string, got %s', $context, get_debug_type($value)));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function callWs(string $method, array $params): array
    {
        $url = $this->baseUrl . '/ws.php?format=json';
        $ch  = curl_init($url);
        self::assertNotFalse($ch);
        $userAgent = self::USER_AGENT;
        $cookieJar = $this->cookieJar();
        assert($cookieJar !== '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge(['method' => $method], $params)));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
        $body = curl_exec($ch);
        unset($ch);
        self::assertIsString($body, "WS call to $method returned no body");
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, "WS $method response is not valid JSON: $body");

        // ws.php's JSON envelope is always a JSON object (stat/result/err
        // keys), never a JSON array, so the decoded top level is always
        // string-keyed.
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}

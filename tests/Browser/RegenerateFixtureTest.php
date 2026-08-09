<?php

declare(strict_types=1);

namespace Piwigo\Tests\Browser;

use Override;
use mysqli;
use PgSql\Connection;
use RuntimeException;
use CURLFile;
use PHPUnit\Framework\Attributes\Group;
use Piwigo\Core\Env;
use Piwigo\Tests\Browser\Helpers\FixturePhotoGenerator;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Regenerates tests/Fixtures/piwigo-17.0.sql (or, on Postgres,
 * piwigo-17.0-pgsql.sql) by driving a fresh install -- via the real
 * Doctrine Migrations baseline InstallWizard::performInstall() now runs
 * (Phase B of the pgsql-support pass; this docblock's own prior "no
 * Doctrine Migrations step" claim predates that and is no longer
 * accurate) -- seeding content, then dumping it.
 *
 * Usage:
 *   vendor/bin/pest --group=fixture-regen
 *
 * Excluded from the default Browser suite run (composer test:browser passes
 * --exclude-group=fixture-regen) — this wipes the test DB and overwrites a
 * committed fixture file. It is not a regression test.
 *
 * Credentials come from .env.test (PIWIGO_DB_HOST/USER/PASSWORD/BASE/PREFIX/
 * DRIVER), loaded via IntegrationTestCase::setUpConnectionFromEnv() --
 * running this against Postgres needs .env.test's own PIWIGO_DB_DRIVER set
 * to pgsql beforehand (the real, live-server-facing side of this test;
 * unlike most Integration tests, it can't be driven by an env-var override
 * on the CLI invocation alone, since the *real running web server* --
 * not this CLI process -- is what actually executes install.php/ws.php
 * and re-reads .env.test itself for every request).
 */
#[Group('fixture-regen')]
final class RegenerateFixtureTest extends IntegrationTestCase
{
    private const string ADMIN_USER = 'fixture_admin';

    private const string ADMIN_PASS = 'fixture_admin';

    /** Real HTTP clients always send one; some legacy code paths assume it. */
    private const string USER_AGENT = 'PiwigoFixtureRegen/1.0';

    private string $cookieJar = '';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();
        $this->requireBaseUrl();

        $tmp = tempnam(sys_get_temp_dir(), 'pwg_fixture_');
        self::assertIsString($tmp);
        $this->cookieJar = $tmp;
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->cookieJar !== '' && file_exists($this->cookieJar)) {
            unlink($this->cookieJar);
        }
        parent::tearDown();
    }

    /** Returns the path to the per-test cookie jar (for raw curl calls). */
    private function cookieJar(): string
    {
        // setUp() always populates this from tempnam() before any test body
        // runs
        assert($this->cookieJar !== '');
        return $this->cookieJar;
    }

    /**
     * This test's own real, direct (raw, unbound)
     * INSERT statements below need a per-connection-type dispatch --
     * unlike every other real caller in this codebase (which goes
     * through Doctrine DBAL, already portable), this file's own seed
     * data is built with $this->newMysqli()/newPgsqlConnection()'s
     * native, driver-specific connection objects directly (matching
     * IntegrationTestCase's own established "raw escape hatch" shape for
     * this kind of fixture-authoring code, not app code).
     */
    private function dbQuery(mysqli|Connection $db, string $sql): void
    {
        if ($db instanceof mysqli) {
            $db->query($sql);
        } else {
            pg_query($db, $sql);
        }
    }

    private function dbEscape(mysqli|Connection $db, string $value): string
    {
        return $db instanceof mysqli ? $db->real_escape_string($value) : pg_escape_string($db, $value);
    }

    private function dbClose(mysqli|Connection $db): void
    {
        if ($db instanceof mysqli) {
            $db->close();
        } else {
            pg_close($db);
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

        // comments.validated/user_mail_notification.enabled are genuine
        // `boolean` columns on Postgres (not the `smallint`-with-a-real-
        // integer-range convention this codebase uses elsewhere) -- a bare
        // `1`/`0` literal is valid MySQL `tinyint(1)` input but Postgres
        // rejects it outright ("column ... is of type boolean but
        // expression is of type integer"), so the two raw INSERTs below
        // that populate them need real driver-aware literals.
        $sqlTrue = $this->dbDriver === 'pgsql' ? 'true' : '1';
        $sqlFalse = $this->dbDriver === 'pgsql' ? 'false' : '0';

        // 1. Wipe the test DB so the installer sees a blank slate, and clear
        // the install sentinel so common.inc.php/install.php show the form.
        $this->resetDatabase();
        $this->removeTestStamp();

        // 2. Drive install.php with fixture admin credentials. install.php
        // itself preserves any pre-existing custom line (e.g. PIWIGO_TEST_NOW
        // — see \Piwigo\Core\Env::now()) when it rewrites .env.test,
        // so nothing extra is needed here to keep it across a regen.
        //
        // 'dbdriver' is real, not a no-op default -- InstallWizardRequest's
        // own parsing falls back to 'mysqli' for anything but a literal
        // 'pgsql' string, so omitting it here would silently install
        // against mysqli even when this whole test is otherwise pgsql-
        // targeted (this->dbDriver already reflects the real .env.test
        // PIWIGO_DB_DRIVER, via setUpConnectionFromEnv()).
        $installBody = $this->postForm('install.php', [
            'install'       => '1',
            'dbhost'        => $this->dbHost,
            'dbuser'        => $this->dbUser,
            'dbpasswd'      => $this->dbPass,
            'dbname'        => $this->dbName,
            'dbdriver'      => $this->dbDriver,
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
            for ($i = 1; $i <= 5; $i++) {
                $filePath = $tmpDir . '/fixture-photo-' . $i . '.jpg';
                FixturePhotoGenerator::write($i, $filePath);

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
        $db = $this->dbDriver === 'pgsql' ? $this->newPgsqlConnection($this->dbName) : $this->newMysqli($this->dbName);
        $this->dbQuery($db, sprintf(
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
        $this->dbQuery($db, sprintf(
            "INSERT INTO %scomments (image_id, date, author, anonymous_id, author_id, content, validated, validation_date) VALUES "
            . "(%d, '%s', 'fixture_admin', '127.0.0.1', 1, 'Fixture comment for integration tests.', {$sqlTrue}, '%s'), "
            . "(%d, '%s', 'regular_user', '127.0.0.2', %d, 'Another perspective on this photo.', {$sqlTrue}, '%s'), "
            . "(%d, '%s', 'power_user', '127.0.0.3', %d, 'Great composition and colors!', {$sqlTrue}, '%s'), "
            . "(%d, '%s', 'power_user', '127.0.0.3', %d, 'I keep coming back to this one.', {$sqlTrue}, '%s'), "
            . "(%d, '%s', 'fixture_admin', '127.0.0.1', 1, 'Pending comment for moderation.', {$sqlFalse}, NULL)",
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
        $this->dbQuery($db, sprintf(
            'INSERT INTO %suser_group (user_id, group_id) VALUES (1,%d),(%d,%d),(%d,%d),(%d,%d)',
            $this->dbPrefix,
            $groupIds[0],
            $userIds['regular_user'], $groupIds[0],
            $userIds['regular_user'], $groupIds[1],
            $userIds['power_user'], $groupIds[2]
        ));
        $this->dbQuery($db, sprintf(
            'INSERT INTO %sgroup_access (group_id, cat_id) VALUES (%d,%d),(%d,%d),(%d,%d),(%d,%d)',
            $this->dbPrefix,
            $groupIds[0], $rootAlbumId,
            $groupIds[0], $subAlbumId,
            $groupIds[1], $rootAlbumId,
            $groupIds[2], $rootAlbumId
        ));

        // 10. Five ratings across users/photos.
        $today = Env::now()->format('Y-m-d');
        $this->dbQuery($db, sprintf(
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
        $this->dbQuery($db, sprintf(
            'UPDATE %simages SET rating_score = 4.50 WHERE id = %d',
            $this->dbPrefix,
            $photoIds[0]
        ));
        $this->dbQuery($db, sprintf(
            'UPDATE %simages SET rating_score = 3.00 WHERE id = %d',
            $this->dbPrefix,
            $photoIds[1]
        ));
        $this->dbQuery($db, sprintf(
            'UPDATE %simages SET rating_score = 5.00 WHERE id = %d',
            $this->dbPrefix,
            $photoIds[2]
        ));
        $this->dbQuery($db, sprintf(
            'UPDATE %simages SET rating_score = 2.00 WHERE id = %d',
            $this->dbPrefix,
            $photoIds[3]
        ));

        // 11. Three favorites for the admin user.
        $this->dbQuery($db, sprintf(
            'INSERT INTO %sfavorites (user_id, image_id) VALUES (1,%d),(1,%d),(1,%d)',
            $this->dbPrefix,
            $photoIds[0],
            $photoIds[2],
            $photoIds[4]
        ));

        // 12. Two mail notification entries.
        $this->dbQuery($db, sprintf(
            "INSERT INTO %suser_mail_notification (user_id, check_key, enabled, last_send) VALUES "
            . "(1, 'abcdef1234567890', {$sqlTrue}, '%s'), (%d, 'ghijkl9876543210', {$sqlFalse}, NULL)",
            $this->dbPrefix,
            $now,
            $userIds['regular_user']
        ));

        // 13. One old permalink.
        $this->dbQuery($db, sprintf(
            "INSERT INTO %sold_permalinks (cat_id, permalink, date_deleted, last_hit, hit) VALUES "
            . "(%d, 'old-sample-album', '%s', '%s', 42)",
            $this->dbPrefix,
            $rootAlbumId,
            $now,
            $now
        ));

        // 14. A few config tweaks. piwigo_config.value is JSON-encoded
        // (ConfigService::encode()) --
        // each entry below is json_encode()d to match. The former
        // `derivatives`/`disabled_derivatives` serialize()-blob exception
        // (ConfigService::OBJECT_SERIALIZED_PARAMS) is gone entirely: both
        // keys retired in favor of the real derivative_settings/
        // derivative_size tables, so there's no longer any non-JSON
        // config value to special-case.
        // show_piwigo_latest_news/dashboard_check_for_updates default to
        // true (config_default.inc.php) and have no row from a fresh
        // install, so this must be an upsert, not an UPDATE — matching
        // conf_update_param()'s own real INSERT ... ON DUPLICATE KEY UPDATE
        // pattern (piwigo_config.param is the PRIMARY KEY). Both gate live
        // HTTP calls to piwigo.org from the admin dashboard (a real
        // news-feed fetch and a core/extension update check) that have
        // nothing to do with test-mode's frozen clock and must never fire
        // from a test run.
        $configEntries = [
            'gallery_title'                => 'Fixture Gallery',
            'activate_comments'            => true,
            'comments_validation'          => true,
            'nb_categories_page'           => 12,
            'rate'                         => true,
            'show_piwigo_latest_news'      => false,
            'dashboard_check_for_updates'  => false,
        ];
        // ON DUPLICATE KEY UPDATE has no Postgres equivalent -- ON CONFLICT
        // (param) DO UPDATE SET is the real one (piwigo_config.param is the
        // PRIMARY KEY on both platforms, so the conflict target is valid
        // either way).
        $upsertSql = $this->dbDriver === 'pgsql'
            ? "INSERT INTO %sconfig (param, value) VALUES ('%s', '%s') ON CONFLICT (param) DO UPDATE SET value = '%s'"
            : "INSERT INTO %sconfig (param, value) VALUES ('%s', '%s') ON DUPLICATE KEY UPDATE value = '%s'";
        foreach ($configEntries as $param => $value) {
            $jsonValue = json_encode($value);
            if ($jsonValue === false) {
                throw new RuntimeException("json_encode failed for config param '{$param}'");
            }

            $this->dbQuery($db, sprintf(
                $upsertSql,
                $this->dbPrefix,
                $this->dbEscape($db, $param),
                $this->dbEscape($db, $jsonValue),
                $this->dbEscape($db, $jsonValue)
            ));
        }
        $this->dbClose($db);

        // 15. Dump the scratch DB to tests/Fixtures/piwigo-17.0.sql (or,
        // on Postgres, piwigo-17.0-pgsql.sql -- the exact sibling path
        // IntegrationTestCase::loadFixture() itself derives from the
        // mysql-shaped literal every real call site passes).
        // --set-gtid-purged=OFF strips a host-specific GTID set that would
        // otherwise make every regeneration produce a spurious diff.
        // --ignore-table/--exclude-table={prefix}migration_versions: real
        // and necessary for `bin/piwigo migrations:migrate` against a real
        // install, but out of place baked into a fixture (same reasoning
        // Piwigo\Db\SchemaDumpService's own docblock already gives for its
        // schema-only dump) -- this table didn't exist in this fixture
        // before InstallWizard started driving schema creation through the
        // real Migrator (Phase B), so this exclusion is a real, newly-
        // needed fix, not carried over from before.
        $fixturePath = $this->dbDriver === 'pgsql'
            ? dirname(__DIR__) . '/Fixtures/piwigo-17.0-pgsql.sql'
            : dirname(__DIR__) . '/Fixtures/piwigo-17.0.sql';

        if ($this->dbDriver === 'pgsql') {
            $cmd = ['pg_dump', '-U' . $this->dbUser, '-h' . $this->dbHost];
            // --clean --if-exists: mysqldump's own --add-drop-table default
            // (a DROP TABLE IF EXISTS ahead of every CREATE TABLE) is what
            // makes IntegrationTestCase::loadFixture() safe to call a
            // second time against an already-populated database (several
            // real tests do exactly that, reloading in their own finally
            // block after intentionally corrupting/truncating data) --
            // pg_dump has no such default, so without these flags a second
            // load hits "function piwigo_set_lastmodified already exists"
            // (confirmed live). --if-exists keeps the emitted DROPs from
            // erroring on a genuinely fresh/empty database either.
            $cmd[] = '--clean';
            $cmd[] = '--if-exists';
            $cmd[] = '--exclude-table=' . $this->dbPrefix . 'migration_versions';
            $cmd[] = $this->dbName;
            $env = $this->dbPass !== '' ? array_merge(getenv(), ['PGPASSWORD' => $this->dbPass]) : null;
        } else {
            $cmd = ['mysqldump', '-u' . $this->dbUser];
            if ($this->dbPass !== '') {
                $cmd[] = '-p' . $this->dbPass;
            }

            $cmd[] = str_starts_with($this->dbHost, '/') ? '--socket=' . $this->dbHost : '-h' . $this->dbHost;
            $cmd[] = '--no-tablespaces';
            $cmd[] = '--set-gtid-purged=OFF';
            $cmd[] = '--ignore-table=' . $this->dbName . '.' . $this->dbPrefix . 'migration_versions';
            $cmd[] = $this->dbName;
            $env = null;
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
        self::assertIsResource($proc, 'proc_open failed for ' . $cmd[0]);
        $dump   = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        self::assertIsString($stderr);
        self::assertSame(0, $exit, $cmd[0] . ' failed: ' . $stderr);
        self::assertIsString($dump, $cmd[0] . ' produced no output');

        if ($this->dbDriver === 'pgsql') {
            // \restrict/\unrestrict (pg_dump 18+, a psql meta-command pair
            // guarding this file against execution unless the matching
            // random token is echoed back) carry a freshly-random token on
            // every single dump -- same non-determinism
            // Piwigo\Db\SchemaDumpService::normalize() already strips for
            // its own pg_dump output, confirmed live here too by diffing
            // two consecutive dumps. Stripped so regenerating this fixture
            // doesn't produce a spurious two-line diff every time.
            $lines = explode("\n", $dump);
            $lines = array_filter($lines, static fn (string $line): bool => ! str_starts_with(ltrim($line), '\\restrict ')
                && ! str_starts_with(ltrim($line), '\\unrestrict '));
            $dump = implode("\n", $lines);
        }

        if ($this->dbDriver !== 'pgsql') {
            // mysqldump's own output never includes this -- MySQL
            // bakes a FULLTEXT index's effective stopword-filtering behavior
            // in at CREATE TABLE time (confirmed live: a later per-connection
            // setting has zero effect on an already-existing index), so the
            // categories/images/tags ngram FULLTEXT indexes below need this
            // active before their own CREATE TABLE runs when this dump is
            // reloaded via IntegrationTestCase::loadFixture() -- same
            // rationale as install/piwigo_structure-mysql.sql's own identical
            // line, kept in sync with it. Postgres has no equivalent concept
            // to prepend -- its own FULLTEXT parity uses generated tsvector
            // columns, with no session-level setting of any kind involved.
            $dump = "SET SESSION innodb_ft_enable_stopword = 0;\n" . $dump;
        }

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
            'image'    => new CURLFile($imagePath, 'image/jpeg', basename($imagePath)),
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
     * the call: the repository layer's insert path (e.g.
     * CategoryRepository::insertCategory(), TagRepository::insert())
     * returns int|string (see CategoryService::createVirtualCategory()/
     * TagService::createTag(), backing pwg.categories.add/pwg.tags.add,
     * and Ws\PwgImages::addSimple()'s own declared return shape of
     * PwgError or array{image_id: int|string, url: string}), while ids
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

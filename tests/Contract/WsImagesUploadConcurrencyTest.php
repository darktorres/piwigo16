<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Override;
use Piwigo\Db\AdvisorySessionLock;
use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;

/**
 * A single PHP test process can't simulate two genuinely simultaneous
 * pwg.images.add requests on its own -- the foreground callWs() call
 * blocks synchronously on a real HTTP request, which itself blocks
 * synchronously on the server's own GET_LOCK() call; nothing else can run
 * on this one thread while that's in flight. Real OS-level parallelism
 * (a second, independent process) is genuinely required, not optional --
 * same reasoning IntegrationTestCase::loadFixture() already established
 * for shelling out to the real `mysql` CLI via proc_open, reused here for
 * the same reason.
 */
final class WsImagesUploadConcurrencyTest extends ContractTestCase
{
    /** 1x1 white PNG, base64-decoded at runtime to avoid binary in source. */
    private const string TINY_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';

    private Connection $conn;

    /** @var list<int> image ids created by a test, deleted in tearDown. */
    private array $createdImageIds = [];

    /** @var array<1|2, resource>|null */
    private ?array $lockHolderPipes = null;

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
        foreach ($this->createdImageIds as $id) {
            $this->conn->executeStatement('DELETE FROM ' . 'images' . ' WHERE id = ?', [$id]);
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
     * Mirrors Ws\PwgImages::add()'s own private lock-name formula exactly
     * (see that method's own docblock) -- 'md5sum' is the only
     * uniqueness_column this suite exercises, matching
     * CurrentConfig::uniquenessMode()'s own default ('md5sum', unless
     * overridden in the fixture -- confirmed not overridden).
     */
    private function uploadUniquenessLockName(string $md5sum): string
    {
        return 'piwigo_iu_' . sha1($this->dbName . ':md5sum:' . $md5sum);
    }

    /**
     * @return list<string>
     */
    private function mysqlCliCommand(): array
    {
        $cmd = ['mysql', '-u' . $this->dbUser];
        if ($this->dbPass !== '') {
            $cmd[] = '-p' . $this->dbPass;
        }

        $cmd[] = str_starts_with($this->dbHost, '/') ? '--socket=' . $this->dbHost : '-h' . $this->dbHost;
        $cmd[] = $this->dbName;

        return $cmd;
    }

    /**
     * @return list<string>
     */
    private function psqlCliCommand(): array
    {
        return ['psql', '-U' . $this->dbUser, '-h' . $this->dbHost, '-d' . $this->dbName, '-q', '-t'];
    }

    /**
     * Spawns a real, separate OS process that: acquires $lockName, sleeps
     * briefly, inserts a minimal images row with $md5sum, then releases
     * the lock -- simulating a second, concurrent upload of the exact same
     * file completing its own check-then-insert critical section while
     * this test's real foreground WS call is blocked waiting for it.
     * Non-blocking: returns immediately, the caller's own foreground call
     * runs concurrently with this background process. Every value baked
     * into the SQL text below is this test's own program-generated
     * md5()/uniqid() output, never external input -- same "controlled,
     * internal-only text" reasoning IntegrationTestCase::loadFixture()
     * already relies on for its own raw `mysql -e` invocations.
     *
     * @return resource
     */
    private function spawnBackgroundLockHolderThatInsertsThenReleases(string $lockName, string $md5sum, string $file)
    {
        // GET_LOCK()/RELEASE_LOCK()/SLEEP() are all MySQL-only, and the CLI
        // client itself differs (mysql -e vs psql -c). The advisory-lock key
        // must be the exact same derived bigint
        // Db\AdvisorySessionLock::key() computes (this shell script has
        // no access to that PHP-side helper, so it's replicated here as
        // a literal), and Postgres's own GET_LOCK(name, timeout)
        // equivalent is a session-level lock_timeout GUC set before a
        // plain (indefinitely-blocking) pg_advisory_lock() call.
        if ($this->dbDriver === 'pgsql') {
            $key = AdvisorySessionLock::key($lockName);
            $sql = sprintf(
                "SET lock_timeout = '5s'; SELECT pg_advisory_lock(%d); SELECT pg_sleep(0.3); INSERT INTO %s (file, path, md5sum) VALUES ('%s', 'upload/%s', '%s'); SELECT pg_advisory_unlock(%d);",
                $key,
                'images',
                $file,
                $file,
                $md5sum,
                $key,
            );
            $cmd = $this->psqlCliCommand();
            $cmd[] = '-c';
            $cmd[] = $sql;
            $env = $this->dbPass !== '' ? array_merge(getenv(), ['PGPASSWORD' => $this->dbPass]) : null;
        } else {
            $sql = sprintf(
                "SELECT GET_LOCK('%s', 5); SELECT SLEEP(0.3); INSERT INTO %s (file, path, md5sum) VALUES ('%s', 'upload/%s', '%s'); SELECT RELEASE_LOCK('%s');",
                $lockName,
                'images',
                $file,
                $file,
                $md5sum,
                $lockName,
            );
            $cmd = $this->mysqlCliCommand();
            $cmd[] = '-e';
            $cmd[] = $sql;
            $env = null;
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
        self::assertIsResource($proc, 'proc_open failed for the background lock-holder process');
        $this->lockHolderPipes = $pipes;

        return $proc;
    }

    /**
     * Part 1 of 3: two concurrent uploads of the SAME value. Confirms the
     * second request genuinely blocks on GET_LOCK() (not an instant,
     * wrong-branch rejection) while the first
     * is in its own check-then-insert window, then correctly detects the
     * row the first inserted once unblocked, returning the clean
     * duplicate error rather than racing past the check and inserting a
     * second row for the same file.
     */
    public function test_add_with_check_uniqueness_blocks_until_a_concurrent_same_value_upload_finishes_then_correctly_detects_the_duplicate(): void
    {
        $md5sum = md5($this->pngBytes() . uniqid('', true));
        $lockName = $this->uploadUniquenessLockName($md5sum);

        $proc = $this->spawnBackgroundLockHolderThatInsertsThenReleases(
            $lockName,
            $md5sum,
            'concurrency-probe-' . uniqid('', true) . '.jpg',
        );

        try {
            // A head start for the background process to actually acquire
            // the lock before our own request reaches it -- GET_LOCK()
            // itself is what serializes correctness here; this is only so
            // the "genuinely waited" timing assertion below is reliable
            // rather than racy against process-startup jitter.
            usleep(50_000);

            $start = microtime(true);
            $response = $this->callWsAllowingServerError('pwg.images.add', [
                'original_sum' => $md5sum,
            ]);
            $elapsed = microtime(true) - $start;

            self::assertSame('fail', $response['stat']);
            self::assertSame(500, $response['err']);
            self::assertSame('file already exists', $response['message']);
            // Proves real blocking occurred, without waiting out
            // GET_LOCK()'s own full 30-second production timeout.
            self::assertGreaterThan(0.15, $elapsed, 'must have genuinely blocked waiting for the other connection\'s lock, not returned instantly');
            self::assertLessThan(10.0, $elapsed, 'must unblock promptly once the other connection releases, not wait anywhere near the full 30s timeout');
        } finally {
            $stdout = $this->lockHolderPipes !== null ? stream_get_contents($this->lockHolderPipes[1]) : '';
            $stderr = $this->lockHolderPipes !== null ? stream_get_contents($this->lockHolderPipes[2]) : '';
            $exit = proc_close($proc);
            self::assertSame(0, $exit, "background lock-holder process failed. stdout=[{$stdout}] stderr=[{$stderr}]");
            $this->conn->executeStatement('DELETE FROM ' . 'images' . ' WHERE md5sum = ?', [$md5sum]);
        }
    }

    /**
     * Part 2 of 3: two concurrent uploads of DIFFERENT values must never
     * block each other -- proves the lock is genuinely scoped per value
     * (via the hashed lock name), not a single global serialization point
     * that would otherwise turn every upload into a queue.
     */
    public function test_add_with_check_uniqueness_for_a_different_value_is_never_blocked_by_an_unrelated_held_lock(): void
    {
        $heldMd5sum = md5(uniqid('', true));
        $heldLockName = $this->uploadUniquenessLockName($heldMd5sum);

        $holderConn = DbConnection::build();
        $acquired = AdvisorySessionLock::acquire($holderConn, $heldLockName, 5);
        self::assertTrue($acquired, 'the unrelated value\'s lock must genuinely be held for this test to prove anything');

        try {
            $sum = md5($this->pngBytes() . uniqid('', true));
            $chunkResponse = $this->callWs('pwg.images.addChunk', [
                'data' => self::TINY_PNG_B64,
                'original_sum' => $sum,
                'type' => 'file',
                'position' => 0,
            ]);
            self::assertSame('ok', $chunkResponse['stat']);

            $start = microtime(true);
            $response = $this->callWs('pwg.images.add', [
                'original_sum' => $sum,
                'original_filename' => 'concurrency-different-value-' . uniqid('', true) . '.png',
                'name' => 'Concurrency different-value test photo',
            ]);
            $elapsed = microtime(true) - $start;

            self::assertSame('ok', $response['stat'], 'a different value\'s lock name must never contend with an unrelated held lock');
            $result = $response['result'];
            self::assertIsArray($result);
            $imageId = $result['image_id'];
            self::assertIsNumeric($imageId);
            $this->createdImageIds[] = (int) $imageId;

            self::assertLessThan(2.0, $elapsed, 'must never wait on a lock scoped to a completely different value');
        } finally {
            AdvisorySessionLock::release($holderConn, $heldLockName);
        }
    }

    /**
     * Part 3 of 3: check_uniqueness=false must never touch the lock at
     * all, even when another request currently holds it for the exact
     * same value -- this is a deliberate, intentional escape hatch, not a
     * gap the lock should close.
     */
    public function test_add_with_check_uniqueness_false_is_never_blocked_even_when_the_same_value_is_locked(): void
    {
        $sum = md5($this->pngBytes() . uniqid('', true));
        $lockName = $this->uploadUniquenessLockName($sum);

        $holderConn = DbConnection::build();
        $acquired = AdvisorySessionLock::acquire($holderConn, $lockName, 5);
        self::assertTrue($acquired, 'the same value\'s lock must genuinely be held for this test to prove anything');

        try {
            $chunkResponse = $this->callWs('pwg.images.addChunk', [
                'data' => self::TINY_PNG_B64,
                'original_sum' => $sum,
                'type' => 'file',
                'position' => 0,
            ]);
            self::assertSame('ok', $chunkResponse['stat']);

            $start = microtime(true);
            $response = $this->callWs('pwg.images.add', [
                'original_sum' => $sum,
                'original_filename' => 'concurrency-false-uniqueness-' . uniqid('', true) . '.png',
                'name' => 'Concurrency check_uniqueness=false test photo',
                'check_uniqueness' => false,
            ]);
            $elapsed = microtime(true) - $start;

            self::assertSame('ok', $response['stat'], 'check_uniqueness=false must never touch the lock at all, regardless of contention on the same value');
            $result = $response['result'];
            self::assertIsArray($result);
            $imageId = $result['image_id'];
            self::assertIsNumeric($imageId);
            $this->createdImageIds[] = (int) $imageId;

            self::assertLessThan(2.0, $elapsed, 'check_uniqueness=false must never block on another request\'s held lock');
        } finally {
            AdvisorySessionLock::release($holderConn, $lockName);
        }
    }
}

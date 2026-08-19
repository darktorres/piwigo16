<?php

declare(strict_types=1);

use Piwigo\Controller\Api\Uploads\TusUploadSession;
use Piwigo\Controller\Api\Uploads\TusUploadStore;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;

/**
 * Piwigo\Controller\Api\Uploads\TusUploadStore -- the filesystem-backed
 * buffer for in-progress tus uploads. Had zero dedicated coverage before
 * this file; written alongside the fix for a real gap it exposed:
 * appendChunk() used to accept and persist PATCH bodies past the
 * upload's own declared Upload-Length with no rejection at all.
 */
function tusUploadStoreTestSubject(): TusUploadStore
{
    return new TusUploadStore(CurrentPathsTestFactory::get(), CurrentConfigTestFactory::get());
}

/**
 * @param array<string, string> $metadata
 */
function tusUploadStoreTestCreate(TusUploadStore $store, int $uploadLength, int $userId, array $metadata = [
    'filename' => 'a.jpg',
]): TusUploadSession
{
    $session = $store->create($uploadLength, $userId, $metadata);
    if (! $session instanceof TusUploadSession) {
        throw new RuntimeException('TusUploadStore::create() unexpectedly returned null');
    }

    return $session;
}

/**
 * @return resource
 */
function tusUploadStoreTestStream(string $contents)
{
    $stream = fopen('php://memory', 'r+b');
    if ($stream === false) {
        throw new RuntimeException('fopen failed');
    }
    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

function tusUploadStoreTestRrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        is_dir($path) ? tusUploadStoreTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-tus-upload-store-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    $this->root = $root;
});

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
    tusUploadStoreTestRrmdir($this->root);
});

test('create writes an empty data file and a json sidecar, and returns a matching session', function (): void {
    $store = tusUploadStoreTestSubject();

    $session = tusUploadStoreTestCreate($store, 100, 7, [
        'filename' => 'photo.jpg',
    ]);

    expect($session->uploadLength)
        ->toBe(100)
        ->and($session->createdByUserId)
        ->toBe(7)
        ->and($session->filename)
        ->toBe('photo.jpg')
        ->and(preg_match('/^[a-f0-9]{32}$/', $session->id))
        ->toBe(1);

    $dataPath = $this->root . 'upload/buffer/tus/' . $session->id . '.data';
    $metaPath = $this->root . 'upload/buffer/tus/' . $session->id . '.json';
    expect(file_exists($dataPath))
        ->toBeTrue()
        ->and(file_get_contents($dataPath))
        ->toBe('')
        ->and(file_exists($metaPath))
        ->toBeTrue();
});

test('find round-trips a real created session', function (): void {
    $store = tusUploadStoreTestSubject();
    $created = tusUploadStoreTestCreate($store, 50, 3, [
        'filename' => 'a.jpg',
        'category' => '5,6',
    ]);

    $found = $store->find($created->id);

    expect($found)
        ->toBeInstanceOf(TusUploadSession::class);
    assert($found instanceof TusUploadSession);
    expect($found->id)
        ->toBe($created->id)
        ->and($found->uploadLength)
        ->toBe(50)
        ->and($found->categoryIds)
        ->toBe([5, 6]);
});

test('find returns null for a malformed id, without touching the filesystem outside the buffer dir', function (): void {
    $store = tusUploadStoreTestSubject();

    expect($store->find('../../../../etc/passwd'))
        ->toBeNull()
        ->and($store->find('not-32-hex-chars'))
        ->toBeNull()
        ->and($store->find(''))
        ->toBeNull();
});

test('find returns null when no sidecar file exists for an otherwise well-formed id', function (): void {
    // find()'s own file_get_contents() call is @-suppressed in the
    // source, but PHPUnit's error handler still surfaces the missing-file
    // E_WARNING regardless of `@` -- absorb it with a scoped handler
    // rather than letting failOnWarning turn it into a failure, same
    // convention DerivativeCacheServiceTest.php already uses.
    $store = tusUploadStoreTestSubject();

    set_error_handler(static fn (): bool => true);
    try {
        $result = $store->find(str_repeat('a', 32));
    } finally {
        restore_error_handler();
    }

    expect($result)
        ->toBeNull();
});

test('find returns null when the sidecar file holds corrupt (non-array) json', function (): void {
    $store = tusUploadStoreTestSubject();
    $id = str_repeat('b', 32);
    $dir = $this->root . 'upload/buffer/tus';
    mkdir($dir, 0o777, true);
    file_put_contents($dir . '/' . $id . '.json', '"just a string"');

    expect($store->find($id))
        ->toBeNull();
});

test('findOwnedBy returns null when the session belongs to a different user', function (): void {
    $store = tusUploadStoreTestSubject();
    $session = tusUploadStoreTestCreate($store, 10, 1);

    expect($store->findOwnedBy($session->id, 2))
        ->toBeNull()
        ->and($store->findOwnedBy($session->id, 1))
        ->toBeInstanceOf(TusUploadSession::class);
});

test('offset returns null for a malformed id and for a missing data file', function (): void {
    $store = tusUploadStoreTestSubject();

    expect($store->offset('not-valid'))
        ->toBeNull();

    // offset()'s own filesize() call is @-suppressed in the source, but
    // PHPUnit's error handler still surfaces the missing-file E_WARNING
    // regardless of `@` -- same scoped-handler convention as the find()
    // test above.
    set_error_handler(static fn (): bool => true);
    try {
        $result = $store->offset(str_repeat('c', 32));
    } finally {
        restore_error_handler();
    }

    expect($result)
        ->toBeNull();
});

test('offset reports the real data file size', function (): void {
    $store = tusUploadStoreTestSubject();
    $session = tusUploadStoreTestCreate($store, 20, 1);

    $stream = tusUploadStoreTestStream('0123456789');
    $store->appendChunk($session->id, 0, $stream, 20);
    fclose($stream);

    expect($store->offset($session->id))
        ->toBe(10);
});

test('appendChunk returns null for a malformed id or a missing upload', function (): void {
    $store = tusUploadStoreTestSubject();
    $stream = tusUploadStoreTestStream('x');

    expect($store->appendChunk('not-valid', 0, $stream, 10))
        ->toBeNull()
        ->and($store->appendChunk(str_repeat('d', 32), 0, $stream, 10))
        ->toBeNull();

    fclose($stream);
});

test('appendChunk rejects a mismatched expectedOffset, leaving the file untouched', function (): void {
    $store = tusUploadStoreTestSubject();
    $session = tusUploadStoreTestCreate($store, 20, 1);

    $stream = tusUploadStoreTestStream('hello');
    $result = $store->appendChunk($session->id, 5, $stream, 20);
    fclose($stream);

    expect($result)
        ->toBeNull()
        ->and($store->offset($session->id))
        ->toBe(0);
});

test('appendChunk writes a chunk smaller than uploadLength and returns the new offset', function (): void {
    $store = tusUploadStoreTestSubject();
    $session = tusUploadStoreTestCreate($store, 20, 1);

    $stream = tusUploadStoreTestStream('hello');
    $result = $store->appendChunk($session->id, 0, $stream, 20);
    fclose($stream);

    expect($result)
        ->toBe(5)
        ->and($store->offset($session->id))
        ->toBe(5);
});

test('appendChunk writes a second chunk continuing from a nonzero offset', function (): void {
    $store = tusUploadStoreTestSubject();
    $session = tusUploadStoreTestCreate($store, 10, 1);

    $first = tusUploadStoreTestStream('hello');
    $store->appendChunk($session->id, 0, $first, 10);
    fclose($first);

    $second = tusUploadStoreTestStream('world');
    $result = $store->appendChunk($session->id, 5, $second, 10);
    fclose($second);

    $dataPath = $this->root . 'upload/buffer/tus/' . $session->id . '.data';
    expect($result)
        ->toBe(10)
        ->and(file_get_contents($dataPath))
        ->toBe('helloworld');
});

test('appendChunk rejects a chunk that would exceed uploadLength, without leaving the file past it', function (): void {
    // Regression test for a real gap: appendChunk() used to write every
    // byte the stream offered with no upper bound at all, so a PATCH body
    // longer than the upload's own declared Upload-Length silently grew
    // the file past it instead of being rejected.
    $store = tusUploadStoreTestSubject();
    $session = tusUploadStoreTestCreate($store, 5, 1);

    $stream = tusUploadStoreTestStream('too-long-body');
    $result = $store->appendChunk($session->id, 0, $stream, 5);
    fclose($stream);

    expect($result)
        ->toBeNull()
        ->and($store->offset($session->id))
        ->toBeLessThanOrEqual(5);
});

test('appendChunk rejects an overshooting chunk continuing from a nonzero offset', function (): void {
    $store = tusUploadStoreTestSubject();
    $session = tusUploadStoreTestCreate($store, 10, 1);

    $first = tusUploadStoreTestStream('hello');
    $store->appendChunk($session->id, 0, $first, 10);
    fclose($first);

    $second = tusUploadStoreTestStream('too-many-bytes');
    $result = $store->appendChunk($session->id, 5, $second, 10);
    fclose($second);

    expect($result)
        ->toBeNull()
        ->and($store->offset($session->id))
        ->toBeLessThanOrEqual(10);
});

test('appendChunk accepts a chunk that lands exactly on uploadLength', function (): void {
    $store = tusUploadStoreTestSubject();
    $session = tusUploadStoreTestCreate($store, 5, 1);

    $stream = tusUploadStoreTestStream('hello');
    $result = $store->appendChunk($session->id, 0, $stream, 5);
    fclose($stream);

    expect($result)
        ->toBe(5);
});

test('delete removes both the data and sidecar files', function (): void {
    $store = tusUploadStoreTestSubject();
    $session = tusUploadStoreTestCreate($store, 10, 1);
    $dataPath = $this->root . 'upload/buffer/tus/' . $session->id . '.data';
    $metaPath = $this->root . 'upload/buffer/tus/' . $session->id . '.json';
    expect(file_exists($dataPath))
        ->toBeTrue();

    $store->delete($session->id);

    expect(file_exists($dataPath))
        ->toBeFalse()
        ->and(file_exists($metaPath))
        ->toBeFalse();
});

test('delete is a silent no-op for a malformed id, without throwing', function (): void {
    $store = tusUploadStoreTestSubject();

    $store->delete('../../../../etc/passwd');
    $store->delete('not-valid');

    expect($store->find('not-valid'))
        ->toBeNull();
});

test('dataFilePath returns the same path appendChunk writes to', function (): void {
    $store = tusUploadStoreTestSubject();
    $session = tusUploadStoreTestCreate($store, 10, 1);

    expect($store->dataFilePath($session->id))
        ->toBe($this->root . CurrentConfigTestFactory::get()->uploadDir . '/buffer/tus/' . $session->id . '.data');
});

<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image;

use Piwigo\Config\CurrentConfig;
use Piwigo\Db\DbConnection;
use Piwigo\Event\Album\EmptyLounge;
use Piwigo\Event\Picture\BeginDeleteElements;
use Piwigo\Event\Picture\DeleteElements;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use RuntimeException;

beforeEach(function (): void {
    CurrentConfig::setSlideshowPeriod(4);
    CurrentConfig::setSlideshowPeriodMin(1);
    CurrentConfig::setSlideshowPeriodMax(10);
    CurrentConfig::setSlideshowRepeat(true);
});

afterEach(function (): void {
    CurrentConfig::reset();
});

test('getDefaultSlideshowParams reads conf', function (): void {
    $params = new ImageService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Image\ImageEntity::class), new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)))->getDefaultSlideshowParams();

    expect($params['period'])->toBe(4)
        ->and($params['repeat'])->toBeTrue()
        ->and($params['play'])->toBeTrue();
});

test('correctSlideshowParams clamps below the minimum', function (): void {
    $corrected = new ImageService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Image\ImageEntity::class), new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)))->correctSlideshowParams(['period' => 0]);

    expect($corrected['period'])->toBe(1);
});

test('correctSlideshowParams clamps above the maximum', function (): void {
    $corrected = new ImageService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Image\ImageEntity::class), new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)))->correctSlideshowParams(['period' => 99]);

    expect($corrected['period'])->toBe(10);
});

test('correctSlideshowParams leaves an in-range value untouched', function (): void {
    $corrected = new ImageService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Image\ImageEntity::class), new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)))->correctSlideshowParams(['period' => 5]);

    expect($corrected['period'])->toBe(5);
});

test('decodeSlideshowParams with a numeric string sets period', function (): void {
    $decoded = new ImageService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Image\ImageEntity::class), new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)))->decodeSlideshowParams('7');

    expect($decoded['period'])->toBe('7');
});

test('decodeSlideshowParams parses key-value tokens', function (): void {
    $decoded = new ImageService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Image\ImageEntity::class), new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)))->decodeSlideshowParams('period-6+repeat-false');

    expect($decoded['period'])->toBe('6')
        ->and($decoded['repeat'])->toBeFalse();
});

test('decodeSlideshowParams with null input returns the defaults', function (): void {
    $decoded = new ImageService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Image\ImageEntity::class), new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)))->decodeSlideshowParams(null);

    expect($decoded['period'])->toBe(4)
        ->and($decoded['repeat'])->toBeTrue()
        ->and($decoded['play'])->toBeTrue();
});

test('decodeSlideshowParams clamps an out-of-range period', function (): void {
    $decoded = new ImageService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Image\ImageEntity::class), new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)))->decodeSlideshowParams('period-99');

    expect($decoded['period'])->toBe(10);
});

test('encodeSlideshowParams round-trips a non-default period', function (): void {
    $service = new ImageService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Image\ImageEntity::class), new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)));
    $encoded = $service->encodeSlideshowParams(['period' => 6, 'repeat' => true, 'play' => true]);

    expect($encoded)->toBe('+period-6');

    $decoded = $service->decodeSlideshowParams($encoded);
    expect($decoded['period'])->toBe('6');
});

test('encodeSlideshowParams omits default values', function (): void {
    $service = new ImageService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Image\ImageEntity::class), new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)));

    expect($service->encodeSlideshowParams($service->getDefaultSlideshowParams()))->toBe('');
});

test('encodeSlideshowParams encodes a changed boolean', function (): void {
    $encoded = new ImageService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Image\ImageEntity::class), new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)))->encodeSlideshowParams(['period' => 4, 'repeat' => false, 'play' => true]);

    expect($encoded)->toBe('+repeat-false');
});

test('correctSlideshowParams defaults an absent period to 0, not 1, before clamping to the configured minimum', function (): void {
    // Kills line 91's IncrementInteger (`?? 1` instead of `?? 0`) --
    // beforeEach's min=1 makes 0 and 1 disagree: with the real `?? 0`
    // default, 0 < 1 clamps 'period' up to 1 (a real, observable key).
    // With the mutant's `?? 1` default, 1 is not < the min(1) and not >
    // the max(10) either, so neither branch fires and 'period' is left
    // absent from the returned array entirely, not just numerically
    // different.
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $corrected = imageServiceTestNewService($repo, $conn)->correctSlideshowParams([]);

    expect($corrected)->toHaveKey('period');
    expect($corrected['period'])->toBe(1);
});

test('correctSlideshowParams defaults an absent period to 0, not -1, before clamping -- observable only against a minimum of exactly 0', function (): void {
    // Kills line 91's DecrementInteger (`?? -1` instead of `?? 0`).
    // Against the default min=1, both 0 and -1 are < 1 and clamp to the
    // SAME value (1) either way -- provably indistinguishable there. A
    // min of exactly 0 is what separates them: the real `?? 0` default
    // is NOT < 0 and not > the max either, so 'period' is left absent;
    // the mutant's `?? -1` default IS < 0, so it clamps 'period' up to
    // 0, a real, observable key the real code never adds here.
    CurrentConfig::setSlideshowPeriodMin(0);
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $corrected = imageServiceTestNewService($repo, $conn)->correctSlideshowParams([]);

    expect($corrected)->not->toHaveKey('period');
});

test('correctSlideshowParams treats a period exactly equal to the minimum as already in range, not clamped', function (): void {
    // Kills line 95's SmallerToSmallerOrEqual (`<= $min` instead of
    // `<`). A period passed in as a numeric STRING equal to the min:
    // real code's strict `<` never enters the clamp branch, so 'period'
    // survives unchanged as the original string; the mutant's `<=`
    // enters it and overwrites 'period' with the int $min -- same
    // numeric value, but a different TYPE, which is what this test
    // actually distinguishes (PHP's numeric-string comparison makes
    // both operators agree on the boolean *value* here, so only the
    // resulting type tells them apart).
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $corrected = imageServiceTestNewService($repo, $conn)->correctSlideshowParams(['period' => '1']);

    expect($corrected['period'])->toBe('1');
});

test('correctSlideshowParams treats a period exactly equal to the maximum as already in range, not clamped', function (): void {
    // Kills line 97's GreaterToGreaterOrEqual, same type-preservation
    // technique as the sibling test above.
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $corrected = imageServiceTestNewService($repo, $conn)->correctSlideshowParams(['period' => '10']);

    expect($corrected['period'])->toBe('10');
});

/**
 * Confirmed-equivalent: line 115's and line 122's RemoveBooleanCast
 * (`if (preg_match_all(...))` instead of `if ((bool) preg_match_all(...))`).
 * An `if()` condition already coerces its operand to bool on its own --
 * `if ((bool) X)` and `if (X)` evaluate identically for every possible
 * X, a universal PHP semantics fact, not something specific to
 * preg_match_all()'s own return shape (int count, or false on a regex
 * engine error). Live sed-verified both against the full suite too.
 *
 * Also confirmed-equivalent: line 116's and line 123's DecrementInteger/
 * IncrementInteger (`count($matches[0])`/`count($matches[2])` instead of
 * `count($matches[1])`). preg_match_all() in its default
 * PREG_PATTERN_ORDER mode always returns arrays where EVERY capture
 * group index (0 = whole match, 1 = first group, 2 = second group, ...)
 * has exactly the same length -- one entry per overall match, with
 * unmatched *optional* groups filled by an empty-string placeholder at
 * the same index, never a shorter array. Both patterns here
 * (`([a-z]+)-(\d+)` and `([a-z]+)-(true|false)`) have their groups
 * required, not optional, but the length-parity guarantee holds
 * regardless -- count($matches[0]) === count($matches[1]) ===
 * count($matches[2]) always, for every possible input string. Live
 * sed-verified a representative one (line 116's DecrementInteger)
 * against the full suite too.
 */
test('decodeSlideshowParams parses more than one key-value token from the same string', function (): void {
    $decoded = new ImageService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Image\ImageEntity::class), new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)))->decodeSlideshowParams('period-6+repeat-false+play-false');

    expect($decoded['period'])->toBe('6')
        ->and($decoded['repeat'])->toBeFalse()
        ->and($decoded['play'])->toBeFalse();
});

test('encodeSlideshowParams accumulates every changed param into one string, not just the last one', function (): void {
    // Kills line 155's ConcatEqualToEqual (`$result = ...` instead of
    // `$result .= ...`) -- the existing round-trip test above only ever
    // has ONE param differing from the default, where `=` and `.=`
    // coincide. Two differing params force a second loop iteration:
    // `.=` (real) keeps both; `=` (mutant) overwrites the first with
    // the second.
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $encoded = imageServiceTestNewService($repo, $conn)->encodeSlideshowParams(['period' => 7, 'repeat' => false, 'play' => true]);

    expect($encoded)->toBe('+period-7+repeat-false');
});

/**
 * Confirmed-equivalent: line 142's UnwrapArrayFilter
 * (`$corrected = $this->correctSlideshowParams($decodeParams);` instead
 * of the array_filter(..., is_scalar(...))-wrapped version). Every
 * value this loop's own `if (! is_scalar($value)) { continue; }` guard
 * (a few lines below, unaffected by this mutation) already re-checks
 * per-entry is_scalar() before the value is ever used -- so removing
 * the upstream array_filter() cannot smuggle a non-scalar value into
 * the final $result string either way; it is caught by the downstream
 * guard instead. Live sed-verified with a genuinely non-scalar
 * $decodeParams entry (an array value) against the full suite: passes
 * identically with or without the filter.
 *
 * Also confirmed-equivalent: line 143's UnwrapArrayFilter (same
 * mutation, applied to $defaults instead of $corrected). $defaults
 * comes exclusively from getDefaultSlideshowParams(), whose own fixed
 * 3-key shape (period: int, repeat: bool, play: bool) is always 100%
 * scalar by construction -- array_filter(..., is_scalar(...)) over an
 * already-all-scalar array is a structural no-op, for every possible
 * config state, not just a tested case. Live sed-verified too.
 *
 * Also confirmed-equivalent: line 155's RemoveStringCast (`. $value`
 * instead of `. (string) $value`). PHP's `.` (concatenation) operator
 * always coerces a non-string operand to string on its own, identically
 * to an explicit `(string)` cast -- by the time this line runs, $value
 * is guaranteed scalar (the loop's own is_scalar() guard above) and
 * never still a raw bool (booleanToString() already turned any bool
 * into a 'true'/'false' string a few lines above), so it is always an
 * int, float, or string here, and `.` string-coerces all three exactly
 * like `(string)` would. Live sed-verified too.
 */
test('encodeSlideshowParams treats a non-scalar caller-supplied value as unrepresentable and skips it, rather than encoding it', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $encoded = imageServiceTestNewService($repo, $conn)->encodeSlideshowParams(['period' => 4, 'repeat' => true, 'play' => true, 'extra' => ['nested']]);

    expect($encoded)->toBe('');
});

// encodeSlideshowParams()'s own `if (! is_scalar($value)) { continue; }`
// guard (right after `SqlDialect::booleanToString($value)`) is confirmed
// dead code, not merely untested: $params only ever comes from
// array_diff_assoc() of two array_filter(..., is_scalar(...)) results, so
// every $value entering that loop is already guaranteed scalar before
// booleanToString() ever runs (which itself only turns a bool into a
// string, or passes any other scalar through unchanged) -- traced the
// only two real callers (decodeSlideshowParams()'s own regex-matched
// values, and a caller-supplied array) and confirmed neither can smuggle
// a non-scalar past that upstream array_filter(). Not chased with a
// reflection-forced non-scalar value, since that would cover behavior no
// real call site can trigger.

test('countPdfPages counts page markers', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'pwg-pdf-test');
    expect($tmp)->toBeString();
    if ($tmp === false) {
        return;
    }
    file_put_contents($tmp, "%PDF-1.4\n1 0 obj\n<< /Type /Page >>\nendobj\n2 0 obj\n<< /Type /Page >>\nendobj\n");

    expect(new ImageService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Image\ImageEntity::class), new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)))->countPdfPages($tmp))->toBe(2);

    unlink($tmp);
});

test('countPdfPages returns false for a missing file', function (): void {
    expect(new ImageService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Image\ImageEntity::class), new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)))->countPdfPages('/no/such/file.pdf'))->toBeFalse();
});

test('countPdfPages returns false when the path is readable but reading it produces no content (not a regular file)', function (): void {
    // is_readable() is true for a Unix domain socket special file, but
    // countPdfPages() also guards with is_file() before calling
    // file_get_contents() -- confirmed live that file_get_contents() on a
    // socket path raises a real PHP warning ("Failed to open stream: No
    // such device or address"), not a clean false, so the is_file() guard
    // is what actually keeps this deterministic and warning-free.

    $sockPath = sys_get_temp_dir() . '/pwg-countpdfpages-test-' . bin2hex(random_bytes(8)) . '.sock';
    $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
    if ($socket === false) {
        throw new RuntimeException('socket_create failed');
    }
    socket_bind($socket, $sockPath);

    $service = new ImageService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Image\ImageEntity::class), new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)));

    try {
        expect($service->countPdfPages($sockPath))->toBeFalse();
    } finally {
        socket_close($socket);
        @unlink($sockPath);
    }
});

function imageServiceTestRrmdir(string $dir): void
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
        is_dir($path) ? imageServiceTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

/**
 * @return array{0: \Doctrine\DBAL\Connection, 1: ImageRepository}
 */
function imageServiceTestConnAndRepo(): array
{
    $conn = DbConnection::build();
    $repo = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Image\ImageEntity::class);
    expect($repo)->toBeInstanceOf(ImageRepository::class);

    return [$conn, $repo];
}

function imageServiceTestNewService(ImageRepository $repo, \Doctrine\DBAL\Connection $conn): ImageService
{
    return new ImageService($repo, new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Activity\ActivityEntity::class)));
}

/**
 * Inserts a throwaway `images` row (real schema columns only: id is
 * auto-increment, so there's no risk of colliding with the fixture's own
 * ids 1-5) and returns its id.
 */
function imageServiceTestInsertImage(\Doctrine\DBAL\Connection $conn, string $path, ?string $representativeExt = null): int
{
    $conn->createQueryBuilder()
        ->insert(\Piwigo\Db\Tables::images())
        ->values([
            'file' => ':file',
            'path' => ':path',
            'representative_ext' => ':representativeExt',
        ])
        ->setParameter('file', basename($path))
        ->setParameter('path', $path)
        ->setParameter('representativeExt', $representativeExt)
        ->executeStatement();

    return (int) $conn->lastInsertId();
}

test('deleteElementFiles deletes the original, its representative, and its formats for local rows; skips remote rows without touching disk', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $root = sys_get_temp_dir() . '/piwigo-imageservice-test-' . bin2hex(random_bytes(8));
    \Piwigo\Core\CurrentPaths::set(\Piwigo\Core\Paths::fromRoot($root));
    CurrentConfig::setNeverDeleteOriginals(false);

    mkdir($root . '/upload/2026/07/pwg_representative', 0o777, true);
    mkdir($root . '/upload/2026/07/pwg_format', 0o777, true);

    // Plain original, no representative/formats.
    $plainId = imageServiceTestInsertImage($conn, 'upload/2026/07/plain.jpg');
    file_put_contents($root . '/upload/2026/07/plain.jpg', 'x');

    // Original + representative (a non-picture original, e.g. a PDF).
    $repId = imageServiceTestInsertImage($conn, 'upload/2026/07/doc.pdf', 'jpg');
    file_put_contents($root . '/upload/2026/07/doc.pdf', 'x');
    file_put_contents($root . '/upload/2026/07/pwg_representative/doc.jpg', 'x');

    // Original + a registered format (e.g. a generated webp).
    $formatId = imageServiceTestInsertImage($conn, 'upload/2026/07/withformat.jpg');
    file_put_contents($root . '/upload/2026/07/withformat.jpg', 'x');
    file_put_contents($root . '/upload/2026/07/pwg_format/withformat.webp', 'x');
    $conn->createQueryBuilder()
        ->insert(\Piwigo\Db\Tables::imageFormat())
        ->values(['image_id' => ':imageId', 'ext' => ':ext'])
        ->setParameter('imageId', $formatId)
        ->setParameter('ext', 'webp')
        ->executeStatement();

    // Remote row -- deleteElementFiles() must skip it (`continue`)
    // without ever touching CurrentPaths/disk for it.
    $remoteId = imageServiceTestInsertImage($conn, 'https://remote.example.test/remote.jpg');

    try {
        $service = imageServiceTestNewService($repo, $conn);
        $urlService = new ImageServiceTestFakeUrlService();

        $result = $service->deleteElementFiles([$plainId, $remoteId, $repId, $formatId], $urlService);

        // findPathsForFileDeletion() has no ORDER BY, so this doesn't
        // assert on iteration order -- only that the remote id was
        // skipped (`continue`) while every local id was still fully
        // processed (not affected by the *other* test below, which
        // proves a local failure `break`s the loop instead).
        $sortedResult = $result;
        sort($sortedResult);
        $expectedIds = [$plainId, $repId, $formatId];
        sort($expectedIds);
        expect($sortedResult)->toBe($expectedIds);
        expect(file_exists($root . '/upload/2026/07/plain.jpg'))->toBeFalse();
        expect(file_exists($root . '/upload/2026/07/doc.pdf'))->toBeFalse();
        expect(file_exists($root . '/upload/2026/07/pwg_representative/doc.jpg'))->toBeFalse();
        expect(file_exists($root . '/upload/2026/07/withformat.jpg'))->toBeFalse();
        expect(file_exists($root . '/upload/2026/07/pwg_format/withformat.webp'))->toBeFalse();
    } finally {
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id IN (?, ?, ?, ?)', [$plainId, $repId, $formatId, $remoteId]);
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::imageFormat() . ' WHERE image_id = ?', [$formatId]);
        imageServiceTestRrmdir($root);
        \Piwigo\Core\CurrentPaths::reset();
        CurrentConfig::reset();
    }
});

test('deleteElementFiles accumulates every registered format for an id, not just the last one seen', function (): void {
    // Kills line 198's IfNegated/RemoveNot (`if (isset($formatsOf[...]))`
    // instead of `if (! isset($formatsOf[...]))`) -- the sibling test
    // above only ever gives one image a single format row, where a
    // wrongly re-triggered `$formatsOf[$id] = []` reset is
    // unobservable (there was nothing to wipe yet). Two format rows for
    // the SAME image id force a second loop iteration that would wipe
    // the first format back to [] under the mutant, losing it.
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $root = sys_get_temp_dir() . '/piwigo-imageservice-test-' . bin2hex(random_bytes(8));
    \Piwigo\Core\CurrentPaths::set(\Piwigo\Core\Paths::fromRoot($root));
    CurrentConfig::setNeverDeleteOriginals(false);

    mkdir($root . '/upload/2026/07/pwg_format', 0o777, true);
    $imageId = imageServiceTestInsertImage($conn, 'upload/2026/07/multiformat.jpg');
    file_put_contents($root . '/upload/2026/07/multiformat.jpg', 'x');
    file_put_contents($root . '/upload/2026/07/pwg_format/multiformat.webp', 'x');
    file_put_contents($root . '/upload/2026/07/pwg_format/multiformat.avif', 'x');
    $conn->createQueryBuilder()->insert(\Piwigo\Db\Tables::imageFormat())
        ->values(['image_id' => ':i', 'ext' => ':e'])->setParameter('i', $imageId)->setParameter('e', 'webp')
        ->executeStatement();
    $conn->createQueryBuilder()->insert(\Piwigo\Db\Tables::imageFormat())
        ->values(['image_id' => ':i', 'ext' => ':e'])->setParameter('i', $imageId)->setParameter('e', 'avif')
        ->executeStatement();

    try {
        $service = imageServiceTestNewService($repo, $conn);
        $urlService = new ImageServiceTestFakeUrlService();

        $result = $service->deleteElementFiles([$imageId], $urlService);

        expect($result)->toBe([$imageId]);
        expect(file_exists($root . '/upload/2026/07/pwg_format/multiformat.webp'))->toBeFalse();
        expect(file_exists($root . '/upload/2026/07/pwg_format/multiformat.avif'))->toBeFalse();
    } finally {
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::imageFormat() . ' WHERE image_id = ?', [$imageId]);
        imageServiceTestRrmdir($root);
        \Piwigo\Core\CurrentPaths::reset();
        CurrentConfig::reset();
    }
});

test('deleteElementFiles skips a remote row with `continue`, not `break` -- a local row after it in the same call is still processed', function (): void {
    // Kills line 211's ContinueToBreak. findPathsForFileDeletion() has
    // no ORDER BY, but a real WHERE id IN (a, b) lookup on an InnoDB
    // PRIMARY KEY is confirmed (live-probed) to walk the clustered index
    // in ascending id order in practice -- explicit ids are chosen here
    // (remote < local) specifically so the remote row is genuinely
    // visited FIRST, making `continue` vs `break` observable via
    // whether the local row after it still gets processed.
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $root = sys_get_temp_dir() . '/piwigo-imageservice-test-' . bin2hex(random_bytes(8));
    \Piwigo\Core\CurrentPaths::set(\Piwigo\Core\Paths::fromRoot($root));
    CurrentConfig::setNeverDeleteOriginals(false);

    mkdir($root . '/upload/2026/07', 0o777, true);
    file_put_contents($root . '/upload/2026/07/afterremote.jpg', 'x');

    $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id IN (760001, 760002)');
    $conn->executeStatement('INSERT INTO ' . \Piwigo\Db\Tables::images() . ' (id, file, path) VALUES (760001, ?, ?)', ['remote.jpg', 'https://remote.example.test/remote.jpg']);
    $conn->executeStatement('INSERT INTO ' . \Piwigo\Db\Tables::images() . ' (id, file, path) VALUES (760002, ?, ?)', ['afterremote.jpg', 'upload/2026/07/afterremote.jpg']);

    try {
        $service = imageServiceTestNewService($repo, $conn);
        $urlService = new ImageServiceTestFakeUrlService();

        $result = $service->deleteElementFiles([760001, 760002], $urlService);

        expect($result)->toBe([760002]);
        expect(file_exists($root . '/upload/2026/07/afterremote.jpg'))->toBeFalse();
    } finally {
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id IN (760001, 760002)');
        imageServiceTestRrmdir($root);
        \Piwigo\Core\CurrentPaths::reset();
        CurrentConfig::reset();
    }
});

test('deleteElementFiles treats an explicitly-empty-string representative_ext the same as none at all', function (): void {
    // Kills line 215's BooleanAndToBooleanOr and EmptyStringToNotEmpty
    // together -- both mutations let a representative_ext of '' survive
    // the guard as non-null, instead of being normalized to null. A
    // decoy file at the exact garbled path an empty-string ext would
    // produce (originalToRepresentative() with a '' ext leaves a
    // trailing dot and no extension) proves whether that extra
    // files[]-entry / unlink attempt genuinely happened.
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $root = sys_get_temp_dir() . '/piwigo-imageservice-test-' . bin2hex(random_bytes(8));
    \Piwigo\Core\CurrentPaths::set(\Piwigo\Core\Paths::fromRoot($root));
    CurrentConfig::setNeverDeleteOriginals(false);

    mkdir($root . '/upload/2026/07/pwg_representative', 0o777, true);
    $imageId = imageServiceTestInsertImage($conn, 'upload/2026/07/emptyrepext.jpg', '');
    file_put_contents($root . '/upload/2026/07/emptyrepext.jpg', 'x');
    // originalToRepresentative('upload/2026/07/emptyrepext.jpg', '') --
    // substr_replace() at the last '.' with a 0-length replacement 'ext'
    // leaves the dot itself in place, with nothing after it.
    $decoyPath = $root . '/upload/2026/07/pwg_representative/emptyrepext.';
    file_put_contents($decoyPath, 'x');

    try {
        $service = imageServiceTestNewService($repo, $conn);
        $urlService = new ImageServiceTestFakeUrlService();

        $result = $service->deleteElementFiles([$imageId], $urlService);

        expect($result)->toBe([$imageId]);
        expect(file_exists($root . '/upload/2026/07/emptyrepext.jpg'))->toBeFalse();
        expect(file_exists($decoyPath))->toBeTrue();
    } finally {
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ?', [$imageId]);
        imageServiceTestRrmdir($root);
        \Piwigo\Core\CurrentPaths::reset();
        CurrentConfig::reset();
    }
});

test('deleteElementFiles stops removing a row\'s remaining files (`break`, not `continue`) once one of them fails, and reports its exact trigger_error() message', function (): void {
    // Kills line 235's RemoveFunctionCall and all 6 Concat* mutations
    // (exact message content) together with line 236's BreakToContinue
    // (a representative file AFTER the failing original in the same
    // row's own $files list is left untouched, not also removed).
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $root = sys_get_temp_dir() . '/piwigo-imageservice-test-' . bin2hex(random_bytes(8));
    \Piwigo\Core\CurrentPaths::set(\Piwigo\Core\Paths::fromRoot($root));
    CurrentConfig::setNeverDeleteOriginals(false);

    mkdir($root . '/upload/2026/07/lockedwithrep/pwg_representative', 0o777, true);
    $imageId = imageServiceTestInsertImage($conn, 'upload/2026/07/lockedwithrep/original.jpg', 'jpg');
    $originalPath = $root . '/upload/2026/07/lockedwithrep/original.jpg';
    $representativePath = $root . '/upload/2026/07/lockedwithrep/pwg_representative/original.jpg';
    file_put_contents($originalPath, 'x');
    file_put_contents($representativePath, 'x');
    // Deny write on the ORIGINAL's own containing directory only --
    // pwg_representative/ is a distinct directory with its own
    // permission bits, still writable.
    chmod($root . '/upload/2026/07/lockedwithrep', 0o555);

    $capturedLevel = null;
    $capturedMessage = null;
    set_error_handler(static function (int $errno, string $errstr) use (&$capturedLevel, &$capturedMessage): bool {
        $capturedLevel = $errno;
        $capturedMessage = $errstr;

        return true;
    });

    try {
        $service = imageServiceTestNewService($repo, $conn);
        $urlService = new ImageServiceTestFakeUrlService();

        $result = $service->deleteElementFiles([$imageId], $urlService);

        expect($result)->toBe([]);
        expect($capturedLevel)->toBe(E_USER_WARNING);
        expect($capturedMessage)->toBe('"' . $originalPath . '" cannot be removed');
        expect(file_exists($originalPath))->toBeTrue();
        expect(file_exists($representativePath))->toBeTrue();
    } finally {
        restore_error_handler();
        chmod($root . '/upload/2026/07/lockedwithrep', 0o755);
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ?', [$imageId]);
        imageServiceTestRrmdir($root);
        \Piwigo\Core\CurrentPaths::reset();
        CurrentConfig::reset();
    }
});

test('deleteElementFiles adds representative_ext to the derivative-cache lookup only when the row genuinely has one, and always calls deleteElementDerivatives()', function (): void {
    // Kills line 245's IfNegated/NotIdenticalToIdentical (whether
    // 'representative_ext' is added to $derivativeInfos at all) and
    // line 248's RemoveMethodCall (whether deleteElementDerivatives()
    // runs at all) together. Two decoy derivative-cache files are
    // planted: one at the path deleteElementDerivatives() targets WHEN
    // representative_ext is present (the pwg_representative-rewritten
    // pattern), one at the path it targets when absent (the plain
    // pattern) -- real code must remove exactly the first and leave the
    // second untouched.
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $root = sys_get_temp_dir() . '/piwigo-imageservice-test-' . bin2hex(random_bytes(8));
    \Piwigo\Core\CurrentPaths::set(\Piwigo\Core\Paths::fromRoot($root));
    CurrentConfig::setDataLocation('data/');
    // Skips the real original/representative file unlink block
    // entirely, keeping this test focused purely on the derivative
    // cache side effect line 245/248 actually control.
    CurrentConfig::setNeverDeleteOriginals(true);

    $imageId = imageServiceTestInsertImage($conn, 'upload/2026/07/repextderiv.jpg', 'jpg');
    $derivRoot = $root . '/' . CurrentConfig::derivativeDir();
    mkdir($derivRoot . 'upload/2026/07/pwg_representative', 0o777, true);
    $representativePatternDecoy = $derivRoot . 'upload/2026/07/pwg_representative/repextderiv-th.jpg';
    $originalPatternDecoy = $derivRoot . 'upload/2026/07/repextderiv-th.jpg';
    file_put_contents($representativePatternDecoy, 'x');
    file_put_contents($originalPatternDecoy, 'x');

    try {
        $service = imageServiceTestNewService($repo, $conn);
        $urlService = new ImageServiceTestFakeUrlService();

        $result = $service->deleteElementFiles([$imageId], $urlService);

        expect($result)->toBe([$imageId]);
        expect(file_exists($representativePatternDecoy))->toBeFalse();
        expect(file_exists($originalPatternDecoy))->toBeTrue();
    } finally {
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ?', [$imageId]);
        imageServiceTestRrmdir($root);
        \Piwigo\Core\CurrentPaths::reset();
        CurrentConfig::reset();
    }
});

test('deleteElementFiles stops at the first file it cannot remove and does not report that id or any id after it', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $root = sys_get_temp_dir() . '/piwigo-imageservice-test-' . bin2hex(random_bytes(8));
    \Piwigo\Core\CurrentPaths::set(\Piwigo\Core\Paths::fromRoot($root));
    CurrentConfig::setNeverDeleteOriginals(false);

    mkdir($root . '/upload/2026/07/locked', 0o777, true);
    $blockedId = imageServiceTestInsertImage($conn, 'upload/2026/07/locked/blocked.jpg');
    file_put_contents($root . '/upload/2026/07/locked/blocked.jpg', 'x');
    // Deny write on the containing directory so unlink() fails with
    // EACCES -- the file itself stays readable, only removal is denied.
    chmod($root . '/upload/2026/07/locked', 0o555);

    $afterId = imageServiceTestInsertImage($conn, 'upload/2026/07/after.jpg');
    file_put_contents($root . '/upload/2026/07/after.jpg', 'x');

    try {
        $service = imageServiceTestNewService($repo, $conn);
        $urlService = new ImageServiceTestFakeUrlService();

        // unlink() failing is a real, unsuppressed E_USER_WARNING
        // (trigger_error()) at the source's own call site -- absorb it
        // the same way PluginMaintainTest does, rather than letting
        // failOnWarning="true" turn it into a failure.
        set_error_handler(static fn (): bool => true);
        try {
            $result = $service->deleteElementFiles([$blockedId, $afterId], $urlService);
        } finally {
            restore_error_handler();
        }

        expect($result)->toBe([]);
        expect(file_exists($root . '/upload/2026/07/locked/blocked.jpg'))->toBeTrue();
        // Never reached: build() breaks out of the loop entirely once
        // $blockedId fails, so $afterId's own (perfectly deletable) file
        // is left untouched too.
        expect(file_exists($root . '/upload/2026/07/after.jpg'))->toBeTrue();
    } finally {
        chmod($root . '/upload/2026/07/locked', 0o755);
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id IN (?, ?)', [$blockedId, $afterId]);
        imageServiceTestRrmdir($root);
        \Piwigo\Core\CurrentPaths::reset();
        CurrentConfig::reset();
    }
});

test('deleteElements() returns 0 without touching the database when physical deletion removes zero files', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $root = sys_get_temp_dir() . '/piwigo-imageservice-test-' . bin2hex(random_bytes(8));
    \Piwigo\Core\CurrentPaths::set(\Piwigo\Core\Paths::fromRoot($root));
    CurrentConfig::setNeverDeleteOriginals(false);

    mkdir($root . '/upload/2026/07/locked', 0o777, true);
    $blockedId = imageServiceTestInsertImage($conn, 'upload/2026/07/locked/blocked.jpg');
    file_put_contents($root . '/upload/2026/07/locked/blocked.jpg', 'x');
    chmod($root . '/upload/2026/07/locked', 0o555);

    try {
        $service = imageServiceTestNewService($repo, $conn);
        $urlService = new ImageServiceTestFakeUrlService();

        set_error_handler(static fn (): bool => true);
        try {
            $result = $service->deleteElements([$blockedId], $urlService, physicalDeletion: true);
        } finally {
            restore_error_handler();
        }

        expect($result)->toBe(0);
        $stillThere = $conn->fetchOne('SELECT COUNT(*) FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ' . $blockedId);
        expect(is_numeric($stillThere) ? (int) $stillThere : -1)->toBe(1);
    } finally {
        chmod($root . '/upload/2026/07/locked', 0o755);
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ?', [$blockedId]);
        imageServiceTestRrmdir($root);
        \Piwigo\Core\CurrentPaths::reset();
        CurrentConfig::reset();
    }
});

test('deleteElements() fires begin_delete_elements and delete_elements with the exact real ids, deletes the image row, and records the activity', function (): void {
    // Kills line 271's IfNegated/IdenticalToNotIdentical (the empty-ids
    // guard), line 274's RemoveMethodCall (begin_delete_elements),
    // line 284's RemoveMethodCall (deleteImages()), line 293's
    // RemoveMethodCall (delete_elements), and line 294's RemoveMethodCall
    // (activityLogger->record()) together -- each is a real,
    // independently observable side effect of one call.
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $imageId = imageServiceTestInsertImage($conn, 'upload/2026/07/notifydelete.jpg');
    $urlService = new ImageServiceTestFakeUrlService();
    $activityRepo = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Activity\ActivityEntity::class);
    expect($activityRepo)->toBeInstanceOf(\Piwigo\Activity\ActivityRepository::class);
    $activityService = new \Piwigo\Activity\ActivityService($activityRepo);

    $capturedBeginIds = null;
    $beginHandler = function (BeginDeleteElements $event) use (&$capturedBeginIds): void {
        $capturedBeginIds = $event->ids;
    };
    $capturedDeleteIds = null;
    $deleteHandler = function (DeleteElements $event) use (&$capturedDeleteIds): void {
        $capturedDeleteIds = $event->ids;
    };
    \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(BeginDeleteElements::class, $beginHandler);
    \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(DeleteElements::class, $deleteHandler);

    try {
        $service = new ImageService($repo, $activityService);

        $count = $service->deleteElements([$imageId], $urlService, physicalDeletion: false);

        expect($count)->toBe(1);
        expect($capturedBeginIds)->toBe([$imageId]);
        expect($capturedDeleteIds)->toBe([$imageId]);
        $imagesRemaining = $conn->fetchOne('SELECT COUNT(*) FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ' . $imageId);
        expect(is_numeric($imagesRemaining) ? (int) $imagesRemaining : -1)->toBe(0);
        expect($activityService->getOccuredOnForObject($imageId, 'photo', 'delete'))->not->toBeNull();
    } finally {
        \Piwigo\PluginConfig\EventDispatcher::get()->removeEventHandler(BeginDeleteElements::class, $beginHandler);
        \Piwigo\PluginConfig\EventDispatcher::get()->removeEventHandler(DeleteElements::class, $deleteHandler);
    }
});

test('deleteElements() with an empty id list never fires begin_delete_elements at all', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $urlService = new ImageServiceTestFakeUrlService();

    $fired = false;
    $handler = function () use (&$fired): void {
        $fired = true;
    };
    \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(BeginDeleteElements::class, $handler);

    try {
        $service = imageServiceTestNewService($repo, $conn);

        expect($service->deleteElements([], $urlService))->toBe(0);
        expect($fired)->toBeFalse();
    } finally {
        \Piwigo\PluginConfig\EventDispatcher::get()->removeEventHandler(BeginDeleteElements::class, $handler);
    }
});

// deleteElements()'s own "are the photos used as category representant?"
// check (findRepresentedCategoryIds($ids) -> categoryService()->
// updateCategory($categoryIds), directly after deleteImages($ids)) is
// confirmed dead code against the real schema, not merely untested:
// `piwigo_categories.representative_picture_id` carries a real
// `fk_categories_representative_picture_id ... ON DELETE SET NULL`
// constraint (tests/Fixtures/piwigo-17.0.sql), verified live against the
// test database directly -- deleting a referenced `images` row already
// nulls out every category's own `representative_picture_id` for it
// synchronously, as part of that same DELETE statement, before
// findRepresentedCategoryIds()'s own later `WHERE representative_picture_id
// IN ($ids)` query ever runs. That query can therefore never match a row
// deleteElements() itself just deleted, so $categoryIds is always [] and
// categoryService()->updateCategory() is never reached through this path.
// Forcing it open (e.g. temporarily disabling FOREIGN_KEY_CHECKS) would
// only cover an ordering the real schema can never produce -- not
// reflecting real behavior, so not done. This confirms line 288's
// IfNegated/NotIdenticalToIdentical as equivalent too: $categoryIds is
// always [], so both the real `!==` and the mutant `===`/negated forms
// agree on never entering the branch.
//
// Confirmed-equivalent: line 283's RemoveMethodCall
// (deleteElementReferences($ids) entirely removed). Every table it
// touches (comments, image_category, image_format, image_tag,
// favorites, rate, caddie) carries its own real `ON DELETE CASCADE`
// FK straight to `images.id` (tests/Fixtures/piwigo-17.0.sql) -- the
// very next line's deleteImages($ids) already removes every one of
// those rows itself, via cascade, regardless of whether this call ran
// first. Live sed-verified: removed the call entirely, seeded a
// comments + image_format row for a throwaway image, called
// deleteElements(), and confirmed both were gone afterward exactly the
// same as with the call present.

test('associateImagesToCategories() returns false and does nothing when either list is empty', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $service = imageServiceTestNewService($repo, $conn);

    expect($service->associateImagesToCategories([], [1]))->toBeFalse();
    expect($service->associateImagesToCategories([1], []))->toBeFalse();
});

test('associateImagesToCategories() treats a numeric-string image id as already associated with its equivalent int entry, not a duplicate', function (): void {
    // Kills line 430's RemoveIntegerCast (`in_array($imageId, ...)`
    // instead of `in_array((int) $imageId, ...)`). findExistingAssociations()
    // always returns real ints (ImageRepository's own mysqli-native-types
    // finding); a caller-supplied STRING id that already matches one of
    // them only compares equal under the real (int)-cast strict
    // in_array(). The mutant's uncast strict in_array() would treat it
    // as a brand new association and attempt a duplicate INSERT, which
    // the real (image_id, category_id) PRIMARY KEY rejects outright --
    // turning this test into an uncaught exception under the mutation.
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $imageId = imageServiceTestInsertImage($conn, 'upload/2026/07/existingassoc.jpg');
    $conn->createQueryBuilder()
        ->insert(\Piwigo\Db\Tables::imageCategory())
        ->values(['image_id' => ':imageId', 'category_id' => ':categoryId', '`rank`' => ':rank'])
        ->setParameter('imageId', $imageId)
        ->setParameter('categoryId', 1)
        ->setParameter('rank', 1)
        ->executeStatement();

    try {
        $service = imageServiceTestNewService($repo, $conn);

        expect($service->associateImagesToCategories([(string) $imageId], [1]))->toBeNull();

        $rows = $conn->fetchAllAssociative('SELECT `rank` FROM ' . \Piwigo\Db\Tables::imageCategory() . ' WHERE image_id = ' . $imageId . ' AND category_id = 1');
        expect($rows)->toHaveCount(1);
        expect($rows[0]['rank'])->toBe(1);
    } finally {
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::imageCategory() . ' WHERE image_id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ?', [$imageId]);
    }
});

test('moveImagesToCategories() returns false for an empty image list, and treats a non-array $categories as none', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $imageId = imageServiceTestInsertImage($conn, 'upload/2026/07/mover.jpg');
    $conn->createQueryBuilder()
        ->insert(\Piwigo\Db\Tables::imageCategory())
        ->values(['image_id' => ':imageId', 'category_id' => ':categoryId', '`rank`' => ':rank'])
        ->setParameter('imageId', $imageId)
        ->setParameter('categoryId', 1)
        ->setParameter('rank', 1)
        ->executeStatement();

    try {
        $service = imageServiceTestNewService($repo, $conn);

        expect($service->moveImagesToCategories([], 'not-an-array'))->toBeFalse();

        // A non-array $categories is coerced to [] -- deleteNonStorageCategoryLinks()
        // still runs (breaking every non-storage link) but
        // associateImagesToCategories() is never called since categories
        // stays empty.
        expect($service->moveImagesToCategories([$imageId], 'not-an-array'))->toBeNull();
        $remaining = $conn->fetchOne('SELECT COUNT(*) FROM ' . \Piwigo\Db\Tables::imageCategory() . ' WHERE image_id = ' . $imageId);
        expect(is_numeric($remaining) ? (int) $remaining : -1)->toBe(0);
    } finally {
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::imageCategory() . ' WHERE image_id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ?', [$imageId]);
    }
});

test('associateImagesToCategories() initializes a brand new category\'s starting rank to exactly 0, without a stray "undefined array key" warning, and updates the category', function (): void {
    // Kills line 417's IfNegated/RemoveNot, line 431's
    // PreIncrementToPreDecrement, line 436's RemoveArrayItem, and line
    // 445's RemoveMethodCall together. A category with NO existing
    // image_category rows at all -- findMaxRanksByCategory() returns no
    // entry for it -- is required to reach line 417's guard TRUE branch
    // for real; the fixture categories (1, 2) both already carry
    // fixture associations, so a fresh throwaway category is used
    // instead. Real code pre-seeds the missing slot to 0 before ++'ing
    // it (417/431: real rank ends up 1); the mutant would either skip
    // the seed (a real "Undefined array key" PHP warning on the bare
    // ++, still numerically converging on 1) or decrement from 0 (-1,
    // not 1) or drop the 'rank' key from the insert entirely (NULL, not
    // 1) -- all four are distinguished by one exact rank assertion.
    // allowRandomRepresentative() defaults to false, so a category that
    // just received its first-ever image (no representative set yet)
    // is exactly the "needs a random representative" case
    // categoryService()->updateCategory() resolves -- proving line 445
    // genuinely ran.
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $conn->executeStatement("INSERT INTO " . \Piwigo\Db\Tables::categories() . " (name) VALUES ('mutation-sweep-fresh-category')");
    $categoryId = (int) $conn->lastInsertId();
    $imageId = imageServiceTestInsertImage($conn, 'upload/2026/07/freshcategoryrank.jpg');

    $capturedWarnings = [];
    set_error_handler(static function (int $errno, string $errstr) use (&$capturedWarnings): bool {
        $capturedWarnings[] = $errstr;

        return true;
    });

    try {
        $service = imageServiceTestNewService($repo, $conn);

        expect($service->associateImagesToCategories([$imageId], [$categoryId]))->toBeNull();

        expect($capturedWarnings)->toBe([]);
        $rank = $conn->fetchOne('SELECT `rank` FROM ' . \Piwigo\Db\Tables::imageCategory() . ' WHERE image_id = ' . $imageId . ' AND category_id = ' . $categoryId);
        expect($rank)->toBe(1);
        $representative = $conn->fetchOne('SELECT representative_picture_id FROM ' . \Piwigo\Db\Tables::categories() . ' WHERE id = ' . $categoryId);
        expect($representative)->toBe($imageId);
    } finally {
        restore_error_handler();
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::imageCategory() . ' WHERE image_id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::categories() . ' WHERE id = ?', [$categoryId]);
    }
});

test('moveImagesToCategories() actually associates images to a real, non-empty category list', function (): void {
    // Kills line 494's IfNegated/NotIdenticalToIdentical -- the sibling
    // test above only ever reaches this guard with $categories coerced
    // to [] (a non-array input), never with a genuine non-empty array
    // that must actually trigger associateImagesToCategories().
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $imageId = imageServiceTestInsertImage($conn, 'upload/2026/07/moverreal.jpg');

    try {
        $service = imageServiceTestNewService($repo, $conn);

        expect($service->moveImagesToCategories([$imageId], [2]))->toBeNull();

        $rows = $conn->fetchAllAssociative('SELECT category_id FROM ' . \Piwigo\Db\Tables::imageCategory() . ' WHERE image_id = ' . $imageId);
        expect($rows)->toBe([['category_id' => 2]]);
    } finally {
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::imageCategory() . ' WHERE image_id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ?', [$imageId]);
    }
});

test('addMd5sum() computes and persists a real md5sum for a readable file, prefixed by the real root path', function (): void {
    // Kills line 524's ConcatRemoveLeft/ConcatSwitchSides (whether
    // CurrentPaths::get()->root is genuinely prefixed) and line 539's
    // RemoveMethodCall (whether massUpdateMd5sums() actually persists
    // anything) together -- a wrong path fails is_readable() (md5sum
    // stays null), and a skipped persist call also leaves it null, so
    // only real, correct code produces the real hash on the real row.
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $root = sys_get_temp_dir() . '/piwigo-imageservice-test-' . bin2hex(random_bytes(8));
    \Piwigo\Core\CurrentPaths::set(\Piwigo\Core\Paths::fromRoot($root));
    mkdir($root . '/upload/2026/07', 0o777, true);
    file_put_contents($root . '/upload/2026/07/hashme.jpg', 'real content to hash');

    $imageId = imageServiceTestInsertImage($conn, 'upload/2026/07/hashme.jpg');

    try {
        $service = imageServiceTestNewService($repo, $conn);

        $count = $service->addMd5sum([$imageId]);

        expect($count)->toBe(1);
        $md5sum = $conn->fetchOne('SELECT md5sum FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ' . $imageId);
        expect($md5sum)->toBe(md5_file($root . '/upload/2026/07/hashme.jpg'));
    } finally {
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ?', [$imageId]);
        imageServiceTestRrmdir($root);
        \Piwigo\Core\CurrentPaths::reset();
    }
});

test('addMd5sum() does not stop at the first unhashable id -- a later id in the same call is still processed', function (): void {
    // Kills line 530's ContinueToBreak. Explicit ids (unreadable <
    // readable) so findPathsForMd5sum()'s real WHERE id IN (...) lookup
    // -- confirmed live to walk an InnoDB PRIMARY KEY in ascending id
    // order -- visits the unhashable row first.
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $root = sys_get_temp_dir() . '/piwigo-imageservice-test-' . bin2hex(random_bytes(8));
    \Piwigo\Core\CurrentPaths::set(\Piwigo\Core\Paths::fromRoot($root));
    mkdir($root . '/upload/2026/07', 0o777, true);
    file_put_contents($root . '/upload/2026/07/readable.jpg', 'hashable content');

    $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id IN (750001, 750002)');
    $conn->executeStatement('INSERT INTO ' . \Piwigo\Db\Tables::images() . ' (id, file, path) VALUES (750001, ?, ?)', ['unreadable.jpg', 'upload/2026/07/does-not-exist-on-disk.jpg']);
    $conn->executeStatement('INSERT INTO ' . \Piwigo\Db\Tables::images() . ' (id, file, path) VALUES (750002, ?, ?)', ['readable.jpg', 'upload/2026/07/readable.jpg']);

    try {
        $service = imageServiceTestNewService($repo, $conn);

        $service->addMd5sum([750001, 750002]);

        $md5sum = $conn->fetchOne('SELECT md5sum FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = 750002');
        expect($md5sum)->toBe(md5_file($root . '/upload/2026/07/readable.jpg'));
    } finally {
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id IN (750001, 750002)');
        imageServiceTestRrmdir($root);
        \Piwigo\Core\CurrentPaths::reset();
    }
});

test('addMd5sum() skips ids whose file cannot be hashed and still counts them among the ids it considered', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $root = sys_get_temp_dir() . '/piwigo-imageservice-test-' . bin2hex(random_bytes(8));
    \Piwigo\Core\CurrentPaths::set(\Piwigo\Core\Paths::fromRoot($root));
    mkdir($root, 0o777, true);

    // md5sum defaults to NULL for a freshly-inserted row, and its path
    // points nowhere on disk -- md5_file() fails (false), so addMd5sum()
    // must `continue` rather than recording a bogus md5sum.
    $missingId = imageServiceTestInsertImage($conn, 'upload/2026/07/does-not-exist-on-disk.jpg');

    try {
        $service = imageServiceTestNewService($repo, $conn);

        // toContain, not an exact-array match: other suites sharing this
        // same database may have their own rows with a null md5sum alive
        // at this exact moment (this codebase's tests all run in one
        // process/DB) -- this only needs to confirm the repo's query
        // finds this test's own disposable row, not that it finds
        // nothing else.
        expect($service->getPhotosNoMd5sum())->toContain($missingId);

        $updatedCount = $service->addMd5sum([$missingId]);

        expect($updatedCount)->toBe(1);
        $md5sum = $conn->fetchOne('SELECT md5sum FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ' . $missingId);
        expect($md5sum)->toBeNull();
    } finally {
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ?', [$missingId]);
        imageServiceTestRrmdir($root);
        \Piwigo\Core\CurrentPaths::reset();
    }
});

test('getImageInfos() delegates a fatal error to HtmlRenderingInterface for a non-numeric id, and continues on to a "not found" lookup afterward', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $service = imageServiceTestNewService($repo, $conn);
    $renderer = new ImageServiceTestFakeHtmlRenderer();

    expect(fn () => $service->getImageInfos('not-a-number', $renderer))->toThrow(ImageServiceTestFatalSignal::class);
    expect($renderer->lastMessage)->toBe('[getImageInfos] invalid image identifier not-a-number');
});

test('getImageInfos() html-escapes special characters in an invalid, non-numeric image id before reporting it', function (): void {
    // Kills line 866's UnwrapHtmlentities -- the sibling test above uses
    // 'not-a-number', which has no HTML-special characters, so
    // htmlentities() is a no-op there and can't distinguish the
    // mutation.
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $service = imageServiceTestNewService($repo, $conn);
    $renderer = new ImageServiceTestFakeHtmlRenderer();

    expect(fn () => $service->getImageInfos('<script>&"\'', $renderer))->toThrow(ImageServiceTestFatalSignal::class);
    expect($renderer->lastMessage)->toBe('[getImageInfos] invalid image identifier ' . htmlentities('<script>&"\''));
});

test('getImageInfos() delegates a fatal error to HtmlRenderingInterface when dieOnMissing is true and the image does not exist', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $service = imageServiceTestNewService($repo, $conn);
    $renderer = new ImageServiceTestFakeHtmlRenderer();

    expect(fn () => $service->getImageInfos(999_999, $renderer, dieOnMissing: true))->toThrow(ImageServiceTestFatalSignal::class);
    expect($renderer->lastMessage)->toBe('photo 999999 does not exist');
});

/**
 * `empty_lounge_running` is a single GLOBAL row in the real `config`
 * table -- every emptyLounge() test below (and ImageRepositoryTest.php's
 * own tryAcquireLoungeLock test) reads/writes it directly, with no
 * per-test scoping possible (the param name is hardcoded inside
 * ImageRepository itself). Under --parallel, Pest runs separate test
 * FILES as separate worker processes concurrently, so two such tests in
 * different files can genuinely race on this one row (caught live: one
 * test's row got deleted out from under another's in-flight assertion).
 * MySQL's GET_LOCK()/RELEASE_LOCK() are server-wide, not
 * connection-local, so they serialize these tests across worker
 * processes for real -- unlike a PHP-level mutex, which would only
 * cover a single process.
 */
function imageServiceTestAcquireEmptyLoungeDbLock(\Doctrine\DBAL\Connection $conn): void
{
    $acquired = $conn->fetchOne("SELECT GET_LOCK('piwigo17-unit-tests-empty_lounge_running', 10)");
    if (! is_numeric($acquired) || (int) $acquired !== 1) {
        throw new RuntimeException('Could not acquire the empty_lounge_running test lock within 10s -- a concurrent test run may be stuck.');
    }
}

function imageServiceTestReleaseEmptyLoungeDbLock(\Doctrine\DBAL\Connection $conn): void
{
    $conn->executeStatement("SELECT RELEASE_LOCK('piwigo17-unit-tests-empty_lounge_running')");
}

/**
 * Strips Logger::formatMessage()'s own fixed
 * "[timestamp][exec=uuid]\t[LEVELCODE]\t" prefix (both parts vary run to
 * run) off every line of a real log file, returning just the message
 * text each debug() call itself built -- letting the tests below assert
 * exact string equality on the message content specifically, which is
 * what all of emptyLounge()'s own Concat-family/RemoveMethodCall
 * mutations actually touch.
 *
 * @return list<string>
 */
function imageServiceTestReadLogMessages(string $logPath): array
{
    $raw = file_get_contents($logPath);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $messages = [];
    foreach (explode("\n", rtrim($raw, "\n")) as $line) {
        $stripped = preg_replace('/^\[[^\]]*\]\[exec=[^\]]*\]\t\[[A-Z]+\]\t/', '', $line);
        if ($stripped === null) {
            throw new RuntimeException('preg_replace() failed on log line: ' . $line);
        }
        $messages[] = $stripped;
    }

    return $messages;
}

/**
 * emptyLounge() reads CurrentLogger through the transitional
 * `getStatic()` shim (singleton/service-locator elimination campaign,
 * Phase 2 -- see that method's own docblock: its callers include the
 * still-static Ws\PwgImages dispatch layer, Phase 10), which resolves the
 * real container-shared instance once Kernel::boot() has run.
 */
function imageServiceTestSeedCurrentLogger(\Piwigo\Core\Logger $logger): void
{
    \Piwigo\Core\Kernel::boot();
    $currentLogger = \Piwigo\Core\Kernel::container()->get(\Piwigo\Core\CurrentLogger::class);
    if (! $currentLogger instanceof \Piwigo\Core\CurrentLogger) {
        throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\CurrentLogger::class);
    }

    $currentLogger->set($logger);
}

/**
 * Confirmed-equivalent: line 374's IncrementInteger
 * (`isset($rows[$idx + 2])` instead of `$idx + 1`, inside the
 * "category changes" grouping guard's isset() half only -- the
 * `$rows[$idx + 1]['category_id']` comparison right after it is
 * untouched by this specific mutation). For a run of 2+ same-category
 * rows, this shifts the grouping flush one row earlier than intended,
 * splitting what should be ONE associateImagesToCategories() call into
 * two smaller ones. Live-probed with 3 real same-category lounge rows
 * against a fresh throwaway category: the persisted `rank` sequence
 * (1, 2, 3) came out byte-for-byte identical with the mutation applied
 * as without it. This holds structurally, not by coincidence: each
 * split call re-queries findMaxRanksByCategory() fresh (the entity
 * manager is cleared after every massInsertImageCategory(), so a
 * second call always sees the first call's own just-inserted rows),
 * so sequential ranks converge to the same values whether assigned by
 * one combined call or several smaller ones in a row. The only other
 * effect -- categoryService()->updateCategory() running an extra time
 * -- is itself idempotent (a category that already has a
 * representative is left alone by a later call). Not something a
 * random image count/order could accidentally hide: the mechanism
 * (identity-map-clearing + idempotent re-verification) holds for any
 * same-category run length.
 */
test('emptyLounge() clears a stale lock, logs the API-suffixed begin/win/end messages exactly, and empties every real lounge row into image_category', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    imageServiceTestAcquireEmptyLoungeDbLock($conn);
    $logDir = sys_get_temp_dir() . '/piwigo-imageservice-test-' . bin2hex(random_bytes(8));
    mkdir($logDir, 0o777, true);
    imageServiceTestSeedCurrentLogger(new \Piwigo\Core\Logger(['severity' => \Piwigo\Core\Logger::DEBUG, 'directory' => $logDir, 'filename' => 'emptylounge.log']));
    $conn->executeStatement("DELETE FROM " . \Piwigo\Db\Tables::config() . " WHERE param = 'empty_lounge_running'");
    // Single hyphen, matching the real "$execId-$startTime" shape
    // tryAcquireLoungeLock() itself always constructs (SessionService::
    // generateKey()'s base64 alphabet, minus '+'/'/', never contains a
    // hyphen) -- explode('-', ...) must split into exactly [execId,
    // startTime] for the (int) cast below to read the real timestamp,
    // not an unrelated string.
    CurrentConfig::setEmptyLoungeRunning('staleexecid-' . (time() - 100));
    $configRepo = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Config\ConfigEntry::class);
    expect($configRepo)->toBeInstanceOf(\Piwigo\Config\ConfigRepository::class);
    \Piwigo\Config\CurrentConfigService::set(new \Piwigo\Config\ConfigService($configRepo));
    // invalidateUserCache: false below -- this must survive untouched,
    // proving line 383's IfNegated does not wrongly invoke
    // PermissionCacheInvalidator::invalidate() (whose own real,
    // observable side effect is deleting this exact param).
    $conn->executeStatement(
        "INSERT INTO " . \Piwigo\Db\Tables::config() . " (param, value) VALUES ('count_orphans', ?)",
        [json_encode(3)]
    );

    // Real lounge rows across 2 categories -- exercises the real
    // foreach()/"category changes" grouping (line 364/374) and gives
    // deleteLoungeUpTo() (line 381) a genuinely non-zero max image id
    // to work with, not just $maxImageId's own untouched 0 default.
    $imageA = imageServiceTestInsertImage($conn, 'upload/2026/07/lounge-a.jpg');
    $imageB = imageServiceTestInsertImage($conn, 'upload/2026/07/lounge-b.jpg');
    $conn->createQueryBuilder()->insert(\Piwigo\Db\Tables::lounge())
        ->values(['image_id' => ':i', 'category_id' => ':c'])->setParameter('i', $imageA)->setParameter('c', 1)
        ->executeStatement();
    $conn->createQueryBuilder()->insert(\Piwigo\Db\Tables::lounge())
        ->values(['image_id' => ':i', 'category_id' => ':c'])->setParameter('i', $imageB)->setParameter('c', 2)
        ->executeStatement();

    $originalRequest = $_REQUEST;
    $_REQUEST['method'] = 'pwg.images.upload';

    $capturedEventRows = null;
    $handler = function (EmptyLounge $event) use (&$capturedEventRows): void {
        $capturedEventRows = $event->rows;
    };
    \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(EmptyLounge::class, $handler);

    try {
        $service = imageServiceTestNewService($repo, $conn);

        $result = $service->emptyLounge(invalidateUserCache: false);

        // Kills line 391's RemoveMethodCall -- dispatchNotify(new EmptyLounge($rows))
        // fires with the exact same rows this call itself returns.
        expect($capturedEventRows)->toBe($result);
        expect($result)->toBe([
            ['image_id' => $imageA, 'category_id' => 1],
            ['image_id' => $imageB, 'category_id' => 2],
        ]);

        // Kills line 235's (unrelated file) sibling pattern here too:
        // line 235-ish Concat* mutations on the log lines themselves --
        // an exact match on every message this run produced, in order,
        // including the real (random) exec id captured from the
        // "begins" line and cross-checked identically on every other
        // line, closes line 336 (stale-clear), 344 (begins, with the
        // real API suffix), 357 (wins the race), and 389 (ends) all at
        // once: any dropped/reordered/switched-sides fragment would
        // break at least one of these.
        $messages = imageServiceTestReadLogMessages($logDir . '/emptylounge.log');
        expect($messages)->toHaveCount(4);
        expect($messages[0])->toBe('emptyLounge, exec=staleexecid, timeout stopped by another call to the function');
        // The {4} quantifier (not a bare +) kills line 341's
        // DecrementInteger/IncrementInteger on generateKey(4)'s own
        // size argument -- a real exec id is exactly 4 characters long.
        if (preg_match('/^emptyLounge \(API:pwg\.images\.upload\), exec=([A-Za-z0-9]{4}), begins$/', $messages[1], $beginMatch) !== 1) {
            throw new RuntimeException('begins message did not match the expected shape: ' . $messages[1]);
        }
        $execId = $beginMatch[1];
        expect($messages[2])->toBe("emptyLounge, exec={$execId} wins the race and gets the token!");
        expect($messages[3])->toBe("emptyLounge, exec={$execId}, ends");

        $remainingLounge = $conn->fetchOne('SELECT COUNT(*) FROM ' . \Piwigo\Db\Tables::lounge() . ' WHERE image_id IN (' . $imageA . ',' . $imageB . ')');
        expect(is_numeric($remainingLounge) ? (int) $remainingLounge : -1)->toBe(0);
        $categoryLinks = $conn->fetchAllAssociative('SELECT image_id, category_id FROM ' . \Piwigo\Db\Tables::imageCategory() . ' WHERE image_id IN (' . $imageA . ',' . $imageB . ') ORDER BY image_id');
        expect($categoryLinks)->toBe([
            ['image_id' => $imageA, 'category_id' => 1],
            ['image_id' => $imageB, 'category_id' => 2],
        ]);
        // Kills line 387's RemoveMethodCall -- the lock this run itself
        // acquired at line 347 is released again once the run
        // completes, distinct from line 337's own mid-function delete
        // of the STALE lock this test seeded (already gone well before
        // this point). pest --mutate's own scanner reports this
        // specific mutation UNTESTED regardless (the established
        // blank-line-mutation blind spot -- RemoveMethodCall leaves a
        // bare blank line its coverage tracking can't attribute back to
        // this assertion) -- live sed-mutate-and-rerun directly
        // confirms a real kill: this exact assertion fails
        // ('"execid-..." is false') the moment the call is removed.
        expect(\Piwigo\Config\CurrentConfigService::get()->findRawValue('empty_lounge_running'))->toBeFalse();
        expect(\Piwigo\Config\CurrentConfigService::get()->findRawValue('count_orphans'))->toBe(json_encode(3));
    } finally {
        imageServiceTestReleaseEmptyLoungeDbLock($conn);
        \Piwigo\PluginConfig\EventDispatcher::get()->removeEventHandler(EmptyLounge::class, $handler);
        $_REQUEST = $originalRequest;
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::imageCategory() . ' WHERE image_id IN (?, ?)', [$imageA, $imageB]);
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::lounge() . ' WHERE image_id IN (?, ?)', [$imageA, $imageB]);
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id IN (?, ?)', [$imageA, $imageB]);
        $conn->executeStatement("DELETE FROM " . \Piwigo\Db\Tables::config() . " WHERE param IN ('empty_lounge_running', 'count_orphans')");
        \Piwigo\Config\CurrentConfigService::reset();
        \Piwigo\Core\Kernel::reset();
        CurrentConfig::reset();
        imageServiceTestRrmdir($logDir);
    }
});

test('emptyLounge() invalidates the permission cache (and its orphan-count cache entry) when asked to', function (): void {
    // Kills line 383's IfNegated from the other direction -- the
    // sibling test above proves invalidateUserCache: false leaves
    // 'count_orphans' untouched; this proves invalidateUserCache: true
    // genuinely deletes it, via PermissionCacheInvalidator::invalidate()'s
    // own real confDeleteParam('count_orphans') call. Also live
    // sed-mutate-and-rerun confirmed kills line 384's RemoveMethodCall
    // (the invalidate() call itself, entirely removed) -- pest --mutate's
    // own scanner reports it UNTESTED regardless (the same
    // blank-line-mutation blind spot as line 387's sibling test).
    [$conn, $repo] = imageServiceTestConnAndRepo();
    imageServiceTestAcquireEmptyLoungeDbLock($conn);
    imageServiceTestSeedCurrentLogger(new \Piwigo\Core\Logger(['severity' => \Piwigo\Core\Logger::OFF]));
    $conn->executeStatement("DELETE FROM " . \Piwigo\Db\Tables::config() . " WHERE param IN ('empty_lounge_running', 'count_orphans')");
    $configRepo = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Config\ConfigEntry::class);
    expect($configRepo)->toBeInstanceOf(\Piwigo\Config\ConfigRepository::class);
    \Piwigo\Config\CurrentConfigService::set(new \Piwigo\Config\ConfigService($configRepo));
    $conn->executeStatement(
        "INSERT INTO " . \Piwigo\Db\Tables::config() . " (param, value) VALUES ('count_orphans', ?)",
        [json_encode(3)]
    );

    try {
        $service = imageServiceTestNewService($repo, $conn);

        $service->emptyLounge(invalidateUserCache: true);

        expect(\Piwigo\Config\CurrentConfigService::get()->findRawValue('count_orphans'))->toBeFalse();
    } finally {
        imageServiceTestReleaseEmptyLoungeDbLock($conn);
        $conn->executeStatement("DELETE FROM " . \Piwigo\Db\Tables::config() . " WHERE param IN ('empty_lounge_running', 'count_orphans')");
        \Piwigo\Config\CurrentConfigService::reset();
        \Piwigo\Core\Kernel::reset();
        CurrentConfig::reset();
    }
});

test('emptyLounge() actually clears a stale lock\'s real database row, letting this run win the race for real', function (): void {
    // Kills line 337's RemoveMethodCall. The main "clears a stale lock"
    // test above only ever seeds CurrentConfig's own in-memory cache
    // (used for the initial staleness pre-check), never a matching real
    // database row -- so confDeleteParam() there was operating on a row
    // that was already absent either way, and its removal wasn't
    // observable. A REAL stale row here means: if line 337 doesn't run,
    // tryAcquireLoungeLock()'s own INSERT IGNORE a few lines later
    // silently fails against it (the row is still there), leaving the
    // STALE value as the "winner" -- so this run would lose the race
    // against its own former self and return null instead of a real
    // array.
    [$conn, $repo] = imageServiceTestConnAndRepo();
    imageServiceTestAcquireEmptyLoungeDbLock($conn);
    imageServiceTestSeedCurrentLogger(new \Piwigo\Core\Logger(['severity' => \Piwigo\Core\Logger::OFF]));
    $conn->executeStatement("DELETE FROM " . \Piwigo\Db\Tables::config() . " WHERE param = 'empty_lounge_running'");
    $staleValue = 'reallystale-' . (time() - 100);
    $conn->executeStatement(
        "INSERT INTO " . \Piwigo\Db\Tables::config() . " (param, value) VALUES ('empty_lounge_running', ?)",
        [json_encode($staleValue)]
    );
    CurrentConfig::setEmptyLoungeRunning($staleValue);
    $configRepo = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Config\ConfigEntry::class);
    expect($configRepo)->toBeInstanceOf(\Piwigo\Config\ConfigRepository::class);
    \Piwigo\Config\CurrentConfigService::set(new \Piwigo\Config\ConfigService($configRepo));

    try {
        $service = imageServiceTestNewService($repo, $conn);

        $result = $service->emptyLounge(invalidateUserCache: false);

        expect($result)->toBeArray();
    } finally {
        imageServiceTestReleaseEmptyLoungeDbLock($conn);
        $conn->executeStatement("DELETE FROM " . \Piwigo\Db\Tables::config() . " WHERE param = 'empty_lounge_running'");
        \Piwigo\Config\CurrentConfigService::reset();
        \Piwigo\Core\Kernel::reset();
        CurrentConfig::reset();
    }
});

test('emptyLounge() does not touch a lock that is not actually stale, and logs the no-API-suffix begin message', function (): void {
    // Kills line 335's MinusToPlus/RemoveIntegerCast/IfNegated/
    // GreaterToGreaterOrEqual/GreaterToSmallerOrEqual/DecrementInteger/
    // IncrementInteger -- the sibling test above seeds a lock 100s old
    // (comfortably past the 60s threshold either way); a lock exactly
    // 30s old (comfortably BELOW 60s either way) must be left alone,
    // closing the boundary from the other direction. Also kills line
    // 343's TernaryNegated/NotIdenticalToIdentical/Concat*/
    // EmptyStringToNotEmpty (the empty, no-API $apiSuffix branch, not
    // exercised by the sibling test's API-suffixed run) and line 337's
    // RemoveMethodCall would be proven NOT reached here (the message at
    // index 0 is 'begins', not the stale-clear message) if it wrongly
    // fired anyway -- but a wrongly-fired confDeleteParam() wouldn't
    // itself be observable without the sibling test's own real lock
    // already established as the baseline this one deliberately avoids
    // recreating.
    [$conn, $repo] = imageServiceTestConnAndRepo();
    imageServiceTestAcquireEmptyLoungeDbLock($conn);
    $logDir = sys_get_temp_dir() . '/piwigo-imageservice-test-' . bin2hex(random_bytes(8));
    mkdir($logDir, 0o777, true);
    imageServiceTestSeedCurrentLogger(new \Piwigo\Core\Logger(['severity' => \Piwigo\Core\Logger::DEBUG, 'directory' => $logDir, 'filename' => 'emptylounge2.log']));
    $conn->executeStatement("DELETE FROM " . \Piwigo\Db\Tables::config() . " WHERE param = 'empty_lounge_running'");
    $freshLockValue = 'freshexecid-' . (time() - 30);
    // A real row, not just CurrentConfig's static cache below -- this is
    // what tryAcquireLoungeLock()'s own real INSERT IGNORE actually
    // contends against.
    $conn->executeStatement(
        "INSERT INTO " . \Piwigo\Db\Tables::config() . " (param, value) VALUES ('empty_lounge_running', ?)",
        [json_encode($freshLockValue)]
    );
    CurrentConfig::setEmptyLoungeRunning($freshLockValue);
    $configRepo = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Config\ConfigEntry::class);
    expect($configRepo)->toBeInstanceOf(\Piwigo\Config\ConfigRepository::class);
    \Piwigo\Config\CurrentConfigService::set(new \Piwigo\Config\ConfigService($configRepo));

    $originalRequest = $_REQUEST;
    unset($_REQUEST['method']);

    try {
        $service = imageServiceTestNewService($repo, $conn);

        // tryAcquireLoungeLock() is a real INSERT IGNORE -- the fresh
        // lock this test seeded directly in `config` (not via a prior
        // tryAcquireLoungeLock() call) still wins the race against it,
        // so this run itself never becomes the lock holder.
        $result = $service->emptyLounge(invalidateUserCache: false);

        expect($result)->toBeNull();

        $messages = imageServiceTestReadLogMessages($logDir . '/emptylounge2.log');
        expect($messages)->toHaveCount(2);
        if (preg_match('/^emptyLounge, exec=([A-Za-z0-9]+), begins$/', $messages[0], $beginMatch) !== 1) {
            throw new RuntimeException('begins message did not match the expected (no-API-suffix) shape: ' . $messages[0]);
        }
        expect($messages[1])->toBe("emptyLounge, exec={$beginMatch[1]}, skip");

        // The fresh lock this test seeded is still there, untouched.
        expect(\Piwigo\Config\CurrentConfigService::get()->findRawValue('empty_lounge_running'))->toBe(json_encode($freshLockValue));
    } finally {
        imageServiceTestReleaseEmptyLoungeDbLock($conn);
        $_REQUEST = $originalRequest;
        $conn->executeStatement("DELETE FROM " . \Piwigo\Db\Tables::config() . " WHERE param = 'empty_lounge_running'");
        \Piwigo\Config\CurrentConfigService::reset();
        \Piwigo\Core\Kernel::reset();
        CurrentConfig::reset();
        imageServiceTestRrmdir($logDir);
    }
});

/**
 * Confirmed-equivalent: line 368's GreaterToGreaterOrEqual
 * (`$rowImageId >= $maxImageId` instead of `>`). `>` and `>=` can only
 * ever disagree at the exact point $rowImageId === $maxImageId -- and
 * at that exact point, the "action" the real `>` branch would skip and
 * the mutant's `>=` branch performs is `$maxImageId = $rowImageId`,
 * assigning the SAME value the variable already holds. A real no-op
 * reassignment, for every possible sequence of lounge row image ids,
 * not just a tested case (a repeated image id across two categories --
 * a real, valid `lounge` state, since its own PRIMARY KEY is
 * (image_id, category_id) -- is exactly the scenario that reaches this
 * equality point at all). Live sed-verified against the full suite too.
 *
 * Also confirmed-equivalent: line 359's IncrementInteger
 * (`$maxImageId = 1;` instead of `= 0;`). This default is only ever
 * still in effect by the time deleteLoungeUpTo($maxImageId) runs when
 * $rows (from findLoungeRows(), the exact same unconditional
 * `SELECT ... FROM lounge` table deleteLoungeUpTo()'s own
 * `DELETE ... WHERE image_id <= ?` targets) is genuinely empty -- any
 * real row makes the foreach loop above overwrite $maxImageId with a
 * real image id regardless of the 0-vs-1 starting point (per the line
 * 368 proof above). With $rows empty, the lounge table has zero rows
 * with ANY image_id at the exact moment deleteLoungeUpTo() runs (no
 * write happens in between, same synchronous call) -- so
 * `WHERE image_id <= 0` and `WHERE image_id <= 1` both delete exactly
 * zero rows, for every possible database state this branch can ever
 * observe. Live sed-verified against the full suite too.
 *
 * Also confirmed-equivalent: line 347's ConcatRemoveRight
 * (`tryAcquireLoungeLock($execId . '-')` instead of
 * `$execId . '-' . time()` -- the trailing timestamp dropped
 * entirely). `[$runningExecId] = explode('-', $emptyLoungeRunning)`
 * a few lines below only ever reads the FIRST hyphen-delimited segment
 * of whatever tryAcquireLoungeLock() just wrote, for THIS run's own
 * win-the-race check -- the timestamp segment is written purely for a
 * LATER, separate process's own staleness check (line 335, which reads
 * it via CurrentConfig::emptyLoungeRunning()'s own bootstrap-time
 * snapshot, never by re-reading what this exact call just persisted).
 * No Unit-level call to emptyLounge() -- which invokes the method
 * directly, never through the real bootstrap flow that populates that
 * snapshot -- can observe this specific segment's content. Live
 * sed-verified against the full suite too.
 */
test('emptyLounge() treats a lock that is exactly 60 seconds old as still fresh, not yet stale', function (): void {
    // Kills line 335's DecrementInteger (`> 59` instead of `> 60`).
    // Every sibling emptyLounge() test uses a lock comfortably on one
    // side of the boundary (100s old = stale either way, 30s old =
    // fresh either way) -- only a lock aged to exactly the threshold
    // itself separates the two operators: real code's strict `> 60`
    // is not yet true at exactly 60 elapsed seconds (not stale, guard
    // skipped); the mutant's `> 59` is already true there (wrongly
    // stale).
    [$conn, $repo] = imageServiceTestConnAndRepo();
    imageServiceTestAcquireEmptyLoungeDbLock($conn);
    $logDir = sys_get_temp_dir() . '/piwigo-imageservice-test-' . bin2hex(random_bytes(8));
    mkdir($logDir, 0o777, true);
    imageServiceTestSeedCurrentLogger(new \Piwigo\Core\Logger(['severity' => \Piwigo\Core\Logger::DEBUG, 'directory' => $logDir, 'filename' => 'emptylounge3.log']));
    $conn->executeStatement("DELETE FROM " . \Piwigo\Db\Tables::config() . " WHERE param = 'empty_lounge_running'");
    $t0 = time();
    $lockValue = 'boundaryexecid-' . ($t0 - 60);
    $conn->executeStatement(
        "INSERT INTO " . \Piwigo\Db\Tables::config() . " (param, value) VALUES ('empty_lounge_running', ?)",
        [json_encode($lockValue)]
    );
    CurrentConfig::setEmptyLoungeRunning($lockValue);
    $configRepo = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Config\ConfigEntry::class);
    expect($configRepo)->toBeInstanceOf(\Piwigo\Config\ConfigRepository::class);
    \Piwigo\Config\CurrentConfigService::set(new \Piwigo\Config\ConfigService($configRepo));

    try {
        $service = imageServiceTestNewService($repo, $conn);

        $result = $service->emptyLounge(invalidateUserCache: false);

        expect($result)->toBeNull();

        $messages = imageServiceTestReadLogMessages($logDir . '/emptylounge3.log');
        // Real code: not stale, so the "timeout stopped..." message
        // (line 336) is never logged at all -- just begins + skip.
        expect($messages)->toHaveCount(2);
        expect($messages[1])->toEndWith(', skip');

        // The boundary lock this test seeded is still there, untouched
        // (real code never entered the staleness-clear branch at all).
        expect(\Piwigo\Config\CurrentConfigService::get()->findRawValue('empty_lounge_running'))->toBe(json_encode($lockValue));
    } finally {
        imageServiceTestReleaseEmptyLoungeDbLock($conn);
        $conn->executeStatement("DELETE FROM " . \Piwigo\Db\Tables::config() . " WHERE param = 'empty_lounge_running'");
        \Piwigo\Config\CurrentConfigService::reset();
        \Piwigo\Core\Kernel::reset();
        CurrentConfig::reset();
        imageServiceTestRrmdir($logDir);
    }
});

test('emptyLounge() treats a lock that is exactly 61 seconds old as genuinely stale', function (): void {
    // Kills line 335's IncrementInteger (`> 61` instead of `> 60`) --
    // the sibling "exactly 60 seconds" test above pins the boundary
    // from the not-yet-stale side; this pins it from the other side.
    // Real code's strict `> 60` is already true at 61 elapsed seconds
    // (stale, lock cleared); the mutant's `> 61` is not yet true there
    // (wrongly left alone). Uses a REAL database row (like the
    // "actually clears a stale lock's real database row" test above),
    // not just CurrentConfig's own static cache, so tryAcquireLoungeLock()'s
    // own real INSERT IGNORE genuinely succeeds only if the stale row
    // was genuinely cleared first.
    [$conn, $repo] = imageServiceTestConnAndRepo();
    imageServiceTestAcquireEmptyLoungeDbLock($conn);
    imageServiceTestSeedCurrentLogger(new \Piwigo\Core\Logger(['severity' => \Piwigo\Core\Logger::OFF]));
    $conn->executeStatement("DELETE FROM " . \Piwigo\Db\Tables::config() . " WHERE param = 'empty_lounge_running'");
    $staleValue = 'boundary61execid-' . (time() - 61);
    $conn->executeStatement(
        "INSERT INTO " . \Piwigo\Db\Tables::config() . " (param, value) VALUES ('empty_lounge_running', ?)",
        [json_encode($staleValue)]
    );
    CurrentConfig::setEmptyLoungeRunning($staleValue);
    $configRepo = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Config\ConfigEntry::class);
    expect($configRepo)->toBeInstanceOf(\Piwigo\Config\ConfigRepository::class);
    \Piwigo\Config\CurrentConfigService::set(new \Piwigo\Config\ConfigService($configRepo));

    try {
        $service = imageServiceTestNewService($repo, $conn);

        $result = $service->emptyLounge(invalidateUserCache: false);

        expect($result)->toBeArray();
    } finally {
        imageServiceTestReleaseEmptyLoungeDbLock($conn);
        $conn->executeStatement("DELETE FROM " . \Piwigo\Db\Tables::config() . " WHERE param = 'empty_lounge_running'");
        \Piwigo\Config\CurrentConfigService::reset();
        \Piwigo\Core\Kernel::reset();
        CurrentConfig::reset();
    }
});

test('emptyLounge() returns null when a different, still-fresh execution already holds the lock', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    imageServiceTestAcquireEmptyLoungeDbLock($conn);
    imageServiceTestSeedCurrentLogger(new \Piwigo\Core\Logger(['severity' => \Piwigo\Core\Logger::OFF]));
    $conn->executeStatement("DELETE FROM " . \Piwigo\Db\Tables::config() . " WHERE param = 'empty_lounge_running'");
    $conn->executeStatement(
        "INSERT INTO " . \Piwigo\Db\Tables::config() . " (param, value) VALUES ('empty_lounge_running', ?)",
        [json_encode('foreignexec-' . time())]
    );
    // Not stale (CurrentConfig::emptyLoungeRunning() defaults to null),
    // so the staleness check is skipped entirely and tryAcquireLoungeLock()
    // -- a real INSERT IGNORE -- finds the row above already taken.
    CurrentConfig::setEmptyLoungeRunning(null);

    try {
        $service = imageServiceTestNewService($repo, $conn);

        expect($service->emptyLounge())->toBeNull();
    } finally {
        imageServiceTestReleaseEmptyLoungeDbLock($conn);
        $conn->executeStatement("DELETE FROM " . \Piwigo\Db\Tables::config() . " WHERE param = 'empty_lounge_running'");
        \Piwigo\Core\Kernel::reset();
        CurrentConfig::reset();
    }
});

test('deleteElementFiles() short-circuits on an empty id list without touching the repository or disk', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $service = imageServiceTestNewService($repo, $conn);

    expect($service->deleteElementFiles([], new ImageServiceTestFakeUrlService()))->toBe([]);
});

test('countPdfPages() returns false when the path passes is_file()/is_readable() but file_get_contents() itself fails', function (): void {
    // A real chmod'd/missing/socket path can't isolate this branch: every
    // one of those either fails is_file() or is_readable() first (see the
    // socket test above), which never reaches file_get_contents() at all.
    // A custom stream wrapper is the only way to make url_stat() report a
    // real, readable regular file while stream_open() itself still fails
    // -- the same technique tests/Integration/ThemesStandardPagesPageRendererTest's
    // own ThemesStandardPagesLogoStreamWrapper uses for an analogous
    // "passes the earlier guard, fails the later I/O call" branch.
    $scheme = 'pwgtestcountpdf' . bin2hex(random_bytes(4));
    stream_wrapper_register($scheme, ImageServiceTestFailedOpenStreamWrapper::class);

    try {
        set_error_handler(static fn (): bool => true);
        try {
            $result = new ImageService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Image\ImageEntity::class), new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)))->countPdfPages($scheme . '://fake.pdf');
        } finally {
            restore_error_handler();
        }

        expect($result)->toBeFalse();
    } finally {
        stream_wrapper_unregister($scheme);
    }
});

test('updateFormatFilesize() delegates straight through to the repository', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $imageId = imageServiceTestInsertImage($conn, 'upload/2026/07/updformatfilesize.jpg');
    $formatId = $repo->insertFormat($imageId, 'webp', null);

    try {
        $service = imageServiceTestNewService($repo, $conn);

        $service->updateFormatFilesize($formatId, 12345);

        $filesize = $conn->fetchOne('SELECT filesize FROM ' . \Piwigo\Db\Tables::imageFormat() . ' WHERE format_id = ' . $formatId);
        expect(is_numeric($filesize) ? (int) $filesize : null)->toBe(12345);
    } finally {
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::imageFormat() . ' WHERE format_id = ?', [$formatId]);
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ?', [$imageId]);
    }
});

test('getIdsByFilenameInCategory() delegates straight through to the repository', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $imageId = imageServiceTestInsertImage($conn, 'upload/2026/07/filenameincat.jpg');
    $conn->createQueryBuilder()
        ->insert(\Piwigo\Db\Tables::imageCategory())
        ->values(['image_id' => ':imageId', 'category_id' => ':categoryId', '`rank`' => ':rank'])
        ->setParameter('imageId', $imageId)
        ->setParameter('categoryId', 1)
        ->setParameter('rank', 1)
        ->executeStatement();

    try {
        $service = imageServiceTestNewService($repo, $conn);

        expect($service->getIdsByFilenameInCategory('filenameincat.jpg', 1))->toBe([$imageId]);
        expect($service->getIdsByFilenameInCategory('no-such-file.jpg', 1))->toBe([]);
    } finally {
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::imageCategory() . ' WHERE image_id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ?', [$imageId]);
    }
});

test('getIdsVisibleInCategoriesRecentlyAvailable() delegates straight through to the repository', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $imageId = imageServiceTestInsertImage($conn, 'upload/2026/07/recentlyavailable.jpg');
    $conn->executeStatement('UPDATE ' . \Piwigo\Db\Tables::images() . ' SET date_available = NOW() WHERE id = ?', [$imageId]);
    $conn->createQueryBuilder()
        ->insert(\Piwigo\Db\Tables::imageCategory())
        ->values(['image_id' => ':imageId', 'category_id' => ':categoryId', '`rank`' => ':rank'])
        ->setParameter('imageId', $imageId)
        ->setParameter('categoryId', 1)
        ->setParameter('rank', 1)
        ->executeStatement();

    try {
        $service = imageServiceTestNewService($repo, $conn);

        $result = $service->getIdsVisibleInCategoriesRecentlyAvailable('1', 'DATE_SUB(NOW(), INTERVAL 1 DAY)');

        expect($result)->toContain($imageId);
    } finally {
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::imageCategory() . ' WHERE image_id = ?', [$imageId]);
        $conn->executeStatement('DELETE FROM ' . \Piwigo\Db\Tables::images() . ' WHERE id = ?', [$imageId]);
    }
});

test('getAddMethodBreakdown() delegates straight through to the repository, grouped by add method', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $service = imageServiceTestNewService($repo, $conn);

    $result = $service->getAddMethodBreakdown();

    // Kills line 1281's AlwaysReturnEmptyArray -- the fixture images
    // (ids 1-5) always give the real repository at least one add_method
    // group to report, so an unconditional `return [];` is observably
    // wrong, not merely untested.
    expect($result)->not->toBeEmpty();
    foreach ($result as $row) {
        expect($row)->toHaveKeys(['add_method', 'last_added_on', 'nb_files']);
        expect(in_array($row['add_method'], ['api', 'sync'], true))->toBeTrue();
    }
});

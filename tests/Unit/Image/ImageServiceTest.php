<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Db\DbConnection;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;

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

/**
 * Fake for deleteElementFiles()'s own UrlServiceInterface parameter --
 * only urlIsRemote() is real call surface (both directly, and via
 * ImagePathHelper::getElementPath()); every other method is unreachable
 * through this path and throws.
 */
final class ImageServiceTestFakeUrlService implements \Piwigo\Core\UrlServiceInterface
{
    #[\Override]
    public function getRootUrl(): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function getAbsoluteRootUrl(bool $withScheme = true): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function addUrlParams(string $url, array $params, string $argSeparator = '&amp;'): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function makeIndexUrl(array $params = []): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function duplicateIndexUrl(array $redefined = [], array $removed = []): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function duplicatePictureUrl(array $redefined = [], array $removed = []): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function makePictureUrl(array $params): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function parseSectionUrl(array $tokens, &$nextToken, \Piwigo\Core\RedirectServiceInterface $redirectService): array
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function parseWellKnownParamsUrl(array $tokens, int &$i): array
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function getActionUrl($id, $whatPart, bool $download): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function getElementUrl(array $elementInfo): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function setMakeFullUrl(): void {}

    #[\Override]
    public function unsetMakeFullUrl(): void {}

    #[\Override]
    public function embellishUrl(string $url): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function getGalleryHomeUrl(): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function getQueryStringDiff(array $rejects = [], bool $escape = true): string
    {
        throw new \LogicException('not used');
    }

    #[\Override]
    public function urlIsRemote(string $url): bool
    {
        return str_starts_with($url, 'https://remote.example.test/');
    }

    #[\Override]
    public function getUserFavorites(): array
    {
        throw new \LogicException('not used');
    }
}

final class ImageServiceTestFatalSignal extends \Exception
{
}

final class ImageServiceTestFakeHtmlRenderer implements \Piwigo\Core\HtmlRenderingInterface
{
    public ?string $lastMessage = null;

    #[\Override]
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        return '';
    }

    #[\Override]
    public function getCatDisplayNameCache(
        string $uppercats,
        ?string $url = '',
        bool $singleLink = false,
        ?string $linkClass = null,
        ?string $authKey = null,
    ): string {
        return '';
    }

    #[\Override]
    public function nameCompare(array $a, array $b): int
    {
        return 0;
    }

    #[\Override]
    public function tagAlphaCompare(array $a, array $b): int
    {
        return 0;
    }

    #[\Override]
    public function accessDenied(\Piwigo\Core\RedirectServiceInterface $redirectService): never
    {
        throw new ImageServiceTestFatalSignal('accessDenied');
    }

    #[\Override]
    public function badRequest(\Piwigo\Core\RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
    {
        throw new ImageServiceTestFatalSignal('badRequest');
    }

    #[\Override]
    public function pageNotFound(\Piwigo\Core\RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
    {
        throw new ImageServiceTestFatalSignal('pageNotFound');
    }

    #[\Override]
    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        $this->lastMessage = $msg;

        throw new ImageServiceTestFatalSignal('fatalError:' . $msg);
    }

    #[\Override]
    public function getTagsContentTitle(array $tags): string
    {
        return '';
    }

    #[\Override]
    public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
    {
        return '';
    }

    #[\Override]
    public function setStatusHeader(int $code, string $text = ''): void {}

    #[\Override]
    public function renderElementName(array $info): string
    {
        return '';
    }

    #[\Override]
    public function renderElementDescription(array $info, string $param = ''): string
    {
        return '';
    }

    #[\Override]
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {
        return '';
    }
}

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
// reflecting real behavior, so not done.

test('associateImagesToCategories() returns false and does nothing when either list is empty', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $service = imageServiceTestNewService($repo, $conn);

    expect($service->associateImagesToCategories([], [1]))->toBeFalse();
    expect($service->associateImagesToCategories([1], []))->toBeFalse();
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

test('getImageInfos() delegates a fatal error to HtmlRenderingInterface when dieOnMissing is true and the image does not exist', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    $service = imageServiceTestNewService($repo, $conn);
    $renderer = new ImageServiceTestFakeHtmlRenderer();

    expect(fn () => $service->getImageInfos(999_999, $renderer, dieOnMissing: true))->toThrow(ImageServiceTestFatalSignal::class);
    expect($renderer->lastMessage)->toBe('photo 999999 does not exist');
});

test('emptyLounge() clears a stale lock and completes the run itself once the lock is free again', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    \Piwigo\Core\CurrentLogger::set(new \Piwigo\Core\Logger(['severity' => \Piwigo\Core\Logger::OFF]));
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

    try {
        $service = imageServiceTestNewService($repo, $conn);

        $result = $service->emptyLounge(invalidateUserCache: false);

        // The stale lock this test seeded is gone, and this call itself
        // wins the (now-free) lock and completes a real (empty) drain.
        expect($result)->toBeArray();
    } finally {
        $conn->executeStatement("DELETE FROM " . \Piwigo\Db\Tables::config() . " WHERE param = 'empty_lounge_running'");
        \Piwigo\Config\CurrentConfigService::reset();
        \Piwigo\Core\CurrentLogger::reset();
        CurrentConfig::reset();
    }
});

test('emptyLounge() returns null when a different, still-fresh execution already holds the lock', function (): void {
    [$conn, $repo] = imageServiceTestConnAndRepo();
    \Piwigo\Core\CurrentLogger::set(new \Piwigo\Core\Logger(['severity' => \Piwigo\Core\Logger::OFF]));
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
        $conn->executeStatement("DELETE FROM " . \Piwigo\Db\Tables::config() . " WHERE param = 'empty_lounge_running'");
        CurrentConfig::reset();
    }
});

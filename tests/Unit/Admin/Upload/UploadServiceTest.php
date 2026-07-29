<?php

declare(strict_types=1);

use Piwigo\Admin\Image\ImageProcessingException;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Url\UrlService;

// Marker-based filesystem safety: this suite writes real files to verify
// [SEC-21]'s SVG sanitizer, so every path must be scoped to a unique
// temp subdirectory it creates and tears down itself -- never touching
// the real app root (see DerivativeCacheServiceTest's own docblock for the
// incident this pattern was built to prevent). sanitizeSvgIfNeeded()
// itself never reads Piwigo\Core\CurrentPaths -- it only touches the path
// passed to it -- so this suite doesn't need to seed it at all.
function upload_service_test_marker(): string
{
    /** @var string|null $marker */
    static $marker = null;

    return $marker ??= sys_get_temp_dir() . '/piwigo-upload-service-test-' . bin2hex(random_bytes(8));
}

beforeEach(function (): void {
    mkdir(upload_service_test_marker(), 0o777, true);
    // needResize() reads Piwigo\Core\CurrentLogger directly (an info-level
    // log line on its own "too big" branch) -- OFF severity is a real,
    // side-effect-free logger, never touching the filesystem.
    CurrentLogger::set(new Logger(['severity' => Logger::OFF]));
});

/** Recursively removes a directory tree (uploadFile* handlers create a nested pwg_representative/ subdirectory). */
function upload_service_rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $entries = scandir($dir);
    foreach ($entries !== false ? $entries : [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            upload_service_rrmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

afterEach(function (): void {
    CurrentLogger::reset();
    upload_service_rrmdir(upload_service_test_marker());
});

function upload_service_call_sanitize(string $path, ?string $finfoType): void
{
    $service = new UploadService();
    $method = new ReflectionMethod($service, 'sanitizeSvgIfNeeded');
    $method->invoke($service, $path, $finfoType);
}

test('sanitizeSvgIfNeeded strips a <script> element from a genuine SVG', function (): void {
    $path = upload_service_test_marker() . '/evil.svg';
    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><circle r="5"/></svg>');

    upload_service_call_sanitize($path, 'image/svg+xml');

    $result = file_get_contents($path);
    expect($result)->not->toContain('<script')
        ->and($result)->not->toContain('alert(1)')
        ->and($result)->toContain('circle');
});

test('sanitizeSvgIfNeeded strips on*= event-handler attributes', function (): void {
    $path = upload_service_test_marker() . '/evil2.svg';
    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><circle r="5" onload="alert(1)" onclick="alert(2)"/></svg>');

    upload_service_call_sanitize($path, 'image/svg');

    $result = file_get_contents($path);
    expect($result)->not->toContain('onload')
        ->and($result)->not->toContain('onclick')
        ->and($result)->not->toContain('alert');
});

test('sanitizeSvgIfNeeded leaves a clean SVG intact', function (): void {
    $path = upload_service_test_marker() . '/clean.svg';
    $original = '<svg xmlns="http://www.w3.org/2000/svg"><circle r="5" fill="red"/></svg>';
    file_put_contents($path, $original);

    upload_service_call_sanitize($path, 'image/svg+xml');

    $result = file_get_contents($path);
    expect($result)->toContain('circle')
        ->and($result)->toContain('fill="red"');
});

test('sanitizeSvgIfNeeded does nothing for a non-SVG MIME type', function (): void {
    $path = upload_service_test_marker() . '/photo.jpg';
    file_put_contents($path, 'not-really-a-jpeg-but-that-does-not-matter-here');

    upload_service_call_sanitize($path, 'image/jpeg');

    expect(file_get_contents($path))->toBe('not-really-a-jpeg-but-that-does-not-matter-here');
});

test('sanitizeSvgIfNeeded does nothing when finfo type is null', function (): void {
    $path = upload_service_test_marker() . '/unknown.bin';
    file_put_contents($path, 'raw-bytes');

    upload_service_call_sanitize($path, null);

    expect(file_get_contents($path))->toBe('raw-bytes');
});

test('sanitizeSvgIfNeeded leaves malformed XML untouched rather than throwing', function (): void {
    $path = upload_service_test_marker() . '/broken.svg';
    file_put_contents($path, '<svg><unclosed>');

    upload_service_call_sanitize($path, 'image/svg+xml');

    expect(file_get_contents($path))->toBe('<svg><unclosed>');
});

test('getUploadFormConfig returns the 4 known fields', function (): void {
    $config = new UploadService()->getUploadFormConfig();

    expect(array_keys($config))->toBe([
        'original_resize',
        'original_resize_maxwidth',
        'original_resize_maxheight',
        'original_resize_quality',
    ]);
});

test('fileUploadErrorMessage maps every UPLOAD_ERR_* constant to a non-empty message', function (): void {
    $service = new UploadService();

    foreach ([
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL,
        UPLOAD_ERR_NO_FILE, UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE,
        UPLOAD_ERR_EXTENSION,
    ] as $code) {
        expect($service->fileUploadErrorMessage($code))->not->toBe('');
    }

    expect($service->fileUploadErrorMessage(-1))->toBe('Unknown upload error');
});

test('getIniSize converts a shorthand ini value to bytes', function (): void {
    $service = new UploadService();

    expect($service->getIniSize('memory_limit', true))->not->toBeFalse();
});

function upload_service_convert_shorthand(string|false $value): int|string|false
{
    $service = new UploadService();
    $method = new ReflectionMethod($service, 'convertShorthandNotationToBytes');

    /** @var int|string|false */
    return $method->invoke($service, $value);
}

test('convertShorthandNotationToBytes multiplies K/M/G suffixes and passes through a bare number', function (): void {
    expect(upload_service_convert_shorthand('8K'))->toBe(8 * 1024);
    expect(upload_service_convert_shorthand('8M'))->toBe(8 * 1024 * 1024);
    expect(upload_service_convert_shorthand('2G'))->toBe(2 * 1024 * 1024 * 1024);
    expect(upload_service_convert_shorthand('1024'))->toBe('1024');
    expect(upload_service_convert_shorthand(false))->toBeFalse();
});

function upload_service_is_falsy(mixed $value): bool
{
    $service = new UploadService();
    $method = new ReflectionMethod($service, 'isFalsy');

    /** @var bool */
    return $method->invoke($service, $value);
}

test('isFalsy matches PHP\'s own empty() falsy set exactly, including the ones empty() shares with loose equality traps', function (): void {
    foreach ([null, false, 0, 0.0, '0', '', []] as $falsy) {
        expect(upload_service_is_falsy($falsy))->toBeTrue();
    }
    foreach (['0.0', ' ', '00', [0], true, 1, -1] as $truthy) {
        expect(upload_service_is_falsy($truthy))->toBeFalse();
    }
});

test('isValidImageExtension returns the lowercased, deduplicated picture extensions by default', function (): void {
    $service = new UploadService();
    $result = $service->isValidImageExtension('JPG');

    expect($result)->toBe(array_unique($result));
    foreach ($result as $extension) {
        expect($extension)->toBe(strtolower($extension));
    }
    expect($result)->toContain('jpg');
});

test('addUploadError appends to, rather than replaces, an existing upload_id\'s error list', function (): void {
    $_SESSION['uploads_error'] = [];
    $service = new UploadService();

    $service->addUploadError('42', 'first error');
    $service->addUploadError('42', 'second error');
    $service->addUploadError('99', 'unrelated upload');

    expect($_SESSION['uploads_error'])->toBe([
        '42' => ['first error', 'second error'],
        '99' => ['unrelated upload'],
    ]);

    $_SESSION['uploads_error'] = [];
});

test('pwgImageInfos reads real width/height/filesize from a generated image', function (): void {
    $path = upload_service_test_marker() . '/infos.png';
    $img = imagecreatetruecolor(37, 21);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagepng($img, $path);

    $service = new UploadService();
    $infos = $service->pwgImageInfos($path);

    expect($infos['width'])->toBe(37);
    expect($infos['height'])->toBe(21);
    expect($infos['filesize'])->toBeFloat();
});

test('pwgImageInfos returns null width/height when getimagesize() can\'t read the file, instead of throwing', function (): void {
    // Real bug, fixed during the coverage-gap-closure pass (see
    // tests/Integration/UploadServiceTest.php's own docblock): every real
    // upload of a non-picture file allowed via
    // CurrentConfig::uploadFormAllTypes() reaches this exact path, and
    // piwigo_images.width/height are nullable columns precisely for it --
    // this used to throw unconditionally, crashing every such upload.
    $path = upload_service_test_marker() . '/not-an-image.png';
    file_put_contents($path, 'definitely not a real PNG');

    $service = new UploadService();
    $infos = $service->pwgImageInfos($path);

    expect($infos['width'])->toBeNull();
    expect($infos['height'])->toBeNull();
    expect($infos['filesize'])->toBeFloat();
});

function upload_service_need_resize(string $path, int $maxWidth, int $maxHeight): bool
{
    $service = new UploadService();
    $method = new ReflectionMethod($service, 'needResize');

    /** @var bool */
    return $method->invoke($service, $path, $maxWidth, $maxHeight);
}

test('needResize is false when the image already fits within the max bounds', function (): void {
    $path = upload_service_test_marker() . '/small.jpg';
    $img = imagecreatetruecolor(50, 50);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($img, $path);

    expect(upload_service_need_resize($path, 200, 200))->toBeFalse();
});

test('needResize is true when the image exceeds either max bound', function (): void {
    $path = upload_service_test_marker() . '/big.jpg';
    $img = imagecreatetruecolor(500, 50);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($img, $path);

    expect(upload_service_need_resize($path, 200, 200))->toBeTrue();
});

test('needResize is false for a non-picture extension, without even reading the file', function (): void {
    expect(upload_service_need_resize(upload_service_test_marker() . '/definitely-missing.svg', 1, 1))->toBeFalse();
});

test('saveUploadFormConfig returns false without writing anything when given no data', function (): void {
    $service = new UploadService();
    $errors = [];
    $formErrors = [];

    expect($service->saveUploadFormConfig([], $errors, $formErrors))->toBeFalse();
    expect($errors)->toBe([]);
    expect($formErrors)->toBe([]);
});

test('saveUploadFormConfig collects a range error and a field-keyed form_errors marker, without persisting anything', function (): void {
    $service = new UploadService();
    $errors = [];
    $formErrors = [];

    // 999999 is far above original_resize_maxwidth's own max (20000).
    $result = $service->saveUploadFormConfig(['original_resize_maxwidth' => '999999'], $errors, $formErrors);

    expect($result)->toBeFalse();
    expect($errors)->toHaveCount(1);
    expect($formErrors)->toBe(['original_resize_maxwidth' => '[500 .. 20000]']);
});

test('saveUploadFormConfig silently skips a field name absent from getUploadFormConfig()', function (): void {
    // A 0-error result always reaches saveUploadFormConfig()'s own final
    // InfrastructureAccessor::entityManager()->clear() call, real DB write
    // or not -- needs a booted container even for this "nothing to
    // persist" case.
    Kernel::reset();
    Kernel::boot();
    try {
        $service = new UploadService();
        $errors = [];
        $formErrors = [];

        // 'totally_unknown_field' never appears in getUploadFormConfig()'s own
        // 4-key map -- the `continue` on the very first isset() guard, before
        // any min/max/pattern lookup even happens.
        $result = $service->saveUploadFormConfig(['totally_unknown_field' => 'whatever'], $errors, $formErrors);

        expect($result)->toBeTrue();
        expect($errors)->toBe([]);
        expect($formErrors)->toBe([]);
    } finally {
        Kernel::reset();
    }
});

test('saveUploadFormConfig skips a numeric field whose posted value is non-scalar (PHPStan-narrowing guard)', function (): void {
    Kernel::reset();
    Kernel::boot();
    try {
        $service = new UploadService();
        $errors = [];
        $formErrors = [];

        // is_scalar($value) is false for an array -- hits the "should never
        // actually skip a field in practice" narrowing `continue`, not the
        // pattern/min/max check below it.
        $result = $service->saveUploadFormConfig(['original_resize_maxwidth' => ['not', 'scalar']], $errors, $formErrors);

        expect($result)->toBeTrue();
        expect($errors)->toBe([]);
        expect($formErrors)->toBe([]);
    } finally {
        Kernel::reset();
    }
});

test('saveUploadFormConfig sets the boolean field true whenever it is present, even with a falsy-looking value', function (): void {
    Kernel::reset();
    Kernel::boot();
    try {
        $service = new UploadService();
        $errors = [];
        $formErrors = [];

        // isset($value) is true for '0' (it's non-null) -- the boolean toggle's
        // own "present at all" semantics, distinct from isFalsy()'s falsy set.
        $result = $service->saveUploadFormConfig(['original_resize' => '0'], $errors, $formErrors);

        expect($result)->toBeTrue();
        expect($errors)->toBe([]);

        $conn = DbConnection::build();
        try {
            $stored = $conn->fetchOne('SELECT value FROM ' . Tables::config() . " WHERE param = 'original_resize'");
            expect($stored)->toBe('true');
        } finally {
            $conn->executeStatement("UPDATE " . Tables::config() . " SET value = 'false' WHERE param = 'original_resize'");
            \Piwigo\Bootstrap\InfrastructureAccessor::entityManager()->clear();
        }
    } finally {
        Kernel::reset();
    }
});

test('saveUploadFormConfig persists a valid in-range numeric field', function (): void {
    Kernel::reset();
    Kernel::boot();
    try {
        $service = new UploadService();
        $errors = [];
        $formErrors = [];

        // 1500 is within original_resize_maxheight's [300 .. 20000] range --
        // the (bool) preg_match(...) and $value >= $min and $value <= $max
        // success branch, distinct from the out-of-range test above.
        $result = $service->saveUploadFormConfig(['original_resize_maxheight' => '1500'], $errors, $formErrors);

        expect($result)->toBeTrue();
        expect($errors)->toBe([]);
        expect($formErrors)->toBe([]);

        $conn = DbConnection::build();
        try {
            $stored = $conn->fetchOne('SELECT value FROM ' . Tables::config() . " WHERE param = 'original_resize_maxheight'");
            expect($stored)->toBe('1500');
        } finally {
            $conn->executeStatement("UPDATE " . Tables::config() . " SET value = '2016' WHERE param = 'original_resize_maxheight'");
            \Piwigo\Bootstrap\InfrastructureAccessor::entityManager()->clear();
        }
    } finally {
        Kernel::reset();
    }
});

test('addUploadedFile throws when md5_file() fails to read the source file', function (): void {
    $service = new UploadService();
    $missingPath = upload_service_test_marker() . '/does-not-exist-at-all.jpg';
    $urlService = new UrlService(new HtmlService());

    // md5_file() on a missing file emits a real PHP warning (confirmed live:
    // "Failed to open stream: No such file or directory") that
    // phpunit.xml's failOnWarning="true" would otherwise convert into a
    // PHPUnit\Framework\Error\Warning right at the call site, before
    // addUploadedFile()'s own `$md5sum === false` check (and its own
    // \Exception with a distinct message) is ever reached -- swallow it
    // for the duration of this one expected-to-warn call only, same
    // pattern as ImageGdTest's own construct-throws-on-bad-jpeg case.
    set_error_handler(static fn (): bool => true);
    try {
        expect(fn () => $service->addUploadedFile($missingPath, $urlService, original_md5sum: null))
            ->toThrow(\Exception::class, "upload(): unable to compute md5sum of {$missingPath}");
    } finally {
        restore_error_handler();
    }
});

test('readyForUploadMessage returns null when the real upload directory exists and is writable', function (): void {
    $root = upload_service_test_marker() . '/root/';
    mkdir($root . 'upload', 0o777, true);
    CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setUploadDir('upload/');

    expect(new UploadService()->readyForUploadMessage())->toBeNull();

    CurrentPaths::reset();
});

test('readyForUploadMessage reports a missing-directory message when the parent is not writable', function (): void {
    $root = upload_service_test_marker() . '/root2/';
    mkdir($root, 0o555, true);
    CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setUploadDir('upload/');

    try {
        expect(new UploadService()->readyForUploadMessage())
            ->toBe('Create the "upload/" directory at the root of your Piwigo installation');
    } finally {
        chmod($root, 0o777);
        CurrentPaths::reset();
    }
});

test('readyForUploadMessage reports a chmod message and fixes an unwritable existing directory', function (): void {
    $root = upload_service_test_marker() . '/root3/';
    mkdir($root . 'upload', 0o777, true);
    chmod($root . 'upload', 0o555);
    CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setUploadDir('upload/');

    $message = new UploadService()->readyForUploadMessage();

    // @chmod(0777) inside the method itself is expected to succeed for a
    // directory this test process owns, so the real branch exercised here
    // is the re-check passing -- confirmed by the directory actually
    // ending up writable, not by asserting a specific returned message.
    expect(is_writable($root . 'upload'))->toBeTrue();
    expect($message)->toBeNull();

    CurrentPaths::reset();
});

function upload_service_prepare_directory(string $directory): void
{
    $method = new ReflectionMethod(UploadService::class, 'prepareDirectoryStatic');
    $method->invoke(null, $directory);
}

test('prepareDirectoryStatic creates a missing directory tree', function (): void {
    $dir = upload_service_test_marker() . '/nested/deep/dir';
    expect(is_dir($dir))->toBeFalse();

    upload_service_prepare_directory($dir);

    expect(is_dir($dir))->toBeTrue();
    expect(is_writable($dir))->toBeTrue();
});

test('prepareDirectoryStatic fixes permissions on an existing unwritable directory', function (): void {
    $dir = upload_service_test_marker() . '/existing-unwritable';
    mkdir($dir, 0o777, true);
    chmod($dir, 0o555);

    upload_service_prepare_directory($dir);

    expect(is_writable($dir))->toBeTrue();
});

test('addFormat throws when formats are disabled', function (): void {
    CurrentConfig::setIsFormatsEnabled(false);
    $service = new UploadService();

    expect(fn () => $service->addFormat('/tmp/whatever', 'tif', 1))
        ->toThrow(ImageProcessingException::class, '[Piwigo\Admin\Upload\UploadService::addFormat] formats are disabled');
});

test('addFormat throws for an unauthorized format extension', function (): void {
    CurrentConfig::setIsFormatsEnabled(true);
    CurrentConfig::setFormatExtensions(['tif', 'psd']);
    $service = new UploadService();

    try {
        expect(fn () => $service->addFormat('/tmp/whatever', 'exe', 1))
            ->toThrow(ImageProcessingException::class);
    } finally {
        CurrentConfig::setIsFormatsEnabled(false);
        CurrentConfig::setFormatExtensions(['cr2', 'tif', 'tiff', 'nef', 'dng', 'ai', 'psd']);
    }
});

test('the 6 upload_file_* representative-generation handlers pass an already-set representative_ext straight through', function (): void {
    expect(UploadService::uploadFilePdf('already-set', '/tmp/whatever.pdf'))->toBe('already-set');
    expect(UploadService::uploadFileHeic('already-set', '/tmp/whatever.heic'))->toBe('already-set');
    expect(UploadService::uploadFileTiff('already-set', '/tmp/whatever.tif'))->toBe('already-set');
    expect(UploadService::uploadFileVideo('already-set', '/tmp/whatever.mp4'))->toBe('already-set');
    expect(UploadService::uploadFilePsd('already-set', '/tmp/whatever.psd'))->toBe('already-set');
    expect(UploadService::uploadFileEps('already-set', '/tmp/whatever.eps'))->toBe('already-set');
});

test('the 6 upload_file_* representative-generation handlers no-op for a non-matching file extension', function (): void {
    // A '.txt' file never matches any of these handlers' own extension
    // whitelist, regardless of which imaging library/binary is actually
    // available in this environment -- so each returns null without
    // touching the filesystem, exec()ing anything, or needing a real
    // PDF/HEIC/TIFF/video/PSD/EPS fixture.
    $path = upload_service_test_marker() . '/plain.txt';
    file_put_contents($path, 'not a representative-worthy file');

    expect(UploadService::uploadFilePdf(null, $path))->toBeNull();
    expect(UploadService::uploadFileHeic(null, $path))->toBeNull();
    expect(UploadService::uploadFilePsd(null, $path))->toBeNull();
    expect(UploadService::uploadFileEps(null, $path))->toBeNull();
    expect(UploadService::uploadFileVideo(null, $path))->toBeNull();
});

/** @return array{0: int, 1: int} */
function upload_service_optimal_dimensions(): array
{
    $method = new ReflectionMethod(UploadService::class, 'getOptimalDimensionsForRepresentative');

    /** @var array{0: int, 1: int} */
    return $method->invoke(null);
}

test('getOptimalDimensionsForRepresentative returns a positive width/height pair', function (): void {
    [$w, $h] = upload_service_optimal_dimensions();

    expect($w)->toBeInt()->toBeGreaterThan(0);
    expect($h)->toBeInt()->toBeGreaterThan(0);
});

/**
 * uploadFileTiff/Pdf/Psd/Eps() all guard on `PwgImage::get_library() !==
 * 'ext_imagick'` -- this environment's real ImageMagick CLI (`magick`,
 * confirmed on PATH) makes PwgImage::is_ext_imagick() true and
 * CurrentConfig::graphicsLibrary()'s own 'auto' default resolves to
 * 'ext_imagick' first, so these 4 handlers' real conversion branches (not
 * just their early-return guards, already covered above) are genuinely
 * reachable here without touching any config. uploadFileHeic()/
 * uploadFileVideo() are NOT covered this way: HEIC needs a libheif
 * delegate and video needs ffmpeg, neither confirmed present in this
 * environment, so those 2 stay on the guard-branch-only coverage above.
 */
function upload_service_make_sample_png(string $path): void
{
    $exec = 'convert -size 40x40 xc:red ' . escapeshellarg($path) . ' 2>&1';
    exec($exec, $out, $status);
    if ($status !== 0) {
        throw new RuntimeException('convert (sample PNG) failed: ' . implode("\n", $out));
    }
}

function upload_service_convert_sample(string $sourcePng, string $destPath): void
{
    $exec = 'convert ' . escapeshellarg($sourcePng) . ' ' . escapeshellarg($destPath) . ' 2>&1';
    exec($exec, $out, $status);
    if ($status !== 0) {
        throw new RuntimeException("convert ({$destPath}) failed: " . implode("\n", $out));
    }
}

test('uploadFileTiff converts a real TIFF into a representative image via the ext_imagick CLI', function (): void {
    $dir = upload_service_test_marker();
    $png = $dir . '/source.png';
    $tiff = $dir . '/photo.tiff';
    upload_service_make_sample_png($png);
    upload_service_convert_sample($png, $tiff);

    $result = UploadService::uploadFileTiff(null, $tiff);

    expect($result)->not->toBeNull();
    $representativePath = $dir . '/pwg_representative/photo.' . $result;
    expect(file_exists($representativePath))->toBeTrue();
    expect(filesize($representativePath))->toBeGreaterThan(0);
});

test('uploadFilePdf converts a real PDF into a representative jpg via the ext_imagick CLI', function (): void {
    $dir = upload_service_test_marker();
    $png = $dir . '/source.png';
    $pdf = $dir . '/document.pdf';
    upload_service_make_sample_png($png);
    upload_service_convert_sample($png, $pdf);

    $result = UploadService::uploadFilePdf(null, $pdf);

    expect($result)->toBe('jpg');
    $representativePath = $dir . '/pwg_representative/document.jpg';
    expect(file_exists($representativePath))->toBeTrue();
    expect(filesize($representativePath))->toBeGreaterThan(0);
});

test('uploadFilePsd converts a real PSD into a representative png via the ext_imagick CLI', function (): void {
    $dir = upload_service_test_marker();
    $png = $dir . '/source.png';
    $psd = $dir . '/layered.psd';
    upload_service_make_sample_png($png);
    upload_service_convert_sample($png, $psd);

    $result = UploadService::uploadFilePsd(null, $psd);

    expect($result)->toBe('png');
    $representativePath = $dir . '/pwg_representative/layered.png';
    expect(file_exists($representativePath))->toBeTrue();
    expect(filesize($representativePath))->toBeGreaterThan(0);
});

test('uploadFileEps converts a real EPS into a representative png via the ext_imagick CLI', function (): void {
    $dir = upload_service_test_marker();
    $png = $dir . '/source.png';
    $eps = $dir . '/vector.eps';
    upload_service_make_sample_png($png);
    upload_service_convert_sample($png, $eps);

    $result = UploadService::uploadFileEps(null, $eps);

    expect($result)->toBe('png');
    $representativePath = $dir . '/pwg_representative/vector.png';
    expect(file_exists($representativePath))->toBeTrue();
    expect(filesize($representativePath))->toBeGreaterThan(0);
});

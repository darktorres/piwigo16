<?php

declare(strict_types=1);

use Piwigo\Admin\Upload\UploadService;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Logger;

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

afterEach(function (): void {
    CurrentLogger::reset();
    $dir = upload_service_test_marker();
    $files = glob($dir . '/*');
    foreach ($files !== false ? $files : [] as $file) {
        unlink($file);
    }
    rmdir($dir);
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

test('pwgImageInfos throws when getimagesize() can\'t read the file', function (): void {
    $path = upload_service_test_marker() . '/not-an-image.png';
    file_put_contents($path, 'definitely not a real PNG');

    $service = new UploadService();

    expect(fn () => $service->pwgImageInfos($path))->toThrow(\Exception::class);
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

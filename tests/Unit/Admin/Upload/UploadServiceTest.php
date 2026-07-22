<?php

declare(strict_types=1);

// UploadService calls the real l10n() (unqualified, resolves to the
// global namespace) for its own validation/error messages -- a real,
// stable, already-migrated function, but one that needs full app
// bootstrap (LangService/Translator) this isolated Unit test deliberately
// doesn't load. Same "minimal stub to load standalone" pattern as
// tests/Integration/PermalinkServiceTest.php.
if (! function_exists('l10n')) {
    function l10n(string $key, mixed ...$args): string
    {
        return $args === [] ? $key : vsprintf($key, array_map(static fn (mixed $a): string => is_scalar($a) ? (string) $a : '', $args));
    }
}

use Piwigo\Admin\Upload\UploadService;

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
});

afterEach(function (): void {
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

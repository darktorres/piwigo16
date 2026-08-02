<?php

declare(strict_types=1);

use Piwigo\Admin\Image\ImageProcessingException;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
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

test('sanitizeSvgIfNeeded strips a DOCTYPE declaration, not replacing it with some other text', function (): void {
    $path = upload_service_test_marker() . '/doctype.svg';
    file_put_contents($path, "<!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd\">\n" . '<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>');

    upload_service_call_sanitize($path, 'image/svg+xml');

    $result = file_get_contents($path);
    expect($result)->not->toContain('DOCTYPE')
        ->and($result)->not->toContain('DTD')
        ->and($result)->toContain('circle');
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

test('sanitizeSvgIfNeeded returns without modifying anything when the source file cannot be read', function (): void {
    // A real, unreadable file (0000, torres owns it but no read bit for
    // anyone) -- file_get_contents() genuinely returns false here, not a
    // mock, matching this suite's own filesystem-safety convention (see
    // this file's own docblock). torres is a non-root user in this
    // environment (confirmed via `id`), so owning a file does not bypass
    // its own permission bits, unlike a common misconception.
    $path = upload_service_test_marker() . '/unreadable.svg';
    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>');
    chmod($path, 0o000);

    // file_get_contents() on a permission-denied file emits a real PHP
    // warning ("failed to open stream: Permission denied") that
    // phpunit.xml's failOnWarning="true" would otherwise turn into a
    // failure right at the call site -- same suppression pattern as
    // addUploadedFile's own md5_file()-read-failure test further down.
    set_error_handler(static fn (): bool => true);
    try {
        upload_service_call_sanitize($path, 'image/svg+xml');
    } finally {
        restore_error_handler();
        chmod($path, 0o644);
    }

    expect(file_get_contents($path))->toContain('circle');
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

test('getUploadFormConfig returns the exact default/min/max/pattern/can_be_null shape for every field', function (): void {
    $config = new UploadService()->getUploadFormConfig();

    expect($config['original_resize'])->toBe([
        'default' => false,
        'min' => null,
        'max' => null,
        'pattern' => null,
        'can_be_null' => false,
        'error_message' => null,
    ]);
    expect($config['original_resize_maxwidth'])->toBe([
        'default' => 2000,
        'min' => 500,
        'max' => 20000,
        'pattern' => '/^\d+$/',
        'can_be_null' => false,
        'error_message' => Lang::t('The original maximum width must be a number between %d and %d'),
    ]);
    expect($config['original_resize_maxheight'])->toBe([
        'default' => 2000,
        'min' => 300,
        'max' => 20000,
        'pattern' => '/^\d+$/',
        'can_be_null' => false,
        'error_message' => Lang::t('The original maximum height must be a number between %d and %d'),
    ]);
    expect($config['original_resize_quality'])->toBe([
        'default' => 95,
        'min' => 50,
        'max' => 98,
        'pattern' => '/^\d+$/',
        'can_be_null' => false,
        'error_message' => Lang::t('The original image quality must be a number between %d and %d'),
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

test('fileUploadErrorMessage embeds the real upload_max_filesize value for UPLOAD_ERR_INI_SIZE', function (): void {
    $service = new UploadService();

    expect($service->fileUploadErrorMessage(UPLOAD_ERR_INI_SIZE))
        ->toBe('The uploaded file exceeds the upload_max_filesize directive in php.ini: ' . ini_get('upload_max_filesize') . 'B');
});

test('getIniSize converts a shorthand ini value to bytes', function (): void {
    $service = new UploadService();

    expect($service->getIniSize('memory_limit', true))->not->toBeFalse();
});

test('getIniSize returns the raw ini value unconverted when in_bytes is false', function (): void {
    $service = new UploadService();

    expect($service->getIniSize('upload_max_filesize', false))->toBe(ini_get('upload_max_filesize'));
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

test('isValidImageExtension actually deduplicates case-variant extensions, not just extensions that happen not to collide', function (): void {
    // The pre-existing tests only ever compare $result against its own
    // array_unique() (`toBe(array_unique($result))`), which is trivially
    // true for any array with no duplicates *before* array_unique() ran --
    // real config data never has case-variant dupes, so those tests can't
    // tell a real array_unique() call apart from a removed one. Injecting
    // deliberate case-variant duplicates is the only way to prove
    // dedup actually happens.
    CurrentConfig::setPictureExtensions(['JPG', 'jpg', 'PNG']);
    try {
        $service = new UploadService();
        $result = $service->isValidImageExtension('JPG');

        expect($result)->toHaveCount(2)
            ->and(array_values($result))->toBe(['jpg', 'png']);
    } finally {
        CurrentConfig::setPictureExtensions(['jpg', 'jpeg', 'png', 'gif', 'webp']);
    }
});

test('isValidImageExtension returns the lowercased, deduplicated file extensions when all types are allowed', function (): void {
    CurrentConfig::setUploadFormAllTypes(true);
    try {
        $service = new UploadService();
        $result = $service->isValidImageExtension('PDF');

        expect($result)->toBe(array_unique($result));
        foreach ($result as $extension) {
            expect($extension)->toBe(strtolower($extension));
        }
        // 'pdf' is a CurrentConfig::fileExtensions() default entry but not a
        // CurrentConfig::pictureExtensions() one -- only reachable here
        // through the uploadFormAllTypes()-true branch above.
        expect($result)->toContain('pdf');
    } finally {
        CurrentConfig::setUploadFormAllTypes(false);
    }
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

/**
 * A real unset(), but performed through a by-ref parameter rather than a
 * literal `unset($_SESSION[...])` in the test body -- PHPStan otherwise
 * remembers the removed key as permanently absent from $_SESSION even
 * past the addUploadError() call below that puts it back, since it has
 * no visibility into that method's own $_SESSION write.
 * @param array<array-key, mixed> $superglobal
 */
function upload_service_unset_key(array &$superglobal, string $key): void
{
    unset($superglobal[$key]);
}

test('addUploadError initializes uploads_error when it is missing or not an array', function (): void {
    upload_service_unset_key($_SESSION, 'uploads_error');
    $service = new UploadService();

    $service->addUploadError('7', 'boom');

    expect($_SESSION['uploads_error'])->toBe(['7' => ['boom']]);

    // The `! is_array(...)` half of the same guard -- a stray non-array
    // value (e.g. left over from unrelated code) is replaced, not merged
    // into.
    $_SESSION['uploads_error'] = 'not-an-array';
    $service->addUploadError('8', 'also boom');
    expect($_SESSION['uploads_error'])->toBe(['8' => ['also boom']]);

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
    $realBytes = filesize($path);
    if ($realBytes === false) {
        throw new RuntimeException('filesize failed');
    }

    expect($infos['width'])->toBe(37);
    expect($infos['height'])->toBe(21);
    // Exact floor(bytes / 1024), not just "some float" -- distinguishes
    // floor() from round()/ceil() and /1024 from *1024.
    expect($infos['filesize'])->toBe(floor($realBytes / 1024));
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

test('pwgImageInfos throws when filesize() fails for a path that does not exist at all', function (): void {
    $service = new UploadService();
    $missing = upload_service_test_marker() . '/does-not-exist-anywhere.jpg';

    // getimagesize() on a missing path also warns (handled the same way the
    // Unit "returns null width/height" test above already accepts, via its
    // own $width/$height=null fallback), then falls through to filesize(),
    // which warns and returns false too -- both real PHP warnings
    // suppressed for the duration of this one expected-to-warn call, same
    // pattern as addUploadedFile's own md5_file()-read-failure test below.
    set_error_handler(static fn (): bool => true);
    try {
        expect(fn () => $service->pwgImageInfos($missing))
            ->toThrow(Exception::class, 'filesize() failed for ' . $missing);
    } finally {
        restore_error_handler();
    }
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

test('needResize is false when getimagesize() cannot decode a picture-extension file, instead of throwing', function (): void {
    // '.jpg' passes needResize()'s own extension whitelist (unlike the
    // non-picture-extension test above, which never even reaches
    // getimagesize()), but the real content here isn't a decodable image at
    // all -- can't determine dimensions, so can't tell whether a resize is
    // needed.
    $path = upload_service_test_marker() . '/broken.jpg';
    file_put_contents($path, 'not a real jpeg at all');

    expect(upload_service_need_resize($path, 100, 100))->toBeFalse();
});

/**
 * Builds a real, minimal JPEG (via GD) with a hand-built EXIF APP1 segment
 * spliced in right after the SOI marker -- same technique (and same
 * rationale: neither ImageMagick's `-set/-define exif:Orientation=` nor a
 * synthetic `xc:` canvas actually persists an EXIF profile) as
 * tests/Unit/Admin/Image/PwgImageTest.php's own pwgImageMakeJpegWithOrientation(),
 * duplicated locally (rather than shared) since that file declares it in
 * the same global namespace this one also uses -- kept self-contained
 * instead of relying on cross-file load-order for a global function.
 * Parameterized by width/height (unlike the original, hardcoded 20x20)
 * so the caller can build a genuinely non-square source, needed to make
 * needResize()'s own width/height swap for a rotated orientation
 * observable via its bool return.
 */
function upload_service_make_jpeg_with_orientation(int $orientation, int $width, int $height): string
{
    $tiff = 'II' . pack('v', 42) . pack('V', 8);
    $ifd = pack('v', 1)
        . pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $orientation) . pack('v', 0)
        . pack('V', 0);
    $exifHeader = "Exif\x00\x00" . $tiff . $ifd;
    $app1 = "\xFF\xE1" . pack('n', strlen($exifHeader) + 2) . $exifHeader;

    assert($width >= 1 && $height >= 1);
    $img = imagecreatetruecolor($width, $height);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    ob_start();
    imagejpeg($img);
    // ob_get_clean() can only return false when there's no active output
    // buffer -- ob_start() immediately above guarantees one here.
    $base = ob_get_clean();

    return substr($base, 0, 2) . $app1 . substr($base, 2);
}

test('needResize swaps width/height for a rotated EXIF orientation before comparing against the max bounds', function (): void {
    // Raw pixel dims are portrait (50x200); EXIF orientation 6 makes
    // PwgImage::get_rotation_angle() report a 270-degree rotation (see
    // PwgImageTest's own comment for the same orientation value), meaning a
    // real viewer displays this landscape at 200x50 -- without the
    // width/height swap this method's own docblock explains, a
    // max_width=100 threshold would never catch a photo that is genuinely
    // 200px wide once rotated.
    $path = upload_service_test_marker() . '/rotated.jpg';
    file_put_contents($path, upload_service_make_jpeg_with_orientation(6, 50, 200));

    expect(upload_service_need_resize($path, 100, 300))->toBeTrue();
});

test('needResize fully swaps both width AND height, not just one of them, for a 270-degree rotation', function (): void {
    // The test above only proves the swap happened *somewhere* (width
    // alone already triggers "too big"). Here neither raw axis exceeds
    // its bound, but a swap that only reassigns $width (leaving $height at
    // its original, unswapped value of 200) would wrongly still trip the
    // max_height=100 bound -- isolating that a genuinely full swap landed
    // both sides correctly (200x50, both within bounds) is only provable
    // via a false result.
    $path = upload_service_test_marker() . '/rotated-270-both-swapped.jpg';
    file_put_contents($path, upload_service_make_jpeg_with_orientation(6, 50, 200));

    expect(upload_service_need_resize($path, 300, 100))->toBeFalse();
});

test('needResize also swaps width/height for a 90-degree rotation (EXIF orientation 7/8)', function (): void {
    // Orientation 6 above maps to a 270-degree rotation; 8 is the other
    // real-world case (get_rotation_angle()'s own orientation 7/8 => 90
    // mapping) -- a boundary distinct from 270, so this can't be satisfied
    // by only recognizing 270 as "needs a swap".
    $path = upload_service_test_marker() . '/rotated-90.jpg';
    file_put_contents($path, upload_service_make_jpeg_with_orientation(8, 50, 200));

    expect(upload_service_need_resize($path, 300, 100))->toBeFalse();
});

test('needResize does not resize when width/height exactly equal the max bounds', function (): void {
    // Strictly `>`, not `>=` -- a photo exactly at the configured max
    // dimensions doesn't need resizing.
    $path = upload_service_test_marker() . '/exact-bounds.jpg';
    $img = imagecreatetruecolor(200, 100);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($img, $path);

    expect(upload_service_need_resize($path, 200, 100))->toBeFalse();
});

test('needResize is case-insensitive about the file extension', function (): void {
    $path = upload_service_test_marker() . '/uppercase.JPG';
    $img = imagecreatetruecolor(500, 50);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    imagejpeg($img, $path);

    expect(upload_service_need_resize($path, 200, 200))->toBeTrue();
});

test('needResize logs the exact current/max dimensions when a resize is needed', function (): void {
    $logDir = upload_service_test_marker() . '/logs';
    mkdir($logDir, 0o777, true);
    CurrentLogger::set(new Logger(['severity' => Logger::INFO, 'directory' => $logDir, 'filename' => 'needresize.log']));

    try {
        $path = upload_service_test_marker() . '/logged-too-big.jpg';
        $img = imagecreatetruecolor(500, 50);
        if ($img === false) {
            throw new RuntimeException('imagecreatetruecolor failed');
        }
        imagejpeg($img, $path);

        upload_service_need_resize($path, 200, 20);

        $logged = file_get_contents($logDir . '/needresize.log');
        expect($logged)->toContain($path . ' is too big (current=500x50px Vs max=200x20px)');
    } finally {
        CurrentLogger::set(new Logger(['severity' => Logger::OFF]));
    }
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

test('saveUploadFormConfig keeps processing later fields after skipping an unknown one, not stopping the whole loop', function (): void {
    Kernel::reset();
    Kernel::boot();
    try {
        $service = new UploadService();
        $errors = [];
        $formErrors = [];

        $result = $service->saveUploadFormConfig(
            ['totally_unknown_field' => 'whatever', 'original_resize_maxheight' => '1500'],
            $errors,
            $formErrors
        );

        expect($result)->toBeTrue()
            ->and($errors)->toBe([]);

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

test('saveUploadFormConfig rejects a value that only satisfies the pattern check via a mixed-up and/or precedence', function (): void {
    // Distinguishes `(pattern-matches and >=min) and <=max` from a
    // precedence-shifted `pattern-matches or (>=min and <=max)` -- a
    // leading-space value fails the digit pattern outright but PHP8's
    // loose string/int comparison still finds it >=min and <=max, which a
    // subtly-wrong `or` would incorrectly accept.
    Kernel::reset();
    Kernel::boot();
    try {
        $service = new UploadService();
        $errors = [];
        $formErrors = [];

        $result = $service->saveUploadFormConfig(['original_resize_maxheight' => ' 15000'], $errors, $formErrors);

        expect($result)->toBeFalse();
        expect($errors)->toHaveCount(1);
    } finally {
        Kernel::reset();
    }
});

test('saveUploadFormConfig rejects a below-minimum value that only satisfies the max check via a mixed-up and/or precedence', function (): void {
    // The other and/or precedence shift: `(pattern-matches and >=min) or
    // <=max` would wrongly accept any small, pattern-matching value just
    // because it's trivially under the max.
    Kernel::reset();
    Kernel::boot();
    try {
        $service = new UploadService();
        $errors = [];
        $formErrors = [];

        $result = $service->saveUploadFormConfig(['original_resize_maxheight' => '100'], $errors, $formErrors);

        expect($result)->toBeFalse();
        expect($errors)->toHaveCount(1);
    } finally {
        Kernel::reset();
    }
});

test('saveUploadFormConfig accepts a value exactly at the min or max boundary', function (): void {
    Kernel::reset();
    Kernel::boot();
    try {
        $service = new UploadService();

        $errorsMin = [];
        $formErrorsMin = [];
        $resultMin = $service->saveUploadFormConfig(['original_resize_maxheight' => '300'], $errorsMin, $formErrorsMin);
        expect($resultMin)->toBeTrue()
            ->and($errorsMin)->toBe([]);

        $errorsMax = [];
        $formErrorsMax = [];
        $resultMax = $service->saveUploadFormConfig(['original_resize_maxheight' => '20000'], $errorsMax, $formErrorsMax);
        expect($resultMax)->toBeTrue()
            ->and($errorsMax)->toBe([]);

        $conn = DbConnection::build();
        $conn->executeStatement("UPDATE " . Tables::config() . " SET value = '2016' WHERE param = 'original_resize_maxheight'");
        \Piwigo\Bootstrap\InfrastructureAccessor::entityManager()->clear();
    } finally {
        Kernel::reset();
    }
});

test('saveUploadFormConfig accepts a real int value without a TypeError, not just a numeric string', function (): void {
    // is_scalar() (the guard above) accepts int as readily as string -- a
    // literal int posted value would throw a TypeError at preg_match()'s
    // own strict `string $subject` parameter without the (string) cast.
    Kernel::reset();
    Kernel::boot();
    try {
        $service = new UploadService();
        $errors = [];
        $formErrors = [];

        $result = $service->saveUploadFormConfig(['original_resize_maxheight' => 1500], $errors, $formErrors);

        expect($result)->toBeTrue()
            ->and($errors)->toBe([]);

        $conn = DbConnection::build();
        $conn->executeStatement("UPDATE " . Tables::config() . " SET value = '2016' WHERE param = 'original_resize_maxheight'");
        \Piwigo\Bootstrap\InfrastructureAccessor::entityManager()->clear();
    } finally {
        Kernel::reset();
    }
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
    // secureDirectory()'s own index.htm write -- confirms that call
    // actually runs, not just the mkdir()/chmod() above.
    expect(file_get_contents($dir . '/index.htm'))->toBe('Not allowed!');
});

test('prepareDirectoryStatic fixes permissions on an existing unwritable directory', function (): void {
    $dir = upload_service_test_marker() . '/existing-unwritable';
    mkdir($dir, 0o777, true);
    chmod($dir, 0o555);

    upload_service_prepare_directory($dir);

    expect(is_writable($dir))->toBeTrue();
});

test('prepareDirectoryStatic throws when mkdir() fails under a real unwritable parent directory', function (): void {
    // A real, deterministic mkdir() failure: torres owns $parent (mode
    // 0555, no write bit), so @mkdir() genuinely cannot create a new entry
    // under it -- same real-permission-bits convention as
    // tests/Browser/AdminConfigurationTest.php's own watermark
    // write-access test (see that file's docblock), not a mock. torres
    // being the owner does not bypass its own permission bits (confirmed
    // via `id`: this process runs as a non-root user).
    $parent = upload_service_test_marker() . '/locked-parent';
    mkdir($parent, 0o555, true);
    $target = $parent . '/never-created';

    // @mkdir() in prepareDirectoryStatic() doesn't suppress this from
    // PHPUnit's own warning collector -- the real mkdir() failure below is
    // the whole point of this test, not a bug, so a real error-handler
    // swap is needed to keep it out of the suite's warning count.
    set_error_handler(static fn (): bool => true);
    try {
        expect(fn () => upload_service_prepare_directory($target))
            ->toThrow(ImageProcessingException::class, 'cannot create directory "' . $target . '"');
        expect(is_dir($target))->toBeFalse();
    } finally {
        restore_error_handler();
        chmod($parent, 0o777);
    }
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
            ->toThrow(
                ImageProcessingException::class,
                '[Piwigo\Admin\Upload\UploadService::addFormat] unexpected format extension "exe" (authorized extensions: tif, psd)'
            );
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

/**
 * uploadFileVideo()'s extension whitelist check (is this file even worth
 * trying ffmpeg on?) is fully reachable without ffmpeg/ffprobe ever
 * actually running: prepareDirectoryStatic() is the very next call after
 * the whitelist guard, well before any exec() -- making the file's parent
 * directory read-only turns "did we pass the guard?" into a cheap,
 * deterministic ImageProcessingException instead of a real (and much
 * slower) ffmpeg round-trip. Same technique for both directions: a
 * matching extension must reach (and therefore throw at)
 * prepareDirectoryStatic(); a non-matching one must return null before
 * ever reaching it.
 */
function upload_service_video_readonly_dir(): string
{
    $dir = upload_service_test_marker() . '/video-readonly-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o777, true);
    chmod($dir, 0o555);

    return $dir;
}

test('uploadFileVideo recognizes every one of its 17 ffmpeg-tested extensions', function (): void {
    $dir = upload_service_video_readonly_dir();
    // mkdir() on a permission-denied directory emits a real PHP warning
    // that phpunit.xml's failOnWarning="true" would otherwise turn into a
    // failure right at the call site -- same suppression pattern as
    // sanitize's own permission-denied-read test further up.
    set_error_handler(static fn (): bool => true);
    try {
        foreach ([
            'wmv', 'mov', 'mkv', 'mp4', 'mpg', 'flv', 'asf', 'xvid', 'divx', 'mpeg',
            'avi', 'rm', 'm4v', 'ogg', 'ogv', 'webm', 'webmv',
        ] as $ext) {
            expect(fn () => UploadService::uploadFileVideo(null, $dir . '/video.' . $ext))
                ->toThrow(ImageProcessingException::class);
        }
    } finally {
        restore_error_handler();
        chmod($dir, 0o777);
    }
});

test('uploadFileVideo matches a video extension case-insensitively', function (): void {
    $dir = upload_service_video_readonly_dir();
    set_error_handler(static fn (): bool => true);
    try {
        expect(fn () => UploadService::uploadFileVideo(null, $dir . '/video.WMV'))
            ->toThrow(ImageProcessingException::class);
    } finally {
        restore_error_handler();
        chmod($dir, 0o777);
    }
});

test('uploadFileVideo returns null for a non-matching extension without even attempting to prepare a directory', function (): void {
    $dir = upload_service_video_readonly_dir();
    try {
        expect(UploadService::uploadFileVideo(null, $dir . '/video.txt'))->toBeNull();
    } finally {
        chmod($dir, 0o777);
    }
});

test('uploadFileVideo logs the exact file_path/representative_ext it was called with', function (): void {
    $logDir = upload_service_test_marker() . '/video-logs';
    mkdir($logDir, 0o777, true);
    CurrentLogger::set(new Logger(['severity' => Logger::INFO, 'directory' => $logDir, 'filename' => 'video.log']));

    try {
        $path = upload_service_test_marker() . '/whatever.mp4';
        UploadService::uploadFileVideo('already-set', $path);

        $logged = file_get_contents($logDir . '/video.log');
        expect($logged)->toContain('uploadFileVideo, $file_path = ' . $path . ', $representative_ext = already-set');
    } finally {
        CurrentLogger::set(new Logger(['severity' => Logger::OFF]));
    }
});

test('the 5 ext_imagick-only handlers return the incoming representative_ext unmodified when the graphics library is not ext_imagick', function (): void {
    // Forces PwgImage::get_library() to resolve to 'gd' instead of this
    // environment's real default 'ext_imagick' (see this file's own
    // "converts a real ... via the ext_imagick CLI" tests further down) --
    // each of these 5 handlers checks the library BEFORE its own extension
    // whitelist, so a bogus, never-read path is enough here; no real
    // PDF/HEIC/TIFF/PSD/EPS fixture or exec() call is reached.
    CurrentConfig::setGraphicsLibrary('gd');
    try {
        expect(UploadService::uploadFilePdf(null, '/tmp/whatever.pdf'))->toBeNull();
        expect(UploadService::uploadFileHeic(null, '/tmp/whatever.heic'))->toBeNull();
        expect(UploadService::uploadFileTiff(null, '/tmp/whatever.tif'))->toBeNull();
        expect(UploadService::uploadFilePsd(null, '/tmp/whatever.psd'))->toBeNull();
        expect(UploadService::uploadFileEps(null, '/tmp/whatever.eps'))->toBeNull();
    } finally {
        CurrentConfig::setGraphicsLibrary('auto');
    }
});

/** @return array{0: int, 1: int} */
function upload_service_optimal_dimensions(): array
{
    $method = new ReflectionMethod(UploadService::class, 'getOptimalDimensionsForRepresentative');

    /** @var array{0: int, 1: int} */
    return $method->invoke(null);
}

test('getOptimalDimensionsForRepresentative computes the exact 1.5x margin from a real defined type', function (): void {
    // The real default ImageStdParams state in this Unit test process
    // happens to have no enabled/disabled type actually reachable via
    // get_all_types() (every real call so far only proved "positive
    // int", the untouched 2000x2000-default*1.5 fallback either way) --
    // directly injecting one real, distinctly-sized DerivativeParams via
    // reflection (then restoring the original maps) is the only way to
    // prove the loop's own array_key_exists()/instanceof/(bool)/(float)
    // cast/1.5 multiplication chain actually runs and computes correctly,
    // rather than just returning the untouched safe default.
    $typeMapProp = new ReflectionProperty(\Piwigo\Image\ImageStdParams::class, 'type_map');
    $disabledMapProp = new ReflectionProperty(\Piwigo\Image\ImageStdParams::class, 'disabled_type_map');
    $originalTypeMap = $typeMapProp->getValue();
    $originalDisabledMap = $disabledMapProp->getValue();

    try {
        $typeMapProp->setValue(null, [
            'xlarge' => new \Piwigo\Image\DerivativeParams(new \Piwigo\Image\SizingParams([1234, 5678])),
        ]);
        $disabledMapProp->setValue(null, []);

        [$w, $h] = upload_service_optimal_dimensions();

        expect($w)->toBe((int) (1234 * 1.5))
            ->and($h)->toBe((int) (5678 * 1.5));
    } finally {
        $typeMapProp->setValue(null, $originalTypeMap);
        $disabledMapProp->setValue(null, $originalDisabledMap);
    }
});

test('getOptimalDimensionsForRepresentative also reads a disabled-by-default type, not just an enabled one', function (): void {
    // Distinguishes `$disabled[$type] ?? null` from a dropped left side --
    // the type above only ever exercised the *enabled* map.
    $typeMapProp = new ReflectionProperty(\Piwigo\Image\ImageStdParams::class, 'type_map');
    $disabledMapProp = new ReflectionProperty(\Piwigo\Image\ImageStdParams::class, 'disabled_type_map');
    $originalTypeMap = $typeMapProp->getValue();
    $originalDisabledMap = $disabledMapProp->getValue();

    try {
        $typeMapProp->setValue(null, []);
        $disabledMapProp->setValue(null, [
            'xlarge' => new \Piwigo\Image\DerivativeParams(new \Piwigo\Image\SizingParams([222, 444])),
        ]);

        [$w, $h] = upload_service_optimal_dimensions();

        expect($w)->toBe((int) (222 * 1.5))
            ->and($h)->toBe((int) (444 * 1.5));
    } finally {
        $typeMapProp->setValue(null, $originalTypeMap);
        $disabledMapProp->setValue(null, $originalDisabledMap);
    }
});

test('getOptimalDimensionsForRepresentative falls back to the exact 2000x2000 safe default when no type is defined at all', function (): void {
    $typeMapProp = new ReflectionProperty(\Piwigo\Image\ImageStdParams::class, 'type_map');
    $disabledMapProp = new ReflectionProperty(\Piwigo\Image\ImageStdParams::class, 'disabled_type_map');
    $originalTypeMap = $typeMapProp->getValue();
    $originalDisabledMap = $disabledMapProp->getValue();

    try {
        $typeMapProp->setValue(null, []);
        $disabledMapProp->setValue(null, []);

        [$w, $h] = upload_service_optimal_dimensions();

        expect($w)->toBe(3000)
            ->and($h)->toBe(3000);
    } finally {
        $typeMapProp->setValue(null, $originalTypeMap);
        $disabledMapProp->setValue(null, $originalDisabledMap);
    }
});

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

test('uploadFileTiff appends the -quality 98 flag and converts to jpg when tiffRepresentativeExt is configured to jpg', function (): void {
    // CurrentConfig::tiffRepresentativeExt()'s own default is 'png' (see
    // the "converts a real TIFF..." test above, which never touches this
    // config) -- forcing it to 'jpg' here is the only way to reach this
    // method's own `if ($representative_ext === 'jpg') { $exec .= '
    // -quality 98'; }` branch.
    CurrentConfig::setTiffRepresentativeExt('jpg');
    try {
        $dir = upload_service_test_marker();
        $png = $dir . '/source-jpgext.png';
        $tiff = $dir . '/photo-jpgext.tiff';
        upload_service_make_sample_png($png);
        upload_service_convert_sample($png, $tiff);

        $result = UploadService::uploadFileTiff(null, $tiff);

        expect($result)->toBe('jpg');
        $representativePath = $dir . '/pwg_representative/photo-jpgext.jpg';
        expect(file_exists($representativePath))->toBeTrue();
        expect(filesize($representativePath))->toBeGreaterThan(0);
    } finally {
        CurrentConfig::setTiffRepresentativeExt('png');
    }
});

/**
 * uploadFileHeic() genuinely reaches ImageMagick's real CLI conversion
 * path in this environment too: the method never validates HEIC's magic
 * bytes, only its extension, and the real `magick` binary auto-detects
 * input format from file content regardless of the extension it's given
 * (confirmed live: a plain PNG saved as `.heic` converts cleanly) -- so a
 * PNG source dressed up with a `.heic` extension exercises the exact same
 * real-CLI success path a genuine HEIC upload would, without needing a
 * libheif delegate at all.
 */
function upload_service_make_fake_heic(string $path): void
{
    // ImageMagick in this environment can DECODE arbitrary content by
    // magic bytes regardless of a misleading extension (confirmed live),
    // but it has no HEIC *encode* delegate -- asking it to write directly
    // to a `.heic`-named destination fails outright. Encoding a real PNG
    // to a real .png path first, then copying those bytes verbatim onto
    // the `.heic` path, gets a genuinely decodable "fake HEIC" without
    // ever asking ImageMagick to encode HEIC.
    $realPng = $path . '.real.png';
    $exec = 'convert -size 40x40 xc:green ' . escapeshellarg($realPng) . ' 2>&1';
    exec($exec, $out, $status);
    if ($status !== 0) {
        throw new RuntimeException('convert (fake heic source) failed: ' . implode("\n", $out));
    }
    rename($realPng, $path);
}

test('uploadFileHeic converts a real image into a representative jpg via the ext_imagick CLI', function (): void {
    $dir = upload_service_test_marker();
    $heic = $dir . '/photo.heic';
    upload_service_make_fake_heic($heic);

    $result = UploadService::uploadFileHeic(null, $heic);

    expect($result)->toBe('jpg');
    $representativePath = $dir . '/pwg_representative/photo.jpg';
    expect(file_exists($representativePath))->toBeTrue();
    expect(filesize($representativePath))->toBeGreaterThan(0);
});

test('uploadFileHeic logs its exact ImageMagick exec string, including the resize/quality flags', function (): void {
    $logDir = upload_service_test_marker() . '/heic-logs';
    mkdir($logDir, 0o777, true);
    CurrentLogger::set(new Logger(['severity' => Logger::INFO, 'directory' => $logDir, 'filename' => 'heic.log']));

    try {
        $dir = upload_service_test_marker();
        $heic = $dir . '/photo-logged.heic';
        upload_service_make_fake_heic($heic);

        UploadService::uploadFileHeic(null, $heic);

        $logged = file_get_contents($logDir . '/heic.log');
        expect($logged)->toContain('uploadFileHeic, exec = ')
            ->toContain('-sampling-factor 4:2:0 -quality 85 -interlace JPEG -colorspace sRGB -auto-orient +repage -resize "');
    } finally {
        CurrentLogger::set(new Logger(['severity' => Logger::OFF]));
    }
});

/**
 * The isset($representative_ext) early return on each of the 5 handlers
 * below is unobservable via a non-matching/missing fixture (both the real
 * early return and a removed one land on the exact same unchanged
 * "already-set" result once the fall-through path also fails to convert)
 * -- only a genuinely convertible file proves the guard actually skips
 * the real work: without it, a successful conversion would silently
 * overwrite the caller-supplied representative_ext with the handler's own
 * extension instead of leaving it alone.
 */
test('the 5 ext_imagick handlers leave an already-set representative_ext untouched even when the file would otherwise convert successfully', function (): void {
    $dir = upload_service_test_marker();

    $pdfPng = $dir . '/isset-guard-src.png';
    upload_service_make_sample_png($pdfPng);
    $pdf = $dir . '/isset-guard.pdf';
    upload_service_convert_sample($pdfPng, $pdf);
    expect(UploadService::uploadFilePdf('already-set', $pdf))->toBe('already-set');

    $heic = $dir . '/isset-guard.heic';
    upload_service_make_fake_heic($heic);
    expect(UploadService::uploadFileHeic('already-set', $heic))->toBe('already-set');

    $tiffPng = $dir . '/isset-guard-src2.png';
    upload_service_make_sample_png($tiffPng);
    $tiff = $dir . '/isset-guard.tiff';
    upload_service_convert_sample($tiffPng, $tiff);
    expect(UploadService::uploadFileTiff('already-set', $tiff))->toBe('already-set');

    $psdPng = $dir . '/isset-guard-src3.png';
    upload_service_make_sample_png($psdPng);
    $psd = $dir . '/isset-guard.psd';
    upload_service_convert_sample($psdPng, $psd);
    expect(UploadService::uploadFilePsd('already-set', $psd))->toBe('already-set');

    $epsPng = $dir . '/isset-guard-src4.png';
    upload_service_make_sample_png($epsPng);
    $eps = $dir . '/isset-guard.eps';
    upload_service_convert_sample($epsPng, $eps);
    expect(UploadService::uploadFileEps('already-set', $eps))->toBe('already-set');
});

/**
 * Same rationale as the isset() guard test above -- only a genuinely
 * convertible file, forced through a non-ext_imagick library setting,
 * proves the library guard itself is what blocks the conversion (not the
 * source file being unconvertible for some unrelated reason).
 */
test('the 5 ext_imagick handlers skip a real, otherwise-convertible file when the graphics library is not ext_imagick', function (): void {
    $dir = upload_service_test_marker();
    CurrentConfig::setGraphicsLibrary('gd');
    try {
        $pdfPng = $dir . '/library-guard-src.png';
        upload_service_make_sample_png($pdfPng);
        $pdf = $dir . '/library-guard.pdf';
        upload_service_convert_sample($pdfPng, $pdf);
        expect(UploadService::uploadFilePdf(null, $pdf))->toBeNull();

        $heic = $dir . '/library-guard.heic';
        upload_service_make_fake_heic($heic);
        expect(UploadService::uploadFileHeic(null, $heic))->toBeNull();

        $tiffPng = $dir . '/library-guard-src2.png';
        upload_service_make_sample_png($tiffPng);
        $tiff = $dir . '/library-guard.tiff';
        upload_service_convert_sample($tiffPng, $tiff);
        expect(UploadService::uploadFileTiff(null, $tiff))->toBeNull();

        $psdPng = $dir . '/library-guard-src3.png';
        upload_service_make_sample_png($psdPng);
        $psd = $dir . '/library-guard.psd';
        upload_service_convert_sample($psdPng, $psd);
        expect(UploadService::uploadFilePsd(null, $psd))->toBeNull();

        $epsPng = $dir . '/library-guard-src4.png';
        upload_service_make_sample_png($epsPng);
        $eps = $dir . '/library-guard.eps';
        upload_service_convert_sample($epsPng, $eps);
        expect(UploadService::uploadFileEps(null, $eps))->toBeNull();
    } finally {
        CurrentConfig::setGraphicsLibrary('auto');
    }
});

/**
 * Same rationale again, this time for the extension whitelist itself --
 * a real PDF/TIFF/PSD/EPS source saved under the *wrong* extension is
 * genuinely convertible content (ImageMagick sniffs format from magic
 * bytes, same as the fake-HEIC technique above), so only this proves the
 * extension check -- not some incidental file-content failure -- is what
 * blocks it.
 */
test('the 5 ext_imagick handlers reject a real, otherwise-convertible file whose extension does not match', function (): void {
    $dir = upload_service_test_marker();

    $pdfPng = $dir . '/ext-guard-src.png';
    upload_service_make_sample_png($pdfPng);
    $mislabeledPdf = $dir . '/ext-guard-pdf.txt';
    upload_service_convert_sample($pdfPng, $mislabeledPdf . '.pdf');
    rename($mislabeledPdf . '.pdf', $mislabeledPdf);
    expect(UploadService::uploadFilePdf(null, $mislabeledPdf))->toBeNull();

    $mislabeledHeic = $dir . '/ext-guard.txt';
    upload_service_make_fake_heic($mislabeledHeic);
    expect(UploadService::uploadFileHeic(null, $mislabeledHeic))->toBeNull();

    $tiffPng = $dir . '/ext-guard-src2.png';
    upload_service_make_sample_png($tiffPng);
    $mislabeledTiff = $dir . '/ext-guard-tiff.txt';
    upload_service_convert_sample($tiffPng, $mislabeledTiff . '.tiff');
    rename($mislabeledTiff . '.tiff', $mislabeledTiff);
    expect(UploadService::uploadFileTiff(null, $mislabeledTiff))->toBeNull();

    $psdPng = $dir . '/ext-guard-src3.png';
    upload_service_make_sample_png($psdPng);
    $mislabeledPsd = $dir . '/ext-guard-psd.txt';
    upload_service_convert_sample($psdPng, $mislabeledPsd . '.psd');
    rename($mislabeledPsd . '.psd', $mislabeledPsd);
    expect(UploadService::uploadFilePsd(null, $mislabeledPsd))->toBeNull();

    $epsPng = $dir . '/ext-guard-src4.png';
    upload_service_make_sample_png($epsPng);
    $mislabeledEps = $dir . '/ext-guard-eps.txt';
    upload_service_convert_sample($epsPng, $mislabeledEps . '.eps');
    rename($mislabeledEps . '.eps', $mislabeledEps);
    expect(UploadService::uploadFileEps(null, $mislabeledEps))->toBeNull();
});

test('the 5 ext_imagick handlers log the exact file_path/representative_ext they were called with', function (): void {
    $logDir = upload_service_test_marker() . '/handler-logs';
    mkdir($logDir, 0o777, true);
    CurrentLogger::set(new Logger(['severity' => Logger::INFO, 'directory' => $logDir, 'filename' => 'handlers.log']));

    try {
        UploadService::uploadFilePdf('pdf-ext', '/whatever/document.pdf');
        UploadService::uploadFileHeic('heic-ext', '/whatever/photo.heic');
        UploadService::uploadFileTiff('tiff-ext', '/whatever/photo.tiff');
        UploadService::uploadFilePsd('psd-ext', '/whatever/layered.psd');
        UploadService::uploadFileEps('eps-ext', '/whatever/vector.eps');

        $logged = file_get_contents($logDir . '/handlers.log');
        expect($logged)->toContain('uploadFilePdf, $file_path = /whatever/document.pdf, $representative_ext = pdf-ext')
            ->toContain('uploadFileHeic, $file_path = /whatever/photo.heic, $representative_ext = heic-ext')
            ->toContain('uploadFileTiff, $file_path = /whatever/photo.tiff, $representative_ext = tiff-ext')
            ->toContain('uploadFilePsd, $file_path = /whatever/layered.psd, $representative_ext = psd-ext')
            ->toContain('uploadFileEps, $file_path = /whatever/vector.eps, $representative_ext = eps-ext');
    } finally {
        CurrentLogger::set(new Logger(['severity' => Logger::OFF]));
    }
});

test('uploadFilePdf actually applies pdfJpgQuality to the generated representative, not just a fixed default', function (): void {
    // Comparing the SAME source converted at 2 wildly different qualities
    // (rather than against ImageMagick's own unconfigured default, which
    // is high enough to be a poor baseline) turns "was -quality even
    // passed through?" into a reliable file-size signal.
    $dir = upload_service_test_marker();
    $png = $dir . '/quality-src.png';
    $exec = 'convert -size 400x400 plasma: ' . escapeshellarg($png) . ' 2>&1';
    exec($exec, $out, $status);
    if ($status !== 0) {
        throw new RuntimeException('convert (plasma source) failed: ' . implode("\n", $out));
    }

    CurrentConfig::setPdfJpgQuality(1);
    try {
        $lowPdf = $dir . '/quality-low.pdf';
        upload_service_convert_sample($png, $lowPdf);
        UploadService::uploadFilePdf(null, $lowPdf);
        $lowSize = filesize($dir . '/pwg_representative/quality-low.jpg');
    } finally {
        CurrentConfig::setPdfJpgQuality(90);
    }

    CurrentConfig::setPdfJpgQuality(100);
    try {
        $highPdf = $dir . '/quality-high.pdf';
        upload_service_convert_sample($png, $highPdf);
        UploadService::uploadFilePdf(null, $highPdf);
        $highSize = filesize($dir . '/pwg_representative/quality-high.jpg');
    } finally {
        CurrentConfig::setPdfJpgQuality(90);
    }
    if ($highSize === false) {
        throw new RuntimeException('filesize (quality-high.jpg) failed');
    }

    expect($lowSize)->toBeLessThan((int) ($highSize / 2));
});

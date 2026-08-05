<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Admin\Image\ImageExtImagick;
use Piwigo\Admin\Image\ImageInterface;
use Piwigo\Admin\Image\ImageProcessingException;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;

/**
 * P23 Stage 1e: __construct()'s "identify couldn't determine dimensions"
 * guard used to die() -- first test coverage for this class, confirming
 * it now throws ImageProcessingException. Only runs the real external
 * `identify` binary when it's genuinely available in this environment
 * (same check PwgImage::is_ext_imagick() itself uses) -- no fake/mocked
 * binary, this is exercising the real shell-exec path.
 *
 * Coverage-gap batch: also drives rotate()/sharpen()/set_compression_quality()/
 * compose()/write() through the same real CLI, plus __construct()'s
 * animated-WebP branch, matching UploadServiceTest's uploadFileTiff/Pdf/
 * Psd/Eps "ext_imagick CLI" tests -- real `magick`/`convert`/`identify`
 * binaries, no mocking.
 */
function imageExtImagickTestMarker(): string
{
    /** @var string|null $marker */
    static $marker = null;

    return $marker ??= sys_get_temp_dir() . '/piwigo-image-ext-imagick-test-' . bin2hex(random_bytes(8));
}

function imageExtImagickTestSkipIfUnavailable(): void
{
    if (! PwgImage::is_ext_imagick()) {
        Assert::markTestSkipped('No external ImageMagick (magick/convert) binary available in this environment.');
    }
}

/**
 * Builds a real JPEG fixture via GD (always available) -- ImageExtImagick's
 * own construct()/rotate()/sharpen()/write() all run the real external
 * `identify`/`convert` CLI against whatever lands on disk, regardless of
 * which library produced it, same convention as ImageImagickTest's own
 * imageImagickTestMakeJpeg().
 */
function imageExtImagickTestMakeJpeg(string $path, int $width, int $height, int $r, int $g, int $b): void
{
    assert($width > 0 && $height > 0);
    assert($r >= 0 && $r <= 255);
    assert($g >= 0 && $g <= 255);
    assert($b >= 0 && $b <= 255);

    $im = imagecreatetruecolor($width, $height);
    if ($im === false) {
        throw new RuntimeException('imagecreatetruecolor() failed building the test fixture image.');
    }
    $color = imagecolorallocate($im, $r, $g, $b);
    if ($color === false) {
        throw new RuntimeException('imagecolorallocate() failed building the test fixture image.');
    }
    imagefill($im, 0, 0, $color);
    imagejpeg($im, $path);
}

/**
 * Builds a real, tiny 2-frame animated WebP via the external ImageMagick
 * CLI. __construct()'s animated-WebP branch reads PwgImage::webp_info()'s
 * real VP8X extended-header animation bit, so a genuine multi-frame WebP
 * container is required here -- no lighter-weight way to set that bit
 * without one.
 */
function imageExtImagickTestMakeAnimatedWebp(string $path, int $width, int $height): void
{
    $exec = 'convert -delay 20 -size ' . $width . 'x' . $height . ' xc:red xc:blue -loop 0 ' . escapeshellarg($path) . ' 2>&1';
    exec($exec, $out, $status);
    if ($status !== 0) {
        throw new RuntimeException('convert (animated webp fixture) failed: ' . implode("\n", $out));
    }
}

/**
 * Counts frames in a real image file via the external `identify` CLI --
 * used to prove write()'s '-layers coalesce' branch actually preserves a
 * multi-frame animation through a full real CLI round-trip, not just that
 * write() happens to return true.
 */
function imageExtImagickTestFrameCount(string $path): int
{
    $exec = 'identify -format ' . escapeshellarg('%n\n') . ' ' . escapeshellarg($path) . ' 2>&1';
    exec($exec, $out);

    return count($out);
}

/**
 * Builds a hand-crafted, deliberately truncated WebP: a real RIFF/WEBP/VP8X
 * header (25 bytes, the minimum PwgImage::webp_info() itself reads) with
 * the animation flag bit set, but cut off before the width/height fields
 * that follow it in a real VP8X chunk. webp_info() only inspects byte 15
 * ('X') and byte 20 (the flags byte) -- both present here -- so it reports
 * has-animation=true, but getimagesize() genuinely can't extract real
 * dimensions from a file this short and returns false (confirmed live).
 * No external ImageMagick CLI involved in building this fixture.
 */
function imageExtImagickTestMakeTruncatedAnimatedWebp(string $path): void
{
    $vp8xPayload = "\x02\x00\x00\x00"; // flags (animation bit set) + 3 reserved bytes, width/height omitted
    $vp8xChunk = 'VP8X' . pack('V', 10) . $vp8xPayload;
    $riffPayload = 'WEBP' . $vp8xChunk;
    $riff = 'RIFF' . pack('V', strlen($riffPayload)) . $riffPayload;

    // Pad up to exactly the 25 bytes webp_info() reads (fread() would
    // otherwise return fewer, tripping its own "not a valid webp image"
    // guard instead of the branch this fixture targets).
    if (strlen($riff) < 25) {
        $riff .= str_repeat("\x00", 25 - strlen($riff));
    }

    file_put_contents($path, $riff);
}

/**
 * ImageExtImagick reads CurrentLogger through real constructor injection
 * (singleton/service-locator elimination campaign, Phase 2) -- resolves
 * the container-shared instance rather than a bare `new CurrentLogger()`
 * so every construction in this file shares the one Kernel::boot()-seeded
 * instance, same as a real request would.
 */
function imageExtImagickTestCurrentLogger(): CurrentLogger
{
    $currentLogger = Kernel::container()->get(CurrentLogger::class);
    if (! $currentLogger instanceof CurrentLogger) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
    }

    return $currentLogger;
}

/**
 * ImageExtImagick reads CurrentConfig through real constructor injection
 * (singleton/service-locator elimination campaign, Phase 9) -- resolves
 * the container-shared instance rather than a bare `new CurrentConfig()`,
 * same reasoning as imageExtImagickTestCurrentLogger() above.
 */
function imageExtImagickTestCurrentConfig(): CurrentConfig
{
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    if (! $currentConfig instanceof CurrentConfig) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
    }

    return $currentConfig;
}

function imageExtImagickTestMake(string $path): ImageExtImagick
{
    return new ImageExtImagick($path, imageExtImagickTestCurrentLogger(), imageExtImagickTestCurrentConfig());
}

beforeEach(function (): void {
    mkdir(imageExtImagickTestMarker(), 0o777, true);
    Kernel::boot();
    imageExtImagickTestCurrentLogger()->set(new Logger(['severity' => Logger::OFF]));
});

afterEach(function (): void {
    CurrentConfig::current()->reset();
    Kernel::reset();
    $dir = imageExtImagickTestMarker();
    $files = glob($dir . '/*');
    foreach ($files !== false ? $files : [] as $file) {
        unlink($file);
    }
    rmdir($dir);
});

test('construct throws when identify cannot determine the image dimensions', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $path = imageExtImagickTestMarker() . '/photo.jpg';
    file_put_contents($path, 'this is plain text, not a real image at all');

    expect(fn () => imageExtImagickTestMake($path))
        ->toThrow(ImageProcessingException::class, '[External ImageMagick] Corrupt image');
});

test('construct detects an animated WebP and reads dimensions via getimagesize instead of identify', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $path = imageExtImagickTestMarker() . '/animated.webp';
    imageExtImagickTestMakeAnimatedWebp($path, 20, 14);

    $image = imageExtImagickTestMake($path);

    expect($image->is_animated_webp)->toBeTrue()
        ->and($image->get_width())->toBe(20)
        ->and($image->get_height())->toBe(14);
});

test('construct throws when an animated webp is too short for getimagesize to read despite a valid VP8X header', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $path = imageExtImagickTestMarker() . '/truncated-animated.webp';
    imageExtImagickTestMakeTruncatedAnimatedWebp($path);
    expect(PwgImage::webp_info($path)['has-animation'])->toBeTrue();
    expect(getimagesize($path))->toBeFalse();

    expect(fn () => imageExtImagickTestMake($path))
        ->toThrow(Exception::class, "ImageExtImagick(): getimagesize({$path}): Failed");
});

test('construct sets MAGICK_THREAD_LIMIT=1 when SCRIPT_FILENAME starts with /kunden/ (1and1 hosting)', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $path = imageExtImagickTestMarker() . '/kunden-src.jpg';
    imageExtImagickTestMakeJpeg($path, 10, 10, 1, 2, 3);

    $originalScriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
    $originalThreadLimit = getenv('MAGICK_THREAD_LIMIT');
    $_SERVER['SCRIPT_FILENAME'] = '/kunden/homepages/1/example/htdocs/i.php';

    try {
        imageExtImagickTestMake($path);

        expect(getenv('MAGICK_THREAD_LIMIT'))->toBe('1');
    } finally {
        if ($originalScriptFilename === null) {
            unset($_SERVER['SCRIPT_FILENAME']);
        } else {
            $_SERVER['SCRIPT_FILENAME'] = $originalScriptFilename;
        }
        // putenv($var)-to-unset must be a real, deliberate restore (not a
        // bare clear) -- see this project's own
        // feedback_putenv_unset_must_restore note.
        if ($originalThreadLimit === false) {
            putenv('MAGICK_THREAD_LIMIT');
        } else {
            putenv('MAGICK_THREAD_LIMIT=' . $originalThreadLimit);
        }
    }
});

test('rotate by 0 degrees is a no-op that adds no command', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $path = imageExtImagickTestMarker() . '/rotate-noop.jpg';
    imageExtImagickTestMakeJpeg($path, 40, 20, 10, 20, 30);
    $image = imageExtImagickTestMake($path);

    $result = $image->rotate(0);

    expect($result)->toBeTrue()
        ->and($image->commands)->toBe([])
        ->and($image->get_width())->toBe(40)
        ->and($image->get_height())->toBe(20);
});

test('rotate by 90 degrees swaps width/height and queues rotate+orient commands', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $path = imageExtImagickTestMarker() . '/rotate-90.jpg';
    imageExtImagickTestMakeJpeg($path, 40, 20, 10, 20, 30);
    $image = imageExtImagickTestMake($path);

    $result = $image->rotate(90);

    expect($result)->toBeTrue()
        ->and($image->get_width())->toBe(20)
        ->and($image->get_height())->toBe(40)
        ->and($image->commands)->toBe(['rotate' => -90, 'orient' => 'top-left']);
});

test('set_compression_quality caps the requested quality via animatedWebpCompressionQuality for an animated webp source', function (): void {
    imageExtImagickTestSkipIfUnavailable();
    imageExtImagickTestCurrentConfig()->setAnimatedWebpCompressionQuality(40);

    $path = imageExtImagickTestMarker() . '/animated-quality.webp';
    imageExtImagickTestMakeAnimatedWebp($path, 16, 10);
    $image = imageExtImagickTestMake($path);

    $result = $image->set_compression_quality(90);

    expect($result)->toBeTrue()
        ->and($image->commands['quality'])->toBe(40);
});

test('sharpen builds the morphology convolve command and produces a valid derivative via write()', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $path = imageExtImagickTestMarker() . '/sharpen-src.jpg';
    $dest = imageExtImagickTestMarker() . '/sharpen-out.jpg';
    imageExtImagickTestMakeJpeg($path, 20, 14, 120, 120, 120);
    $image = imageExtImagickTestMake($path);

    // Independently rebuild the expected command string from the same
    // shared matrix PwgImage::get_sharpen_matrix() produces -- this locks
    // down ImageExtImagick::sharpen()'s own string-builder logic
    // (untested until now), not the matrix math itself.
    $m = PwgImage::get_sharpen_matrix(50);
    $expectedParam = 'convolve "' . count($m) . ':';
    foreach ($m as $line) {
        $expectedParam .= ' ';
        $expectedParam .= implode(',', $line);
    }
    $expectedParam .= '"';

    $result = $image->sharpen(50);

    expect($result)->toBeTrue()
        ->and($image->commands['morphology'])->toBe($expectedParam);

    // The 'morphology' command value is concatenated into the real shell
    // command *without* escapeshellarg() (its embedded literal double
    // quotes are load-bearing shell-quoting, splitting "convolve" and the
    // kernel spec into 2 separate CLI arguments) -- only a real write()
    // proves that raw string is actually well-formed for the shell.
    $image->write($dest);

    expect(file_exists($dest))->toBeTrue();
    $info = getimagesize($dest);
    if ($info === false) {
        throw new RuntimeException('getimagesize failed');
    }
    expect($info[0])->toBe(20)
        ->and($info[1])->toBe(14);
});

test('compose throws a LogicException when the overlay uses a different image backend', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $basePath = imageExtImagickTestMarker() . '/compose-base.jpg';
    $overlayPath = imageExtImagickTestMarker() . '/compose-overlay.jpg';
    imageExtImagickTestMakeJpeg($basePath, 20, 14, 10, 10, 10);
    imageExtImagickTestMakeJpeg($overlayPath, 8, 8, 200, 200, 200);
    $base = imageExtImagickTestMake($basePath);
    $overlay = new PwgImage($overlayPath, imageExtImagickTestCurrentLogger(), new EventDispatcher(), imageExtImagickTestCurrentConfig(), 'ext_imagick');
    // Swap in a fake, non-ImageExtImagick backend to force the mismatch --
    // same idea as ImageGdTest's own compose()-mismatch test, this class's
    // guard only cares that it's genuinely not `self` (ImageExtImagick).
    $overlay->image = new class implements ImageInterface {
        public function get_width(): int
        {
            return 1;
        }

        public function get_height(): int
        {
            return 1;
        }

        public function set_compression_quality(int $quality): bool
        {
            return true;
        }

        public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool
        {
            return true;
        }

        public function strip(): bool
        {
            return true;
        }

        public function rotate(int|float $rotation): bool
        {
            return true;
        }

        public function resize(int|float $width, int|float $height): bool
        {
            return true;
        }

        public function sharpen(int|float $amount): bool
        {
            return true;
        }

        public function compose(PwgImage $overlay, int|float $x, int|float $y, int|float $opacity): bool
        {
            return true;
        }

        public function write(string $destination_filepath): bool
        {
            return true;
        }
    };

    expect(fn () => $base->compose($overlay, 0, 0, 50))
        ->toThrow(LogicException::class, 'PwgImage::compose(): overlay must use the same image backend');
});

test('compose throws when the overlay source path cannot be resolved', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $basePath = imageExtImagickTestMarker() . '/compose-base2.jpg';
    $overlayPath = imageExtImagickTestMarker() . '/compose-overlay2.jpg';
    imageExtImagickTestMakeJpeg($basePath, 20, 14, 10, 10, 10);
    imageExtImagickTestMakeJpeg($overlayPath, 8, 8, 200, 200, 200);
    $base = imageExtImagickTestMake($basePath);
    $overlay = new PwgImage($overlayPath, imageExtImagickTestCurrentLogger(), new EventDispatcher(), imageExtImagickTestCurrentConfig(), 'ext_imagick');
    expect($overlay->image)->toBeInstanceOf(ImageExtImagick::class);
    // The overlay backend was legitimately constructed from a real file,
    // but that file is now gone by the time compose() actually resolves
    // it -- realpath() must fail.
    unlink($overlayPath);

    expect(fn () => $base->compose($overlay, 0, 0, 50))
        ->toThrow(Exception::class, "compose(): unable to resolve overlay path {$overlayPath}");
});

test('write throws when the destination directory cannot be resolved', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $path = imageExtImagickTestMarker() . '/dirfail-src.jpg';
    imageExtImagickTestMakeJpeg($path, 10, 10, 5, 5, 5);
    $image = imageExtImagickTestMake($path);

    $missingDir = imageExtImagickTestMarker() . '/no-such-subdir';
    $dest = $missingDir . '/out.jpg';

    expect(fn () => $image->write($dest))
        ->toThrow(Exception::class, "write(): unable to resolve directory {$missingDir}");
});

test('write throws when the destination path has no directory component at all', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $path = imageExtImagickTestMarker() . '/emptydest-src.jpg';
    imageExtImagickTestMakeJpeg($path, 10, 10, 5, 5, 5);
    $image = imageExtImagickTestMake($path);

    // pathinfo('') is the one real value that omits the 'dirname' key
    // entirely (confirmed live: only 'basename'/'filename' come back, both
    // empty strings) -- every non-empty path, even a bare filename with no
    // slash, still gets a 'dirname' of '.'.
    expect(fn () => $image->write(''))
        ->toThrow(Exception::class, 'write(): unable to determine directory for ');
});

test('write triggers E_USER_WARNING for each line of real CLI failure output', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $path = imageExtImagickTestMarker() . '/cli-fail-src.jpg';
    imageExtImagickTestMakeJpeg($path, 12, 8, 10, 20, 30);
    $image = imageExtImagickTestMake($path);
    // Corrupt the source file in place *after* a successful construct() --
    // write()'s own real `convert`/`magick` invocation now fails
    // deterministically (bad header, unreadable content), independent of
    // the directory-resolution guard tested above.
    file_put_contents($path, 'not a real jpeg anymore, just garbage bytes');

    $dest = imageExtImagickTestMarker() . '/cli-fail-out.jpg';

    /** @var array<int, array{0: int, 1: string}> $captured */
    $captured = [];
    set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
        $captured[] = [$errno, $errstr];

        return true;
    });
    try {
        $result = $image->write($dest);
    } finally {
        restore_error_handler();
    }

    expect($result)->toBeTrue('write() always reports success, even when the underlying CLI call fails')
        ->and($captured)->not->toBeEmpty();
    foreach ($captured as [$errno, $errstr]) {
        expect($errno)->toBe(E_USER_WARNING);
        expect($errstr)->not->toBe('');
    }
    expect(file_exists($dest))->toBeFalse();
});

test('write adds the -layers coalesce flag and preserves every frame of an animated webp source', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $path = imageExtImagickTestMarker() . '/anim-write-src.webp';
    $dest = imageExtImagickTestMarker() . '/anim-write-out.webp';
    imageExtImagickTestMakeAnimatedWebp($path, 20, 14);
    $image = imageExtImagickTestMake($path);
    expect($image->is_animated_webp)->toBeTrue();
    expect(imageExtImagickTestFrameCount($path))->toBeGreaterThan(1);

    $result = $image->write($dest);

    expect($result)->toBeTrue();
    expect(file_exists($dest))->toBeTrue();
    expect(filesize($dest))->toBeGreaterThan(0);
    expect(imageExtImagickTestFrameCount($dest))->toBeGreaterThan(1);
});

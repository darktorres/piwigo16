<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Admin\Image\ImageExtImagick;
use Piwigo\Admin\Image\ImageInterface;
use Piwigo\Admin\Image\ImageProcessingException;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;

/**
 * __construct()'s "identify couldn't determine dimensions" guard throws
 * ImageProcessingException. Tests here only run the real external
 * `identify` binary when it's genuinely available in this environment
 * (same check PwgImage::is_ext_imagick() itself uses) -- no fake/mocked
 * binary, this exercises the real shell-exec path. This file also drives
 * rotate()/sharpen()/set_compression_quality()/compose()/write() through
 * the same real CLI, plus __construct()'s animated-WebP branch, matching
 * UploadServiceTest's uploadFileTiff/Pdf/Psd/Eps "ext_imagick CLI" tests
 * -- real `magick`/`convert`/`identify` binaries, no mocking.
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
 * ImageExtImagick reads CurrentLogger through constructor injection --
 * resolves the container-shared instance rather than a bare
 * `new CurrentLogger()` so every construction in this file shares the one
 * Kernel::boot()-seeded instance, same as a real request would.
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
 * ImageExtImagick reads CurrentConfig through constructor injection --
 * resolves the container-shared instance rather than a bare
 * `new CurrentConfig()`, same reasoning as imageExtImagickTestCurrentLogger()
 * above.
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

/**
 * Resolves the real, absolute path of a binary via the same `command -v`
 * idiom PwgImage::get_ext_imagick_command() itself uses internally --
 * used to build a private, non-PATH symlink to the real magick/convert
 * binary (see the write() imagickdir-prefix test below).
 */
function imageExtImagickTestRealBinaryPath(string $commandName): string
{
    exec('command -v ' . escapeshellarg($commandName), $out, $status);
    if ($status !== 0 || ! isset($out[0]) || $out[0] === '') {
        throw new RuntimeException("Unable to locate the real '{$commandName}' binary via \`command -v\`.");
    }

    return $out[0];
}

/**
 * Recursively removes a directory -- used for the standalone log
 * directories some tests below point a real, DEBUG-severity Logger at
 * (separate from imageExtImagickTestMarker(), same convention as
 * ImageServiceTest's own imageServiceTestRrmdir()), since Logger's own
 * open() may add more than a single flat file under it.
 */
function imageExtImagickTestRrmdir(string $dir): void
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
        is_dir($path) ? imageExtImagickTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

beforeEach(function (): void {
    mkdir(imageExtImagickTestMarker(), 0o777, true);
    Kernel::boot();
    imageExtImagickTestCurrentLogger()->set(new Logger(['severity' => Logger::OFF]));
});

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
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

test('construct concatenates the imagickdir prefix directly onto the identify binary name', function (): void {
    imageExtImagickTestSkipIfUnavailable();
    // Concatenated adjacent to 'identify' via escapeshellarg() -- see
    // write()'s own [SEC-16] comment -- so this genuinely points exec() at
    // a nonexistent path regardless of which real binary this environment
    // already resolves 'identify' to via PATH. Same convention as
    // PwgImageTest's own is_ext_imagick() "nonexistent dir" tests.
    imageExtImagickTestCurrentConfig()->setExtImagickDir('/totally/nonexistent/dir/');

    $path = imageExtImagickTestMarker() . '/prefix-src.jpg';
    imageExtImagickTestMakeJpeg($path, 12, 9, 5, 5, 5);

    expect(fn () => imageExtImagickTestMake($path))
        ->toThrow(ImageProcessingException::class, '[External ImageMagick] Corrupt image');
});

test('construct casts a since-vanished source path\'s realpath() failure to an empty string instead of passing bool into escapeshellarg', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $path = imageExtImagickTestMarker() . '/does-not-exist-at-all.jpg';
    // Deliberately never created on disk -- realpath() genuinely returns
    // false here, at construct() time. Without the (string) cast,
    // escapeshellarg(false) would throw a TypeError under this file's own
    // strict_types=1 instead of the real ImageProcessingException below.

    expect(fn () => imageExtImagickTestMake($path))
        ->toThrow(ImageProcessingException::class, '[External ImageMagick] Corrupt image');
});

test('construct embeds a genuine var_export() dump of the raw CLI output, after the message prefix, in the corrupt-image exception', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $path = imageExtImagickTestMarker() . '/corrupt-dump-src.jpg';
    file_put_contents($path, 'not a real image, just garbage bytes');

    try {
        imageExtImagickTestMake($path);
        throw new RuntimeException('Expected ImageProcessingException was not thrown.');
    } catch (ImageProcessingException $imageProcessingException) {
        // toStartWith() proves the prefix comes *before* the dump (not the
        // reverse); toContain('array (') proves var_export()'s 2nd arg is
        // genuinely `true` (returns the string) rather than `false`
        // (which would print directly and return null here instead).
        expect($imageProcessingException->getMessage())
            ->toStartWith("[External ImageMagick] Corrupt image\n")
            ->toContain('array (');
    }
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

test('construct treats an uppercase .WEBP extension as webp case-insensitively', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    // StringHelper::getExtension() preserves case verbatim (a plain
    // substr() after the last dot) -- without construct()'s own
    // strtolower(), 'WEBP' !== 'webp' would skip the animated-webp branch
    // entirely and fall through to the multi-frame `identify` command,
    // which can't parse concatenated per-frame dimensions and throws
    // instead (confirmed live: "20x1420x14" fails the single-pair regex).
    $path = imageExtImagickTestMarker() . '/animated-uppercase.WEBP';
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
    expect(PwgImage::webp_info($path)->hasAnimation)->toBeTrue();
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

test('construct does not set MAGICK_THREAD_LIMIT when SCRIPT_FILENAME does not start with /kunden/', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $path = imageExtImagickTestMarker() . '/non-kunden-src.jpg';
    imageExtImagickTestMakeJpeg($path, 10, 10, 1, 2, 3);

    $originalScriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
    $originalThreadLimit = getenv('MAGICK_THREAD_LIMIT');
    // A real string that does *not* start with '/kunden/' -- is_string()
    // alone (the BooleanAndToBooleanOr mutant) is still true here, so this
    // genuinely isolates the str_starts_with() half of the condition.
    $_SERVER['SCRIPT_FILENAME'] = '/var/www/html/i.php';
    // Deliberately cleared, not just captured -- leaves a clean baseline so
    // this test can tell "construct() left it alone" apart from "some
    // earlier state already happened to be unset".
    putenv('MAGICK_THREAD_LIMIT');

    try {
        imageExtImagickTestMake($path);

        expect(getenv('MAGICK_THREAD_LIMIT'))->toBeFalse();
    } finally {
        if ($originalScriptFilename === null) {
            unset($_SERVER['SCRIPT_FILENAME']);
        } else {
            $_SERVER['SCRIPT_FILENAME'] = $originalScriptFilename;
        }
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

test('rotate by a literal float 0.0 is also a no-op that adds no command', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    // rotate(0) (int) above only ever exercises the first `$rotation ===
    // 0` disjunct (int-vs-int) -- it short-circuits before the second
    // `$rotation === 0.0` disjunct is ever reached. A literal float 0.0
    // fails the int disjunct (0.0 !== 0 is a type mismatch under ===) and
    // is the only input that actually reaches/depends on the float one.
    $path = imageExtImagickTestMarker() . '/rotate-float-noop.jpg';
    imageExtImagickTestMakeJpeg($path, 40, 20, 10, 20, 30);
    $image = imageExtImagickTestMake($path);

    $result = $image->rotate(0.0);

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

test('rotate by 270 degrees swaps width/height and queues rotate+orient commands', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    // The 90-degree test above only ever exercises the first `(float)
    // $rotation === 90.0` disjunct -- it short-circuits before the second
    // `(float) $rotation === 270.0` disjunct is ever reached. 270 is the
    // only input that actually reaches/depends on that second disjunct
    // (and its own (float) cast: comparing bare int 270 against float
    // 270.0 via strict === would otherwise never match).
    $path = imageExtImagickTestMarker() . '/rotate-270.jpg';
    imageExtImagickTestMakeJpeg($path, 40, 20, 10, 20, 30);
    $image = imageExtImagickTestMake($path);

    $result = $image->rotate(270);

    expect($result)->toBeTrue()
        ->and($image->get_width())->toBe(20)
        ->and($image->get_height())->toBe(40)
        ->and($image->commands)->toBe(['rotate' => -270, 'orient' => 'top-left']);
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

test('write always queues the interlace=line command for progressive JPEG rendering', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $path = imageExtImagickTestMarker() . '/interlace-src.jpg';
    $dest = imageExtImagickTestMarker() . '/interlace-out.jpg';
    imageExtImagickTestMakeJpeg($path, 8, 8, 1, 1, 1);
    $image = imageExtImagickTestMake($path);

    $image->write($dest);

    expect($image->commands['interlace'] ?? null)->toBe('line');
});

test('write adds the sampling-factor command only when ext_imagick_version compares strictly greater than 6.6', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $path = imageExtImagickTestMarker() . '/sampling-src.jpg';
    imageExtImagickTestMakeJpeg($path, 8, 8, 1, 1, 1);
    // imageExtImagickTestSkipIfUnavailable() itself already refreshed this
    // to the real, live detected version via is_ext_imagick() -- captured
    // here so it can be restored, not assumed to start at ''.
    $originalVersion = PwgImage::$ext_imagick_version;

    try {
        // Strictly greater than '6.6' -- the add_command() call must fire.
        PwgImage::$ext_imagick_version = '7.0';
        $imageGreater = imageExtImagickTestMake($path);
        $imageGreater->write(imageExtImagickTestMarker() . '/sampling-greater-out.jpg');
        expect($imageGreater->commands['sampling-factor'] ?? null)->toBe('4:2:2');

        // Exactly '6.6' (version_compare() returns 0) -- *not* greater, so
        // the add_command() call must *not* fire. This exact-equality case
        // is what actually discriminates >, >=, and <= from each other.
        PwgImage::$ext_imagick_version = '6.6';
        $imageEqual = imageExtImagickTestMake($path);
        $imageEqual->write(imageExtImagickTestMarker() . '/sampling-equal-out.jpg');
        expect($imageEqual->commands)->not->toHaveKey('sampling-factor');
    } finally {
        PwgImage::$ext_imagick_version = $originalVersion;
    }
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

test('write concatenates the imagickdir prefix directly onto the convert/magick binary name', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $path = imageExtImagickTestMarker() . '/dirprefix-src.jpg';
    $dest = imageExtImagickTestMarker() . '/dirprefix-out.jpg';
    imageExtImagickTestMakeJpeg($path, 12, 9, 5, 5, 5);
    $image = imageExtImagickTestMake($path);

    // A real symlink to the actual convert/magick binary, placed inside
    // this test's own private marker directory -- one that is genuinely
    // never on PATH -- proves the imagickdir prefix concatenation is
    // itself what makes the binary resolve, not PATH doing it for us
    // regardless of concatenation order/presence. Unlike the "nonexistent
    // dir" trick used for construct()'s own identify command above, a
    // nonexistent dir here can't discriminate a swapped concatenation
    // order from the real one: with an empty/missing dir, *both* the
    // correct and the reordered command end up trying (and failing) to
    // execute a nonexistent path, so this needs a real, working target to
    // tell them apart.
    $commandName = PwgImage::get_ext_imagick_command();
    $realBinaryPath = imageExtImagickTestRealBinaryPath($commandName);
    $privateBinPath = imageExtImagickTestMarker() . '/' . $commandName;
    symlink($realBinaryPath, $privateBinPath);

    $image->imagickdir = imageExtImagickTestMarker() . '/';

    $result = $image->write($dest);

    expect($result)->toBeTrue()
        ->and(file_exists($dest))->toBeTrue();
    $info = getimagesize($dest);
    if ($info === false) {
        throw new RuntimeException('getimagesize failed');
    }
    expect($info[0])->toBe(12)
        ->and($info[1])->toBe(9);
});

test('write casts a since-deleted source file\'s realpath() failure to an empty string instead of passing bool into escapeshellarg', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $path = imageExtImagickTestMarker() . '/write-vanish-src.jpg';
    imageExtImagickTestMakeJpeg($path, 10, 10, 1, 1, 1);
    $image = imageExtImagickTestMake($path);
    // Source now genuinely gone -- realpath() at write() time returns
    // false. Without the (string) cast, escapeshellarg(false) would throw
    // a TypeError under this file's own strict_types=1 instead of write()
    // completing (and returning true, per its own always-succeeds
    // contract) with a real, swallowed CLI failure below.
    unlink($path);

    $dest = imageExtImagickTestMarker() . '/write-vanish-out.jpg';

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

    expect($result)->toBeTrue()
        ->and(file_exists($dest))->toBeFalse();
});

test('write adds the -layers coalesce flag to the logged command for an animated webp source', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $logDir = sys_get_temp_dir() . '/piwigo-imageextimagick-test-log-' . bin2hex(random_bytes(8));
    mkdir($logDir, 0o777, true);
    imageExtImagickTestCurrentLogger()->set(new Logger(['severity' => Logger::DEBUG, 'directory' => $logDir, 'filename' => 'coalesce.log']));

    try {
        $path = imageExtImagickTestMarker() . '/coalesce-anim-src.webp';
        $dest = imageExtImagickTestMarker() . '/coalesce-anim-out.webp';
        imageExtImagickTestMakeAnimatedWebp($path, 20, 14);
        $image = imageExtImagickTestMake($path);
        expect($image->is_animated_webp)->toBeTrue();

        $image->write($dest);

        // Frame count alone (this file's own earlier "-layers coalesce"
        // test) doesn't actually discriminate whether the flag was really
        // added -- a simple, non-optimized 2-frame animation round-trips
        // to the same frame count either way (confirmed live). The
        // *logged* $exec command is the only real, non-mocked window onto
        // whether the flag itself was actually built into the command.
        $logContent = file_get_contents($logDir . '/coalesce.log');
        if ($logContent === false) {
            throw new RuntimeException('Failed to read the real log file written by write().');
        }
        expect($logContent)->toContain(' -layers coalesce ');
    } finally {
        imageExtImagickTestRrmdir($logDir);
    }
});

test('write omits the parameter value for every "no value" sentinel (null/0/0.0/\'0\'/\'\') but keeps a real value', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $logDir = sys_get_temp_dir() . '/piwigo-imageextimagick-test-log-' . bin2hex(random_bytes(8));
    mkdir($logDir, 0o777, true);
    imageExtImagickTestCurrentLogger()->set(new Logger(['severity' => Logger::DEBUG, 'directory' => $logDir, 'filename' => 'sentinel.log']));

    try {
        $path = imageExtImagickTestMarker() . '/sentinel-src.jpg';
        $dest = imageExtImagickTestMarker() . '/sentinel-out.jpg';
        imageExtImagickTestMakeJpeg($path, 10, 10, 1, 1, 1);
        $image = imageExtImagickTestMake($path);

        $image->add_command('optnull', null);
        $image->add_command('optzeroint', 0);
        $image->add_command('optzerofloat', 0.0);
        $image->add_command('optzerostr', '0');
        $image->add_command('optemptystr', '');
        $image->add_command('optreal', 5);

        // Fake, non-real ImageMagick flag names -- write()'s own exec()
        // call is expected to genuinely fail against these (real CLI
        // failure, real E_USER_WARNING via trigger_error(), swallowed
        // below same as this file's own "triggers E_USER_WARNING" test
        // above). This test only cares about the *logged* $exec string
        // built before that call, not whether the call itself succeeds.
        set_error_handler(static fn (): bool => true);
        try {
            $image->write($dest);
        } finally {
            restore_error_handler();
        }

        $logContent = file_get_contents($logDir . '/sentinel.log');
        if ($logContent === false) {
            throw new RuntimeException('Failed to read the real log file written by write().');
        }
        // One exact, contiguous substring: proves null/0/0.0/'0'/'' each
        // contribute *only* their '-flagname' token (no trailing value,
        // and critically no extra space from an empty-string append), and
        // that a real value (5) still gets its own value appended right
        // after its flag.
        expect($logContent)->toContain(' -optnull -optzeroint -optzerofloat -optzerostr -optemptystr -optreal 5');
    } finally {
        imageExtImagickTestRrmdir($logDir);
    }
});

test('write does not log an error when the CLI call succeeds with no output at all', function (): void {
    imageExtImagickTestSkipIfUnavailable();

    $logDir = sys_get_temp_dir() . '/piwigo-imageextimagick-test-log-' . bin2hex(random_bytes(8));
    mkdir($logDir, 0o777, true);
    imageExtImagickTestCurrentLogger()->set(new Logger(['severity' => Logger::DEBUG, 'directory' => $logDir, 'filename' => 'success.log']));

    try {
        $path = imageExtImagickTestMarker() . '/success-src.jpg';
        $dest = imageExtImagickTestMarker() . '/success-out.jpg';
        imageExtImagickTestMakeJpeg($path, 10, 10, 1, 2, 3);
        $image = imageExtImagickTestMake($path);

        $result = $image->write($dest);

        expect($result)->toBeTrue()
            ->and(file_exists($dest))->toBeTrue();

        // A real, successful convert/magick call genuinely produces zero
        // lines of CLI output (confirmed live) -- count($returnarray)
        // stays 0, so $logger->error()/trigger_error() must never fire.
        $logContent = file_get_contents($logDir . '/success.log');
        if ($logContent === false) {
            throw new RuntimeException('Failed to read the real log file written by write().');
        }
        expect($logContent)->not->toContain("\t[ERROR]\t");
    } finally {
        imageExtImagickTestRrmdir($logDir);
    }
});

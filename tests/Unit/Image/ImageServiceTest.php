<?php

declare(strict_types=1);

use Piwigo\Db\DbConnection;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;

// \Piwigo\Db\MysqliDb::getBoolean()/\Piwigo\Db\MysqliDb::booleanToString() are real, pure, dependency-free
// functions (include/dblayer/functions_mysqli.inc.php) -- copied verbatim
// rather than requiring that file (which pulls in the whole legacy mysqli
// driver bootstrap this isolated unit test doesn't want).
if (! function_exists('get_boolean')) {
    function get_boolean(mixed $input): bool
    {
        if (is_string($input) && strtolower($input) === 'false') {
            return false;
        }

        return (bool) $input;
    }
}

if (! function_exists('boolean_to_string')) {
    function boolean_to_string(mixed $var): mixed
    {
        if (is_bool($var)) {
            return $var ? 'true' : 'false';
        }

        return $var;
    }
}

beforeEach(function (): void {
    $GLOBALS['conf'] = [
        'slideshow_period' => 4,
        'slideshow_period_min' => 1,
        'slideshow_period_max' => 10,
        'slideshow_repeat' => true,
    ];
});

test('getDefaultSlideshowParams reads conf', function (): void {
    $params = new ImageService(new ImageRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(DbConnection::build())))->getDefaultSlideshowParams();

    expect($params['period'])->toBe(4)
        ->and($params['repeat'])->toBeTrue()
        ->and($params['play'])->toBeTrue();
});

test('correctSlideshowParams clamps below the minimum', function (): void {
    $corrected = new ImageService(new ImageRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(DbConnection::build())))->correctSlideshowParams(['period' => 0]);

    expect($corrected['period'])->toBe(1);
});

test('correctSlideshowParams clamps above the maximum', function (): void {
    $corrected = new ImageService(new ImageRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(DbConnection::build())))->correctSlideshowParams(['period' => 99]);

    expect($corrected['period'])->toBe(10);
});

test('correctSlideshowParams leaves an in-range value untouched', function (): void {
    $corrected = new ImageService(new ImageRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(DbConnection::build())))->correctSlideshowParams(['period' => 5]);

    expect($corrected['period'])->toBe(5);
});

test('decodeSlideshowParams with a numeric string sets period', function (): void {
    $decoded = new ImageService(new ImageRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(DbConnection::build())))->decodeSlideshowParams('7');

    expect($decoded['period'])->toBe('7');
});

test('decodeSlideshowParams parses key-value tokens', function (): void {
    $decoded = new ImageService(new ImageRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(DbConnection::build())))->decodeSlideshowParams('period-6+repeat-false');

    expect($decoded['period'])->toBe('6')
        ->and($decoded['repeat'])->toBeFalse();
});

test('decodeSlideshowParams with null input returns the defaults', function (): void {
    $decoded = new ImageService(new ImageRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(DbConnection::build())))->decodeSlideshowParams(null);

    expect($decoded['period'])->toBe(4)
        ->and($decoded['repeat'])->toBeTrue()
        ->and($decoded['play'])->toBeTrue();
});

test('decodeSlideshowParams clamps an out-of-range period', function (): void {
    $decoded = new ImageService(new ImageRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(DbConnection::build())))->decodeSlideshowParams('period-99');

    expect($decoded['period'])->toBe(10);
});

test('encodeSlideshowParams round-trips a non-default period', function (): void {
    $service = new ImageService(new ImageRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(DbConnection::build())));
    $encoded = $service->encodeSlideshowParams(['period' => 6, 'repeat' => true, 'play' => true]);

    expect($encoded)->toBe('+period-6');

    $decoded = $service->decodeSlideshowParams($encoded);
    expect($decoded['period'])->toBe('6');
});

test('encodeSlideshowParams omits default values', function (): void {
    $service = new ImageService(new ImageRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(DbConnection::build())));

    expect($service->encodeSlideshowParams($service->getDefaultSlideshowParams()))->toBe('');
});

test('encodeSlideshowParams encodes a changed boolean', function (): void {
    $encoded = new ImageService(new ImageRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(DbConnection::build())))->encodeSlideshowParams(['period' => 4, 'repeat' => false, 'play' => true]);

    expect($encoded)->toBe('+repeat-false');
});

test('countPdfPages counts page markers', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'pwg-pdf-test');
    expect($tmp)->toBeString();
    file_put_contents($tmp, "%PDF-1.4\n1 0 obj\n<< /Type /Page >>\nendobj\n2 0 obj\n<< /Type /Page >>\nendobj\n");

    expect(new ImageService(new ImageRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(DbConnection::build())))->countPdfPages($tmp))->toBe(2);

    unlink($tmp);
});

test('countPdfPages returns false for a missing file', function (): void {
    // Matches the original count_pdf_pages()'s own contract: file_get_contents()
    // is never @-suppressed there either (only ever called with a path
    // that's expected to exist) -- suppressed here only to assert the
    // false-return branch without failing on the resulting PHP warning.
    expect(@new ImageService(new ImageRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(DbConnection::build())))->countPdfPages('/no/such/file.pdf'))->toBeFalse();
});

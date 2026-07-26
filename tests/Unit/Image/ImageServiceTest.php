<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
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

<?php

declare(strict_types=1);

use Piwigo\Admin\BatchManager\Projection\DuplicateFieldFlags;

test('fromBulkFilter reads each flag as presence-only, ignoring a non-null value', function (): void {
    $flags = DuplicateFieldFlags::fromBulkFilter([
        'duplicates_filename' => false,
        'duplicates_checksum' => '0',
        'duplicates_date' => null,
    ]);

    expect($flags->filename)->toBeTrue()
        ->and($flags->checksum)->toBeTrue()
        // isset() treats an explicit null value as "not set" -- same as
        // the key being absent entirely, unlike array_key_exists().
        ->and($flags->date)->toBeFalse()
        ->and($flags->dimensions)->toBeFalse();
});

test('fromBulkFilter defaults every flag to false when its key is absent', function (): void {
    $flags = DuplicateFieldFlags::fromBulkFilter([]);

    expect($flags->filename)->toBeFalse()
        ->and($flags->checksum)->toBeFalse()
        ->and($flags->date)->toBeFalse()
        ->and($flags->dimensions)->toBeFalse();
});

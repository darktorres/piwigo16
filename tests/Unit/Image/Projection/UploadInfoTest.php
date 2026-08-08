<?php

declare(strict_types=1);

use Piwigo\Image\Projection\UploadInfo;

test('constructs with distinct values for every property', function (): void {
    $row = new UploadInfo(
        'upload/2026/08/01/',
        'photo.jpg',
        '2e7ee450c4a4cffe42945205029782b9',
        800,
        600,
        4_096
    );

    expect($row->path)->toBe('upload/2026/08/01/')
        ->and($row->file)->toBe('photo.jpg')
        ->and($row->md5sum)->toBe('2e7ee450c4a4cffe42945205029782b9')
        ->and($row->width)->toBe(800)
        ->and($row->height)->toBe(600)
        ->and($row->filesize)->toBe(4_096);
});

test('toArray round-trips the exact same shape', function (): void {
    $row = new UploadInfo(
        'upload/2026/08/01/',
        'photo.jpg',
        '2e7ee450c4a4cffe42945205029782b9',
        800,
        600,
        4_096
    );

    expect($row->toArray())->toBe([
        'path' => 'upload/2026/08/01/',
        'file' => 'photo.jpg',
        'md5sum' => '2e7ee450c4a4cffe42945205029782b9',
        'width' => 800,
        'height' => 600,
        'filesize' => 4_096,
    ]);
});

test('accepts a null md5sum/width/height/filesize', function (): void {
    $row = new UploadInfo('upload/2026/08/01/', 'photo.jpg', null, null, null, null);

    expect($row->md5sum)->toBeNull()
        ->and($row->width)->toBeNull()
        ->and($row->height)->toBeNull()
        ->and($row->filesize)->toBeNull();
});

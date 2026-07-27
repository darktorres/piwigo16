<?php

declare(strict_types=1);

use Piwigo\Job\BatchUploadJob;

/**
 * @return array{sourceFilepath: string, originalFilename: ?string, categories: ?list<int>, level: ?int, imageId: ?int, originalMd5sum: ?string}
 */
function baseBatchUploadJobArgs(): array
{
    return [
        'sourceFilepath' => '/tmp/piwigo-uploads/staged-photo.jpg',
        'originalFilename' => 'family-picnic.jpg',
        'categories' => [12, 45, 7],
        'level' => 4,
        'imageId' => 231,
        'originalMd5sum' => '9e107d9d372bb6826bd81d3542a419d6',
    ];
}

test('constructs with distinct values for every property', function (): void {
    $job = new BatchUploadJob(...baseBatchUploadJobArgs());

    expect($job->sourceFilepath)->toBe('/tmp/piwigo-uploads/staged-photo.jpg')
        ->and($job->originalFilename)->toBe('family-picnic.jpg')
        ->and($job->categories)->toBe([12, 45, 7])
        ->and($job->level)->toBe(4)
        ->and($job->imageId)->toBe(231)
        ->and($job->originalMd5sum)->toBe('9e107d9d372bb6826bd81d3542a419d6');
});

test('constructs with only the required sourceFilepath, every optional param defaulting to null', function (): void {
    $job = new BatchUploadJob('/tmp/piwigo-uploads/only-required.jpg');

    expect($job->sourceFilepath)->toBe('/tmp/piwigo-uploads/only-required.jpg')
        ->and($job->originalFilename)->toBeNull()
        ->and($job->categories)->toBeNull()
        ->and($job->level)->toBeNull()
        ->and($job->imageId)->toBeNull()
        ->and($job->originalMd5sum)->toBeNull();
});

test('constructs with originalFilename null', function (): void {
    $args = baseBatchUploadJobArgs();
    $args['originalFilename'] = null;

    $job = new BatchUploadJob(...$args);

    expect($job->originalFilename)->toBeNull();
});

test('constructs with categories null', function (): void {
    $args = baseBatchUploadJobArgs();
    $args['categories'] = null;

    $job = new BatchUploadJob(...$args);

    expect($job->categories)->toBeNull();
});

test('constructs with an empty categories list, distinct from null', function (): void {
    $args = baseBatchUploadJobArgs();
    $args['categories'] = [];

    $job = new BatchUploadJob(...$args);

    expect($job->categories)->toBe([]);
});

test('constructs with level null', function (): void {
    $args = baseBatchUploadJobArgs();
    $args['level'] = null;

    $job = new BatchUploadJob(...$args);

    expect($job->level)->toBeNull();
});

test('constructs with imageId null', function (): void {
    $args = baseBatchUploadJobArgs();
    $args['imageId'] = null;

    $job = new BatchUploadJob(...$args);

    expect($job->imageId)->toBeNull();
});

test('constructs with originalMd5sum null', function (): void {
    $args = baseBatchUploadJobArgs();
    $args['originalMd5sum'] = null;

    $job = new BatchUploadJob(...$args);

    expect($job->originalMd5sum)->toBeNull();
});

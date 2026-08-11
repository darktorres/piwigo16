<?php

declare(strict_types=1);

use Piwigo\Site\Projection\Site;

/**
 * @return array<string, mixed>
 */
function fullSiteRow(): array
{
    return [
        'id' => '2',
        'galleries_url' => 'http://remote.example/galleries/',
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $site = Site::fromRow(fullSiteRow());

    expect($site->id)
        ->toBe(2)
        ->and($site->galleriesUrl)
        ->toBe('http://remote.example/galleries/');
});

test('fromRow defaults id/galleries_url to their zero value when absent', function (): void {
    $row = fullSiteRow();
    $row['id'] = null;
    $row['galleries_url'] = null;

    $site = Site::fromRow($row);

    expect($site->id)
        ->toBe(0)
        ->and($site->galleriesUrl)
        ->toBe('');
});

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $roundTripped = Site::fromRow(fullSiteRow())->toArray();

    expect($roundTripped)
        ->toBe([
            'id' => 2,
            'galleries_url' => 'http://remote.example/galleries/',
        ]);
});

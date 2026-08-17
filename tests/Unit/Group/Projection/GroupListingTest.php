<?php

declare(strict_types=1);

use Piwigo\Group\Projection\GroupListing;

/**
 * @return array<string, mixed>
 */
function fullGroupListingRow(): array
{
    return [
        'id' => '2',
        'name' => 'Editors',
        'is_default' => '1',
        'lastmodified' => '2026-08-01 12:00:00',
        'nb_users' => '5',
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $group = GroupListing::fromRow(fullGroupListingRow());

    expect($group->id->value)
        ->toBe(2)
        ->and($group->name)
        ->toBe('Editors')
        ->and($group->isDefault)
        ->toBeTrue()
        ->and($group->lastmodified)
        ->toBe('2026-08-01 12:00:00')
        ->and($group->nbUsers)
        ->toBe(5);
});

test('fromRow treats a falsy is_default as false', function (): void {
    $row = fullGroupListingRow();
    $row['is_default'] = '0';

    expect(GroupListing::fromRow($row)->isDefault)->toBeFalse();
});

test('fromRow throws for a missing/malformed id, no valid zero-default', function (): void {
    $row = fullGroupListingRow();
    $row['id'] = null;

    GroupListing::fromRow($row);
})->throws(InvalidArgumentException::class, 'got 0');

test('fromRow defaults name/lastmodified to empty string and nb_users to 0 when absent, given a valid id', function (): void {
    $row = fullGroupListingRow();
    $row['name'] = null;
    $row['lastmodified'] = null;
    $row['nb_users'] = null;

    $group = GroupListing::fromRow($row);

    expect($group->name)
        ->toBe('')
        ->and($group->lastmodified)
        ->toBe('')
        ->and($group->nbUsers)
        ->toBe(0);
});

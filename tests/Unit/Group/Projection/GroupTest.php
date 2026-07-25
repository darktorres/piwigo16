<?php

declare(strict_types=1);

use Piwigo\Group\Projection\Group;

/**
 * @return array<string, mixed>
 */
function fullGroupRow(): array
{
    return [
        'id' => '2',
        'name' => 'Editors',
        'is_default' => '1',
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $group = Group::fromRow(fullGroupRow());

    expect($group->id)->toBe(2)
        ->and($group->name)->toBe('Editors')
        ->and($group->isDefault)->toBeTrue();
});

test('fromRow treats a falsy is_default as false', function (): void {
    $row = fullGroupRow();
    $row['is_default'] = '0';

    $group = Group::fromRow($row);

    expect($group->isDefault)->toBeFalse();
});

test('fromRow defaults id/name to their zero value and is_default to false when absent', function (): void {
    $row = fullGroupRow();
    $row['id'] = null;
    $row['name'] = null;
    $row['is_default'] = null;

    $group = Group::fromRow($row);

    expect($group->id)->toBe(0)
        ->and($group->name)->toBe('')
        ->and($group->isDefault)->toBeFalse();
});

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $roundTripped = Group::fromRow(fullGroupRow())->toArray();

    expect($roundTripped)->toBe([
        'id' => 2,
        'name' => 'Editors',
        'is_default' => true,
    ]);
});

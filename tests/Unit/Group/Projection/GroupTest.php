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

    expect($group->id->value)->toBe(2)
        ->and($group->name)->toBe('Editors')
        ->and($group->isDefault)->toBeTrue();
});

test('fromRow treats a falsy is_default as false', function (): void {
    $row = fullGroupRow();
    $row['is_default'] = '0';

    $group = Group::fromRow($row);

    expect($group->isDefault)->toBeFalse();
});

test('fromRow throws when id is null', function (): void {
    // Behavior change, deliberate: id is a GroupId now, and GroupId::from(0)
    // always throws (0 was never a valid id) -- the previous "silently
    // default id to 0" contract (still every other Projection DTO's
    // convention, e.g. Tag::fromRow()) is structurally impossible to keep
    // once the field is really typed. fromRow() has no real caller in
    // src/Piwigo (findAllBasic() constructs Group directly), so there's no
    // production behavior being preserved -- a loud failure on malformed
    // id data is exactly the point of this VO.
    $row = fullGroupRow();
    $row['id'] = null;

    Group::fromRow($row);
})->throws(InvalidArgumentException::class);

test('fromRow defaults name to empty string and is_default to false when absent, given a valid id', function (): void {
    $row = fullGroupRow();
    $row['name'] = null;
    $row['is_default'] = null;

    $group = Group::fromRow($row);

    expect($group->id->value)->toBe(2)
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

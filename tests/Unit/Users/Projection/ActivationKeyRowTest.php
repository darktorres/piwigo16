<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\UserId;
use Piwigo\Users\Projection\ActivationKeyRow;

/**
 * @return array<string, mixed>
 */
function fullActivationKeyRow(): array
{
    return [
        'user_id' => '7',
        'status' => 'normal',
        'activation_key' => '$2y$10$abcdefghijklmnopqrstuv',
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $row = ActivationKeyRow::fromRow(fullActivationKeyRow());

    expect($row->userId)->toEqual(UserId::from(7))
        ->and($row->status)->toBe('normal')
        ->and($row->activationKey)->toBe('$2y$10$abcdefghijklmnopqrstuv');
});

test('fromRow defaults status/activationKey to empty string when absent, given a valid user_id', function (): void {
    $row = fullActivationKeyRow();
    $row['status'] = null;
    $row['activation_key'] = null;

    $activationKeyRow = ActivationKeyRow::fromRow($row);

    expect($activationKeyRow->userId)->toEqual(UserId::from(7))
        ->and($activationKeyRow->status)->toBe('')
        ->and($activationKeyRow->activationKey)->toBe('');
});

test('fromRow throws when user_id is missing', function (): void {
    $row = fullActivationKeyRow();
    $row['user_id'] = null;

    ActivationKeyRow::fromRow($row);
})->throws(InvalidArgumentException::class);

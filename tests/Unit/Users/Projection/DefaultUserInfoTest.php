<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Users\Projection\DefaultUserInfo;

/**
 * Every boolean/numeric field here is deliberately truthy/non-zero (the
 * all-absent-defaults path is covered separately below) -- a falsy/zero
 * fixture value here would make fromArray()'s `?? false`/ternary
 * fallback indistinguishable from its real branch, silently masking a
 * real regression there.
 *
 * @return array<string, mixed>
 */
function fullDefaultUserInfoRow(): array
{
    return [
        'nb_image_page' => '20',
        'language' => 'en_US',
        'expand' => 1,
        'show_nb_comments' => 1,
        'show_nb_hits' => 1,
        'recent_period' => '7',
        'theme' => 'default',
        'enabled_high' => 1,
        'level' => '3',
        'activation_key' => 'abc123',
        'activation_key_expire' => '2026-08-08 00:00:00',
        'lastmodified' => '2026-08-01 12:00:00',
        'preferences' => [
            'maxwidth' => '100',
            'maxheight' => '100',
        ],
    ];
}

test('fromArray narrows every column to its real type', function (): void {
    $info = DefaultUserInfo::fromArray(fullDefaultUserInfoRow());

    expect($info->nbImagePage)
        ->toBe(20)
        ->and($info->language)
        ->toEqual(LangCode::from('en_US'))
        ->and($info->expand)
        ->toBeTrue()
        ->and($info->showNbComments)
        ->toBeTrue()
        ->and($info->showNbHits)
        ->toBeTrue()
        ->and($info->recentPeriod)
        ->toBe(7)
        ->and($info->theme)
        ->toEqual(ThemeId::from('default'))
        ->and($info->enabledHigh)
        ->toBeTrue()
        ->and($info->level)
        ->toBe(3)
        ->and($info->activationKey)
        ->toBe('abc123')
        ->and($info->activationKeyExpire)
        ->toBe('2026-08-08 00:00:00')
        ->and($info->lastmodified)
        ->toBe('2026-08-01 12:00:00')
        ->and($info->preferences)
        ->toBe([
            'maxwidth' => '100',
            'maxheight' => '100',
        ]);
});

test('fromArray defaults absent scalar columns to their zero value', function (): void {
    $info = DefaultUserInfo::fromArray([]);

    expect($info->nbImagePage)
        ->toBe(0)
        ->and($info->language)
        ->toBeNull()
        ->and($info->expand)
        ->toBeFalse()
        ->and($info->showNbComments)
        ->toBeFalse()
        ->and($info->showNbHits)
        ->toBeFalse()
        ->and($info->recentPeriod)
        ->toBe(0)
        ->and($info->theme)
        ->toBeNull()
        ->and($info->enabledHigh)
        ->toBeFalse()
        ->and($info->level)
        ->toBe(0)
        ->and($info->activationKey)
        ->toBeNull()
        ->and($info->activationKeyExpire)
        ->toBeNull()
        ->and($info->lastmodified)
        ->toBe('')
        ->and($info->preferences)
        ->toBeNull();
});

test('fromArray drops any non-string-keyed entry from a corrupted preferences cache value', function (): void {
    $row = fullDefaultUserInfoRow();
    $row['preferences'] = [
        'maxwidth' => '100',
        0 => 'stray-int-key',
        1 => 'another',
    ];

    $info = DefaultUserInfo::fromArray($row);

    expect($info->preferences)
        ->toBe([
            'maxwidth' => '100',
        ]);
});

test('fromArray treats a non-array preferences value as null (corrupted ProcessCache entry)', function (): void {
    $row = fullDefaultUserInfoRow();
    $row['preferences'] = 'not-an-array';

    $info = DefaultUserInfo::fromArray($row);

    expect($info->preferences)
        ->toBeNull();
});

test('toArray round-trips the exact same shape fromArray narrowed', function (): void {
    $roundTripped = DefaultUserInfo::fromArray(fullDefaultUserInfoRow())->toArray();

    expect($roundTripped)
        ->toBe([
            'nb_image_page' => 20,
            'language' => 'en_US',
            'expand' => true,
            'show_nb_comments' => true,
            'show_nb_hits' => true,
            'recent_period' => 7,
            'theme' => 'default',
            'enabled_high' => true,
            'level' => 3,
            'activation_key' => 'abc123',
            'activation_key_expire' => '2026-08-08 00:00:00',
            'lastmodified' => '2026-08-01 12:00:00',
            'preferences' => [
                'maxwidth' => '100',
                'maxheight' => '100',
            ],
        ]);
});

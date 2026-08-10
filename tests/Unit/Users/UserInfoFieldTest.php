<?php

declare(strict_types=1);

use Piwigo\Users\UserInfoField;

/**
 * Piwigo\Users\UserInfoField -- UserRepository::updateInfosForUsers()'s
 * $updates map keys, enumerated. No dedicated Integration/Browser spec
 * of its own.
 */
test('fromToken maps every real user_infos column token to its case', function (): void {
    expect(UserInfoField::fromToken('level'))->toBe(UserInfoField::Level)
        ->and(UserInfoField::fromToken('language'))->toBe(UserInfoField::Language)
        ->and(UserInfoField::fromToken('theme'))->toBe(UserInfoField::Theme)
        ->and(UserInfoField::fromToken('nb_image_page'))->toBe(UserInfoField::NbImagePage)
        ->and(UserInfoField::fromToken('recent_period'))->toBe(UserInfoField::RecentPeriod)
        ->and(UserInfoField::fromToken('expand'))->toBe(UserInfoField::Expand)
        ->and(UserInfoField::fromToken('show_nb_comments'))->toBe(UserInfoField::ShowNbComments)
        ->and(UserInfoField::fromToken('show_nb_hits'))->toBe(UserInfoField::ShowNbHits)
        ->and(UserInfoField::fromToken('enabled_high'))->toBe(UserInfoField::EnabledHigh);
});

test('fromToken returns null for an unrecognized token', function (): void {
    expect(UserInfoField::fromToken('not_a_real_column'))->toBeNull();
});

test('dqlPropertyAndIsBoolean maps every case to its real DQL property path and boolean-ness', function (): void {
    $cases = [
        [UserInfoField::Level, 'level', false],
        [UserInfoField::Language, 'language', false],
        [UserInfoField::Theme, 'theme', false],
        [UserInfoField::NbImagePage, 'nbImagePage', false],
        [UserInfoField::RecentPeriod, 'recentPeriod', false],
        [UserInfoField::Expand, 'expand', true],
        [UserInfoField::ShowNbComments, 'showNbComments', true],
        [UserInfoField::ShowNbHits, 'showNbHits', true],
        [UserInfoField::EnabledHigh, 'enabledHigh', true],
    ];

    foreach ($cases as [$case, $expectedProperty, $expectedIsBoolean]) {
        $result = $case->dqlPropertyAndIsBoolean();
        expect($result->property)->toBe($expectedProperty)
            ->and($result->isBoolean)->toBe($expectedIsBoolean);
    }
});

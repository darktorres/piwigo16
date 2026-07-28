<?php

declare(strict_types=1);

use Piwigo\Users\Projection\UserInfo;

/**
 * Piwigo\Users\Projection\UserInfo -- had zero dedicated coverage (see
 * /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1).
 * fromEntity() (the only real production caller today) is already
 * exercised indirectly via UserRepositoryTest; this file covers fromRow()
 * (kept for a future raw-row caller, per this class's own docblock) and
 * toArray() directly.
 */
test('fromRow narrows a real DB row into typed properties, decoding JSON preferences', function (): void {
    $info = UserInfo::fromRow([
        'user_id' => 5,
        'nb_image_page' => '20',
        'status' => 'normal',
        'language' => 'en_UK',
        'expand' => 1,
        'show_nb_comments' => 0,
        'show_nb_hits' => 1,
        'recent_period' => '7',
        'theme' => 'default',
        'registration_date' => '2024-01-01 00:00:00',
        'enabled_high' => 1,
        'level' => '2',
        'activation_key' => 'abc123',
        'activation_key_expire' => '2024-02-01 00:00:00',
        'last_visit' => '2024-03-01 00:00:00',
        'last_visit_from_history' => 1,
        'lastmodified' => '2024-04-01 00:00:00',
        'preferences' => json_encode(['theme' => 'dark', 'lang' => 'fr']),
    ]);

    expect($info->userId->value)->toBe(5);
    expect($info->nbImagePage)->toBe(20);
    expect($info->status)->toBe('normal');
    expect($info->language)->toBe('en_UK');
    expect($info->expand)->toBeTrue();
    expect($info->showNbComments)->toBeFalse();
    expect($info->showNbHits)->toBeTrue();
    expect($info->recentPeriod)->toBe(7);
    expect($info->theme)->toBe('default');
    expect($info->registrationDate)->toBe('2024-01-01 00:00:00');
    expect($info->enabledHigh)->toBeTrue();
    expect($info->level)->toBe(2);
    expect($info->activationKey)->toBe('abc123');
    expect($info->activationKeyExpire)->toBe('2024-02-01 00:00:00');
    expect($info->lastVisit)->toBe('2024-03-01 00:00:00');
    expect($info->lastVisitFromHistory)->toBeTrue();
    expect($info->lastmodified)->toBe('2024-04-01 00:00:00');
    expect($info->preferences)->toBe(['theme' => 'dark', 'lang' => 'fr']);
});

test('fromRow falls back to safe defaults for every field except user_id', function (): void {
    $info = UserInfo::fromRow(['user_id' => 7]);

    expect($info->userId->value)->toBe(7);
    expect($info->nbImagePage)->toBe(0);
    expect($info->status)->toBe('guest');
    expect($info->language)->toBe('');
    expect($info->expand)->toBeFalse();
    expect($info->theme)->toBe('');
    expect($info->registrationDate)->toBeNull();
    expect($info->level)->toBe(0);
    expect($info->activationKey)->toBeNull();
    expect($info->lastmodified)->toBe('');
    expect($info->preferences)->toBeNull();
});

test('fromRow throws for a missing or invalid user_id', function (): void {
    expect(fn () => UserInfo::fromRow(['user_id' => 'not-a-number']))
        ->toThrow(InvalidArgumentException::class, 'UserInfo::fromRow(): missing or invalid user_id');

    expect(fn () => UserInfo::fromRow([]))
        ->toThrow(InvalidArgumentException::class, 'UserInfo::fromRow(): missing or invalid user_id');
});

test('toArray round-trips every typed property back into its raw DB-shaped key', function (): void {
    $info = UserInfo::fromRow([
        'user_id' => 5,
        'nb_image_page' => 20,
        'status' => 'normal',
        'language' => 'en_UK',
        'expand' => true,
        'show_nb_comments' => false,
        'show_nb_hits' => true,
        'recent_period' => 7,
        'theme' => 'default',
        'registration_date' => '2024-01-01 00:00:00',
        'enabled_high' => true,
        'level' => 2,
        'activation_key' => 'abc123',
        'activation_key_expire' => '2024-02-01 00:00:00',
        'last_visit' => '2024-03-01 00:00:00',
        'last_visit_from_history' => true,
        'lastmodified' => '2024-04-01 00:00:00',
        'preferences' => json_encode(['theme' => 'dark']),
    ]);

    expect($info->toArray())->toBe([
        'user_id' => 5,
        'nb_image_page' => 20,
        'status' => 'normal',
        'language' => 'en_UK',
        'expand' => true,
        'show_nb_comments' => false,
        'show_nb_hits' => true,
        'recent_period' => 7,
        'theme' => 'default',
        'registration_date' => '2024-01-01 00:00:00',
        'enabled_high' => true,
        'level' => 2,
        'activation_key' => 'abc123',
        'activation_key_expire' => '2024-02-01 00:00:00',
        'last_visit' => '2024-03-01 00:00:00',
        'last_visit_from_history' => true,
        'lastmodified' => '2024-04-01 00:00:00',
        'preferences' => ['theme' => 'dark'],
    ]);
});

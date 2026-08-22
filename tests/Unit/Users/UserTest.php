<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Core\AppInfo;
use Piwigo\Users\Projection\DefaultUserInfo;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

test('fromUserArray coerces a real legacy $user row', function (): void {
    $user = User::fromUserArray([
        'id' => '7',
        'username' => 'alice',
        'email' => 'alice@example.com',
        'language' => 'en_UK',
        'theme' => 'modus',
        'status' => 'admin',
        'enabled_high' => '1',
        'forbidden_categories' => '3,8,12',
        'level' => '4',
        'preferences' => [
            'show_tags' => 'yes',
            0 => 'dropped',
        ],
        'nb_image_page' => '30',
        'recent_period' => '14',
        'expand' => '1',
        'show_nb_comments' => '1',
        'show_nb_hits' => '1',
    ]);

    expect($user->id->value)
        ->toBe(7)
        ->and($user->username)
        ->toEqual(Username::from('alice'))
        ->and($user->email)
        ->toEqual(Email::from('alice@example.com'))
        ->and($user->language)
        ->toEqual(LangCode::from('en_UK'))
        ->and($user->theme)
        ->toEqual(ThemeId::from('modus'))
        ->and($user->status)
        ->toBe(UserStatus::Admin)
        ->and($user->enabledHigh)
        ->toBeTrue()
        ->and($user->forbiddenCategories)
        ->toBe('3,8,12')
        ->and($user->level)
        ->toBe(4)
        ->and($user->preferences)
        ->toBe([
            'show_tags' => 'yes',
        ])
        ->and($user->nbImagePage)
        ->toBe(30)
        ->and($user->recentPeriod)
        ->toBe(14)
        ->and($user->expand)
        ->toBeTrue()
        ->and($user->showNbComments)
        ->toBeTrue()
        ->and($user->showNbHits)
        ->toBeTrue()
        ->and($user->rawAttributes)
        ->toHaveKey('id');
});

test('fromUserArray throws on a missing/malformed id', function (): void {
    User::fromUserArray([
        'username' => 'alice',
    ]);
})->throws(InvalidArgumentException::class);

test('fromUserArray degrades safely on a missing/malformed non-id field', function (): void {
    $user = User::fromUserArray([
        'id' => 7,
    ]);

    expect($user->id->value)
        ->toBe(7)
        ->and($user->username)
        ->toBeNull()
        ->and($user->email)
        ->toBeNull()
        ->and($user->language)
        ->toEqual(LangCode::from('en_UK'))
        ->and($user->theme)
        ->toEqual(ThemeId::from(AppInfo::DEFAULT_TEMPLATE))
        ->and($user->status)
        ->toBe(UserStatus::Guest)
        ->and($user->enabledHigh)
        ->toBeFalse()
        ->and($user->forbiddenCategories)
        ->toBe('')
        ->and($user->level)
        ->toBe(0)
        ->and($user->preferences)
        ->toBe([])
        ->and($user->nbImagePage)
        ->toBe(0)
        ->and($user->recentPeriod)
        ->toBe(0)
        ->and($user->expand)
        ->toBeFalse()
        ->and($user->showNbComments)
        ->toBeFalse()
        ->and($user->showNbHits)
        ->toBeFalse();
});

test('fromUserArray falls back to Guest for an unrecognized status value', function (): void {
    $user = User::fromUserArray([
        'id' => 1,
        'status' => 'not_a_real_status',
    ]);

    expect($user->status)
        ->toBe(UserStatus::Guest);
});

test('withLanguage returns a new immutable instance, original is untouched', function (): void {
    $original = new User(
        id: UserId::from(1),
        username: Username::from('bob'),
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('modus'),
        status: UserStatus::Normal,
        enabledHigh: false,
    );

    $updated = $original->withLanguage(LangCode::from('fr_FR'));

    expect($updated->language)
        ->toEqual(LangCode::from('fr_FR'))
        ->and($original->language)
        ->toEqual(LangCode::from('en_UK'))
        ->and($updated)
        ->not->toBe($original);
});

test('withUsername returns a new immutable instance', function (): void {
    $original = new User(
        id: UserId::from(1),
        username: Username::from('bob'),
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('modus'),
        status: UserStatus::Normal,
        enabledHigh: false,
    );

    $updated = $original->withUsername(Username::from('robert'));

    expect($updated->username)
        ->toEqual(Username::from('robert'))
        ->and($original->username)
        ->toEqual(Username::from('bob'));
});

test('withLevel returns a new immutable instance and syncs rawAttributes', function (): void {
    $original = new User(
        id: UserId::from(1),
        username: Username::from('bob'),
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('modus'),
        status: UserStatus::Normal,
        enabledHigh: false,
        level: 4,
        rawAttributes: [
            'level' => 4,
        ],
    );

    $updated = $original->withLevel(8);

    expect($updated->level)
        ->toBe(8)
        ->and($updated->rawAttributes)
        ->toBe([
            'level' => 8,
        ])
        ->and($original->level)
        ->toBe(4)
        ->and($original->rawAttributes)
        ->toBe([
            'level' => 4,
        ])
        ->and($updated)
        ->not->toBe($original);
});

test('withEnabledHigh returns a new immutable instance and syncs rawAttributes', function (): void {
    $original = new User(
        id: UserId::from(1),
        username: Username::from('bob'),
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('modus'),
        status: UserStatus::Normal,
        enabledHigh: false,
        rawAttributes: [
            'enabled_high' => false,
        ],
    );

    $updated = $original->withEnabledHigh(true);

    expect($updated->enabledHigh)
        ->toBeTrue()
        ->and($updated->rawAttributes)
        ->toBe([
            'enabled_high' => true,
        ])
        ->and($original->enabledHigh)
        ->toBeFalse()
        ->and($original->rawAttributes)
        ->toBe([
            'enabled_high' => false,
        ]);
});

test('withDefaultsFrom overlays the 5 site-default fields, leaves theme/language untouched', function (): void {
    $original = new User(
        id: UserId::from(1),
        username: Username::from('bob'),
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('modus'),
        status: UserStatus::Normal,
        enabledHigh: false,
        nbImagePage: 15,
        recentPeriod: 7,
        expand: false,
        showNbComments: false,
        showNbHits: false,
    );

    $defaults = DefaultUserInfo::fromArray([
        'nb_image_page' => 30,
        'language' => 'fr_FR',
        'theme' => 'default',
        'recent_period' => 14,
        'expand' => true,
        'show_nb_comments' => true,
        'show_nb_hits' => true,
        'lastmodified' => '2026-01-01 00:00:00',
    ]);

    $updated = $original->withDefaultsFrom($defaults);

    expect($updated->nbImagePage)
        ->toBe(30)
        ->and($updated->recentPeriod)
        ->toBe(14)
        ->and($updated->expand)
        ->toBeTrue()
        ->and($updated->showNbComments)
        ->toBeTrue()
        ->and($updated->showNbHits)
        ->toBeTrue()
        ->and($updated->language)
        ->toEqual(LangCode::from('en_UK'))
        ->and($updated->theme)
        ->toEqual(ThemeId::from('modus'))
        ->and($original->nbImagePage)
        ->toBe(15)
        ->and($original->expand)
        ->toBeFalse();
});

/**
 * Regression test for a real bug found while porting this class from
 * the reference tree: none of the 7 pre-existing wither methods
 * (withLanguage/withUsername/withLevel/withPreferences/withEnabledHigh/
 * withStatus/withRawAttribute) propagated the 5 fields added by
 * withDefaultsFrom() above -- calling any of them would silently reset
 * nbImagePage/recentPeriod/expand/showNbComments/showNbHits back to
 * their constructor defaults.
 */
test('every existing wither propagates the 5 site-default fields unchanged', function (): void {
    $original = new User(
        id: UserId::from(1),
        username: Username::from('bob'),
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('modus'),
        status: UserStatus::Normal,
        enabledHigh: false,
        nbImagePage: 15,
        recentPeriod: 7,
        expand: true,
        showNbComments: true,
        showNbHits: true,
    );

    $withers = [
        $original->withLanguage(LangCode::from('fr_FR')),
        $original->withUsername(Username::from('robert')),
        $original->withLevel(8),
        $original->withPreferences([
            'a' => 'b',
        ]),
        $original->withEnabledHigh(true),
        $original->withStatus(UserStatus::Admin),
        $original->withRawAttribute('key', 'value'),
    ];

    foreach ($withers as $updated) {
        expect($updated->nbImagePage)
            ->toBe(15)
            ->and($updated->recentPeriod)
            ->toBe(7)
            ->and($updated->expand)
            ->toBeTrue()
            ->and($updated->showNbComments)
            ->toBeTrue()
            ->and($updated->showNbHits)
            ->toBeTrue();
    }
});

test('withRawAttribute adds a key without disturbing the rest of the array', function (): void {
    $original = new User(
        id: UserId::from(1),
        username: Username::from('bob'),
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('modus'),
        status: UserStatus::Normal,
        enabledHigh: false,
        rawAttributes: [
            'existing' => 'value',
        ],
    );

    $updated = $original->withRawAttribute('new_key', 'new_value');

    expect($updated->rawAttributes)
        ->toBe([
            'existing' => 'value',
            'new_key' => 'new_value',
        ])
        ->and($original->rawAttributes)
        ->toBe([
            'existing' => 'value',
        ]);
});

<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

beforeEach(function (): void {
    CurrentUser::reset();
    CurrentConfig::reset();
});

afterEach(function (): void {
    CurrentUser::reset();
    CurrentConfig::reset();
});

test('isInitialized is false before attachGlobals runs', function (): void {
    expect(CurrentUser::isInitialized())->toBeFalse();
});

test('get throws before attachGlobals or set has run', function (): void {
    CurrentUser::get();
})->throws(LogicException::class);

test('attachGlobals seeds a guest user', function (): void {
    CurrentUser::attachGlobals();
    $user = CurrentUser::get();

    expect($user->status)->toBe(UserStatus::Guest)
        ->and($user->language)->toBe(AppInfo::DEFAULT_LANGUAGE)
        ->and($user->theme)->toBe(AppInfo::DEFAULT_TEMPLATE)
        ->and($user->id)->toBe(CurrentConfig::guestId());
});

test('attachGlobals is idempotent -- does not clobber a real set() user', function (): void {
    $real = new User(
        id: 42,
        username: 'alice',
        email: '',
        language: 'fr_FR',
        theme: 'modus',
        status: UserStatus::Admin,
        enabledHigh: true,
    );
    CurrentUser::set($real);

    CurrentUser::attachGlobals();

    expect(CurrentUser::get())->toBe($real);
});

test('updateLanguage replaces the instance with a language-updated copy', function (): void {
    CurrentUser::attachGlobals();

    CurrentUser::updateLanguage('fr_FR');

    expect(CurrentUser::get()->language)->toBe('fr_FR');
});

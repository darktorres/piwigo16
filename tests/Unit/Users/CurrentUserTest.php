<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Users\CurrentUser;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

// CurrentUser is a container-shared instance -- each test constructs its
// own fresh instance directly; no reset() needed.

beforeEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
});

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
});

test('isInitialized is false before attachGlobals runs', function (): void {
    $currentUser = new CurrentUser(new CurrentConfig());

    expect($currentUser->isInitialized())->toBeFalse();
});

test('get throws before attachGlobals or set has run', function (): void {
    $currentUser = new CurrentUser(new CurrentConfig());

    $currentUser->get();
})->throws(LogicException::class);

test('attachGlobals seeds a guest user', function (): void {
    $currentUser = new CurrentUser(new CurrentConfig());
    $currentUser->attachGlobals();
    $user = $currentUser->get();

    expect($user->status)->toBe(UserStatus::Guest)
        ->and($user->username)->toBeNull()
        ->and($user->email)->toBeNull()
        ->and($user->enabledHigh)->toBeFalse()
        ->and($user->language)->toEqual(LangCode::from(AppInfo::DEFAULT_LANGUAGE))
        ->and($user->theme)->toBe(AppInfo::DEFAULT_TEMPLATE)
        ->and($user->id->value)->toBe(new CurrentConfig()->guestId);
});

test('attachGlobals is idempotent -- does not clobber a real set() user', function (): void {
    $real = new User(
        id: UserId::from(42),
        username: Username::from('alice'),
        email: null,
        language: LangCode::from('fr_FR'),
        theme: 'modus',
        status: UserStatus::Admin,
        enabledHigh: true,
    );
    $currentUser = new CurrentUser(new CurrentConfig());
    $currentUser->set($real);

    $currentUser->attachGlobals();

    expect($currentUser->get())->toBe($real);
});

test('updateLanguage replaces the instance with a language-updated copy', function (): void {
    $currentUser = new CurrentUser(new CurrentConfig());
    $currentUser->attachGlobals();

    $currentUser->updateLanguage(LangCode::from('fr_FR'));

    expect($currentUser->get()->language)->toEqual(LangCode::from('fr_FR'));
});

test('reset clears the real-user-resolved flag back to false', function (): void {
    $currentUser = new CurrentUser(new CurrentConfig());
    $currentUser->markRealUserResolved();
    expect($currentUser->wasRealUserResolved())->toBeTrue();

    $currentUser->reset();

    expect($currentUser->wasRealUserResolved())->toBeFalse();
});

test('current() falls back to a memoized instance when Kernel is not booted', function (): void {
    // Memoized (not fresh-per-call), same reasoning as
    // CurrentTemplate::current(): a caller that writes via current() in
    // one call and reads via current() in a later call must see the same
    // instance, or the write would be lost. reset() first:
    // the memoized fallback is one real object shared by every not-booted
    // caller across the whole test process (other test files' own
    // shim-using calls can leave prior state on it), so this test must
    // start from a clean slate rather than assume it's the first caller.
    $first = CurrentUserTestFactory::get();
    $first->reset();
    $first->attachGlobals();

    $second = CurrentUserTestFactory::get();

    expect($second)->toBe($first)
        ->and($second->isInitialized())->toBeTrue();
});

test('current() resolves the container-shared instance once Kernel is booted', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/piwigo-current-user-test'));

    $instance = Kernel::container()->get(CurrentUser::class);

    expect(CurrentUserTestFactory::get())->toBe($instance);
});

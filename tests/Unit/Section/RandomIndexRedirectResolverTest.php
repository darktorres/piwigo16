<?php

declare(strict_types=1);

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Section\RandomIndexRedirectResolver;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

// RandomIndexRedirectResolver calls an injected AccessLevelChecker::
// isAGuest(), which reads Piwigo\Users\CurrentUser -- so seeding
// CurrentUser with the desired status is enough.
function seedCurrentUserStatus(UserStatus $status): void
{
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(1),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: $status,
        enabledHigh: false,
    ));
}

/**
 * Only isAGuest() is ever reached, which only reads CurrentUser --
 * AccessLevelChecker has no HtmlRenderingInterface/
 * RedirectServiceInterface dependency at all, so no fakes are needed
 * here.
 */
function randomIndexRedirectResolverTestAccessLevelChecker(): AccessLevelChecker
{
    return new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get());
}

beforeEach(function (): void {
    seedCurrentUserStatus(UserStatus::Normal);
});

afterEach(function (): void {
    CurrentUserTestFactory::get()->reset();
});

test('resolveCandidates matches an empty-string condition', function (): void {
    $resolver = new RandomIndexRedirectResolver();

    expect($resolver->resolveCandidates(randomIndexRedirectResolverTestAccessLevelChecker(), [
        'index.php' => '',
    ]))->toBe(['index.php']);
});

test('resolveCandidates matches a literal "return true;" condition', function (): void {
    $resolver = new RandomIndexRedirectResolver();

    expect($resolver->resolveCandidates(randomIndexRedirectResolverTestAccessLevelChecker(), [
        'index.php' => 'return true;',
    ]))->toBe(['index.php']);
});

test('resolveCandidates matches "return is_a_guest();" only for a real guest', function (): void {
    $resolver = new RandomIndexRedirectResolver();
    $candidates = [
        'guest.php' => 'return is_a_guest();',
    ];

    seedCurrentUserStatus(UserStatus::Guest);
    expect($resolver->resolveCandidates(randomIndexRedirectResolverTestAccessLevelChecker(), $candidates))
        ->toBe(['guest.php']);

    seedCurrentUserStatus(UserStatus::Normal);
    expect($resolver->resolveCandidates(randomIndexRedirectResolverTestAccessLevelChecker(), $candidates))
        ->toBe([]);
});

test('resolveCandidates never matches an arbitrary PHP condition string', function (): void {
    $resolver = new RandomIndexRedirectResolver();

    expect($resolver->resolveCandidates(randomIndexRedirectResolverTestAccessLevelChecker(), [
        'evil.php' => 'system("id");',
    ]))->toBe([]);
});

test('resolveCandidates skips non-string keys and preserves match order', function (): void {
    $resolver = new RandomIndexRedirectResolver();

    $result = $resolver->resolveCandidates(randomIndexRedirectResolverTestAccessLevelChecker(), [
        'first.php' => '',
        0 => 'return true;',
        'second.php' => 'return true;',
    ]);

    expect($result)
        ->toBe(['first.php', 'second.php']);
});

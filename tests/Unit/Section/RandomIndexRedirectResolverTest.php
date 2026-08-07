<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\UserId;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Section\RandomIndexRedirectResolver;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

// RandomIndexRedirectResolver now calls (an injected) AccessLevelChecker::
// isAGuest() (P23 batch 8d, converted to constructor injection in the
// singleton/service-locator elimination campaign's Phase 7), which reads
// Piwigo\Users\CurrentUser (Legacy Coupling Retirement Track A batch A3)
// -- so seeding CurrentUser with the desired status is enough, matching
// this test's old $GLOBALS['user'] stub.
function seedCurrentUserStatus(UserStatus $status): void
{
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(1),
        username: '',
        email: '',
        language: '',
        theme: '',
        status: $status,
        enabledHigh: false,
    ));
}

/**
 * Only isAGuest() is ever reached, which only reads CurrentUser --
 * AccessLevelChecker (singleton/service-locator elimination campaign,
 * Phase 12 sub-phase 12A) has no HtmlRenderingInterface/
 * RedirectServiceInterface dependency at all, so no fakes are needed here
 * any more.
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

    expect($resolver->resolveCandidates(randomIndexRedirectResolverTestAccessLevelChecker(), ['index.php' => '']))->toBe(['index.php']);
});

test('resolveCandidates matches a literal "return true;" condition', function (): void {
    $resolver = new RandomIndexRedirectResolver();

    expect($resolver->resolveCandidates(randomIndexRedirectResolverTestAccessLevelChecker(), ['index.php' => 'return true;']))->toBe(['index.php']);
});

test('resolveCandidates matches "return is_a_guest();" only for a real guest', function (): void {
    $resolver = new RandomIndexRedirectResolver();
    $candidates = ['guest.php' => 'return is_a_guest();'];

    seedCurrentUserStatus(UserStatus::Guest);
    expect($resolver->resolveCandidates(randomIndexRedirectResolverTestAccessLevelChecker(), $candidates))->toBe(['guest.php']);

    seedCurrentUserStatus(UserStatus::Normal);
    expect($resolver->resolveCandidates(randomIndexRedirectResolverTestAccessLevelChecker(), $candidates))->toBe([]);
});

test('resolveCandidates never matches an arbitrary PHP condition string', function (): void {
    // The [SEC-15] fix itself: this used to be eval()'d.
    $resolver = new RandomIndexRedirectResolver();

    expect($resolver->resolveCandidates(randomIndexRedirectResolverTestAccessLevelChecker(), ['evil.php' => 'system("id");']))->toBe([]);
});

test('resolveCandidates skips non-string keys and preserves match order', function (): void {
    $resolver = new RandomIndexRedirectResolver();

    $result = $resolver->resolveCandidates(randomIndexRedirectResolverTestAccessLevelChecker(), [
        'first.php' => '',
        0 => 'return true;',
        'second.php' => 'return true;',
    ]);

    expect($result)->toBe(['first.php', 'second.php']);
});

<?php

declare(strict_types=1);

use Piwigo\Section\RandomIndexRedirectResolver;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

// RandomIndexRedirectResolver now calls Piwigo\Auth\AccessControl::isAGuest()
// directly (P23 batch 8d), which reads Piwigo\Users\CurrentUser (Legacy
// Coupling Retirement Track A batch A3) -- so seeding CurrentUser with the
// desired status is enough, matching this test's old $GLOBALS['user'] stub.
function seedCurrentUserStatus(UserStatus $status): void
{
    CurrentUser::current()->set(new User(
        id: \Piwigo\Common\ValueObject\UserId::from(1),
        username: '',
        email: '',
        language: '',
        theme: '',
        status: $status,
        enabledHigh: false,
    ));
}

beforeEach(function (): void {
    seedCurrentUserStatus(UserStatus::Normal);
});

afterEach(function (): void {
    CurrentUser::current()->reset();
});

test('resolveCandidates matches an empty-string condition', function (): void {
    $resolver = new RandomIndexRedirectResolver();

    expect($resolver->resolveCandidates(['index.php' => '']))->toBe(['index.php']);
});

test('resolveCandidates matches a literal "return true;" condition', function (): void {
    $resolver = new RandomIndexRedirectResolver();

    expect($resolver->resolveCandidates(['index.php' => 'return true;']))->toBe(['index.php']);
});

test('resolveCandidates matches "return is_a_guest();" only for a real guest', function (): void {
    $resolver = new RandomIndexRedirectResolver();
    $candidates = ['guest.php' => 'return is_a_guest();'];

    seedCurrentUserStatus(UserStatus::Guest);
    expect($resolver->resolveCandidates($candidates))->toBe(['guest.php']);

    seedCurrentUserStatus(UserStatus::Normal);
    expect($resolver->resolveCandidates($candidates))->toBe([]);
});

test('resolveCandidates never matches an arbitrary PHP condition string', function (): void {
    // The [SEC-15] fix itself: this used to be eval()'d.
    $resolver = new RandomIndexRedirectResolver();

    expect($resolver->resolveCandidates(['evil.php' => 'system("id");']))->toBe([]);
});

test('resolveCandidates skips non-string keys and preserves match order', function (): void {
    $resolver = new RandomIndexRedirectResolver();

    $result = $resolver->resolveCandidates([
        'first.php' => '',
        0 => 'return true;',
        'second.php' => 'return true;',
    ]);

    expect($result)->toBe(['first.php', 'second.php']);
});

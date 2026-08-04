<?php

declare(strict_types=1);

use Piwigo\Auth\AccessControl;
use Piwigo\Section\RandomIndexRedirectResolver;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

// RandomIndexRedirectResolver now calls (an injected) AccessControl::
// isAGuest() (P23 batch 8d, converted to constructor injection in the
// singleton/service-locator elimination campaign's Phase 7), which reads
// Piwigo\Users\CurrentUser (Legacy Coupling Retirement Track A batch A3)
// -- so seeding CurrentUser with the desired status is enough, matching
// this test's old $GLOBALS['user'] stub.
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

/**
 * Only isAGuest() is ever reached, which only reads CurrentUser -- the
 * HtmlRenderingInterface/RedirectServiceInterface collaborators are never
 * touched, so the shared "throws if called" fakes from AccessControlTest.php
 * are reused here rather than duplicating them. No Kernel::boot() happens
 * in this file, so AccessControl::current() (which has no pre-boot
 * fallback) isn't an option -- construct directly instead, against the
 * same shared CurrentUser::current() instance seedCurrentUserStatus()
 * mutates.
 */
function randomIndexRedirectResolverTestAccessControl(): AccessControl
{
    return new AccessControl(
        new \Piwigo\Tests\Unit\Auth\AccessControlTestFakeHtmlRendererDeniesAccess(),
        new \Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled(),
        CurrentUser::current(),
    );
}

beforeEach(function (): void {
    seedCurrentUserStatus(UserStatus::Normal);
});

afterEach(function (): void {
    CurrentUser::current()->reset();
});

test('resolveCandidates matches an empty-string condition', function (): void {
    $resolver = new RandomIndexRedirectResolver();

    expect($resolver->resolveCandidates(randomIndexRedirectResolverTestAccessControl(), ['index.php' => '']))->toBe(['index.php']);
});

test('resolveCandidates matches a literal "return true;" condition', function (): void {
    $resolver = new RandomIndexRedirectResolver();

    expect($resolver->resolveCandidates(randomIndexRedirectResolverTestAccessControl(), ['index.php' => 'return true;']))->toBe(['index.php']);
});

test('resolveCandidates matches "return is_a_guest();" only for a real guest', function (): void {
    $resolver = new RandomIndexRedirectResolver();
    $candidates = ['guest.php' => 'return is_a_guest();'];

    seedCurrentUserStatus(UserStatus::Guest);
    expect($resolver->resolveCandidates(randomIndexRedirectResolverTestAccessControl(), $candidates))->toBe(['guest.php']);

    seedCurrentUserStatus(UserStatus::Normal);
    expect($resolver->resolveCandidates(randomIndexRedirectResolverTestAccessControl(), $candidates))->toBe([]);
});

test('resolveCandidates never matches an arbitrary PHP condition string', function (): void {
    // The [SEC-15] fix itself: this used to be eval()'d.
    $resolver = new RandomIndexRedirectResolver();

    expect($resolver->resolveCandidates(randomIndexRedirectResolverTestAccessControl(), ['evil.php' => 'system("id");']))->toBe([]);
});

test('resolveCandidates skips non-string keys and preserves match order', function (): void {
    $resolver = new RandomIndexRedirectResolver();

    $result = $resolver->resolveCandidates(randomIndexRedirectResolverTestAccessControl(), [
        'first.php' => '',
        0 => 'return true;',
        'second.php' => 'return true;',
    ]);

    expect($result)->toBe(['first.php', 'second.php']);
});

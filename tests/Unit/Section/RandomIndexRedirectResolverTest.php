<?php

declare(strict_types=1);

use Piwigo\Section\RandomIndexRedirectResolver;

// RandomIndexRedirectResolver now calls Piwigo\Auth\AccessControl::isAGuest()
// directly (P23 batch 8d) -- a pure `global $user;` read, zero DB
// dependency, so no stub is needed here; setting the same global this
// resolver's real dependency reads is enough.
beforeEach(function (): void {
    $GLOBALS['user'] = ['status' => 'normal'];
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

    $GLOBALS['user'] = ['status' => 'guest'];
    expect($resolver->resolveCandidates($candidates))->toBe(['guest.php']);

    $GLOBALS['user'] = ['status' => 'normal'];
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

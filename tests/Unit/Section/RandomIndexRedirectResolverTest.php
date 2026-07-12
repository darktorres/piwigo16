<?php

declare(strict_types=1);

use Piwigo\Section\RandomIndexRedirectResolver;

// is_a_guest() is a real functions_user.inc.php function, but whichever
// Integration test file's own function_exists()-guarded global stub for
// it loaded first in this shared process wins for the whole run (see
// CommentServiceTest.php's own stub) -- that stub reads
// $GLOBALS['test_is_guest'], not `global $user['status']`, when called
// with no argument (this resolver's own real call convention).
if (! function_exists('is_a_guest')) {
    function is_a_guest(): bool
    {
        return (bool) ($GLOBALS['test_is_guest'] ?? false);
    }
}

beforeEach(function (): void {
    $GLOBALS['test_is_guest'] = false;
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

    $GLOBALS['test_is_guest'] = true;
    expect($resolver->resolveCandidates($candidates))->toBe(['guest.php']);

    $GLOBALS['test_is_guest'] = false;
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

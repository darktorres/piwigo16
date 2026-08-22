<?php

declare(strict_types=1);

use Piwigo\Users\Projection\RegistrationCandidate;

test('constructs with distinct values for every property', function (): void {
    $candidate = new RegistrationCandidate(
        username: 'alice',
        password: 'plaintext-not-yet-hashed',
        email: 'alice@example.com',
    );

    expect($candidate->username)
        ->toBe('alice')
        ->and($candidate->password)
        ->toBe('plaintext-not-yet-hashed')
        ->and($candidate->email)
        ->toBe('alice@example.com');
});

test('accepts a null email', function (): void {
    $candidate = new RegistrationCandidate(
        username: 'alice',
        password: 'x',
        email: null,
    );

    expect($candidate->email)
        ->toBeNull();
});

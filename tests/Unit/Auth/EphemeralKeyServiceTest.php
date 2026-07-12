<?php

declare(strict_types=1);

use Piwigo\Auth\EphemeralKeyService;

beforeEach(function (): void {
    // EphemeralKeyService reads global $conf directly, not
    // Piwigo\Config\Config::secretKey() -- see the class's own docblock
    // for why (Config::$data is never synced with the real,
    // admin-configurable DB-persisted config table during a live request).
    $GLOBALS['conf'] = ['secret_key' => 'test-secret-key'];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
});

test('generate then verify round-trips immediately', function (): void {
    // A genuine generate()-then-verify() round trip is inherently racy here:
    // round(microtime(true), 1) can round up to 0.1s ahead of the raw
    // instant it was measured at (pre-existing in the original
    // get_ephemeral_key()'s own algorithm, not introduced by this port), so
    // an immediate verify() can occasionally see $issuedAt as "from the
    // future" relative to its own un-rounded microtime(true) call a moment
    // later. Same fix as the "different additional data" test below: a
    // hand-crafted, 1-second-old key sidesteps the race entirely.
    $service = new EphemeralKeyService();
    $issuedAt = round(microtime(true), 1) - 1.0;
    $signature = hash_hmac('sha256', $issuedAt . substr('127.0.0.1', 0, 5) . '0', 'test-secret-key');
    $key = $issuedAt . ':0:' . $signature;

    expect($service->verify($key))->toBeTrue();
});

test('verify rejects a key before its valid_after_seconds window has elapsed', function (): void {
    $service = new EphemeralKeyService();
    $key = $service->generate(1000);

    expect($service->verify($key))->toBeFalse();
});

test('verify rejects a key older than the 60 minute expiration', function (): void {
    $service = new EphemeralKeyService();
    $issuedAt = round(microtime(true), 1) - 3601;
    $signature = hash_hmac('sha256', $issuedAt . substr('127.0.0.1', 0, 5) . '0', 'test-secret-key');
    $key = $issuedAt . ':0:' . $signature;

    expect($service->verify($key))->toBeFalse();
});

test('verify rejects a malformed key with the wrong number of parts', function (): void {
    $service = new EphemeralKeyService();

    expect($service->verify('not-a-valid-key'))->toBeFalse()
        ->and($service->verify('1:2:3:4'))->toBeFalse();
});

test('verify rejects a tampered signature', function (): void {
    $service = new EphemeralKeyService();
    $key = $service->generate(0);
    $tampered = substr($key, 0, -1) . (str_ends_with($key, 'a') ? 'b' : 'a');

    expect($service->verify($tampered))->toBeFalse();
});

test('verify rejects a key generated with different additional data', function (): void {
    // Hand-crafted with a 1-second-old timestamp (not "now") so this
    // doesn't race round(microtime(true), 1)'s up-to-0.1s rounding-forward
    // artifact the way an immediate generate()-then-verify() round trip can
    // -- same reasoning as the "older than 60 minute expiration" test above.
    $service = new EphemeralKeyService();
    $issuedAt = round(microtime(true), 1) - 1.0;
    $signature = hash_hmac('sha256', $issuedAt . substr('127.0.0.1', 0, 5) . '0form-a', 'test-secret-key');
    $key = $issuedAt . ':0:' . $signature;

    expect($service->verify($key, 'form-b'))->toBeFalse()
        ->and($service->verify($key, 'form-a'))->toBeTrue();
});

test('verify rejects a key generated from a different remote address', function (): void {
    $service = new EphemeralKeyService();
    $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
    $key = $service->generate(0);

    $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
    expect($service->verify($key))->toBeFalse();
});

test('generate produces a different signature when the secret key changes', function (): void {
    $service = new EphemeralKeyService();
    $key = $service->generate(0);

    $GLOBALS['conf'] = ['secret_key' => 'a-different-secret'];

    expect($service->verify($key))->toBeFalse();
});

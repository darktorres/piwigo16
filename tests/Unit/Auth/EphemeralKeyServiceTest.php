<?php

declare(strict_types=1);

use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;

beforeEach(function (): void {
    CurrentConfigTestFactory::get()->secretKey = 'test-secret-key';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
});

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
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
    $service = new EphemeralKeyService(CurrentConfigTestFactory::get());
    $issuedAt = round(microtime(true), 1) - 1.0;
    $signature = hash_hmac('sha256', (string) $issuedAt . substr('127.0.0.1', 0, 5) . '0', 'test-secret-key');
    $key = (string) $issuedAt . ':0:' . $signature;

    expect($service->verify($key))->toBeTrue();
});

test('generate then verify round-trips immediately using generate()\'s own real output', function (): void {
    // Every other "round trips" test hand-crafts its own key (to sidestep
    // the 0.1s rounding race the class's own docblock documents) --
    // meaning generate()'s own hash_hmac() input order/concatenation was
    // never actually exercised end-to-end by a genuine positive
    // verification. A negative validAfterSeconds sidesteps the race
    // instead of avoiding generate() entirely: `$time - (float)(-1)` widens
    // the "not before" check by a full second, so a same-instant
    // generate()-then-verify() can never land on the wrong side of it.
    $service = new EphemeralKeyService(CurrentConfigTestFactory::get());

    $key = $service->generate(-1);

    expect($service->verify($key))->toBeTrue();
});

test('generate then verify round-trips with non-empty additionalDataToHash, hashed on both sides', function (): void {
    // Every other round trip uses the default '' additionalDataToHash --
    // a dropped/reordered `. $additionalDataToHash` concat piece in
    // generate()'s own hash_hmac() input is unobservable against an empty
    // string (nothing to drop). A real, non-empty value on both the
    // generate() and verify() sides is the only way to prove it's
    // actually threaded through generate()'s own concat correctly.
    $service = new EphemeralKeyService(CurrentConfigTestFactory::get());

    $key = $service->generate(-1, 'real-form-token');

    expect($service->verify($key, 'real-form-token'))->toBeTrue();
});

test('generate then verify round-trips when REMOTE_ADDR is absent, both sides defaulting the same way', function (): void {
    // fromRemoteAddr() returns null when REMOTE_ADDR is unset -- the `??
    // ''` fallback on both generate()'s and verify()'s own separate call
    // to it must default identically for a real key produced with no
    // REMOTE_ADDR to still verify.
    unset($_SERVER['REMOTE_ADDR']);
    $service = new EphemeralKeyService(CurrentConfigTestFactory::get());

    $key = $service->generate(-1);

    expect($service->verify($key))->toBeTrue();
});

test('verify rejects a key before its valid_after_seconds window has elapsed', function (): void {
    $service = new EphemeralKeyService(CurrentConfigTestFactory::get());
    $key = $service->generate(1000);

    expect($service->verify($key))->toBeFalse();
});

test('verify rejects a key older than the 60 minute expiration', function (): void {
    $service = new EphemeralKeyService(CurrentConfigTestFactory::get());
    $issuedAt = round(microtime(true), 1) - 3601.0;
    $signature = hash_hmac('sha256', (string) $issuedAt . substr('127.0.0.1', 0, 5) . '0', 'test-secret-key');
    $key = (string) $issuedAt . ':0:' . $signature;

    expect($service->verify($key))->toBeFalse();
});

test('verify rejects a malformed key with the wrong number of parts', function (): void {
    $service = new EphemeralKeyService(CurrentConfigTestFactory::get());

    expect($service->verify('not-a-valid-key'))->toBeFalse()
        ->and($service->verify('1:2:3:4'))->toBeFalse();
});

test('verify rejects a key with the right shape but non-numeric issuedAt/validAfterSeconds parts', function (): void {
    $service = new EphemeralKeyService(CurrentConfigTestFactory::get());

    expect($service->verify('not-a-number:0:somesignature'))->toBeFalse()
        ->and($service->verify('123.0:not-a-number:somesignature'))->toBeFalse();
});

test('verify rejects a non-numeric issuedAt even when its leading digits and a matching signature would otherwise pass', function (): void {
    // The existing "non-numeric parts" test above can't distinguish the
    // is_numeric() guard's own `||` (a real gap: an AND would only reject
    // when *both* parts are non-numeric) from an always-eventually-false
    // outcome: (float) on a non-numeric string like "not-a-number" casts
    // to 0.0, which the later time-window checks reject anyway regardless
    // of the guard. A string that's non-numeric only because of trailing
    // garbage -- but float-casts to a real, valid, *recent* timestamp --
    // sidesteps that: paired with a signature computed over this exact
    // raw (unstringified) key material, only the is_numeric() guard
    // itself stands between this key and a false "verified".
    $service = new EphemeralKeyService(CurrentConfigTestFactory::get());
    $issuedAtRaw = (string) round(microtime(true), 1) . 'xyz';
    $validAfterSecondsRaw = '-1';
    $signature = hash_hmac(
        'sha256',
        $issuedAtRaw . substr('127.0.0.1', 0, 5) . $validAfterSecondsRaw,
        'test-secret-key'
    );
    $key = $issuedAtRaw . ':' . $validAfterSecondsRaw . ':' . $signature;

    expect($service->verify($key))->toBeFalse();
});

test('verify rejects a tampered signature', function (): void {
    $service = new EphemeralKeyService(CurrentConfigTestFactory::get());
    $key = $service->generate(0);
    $tampered = substr($key, 0, -1) . (str_ends_with($key, 'a') ? 'b' : 'a');

    expect($service->verify($tampered))->toBeFalse();
});

test('verify rejects a key generated with different additional data', function (): void {
    // Hand-crafted with a 1-second-old timestamp (not "now") so this
    // doesn't race round(microtime(true), 1)'s up-to-0.1s rounding-forward
    // artifact the way an immediate generate()-then-verify() round trip can
    // -- same reasoning as the "older than 60 minute expiration" test above.
    $service = new EphemeralKeyService(CurrentConfigTestFactory::get());
    $issuedAt = round(microtime(true), 1) - 1.0;
    $signature = hash_hmac('sha256', (string) $issuedAt . substr('127.0.0.1', 0, 5) . '0form-a', 'test-secret-key');
    $key = (string) $issuedAt . ':0:' . $signature;

    expect($service->verify($key, 'form-b'))->toBeFalse()
        ->and($service->verify($key, 'form-a'))->toBeTrue();
});

test('verify rejects a key generated from a different remote address', function (): void {
    $service = new EphemeralKeyService(CurrentConfigTestFactory::get());
    $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
    $key = $service->generate(0);

    $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
    expect($service->verify($key))->toBeFalse();
});

test('verify accepts a key when only the first 5 chars of the remote address match', function (): void {
    // Distinguishes substr($remote_addr, 0, 5) from the full address: two
    // genuinely different IPs sharing the same 5-char prefix ('192.1')
    // must still verify, proving only the prefix is hashed on either side.
    $service = new EphemeralKeyService(CurrentConfigTestFactory::get());
    $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
    $key = $service->generate(-1);

    $_SERVER['REMOTE_ADDR'] = '192.100.2.2';
    expect($service->verify($key))->toBeTrue();
});

test('generate produces a different signature when the secret key changes', function (): void {
    $service = new EphemeralKeyService(CurrentConfigTestFactory::get());
    $key = $service->generate(0);

    CurrentConfigTestFactory::get()->secretKey = 'a-different-secret';

    expect($service->verify($key))->toBeFalse();
});

test('generate does not throw when REMOTE_ADDR is not a string', function (): void {
    // Regression: before this call site's REMOTE_ADDR read was centralised
    // through Piwigo\Common\ValueObject\IpAddress::fromRemoteAddr(), it read
    // $_SERVER['REMOTE_ADDR'] with only `?? ''` -- no is_string() guard --
    // so a non-string value would have reached substr() and TypeError'd
    // under this file's own strict_types=1. 8 of the other 11 real
    // REMOTE_ADDR call sites already guarded with is_string() before this
    // pass; this one didn't. Not reachable via a real web SAPI (REMOTE_ADDR
    // is always a string when set), but $_SERVER is untyped, and the fix
    // is real regardless.
    $_SERVER['REMOTE_ADDR'] = ['not', 'a', 'string'];
    $service = new EphemeralKeyService(CurrentConfigTestFactory::get());

    expect(fn () => $service->generate(0))->not->toThrow(TypeError::class);
});

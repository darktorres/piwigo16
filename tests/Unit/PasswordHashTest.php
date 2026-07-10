<?php

declare(strict_types=1);

// Minimal stubs required to load include/functions_user.inc.php standalone:
// - add_event_handler() is called at file top-level (`add_event_handler('try_log_user',
//   'pwg_login');`), so it must exist before the require, not just before a call.
// - PHPWG_ROOT_PATH is read elsewhere in the file (unrelated to password hashing).
// - single_update()/USERS_TABLE back the legacy-phpass-rehash path (only exercised
//   by the last two tests below).
if (!defined('PHPWG_ROOT_PATH')) {
    define('PHPWG_ROOT_PATH', __DIR__ . '/../../');
}

if (!function_exists('add_event_handler')) {
    function add_event_handler(string $event, callable|string $handler): void
    {
    }
}

if (!function_exists('single_update')) {
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    function single_update(string $table, array $data, array $where): void
    {
    }
}

if (!defined('USERS_TABLE')) {
    define('USERS_TABLE', 'piwigo_users');
}

require_once PHPWG_ROOT_PATH . 'include/functions_user.inc.php';

// pwg_password_hash()/pwg_password_verify() (include/functions_user.inc.php) use
// native password_hash()/password_verify() (bcrypt) as of P5. pwg_password_verify()
// also accepts a legacy phpass ($P$/$H$-prefixed) hash -- this codebase's own
// pre-P5 format, still present in installs/fixtures created before this migration
// -- rehashing it to bcrypt on successful verify. The old MD5/$conf['pass_convert']
// fallback (bridging from *upstream* Piwigo's pre-2.5 format) is gone: this fork
// has no in-place upgrade from upstream (docs/adr/0002-clean-fork-no-inplace-upgrade.md).

test('pwg_password_hash produces a bcrypt hash', function (): void {
    $hash = pwg_password_hash('correcthorsebatterystaple');
    expect($hash)->toStartWith('$2y$');
});

test('pwg_password_verify accepts its own hash and rejects a wrong password', function (): void {
    $hash = pwg_password_hash('hunter2');
    expect(pwg_password_verify('hunter2', $hash))->toBeTrue();
    expect(pwg_password_verify('wrong', $hash))->toBeFalse();
});

test('pwg_password_hash reads cost from test mode', function (): void {
    // pwg_test_mode_is_active() reads the X-Piwigo-Env header (include/env.inc.php);
    // PHP_SAPI is 'cli' here, so it only needs the header present, matching
    // tests/bootstrap.php's convention for the rest of the suite.
    $_SERVER['HTTP_X_PIWIGO_ENV'] = 'test';
    $hash = pwg_password_hash('costcheck');
    // bcrypt hash format: $2y$<cost>$<22-char-salt><31-char-hash>
    expect($hash)->toStartWith('$2y$04$');
});

test('pwg_password_verify accepts a legacy phpass hash and rejects a wrong password', function (): void {
    // Precomputed with a fixed salt via the (now-removed) vendored phpass library,
    // cross-checked byte-for-byte against pwg_phpass_verify_legacy()'s extraction.
    $phpassHash = '$P$5testsalt/.6ES3kLR5L.kwZkBtHpD/';
    expect(pwg_password_verify('legacyPhpassPassw0rd!', $phpassHash))->toBeTrue();
    expect(pwg_password_verify('wrongpassword', $phpassHash))->toBeFalse();
});

test('pwg_password_verify accepts a legacy phpass hash without touching the DB', function (): void {
    // No $user_id passed: pwg_password_verify()'s `!isset($user_id)` branch returns
    // true immediately, before reaching the single_update() rehash call.
    $phpassHash = '$P$5testsalt/.6ES3kLR5L.kwZkBtHpD/';
    expect(pwg_password_verify('legacyPhpassPassw0rd!', $phpassHash))->toBeTrue();
});

test('pwg_password_verify rehashes a legacy phpass hash when a user_id is given', function (): void {
    global $conf;
    $conf ??= [];
    /** @var array<string, mixed> $conf */
    $conf['external_authentification'] = false;

    $phpassHash = '$P$5testsalt/.6ES3kLR5L.kwZkBtHpD/';
    expect(pwg_password_verify('legacyPhpassPassw0rd!', $phpassHash, 42))->toBeTrue();
});

test('pwg_phpass_verify_legacy rejects malformed hashes', function (): void {
    expect(pwg_phpass_verify_legacy('anything', 'not-a-phpass-hash'))->toBeFalse();
    expect(pwg_phpass_verify_legacy('anything', '$2y$04$tooshortforbcryptbutwrongprefix'))->toBeFalse();
});

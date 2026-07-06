<?php

declare(strict_types=1);

// Minimal stubs required to load include/functions_user.inc.php standalone:
// - add_event_handler() is called at file top-level (`add_event_handler('try_log_user',
//   'pwg_login');`), so it must exist before the require, not just before a call.
// - PHPWG_ROOT_PATH is read by pwg_password_hash()/pwg_password_verify() to
//   require_once include/passwordhash.class.php.
// - single_update()/USERS_TABLE back the legacy-md5-rehash path (only exercised
//   by the last test below).
if (!defined('PHPWG_ROOT_PATH')) {
    define('PHPWG_ROOT_PATH', __DIR__ . '/../../');
}

if (!function_exists('add_event_handler')) {
    function add_event_handler(string $event, callable|string $handler): void
    {
    }
}

if (!function_exists('single_update')) {
    function single_update(string $table, array $data, array $where): void
    {
    }
}

if (!defined('USERS_TABLE')) {
    define('USERS_TABLE', 'piwigo_users');
}

require_once PHPWG_ROOT_PATH . 'include/functions_user.inc.php';

// pwg_password_hash()/pwg_password_verify() (include/functions_user.inc.php:1153,1181)
// always use the phpass portable-hash library (include/passwordhash.class.php):
// `new PasswordHash(13, true)` forces portable mode regardless of CRYPT_BLOWFISH
// availability, producing a `$P$`-prefixed hash. There is no bcrypt path in this
// codebase yet — that migration is docs/PLAN-REPLAY.md's SEC-41, done in P28.

test('pwg_password_hash produces a phpass portable hash', function (): void {
    $hash = pwg_password_hash('correcthorsebatterystaple');
    expect($hash)->toStartWith('$P$');
});

test('pwg_password_verify accepts its own hash and rejects a wrong password', function (): void {
    $hash = pwg_password_hash('hunter2');
    expect(pwg_password_verify('hunter2', $hash))->toBeTrue();
    expect(pwg_password_verify('wrong', $hash))->toBeFalse();
});

test('pwg_password_verify accepts a legacy md5 hash without touching the DB', function (): void {
    // No $user_id passed: pwg_password_verify()'s `!isset($user_id)` branch returns
    // true immediately, before reaching the single_update() rehash call.
    $md5Hash = md5('ancientpassword');
    expect(pwg_password_verify('ancientpassword', $md5Hash))->toBeTrue();
    expect(pwg_password_verify('wrongpassword', $md5Hash))->toBeFalse();
});

test('pwg_password_verify rehashes a legacy md5 hash when a user_id is given', function (): void {
    global $conf;
    $conf ??= [];
    $conf['external_authentification'] = false;

    $md5Hash = md5('ancientpassword');
    expect(pwg_password_verify('ancientpassword', $md5Hash, 42))->toBeTrue();
});

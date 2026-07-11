<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Session\PwgSession;
use Piwigo\Session\SessionService;

// In PHP 8.4+ calling session_set_save_handler with
// two parameters is deprecated. To correct this,
// we pass a SessionHandlerInterface instance.
// https://github.com/Piwigo/Piwigo/issues/2296

/** @var array<string, mixed> $conf */
if (isset($conf['session_save_handler'])
  and ($conf['session_save_handler'] === 'db')
  and defined('PHPWG_INSTALLED')) {
    session_set_save_handler(new PwgSession());

    if (function_exists('ini_set')) {
        $session_use_cookies = $conf['session_use_cookies'];
        $session_use_cookies = is_scalar($session_use_cookies) ? $session_use_cookies : null;
        ini_set('session.use_cookies', $session_use_cookies);

        $session_use_only_cookies = $conf['session_use_only_cookies'];
        $session_use_only_cookies = is_scalar($session_use_only_cookies) ? $session_use_only_cookies : null;
        ini_set('session.use_only_cookies', $session_use_only_cookies);

        $session_use_trans_sid = $conf['session_use_trans_sid'];
        $session_use_trans_sid = is_scalar($session_use_trans_sid) ? $session_use_trans_sid : 0;
        ini_set('session.use_trans_sid', intval($session_use_trans_sid));
        ini_set('session.cookie_httponly', 1);
    }

    $session_name = $conf['session_name'];
    $session_name = is_string($session_name) ? $session_name : null;
    session_name($session_name);
    session_set_cookie_params(0, cookie_path());
    register_shutdown_function(session_write_close(...));
}

/**
 * Generates a pseudo random string.
 * Characters used are a-z A-Z and numerical values.
 *
 * @param int $size
 */
function generate_key($size): string
{
    return SessionService::get()
        ->generateKey($size);
}

/**
 * Called by PHP session manager, always return true.
 *
 * @param string $path
 * @param string $name
 * @return true
 */
function pwg_session_open($path, $name): bool
{
    return SessionService::get()->sessionOpen();
}

/**
 * Called by PHP session manager, always return true.
 *
 * @return true
 */
function pwg_session_close(): bool
{
    return SessionService::get()->sessionClose();
}

/**
 * Returns a hash from current user IP
 */
function get_remote_addr_session_hash(): string
{
    return SessionService::get()
        ->getRemoteAddrSessionHash();
}

/**
 * Called by PHP session manager, retrieves data stored in the sessions table.
 *
 * @param string $session_id
 * @return string
 */
function pwg_session_read($session_id)
{
    return SessionService::get()
        ->sessionRead($session_id);
}

/**
 * Called by PHP session manager, writes data in the sessions table.
 *
 * @param string $session_id
 * @return true
 */
function pwg_session_write($session_id, ?string $data): bool
{
    return SessionService::get()
        ->sessionWrite($session_id, $data ?? '');
}

/**
 * Called by PHP session manager, deletes data in the sessions table.
 *
 * @param string $session_id
 * @return true
 */
function pwg_session_destroy($session_id): bool
{
    return SessionService::get()
        ->sessionDestroy($session_id);
}

/**
 * Called by PHP session manager, garbage collector for expired sessions.
 *
 * @return int number of expired sessions deleted
 */
function pwg_session_gc(): int
{
    return SessionService::get()
        ->sessionGc();
}

/**
 * Persistently stores a variable for the current session.
 *
 * @param string $var
 * @param mixed $value
 */
function pwg_set_session_var($var, $value): bool
{
    return SessionService::get()
        ->setSessionVar($var, $value);
}

/**
 * Retrieves the value of a persistent variable for the current session.
 *
 * @param string $var
 * @param mixed $default
 * @return mixed
 */
function pwg_get_session_var($var, $default = null)
{
    return SessionService::get()
        ->getSessionVar($var, $default);
}

/**
 * Deletes a persistent variable for the current session.
 *
 * @param string $var
 */
function pwg_unset_session_var($var): bool
{
    return SessionService::get()
        ->unsetSessionVar($var);
}

/**
 * delete all sessions for a given user (certainly deleted)
 *
 * @since 2.8
 * @param int $user_id
 */
function delete_user_sessions($user_id): void
{
    SessionService::get()
        ->deleteUserSessions($user_id);
}

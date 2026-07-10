<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// In PHP 8.4+ calling session_set_save_handler with
// two parameters is deprecated. To correct this,
// we pass a SessionHandlerInterface instance.
// https://github.com/Piwigo/Piwigo/issues/2296
include_once PHPWG_ROOT_PATH . '/include/pwgsession.class.php';

/** @var array<string, mixed> $conf */
if (isset($conf['session_save_handler'])
  and ($conf['session_save_handler'] == 'db')
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
    if ($size < 1) {
        throw new \InvalidArgumentException('generate_key(): $size must be at least 1');
    }
    $bytes = random_bytes($size + 10);

    return substr(
        str_replace(
            ['+', '/'],
            '',
            base64_encode($bytes)
        ),
        0,
        $size
    );
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
    return true;
}

/**
 * Called by PHP session manager, always return true.
 *
 * @return true
 */
function pwg_session_close(): bool
{
    return true;
}

/**
 * Returns a hash from current user IP
 */
function get_remote_addr_session_hash(): string
{
    /** @var array<string, mixed> $conf */
    global $conf;

    if (! (bool) $conf['session_use_ip_address']) {
        return '';
    }

    $remote_addr = $_SERVER['REMOTE_ADDR'];
    $remote_addr = is_string($remote_addr) ? $remote_addr : '';

    if (! str_contains($remote_addr, ':')) {// ipv4
        return vsprintf(
            '%02X%02X',
            explode('.', $remote_addr)
        );
    }
    return ''; // ipv6 not yet
}

/**
 * Called by PHP session manager, retrieves data stored in the sessions table.
 *
 * @param string $session_id
 * @return string
 */
function pwg_session_read($session_id)
{
    $query = '
SELECT data
  FROM ' . SESSIONS_TABLE . '
  WHERE id = \'' . get_remote_addr_session_hash() . $session_id . '\'
;';
    $result = pwg_query($query);
    if ((bool) ($row = pwg_db_fetch_assoc($result))) {
        return $row['data'] ?? '';
    }
    return '';
}

/**
 * Called by PHP session manager, writes data in the sessions table.
 *
 * @param string $session_id
 * @return true
 */
function pwg_session_write($session_id, ?string $data): bool
{
    // when the request is authenticated via api_key (PWG_API_KEY_REQUEST),
    // you do not want the session to be written to the database (no user session persistence)
    // this avoids polluting the session table with stateless API accesses
    if (defined('PWG_API_KEY_REQUEST')) {
        return true;
    }
    $query = '
REPLACE INTO ' . SESSIONS_TABLE . '
  (id,data,expiration)
  VALUES(\'' . get_remote_addr_session_hash() . $session_id . '\',\'' . pwg_db_real_escape_string($data) . '\',now())
;';
    pwg_query($query);
    return true;
}

/**
 * Called by PHP session manager, deletes data in the sessions table.
 *
 * @param string $session_id
 * @return true
 */
function pwg_session_destroy($session_id): bool
{
    $query = '
DELETE
  FROM ' . SESSIONS_TABLE . '
  WHERE id = \'' . get_remote_addr_session_hash() . $session_id . '\'
;';
    pwg_query($query);
    return true;
}

/**
 * Called by PHP session manager, garbage collector for expired sessions.
 *
 * @return int number of expired sessions deleted
 */
function pwg_session_gc(): int
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $session_length = $conf['session_length'];
    $session_length = is_scalar($session_length) ? $session_length : 0;

    $query = '
DELETE
  FROM ' . SESSIONS_TABLE . '
  WHERE ' . pwg_db_date_to_ts('NOW()') . ' - ' . pwg_db_date_to_ts('expiration') . ' > '
    . $session_length . '
;';
    pwg_query($query);
    return (int) pwg_db_changes();
}

/**
 * Persistently stores a variable for the current session.
 *
 * @param string $var
 * @param mixed $value
 */
function pwg_set_session_var($var, $value): bool
{
    if (! isset($_SESSION)) {
        return false;
    }
    $_SESSION['pwg_' . $var] = $value;
    return true;
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
    return $_SESSION['pwg_' . $var] ?? $default;
}

/**
 * Deletes a persistent variable for the current session.
 *
 * @param string $var
 */
function pwg_unset_session_var($var): bool
{
    if (! isset($_SESSION)) {
        return false;
    }
    unset($_SESSION['pwg_' . $var]);
    return true;
}

/**
 * delete all sessions for a given user (certainly deleted)
 *
 * @since 2.8
 * @param int $user_id
 */
function delete_user_sessions($user_id): void
{
    $query = '
DELETE
  FROM ' . SESSIONS_TABLE . '
  WHERE data LIKE \'%pwg_uid|i:' . $user_id . ';%\'
;';
    pwg_query($query);
}

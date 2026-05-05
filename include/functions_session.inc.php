<?php

declare(strict_types=1);

use Piwigo\Session\PwgSession;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\session
 */

// In PHP 8.4+ calling session_set_save_handler with
// two parameters is deprecated. To correct this,
// we pass a SessionHandlerInterface instance.
// https://github.com/Piwigo/Piwigo/issues/2296
// PwgSession is autoloaded by Composer.

// Config class may not be autoloaded yet during install.php bootstrap.
if (class_exists(\Piwigo\Config\Config::class, false)
  and \Piwigo\Config\Config::has('session_save_handler')
  and (\Piwigo\Config\Config::sessionSaveHandler() == 'db')
  and \Piwigo\Core\InstallSentinel::isInstalled()) {
    session_set_save_handler(new PwgSession());

    if (function_exists('ini_set')) {
        ini_set('session.use_cookies', \Piwigo\Config\Config::sessionUseCookies());
        ini_set('session.use_only_cookies', \Piwigo\Config\Config::sessionUseOnlyCookies());
        ini_set('session.use_trans_sid', intval(\Piwigo\Config\Config::sessionUseTransSid()));
        ini_set('session.cookie_httponly', 1);
    }

    session_name(\Piwigo\Config\Config::sessionName());
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => cookie_path(),
        'samesite' => 'Strict',
        'httponly' => true,
        'secure' => isset($_SERVER['HTTPS']) && is_string($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) === 'on',
    ]);
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
    $bytes = random_bytes(max(1, (int) $size + 10));

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
    if (!\Piwigo\Config\Config::sessionUseIpAddress()) {
        return '';
    }

    $remoteAddr = is_scalar($_SERVER['REMOTE_ADDR'] ?? null) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    if (!str_contains($remoteAddr, ':')) {//ipv4
        return vsprintf(
            '%02X%02X',
            explode('.', $remoteAddr)
        );
    }
    return ''; //ipv6 not yet
}

/**
 * Called by PHP session manager, retrieves data stored in the sessions table.
 *
 * @return string
 */
function pwg_session_read(string $session_id)
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Session\SessionRepository::class)
        ->read(get_remote_addr_session_hash() . $session_id);
}


/**
 * Called by PHP session manager, writes data in the sessions table.
 *
 * @param string $data
 * @return true
 */
function pwg_session_write(string $session_id, $data): bool
{
    // when the request is authenticated via api_key (PWG_API_KEY_REQUEST),
    // you do not want the session to be written to the database (no user session persistence)
    // this avoids polluting the session table with stateless API accesses
    if (defined('PWG_API_KEY_REQUEST')) {
        return true;
    }
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Session\SessionRepository::class)
        ->write(get_remote_addr_session_hash() . $session_id, (string) $data);
    return true;
}

/**
 * Called by PHP session manager, deletes data in the sessions table.
 *
 * @return true
 */
function pwg_session_destroy(string $session_id): bool
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Session\SessionRepository::class)
        ->destroy(get_remote_addr_session_hash().$session_id);
    return true;
}

/**
 * Called by PHP session manager, garbage collector for expired sessions.
 *
 * @return true
 */
function pwg_session_gc(): bool
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Session\SessionRepository::class)
        ->gc(\Piwigo\Config\Config::sessionLength());
    return true;
}

/**
 * Persistently stores a variable for the current session.
 *
 * @param mixed $value
 */
function pwg_set_session_var(string $var, $value): bool
{
    if (!isset($_SESSION)) {
        return false;
    }
    $_SESSION['pwg_'.$var] = $value;
    return true;
}

/**
 * Retrieves the value of a persistent variable for the current session.
 *
 * @param mixed $default
 * @return mixed
 */
function pwg_get_session_var(string $var, $default = null)
{
    return $_SESSION['pwg_'.$var] ?? $default;
}

/**
 * Deletes a persistent variable for the current session.
 */
function pwg_unset_session_var(string $var): bool
{
    if (!isset($_SESSION)) {
        return false;
    }
    unset($_SESSION['pwg_'.$var]);
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
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Session\SessionRepository::class)
        ->deleteByUserId((int) $user_id);
}

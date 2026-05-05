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
 * Called before Kernel::boot() in common.inc.php — must have its own
 * implementation. SessionService::generateKey() is the canonical copy.
 */
function generate_key(int $size): string
{
    $bytes = random_bytes(max(1, $size + 10));
    return substr(str_replace(['+', '/'], '', base64_encode($bytes)), 0, $size);
}

function pwg_session_open(string $path, string $name): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Session\SessionService::class)->sessionOpen($path, $name);
}

function pwg_session_close(): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Session\SessionService::class)->sessionClose();
}

function get_remote_addr_session_hash(): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Session\SessionService::class)->getRemoteAddrSessionHash();
}

function pwg_session_read(string $session_id): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Session\SessionService::class)->sessionRead($session_id);
}

function pwg_session_write(string $session_id, string $data): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Session\SessionService::class)->sessionWrite($session_id, $data);
}

function pwg_session_destroy(string $session_id): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Session\SessionService::class)->sessionDestroy($session_id);
}

function pwg_session_gc(): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Session\SessionService::class)->sessionGc();
}

function pwg_set_session_var(string $var, mixed $value): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Session\SessionService::class)->setSessionVar($var, $value);
}

function pwg_get_session_var(string $var, mixed $default = null): mixed
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Session\SessionService::class)->getSessionVar($var, $default);
}

function pwg_unset_session_var(string $var): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Session\SessionService::class)->unsetSessionVar($var);
}

function delete_user_sessions(int $user_id): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Session\SessionService::class)->deleteUserSessions($user_id);
}

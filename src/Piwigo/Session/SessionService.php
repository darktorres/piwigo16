<?php

declare(strict_types=1);

namespace Piwigo\Session;

use Piwigo\Config\Config;
use Piwigo\Db\DbConnection;

final class SessionService
{
    private static ?self $instance = null;

    public function __construct(
        private readonly SessionRepository $repo,
    ) {}

    /**
     * Self-managed singleton, same bridging pattern as Piwigo\Lang\
     * Translator::get() -- procedural call sites (functions_session.inc.php's
     * free-function delegates, PwgSession's own default) share one
     * SessionRepository/Connection per request instead of opening a fresh
     * DB connection on every session-var access.
     */
    public static function get(): self
    {
        return self::$instance ??= new self(new SessionRepository(DbConnection::build()));
    }

    public static function set(self $service): void
    {
        self::$instance = $service;
    }

    /**
     * Test-only -- restricted to tests/ by an arch test.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Generates a pseudo random string.
     * Characters used are a-z A-Z and numerical values.
     */
    public function generateKey(int $size): string
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('generateKey(): $size must be at least 1');
        }
        $bytes = random_bytes($size + 10);

        return substr(
            str_replace(['+', '/'], '', base64_encode($bytes)),
            0,
            $size,
        );
    }

    /**
     * Called by PHP session manager, always return true.
     */
    public function sessionOpen(): true
    {
        return true;
    }

    /**
     * Called by PHP session manager, always return true.
     */
    public function sessionClose(): true
    {
        return true;
    }

    /**
     * Returns a hash from the current user's IP address.
     */
    public function getRemoteAddrSessionHash(): string
    {
        if (! Config::sessionUseIpAddress()) {
            return '';
        }

        $remoteAddr = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR']
            : '';

        if (! str_contains($remoteAddr, ':')) { // ipv4
            return vsprintf('%02X%02X', explode('.', $remoteAddr));
        }

        return ''; // ipv6 not yet
    }

    /**
     * Called by PHP session manager, retrieves data stored in the sessions table.
     */
    public function sessionRead(string $sessionId): string
    {
        return $this->repo->read($this->getRemoteAddrSessionHash() . $sessionId);
    }

    /**
     * Called by PHP session manager, writes data in the sessions table.
     */
    public function sessionWrite(string $sessionId, string $data): true
    {
        // when the request is authenticated via api_key (PWG_API_KEY_REQUEST),
        // you do not want the session to be written to the database (no user
        // session persistence) -- this avoids polluting the session table
        // with stateless API accesses
        if (defined('PWG_API_KEY_REQUEST')) {
            return true;
        }
        $this->repo->write($this->getRemoteAddrSessionHash() . $sessionId, $data);

        return true;
    }

    /**
     * Called by PHP session manager, deletes data in the sessions table.
     */
    public function sessionDestroy(string $sessionId): true
    {
        $this->repo->destroy($this->getRemoteAddrSessionHash() . $sessionId);

        return true;
    }

    /**
     * Called by PHP session manager, garbage collector for expired
     * sessions. Returns the number of expired sessions deleted.
     */
    public function sessionGc(): int
    {
        return $this->repo->gc(Config::sessionLength());
    }

    /**
     * Persistently stores a variable for the current session.
     */
    public function setSessionVar(string $var, mixed $value): bool
    {
        if (! isset($_SESSION)) {
            return false;
        }
        $_SESSION['pwg_' . $var] = $value;

        return true;
    }

    /**
     * Retrieves the value of a persistent variable for the current session.
     */
    public function getSessionVar(string $var, mixed $default = null): mixed
    {
        return $_SESSION['pwg_' . $var] ?? $default;
    }

    /**
     * Deletes a persistent variable for the current session.
     */
    public function unsetSessionVar(string $var): bool
    {
        if (! isset($_SESSION)) {
            return false;
        }
        unset($_SESSION['pwg_' . $var]);

        return true;
    }

    /**
     * Delete all sessions for a given user (certainly deleted).
     */
    public function deleteUserSessions(int $userId): void
    {
        $this->repo->deleteByUserId($userId);
    }
}

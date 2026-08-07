<?php

declare(strict_types=1);

namespace Piwigo\Session;

use InvalidArgumentException;
use LogicException;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\Kernel;

final class SessionService
{
    public function __construct(
        private readonly SessionRepository $repo,
        private readonly CurrentConfig $currentConfig,
    ) {}

    /**
     * Container resolve, not a constructor property -- used only inside
     * sessionWrite()'s own ApiKeyRequestFlag::isActive() check below. A
     * required constructor param here would ripple across this class's
     * many real construction sites for the sake of one internal caller.
     * Falls back to a fresh, unmemoized `false`-active instance when
     * Kernel::boot() hasn't run.
     */
    private function apiKeyRequestFlag(): ApiKeyRequestFlag
    {
        if (Kernel::isBooted()) {
            $apiKeyRequestFlag = Kernel::container()->get(ApiKeyRequestFlag::class);
            if (! $apiKeyRequestFlag instanceof ApiKeyRequestFlag) {
                throw new LogicException('Container returned an unexpected type for ' . ApiKeyRequestFlag::class);
            }

            return $apiKeyRequestFlag;
        }

        return new ApiKeyRequestFlag();
    }

    /**
     * Generates a pseudo random string.
     * Characters used are a-z A-Z and numerical values.
     */
    public function generateKey(int $size): string
    {
        if ($size < 1) {
            throw new InvalidArgumentException('generateKey(): $size must be at least 1');
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
        return self::remoteAddrHash($this->currentConfig->sessionUseIpAddress());
    }

    /**
     * Pure computation behind getRemoteAddrSessionHash(), with the
     * "bind sessions to the client IP?" policy bit passed in explicitly so
     * SessionUserResolver (the i.php fast path) can share the exact
     * composite-key hash logic while sourcing the policy bit from the
     * legacy global $conf instead of CurrentConfig:: -- on that
     * fast-bootstrap path CurrentConfig::$data is populated from
     * ConfigLoader defaults/env only, never from local config overrides,
     * so CurrentConfig::sessionUseIpAddress() would not be authoritative
     * there.
     */
    public static function remoteAddrHash(bool $useIpAddress): string
    {
        if (! $useIpAddress) {
            return '';
        }

        $remoteAddr = IpAddress::fromRemoteAddr()->value ?? '';

        // Real bug, found via a new Integration test that legitimately
        // initializes a session with no HTTP request behind it (no real
        // REMOTE_ADDR -- e.g. a CLI-driven install/bootstrap flow):
        // explode('.', '') yields a single-element array, and vsprintf()
        // requires exactly 2 for '%02X%02X', throwing a ValueError instead
        // of the "no IP available" empty-string fallback this method
        // already uses for ipv6/no-REMOTE_ADDR below. Same pre-existing
        // gap in the original include/functions_session.inc.php (a bare
        // (string) cast of an unset $_SERVER['REMOTE_ADDR'] hits the
        // identical crash there too) -- never reachable in a real Apache
        // request (the web server always populates REMOTE_ADDR), only in
        // a session created outside one.
        $octets = explode('.', $remoteAddr);
        if (! str_contains($remoteAddr, ':') && count($octets) === 4) { // ipv4
            // Deliberately only the first 2 octets -- see
            // SessionServiceTest.php's own docblock for why this narrower
            // hash is the original, long-standing behavior, not something
            // to widen here.
            return vsprintf('%02X%02X', array_slice($octets, 0, 2));
        }

        return ''; // ipv6, or no real IP available, not yet
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
        // when the request is authenticated via api_key (ApiKeyRequestFlag),
        // you do not want the session to be written to the database (no user
        // session persistence) -- this avoids polluting the session table
        // with stateless API accesses
        if ($this->apiKeyRequestFlag()->isActive()) {
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
        return $this->repo->gc($this->currentConfig->sessionLength());
    }

    /**
     * Persistently stores a variable for the current session. $value is
     * genuinely arbitrary by design -- the app writes any serializable PHP
     * value here deliberately, same generic-KV-bag rationale as
     * Piwigo\Core\ProcessCache.
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

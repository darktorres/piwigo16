<?php

declare(strict_types=1);

namespace Piwigo\Session;

/**
 * [SEC-33] Resolves a raw session cookie value to its logged-in user id,
 * or null if the session doesn't exist / was never logged in.
 *
 * P23 batch 8f (i.php): relocated from i.php's resolve_session_user_id()
 * free function, unchanged logic. Goes through the real SessionRepository
 * (DBAL) for the composite-key lookup -- AuthService writes sessions keyed
 * by `getRemoteAddrSessionHash() . $sessionId`, not the raw cookie value
 * alone, so hand-rolling this against the legacy mysqli layer would
 * silently never find a real session. The hash itself is
 * SessionService::remoteAddrHash(), the single shared implementation; the
 * "bind sessions to the client IP?" policy bit is a parameter here because
 * the i.php fast-bootstrap path must source it from the legacy global
 * $conf (CurrentConfig::$data isn't authoritatively populated from local config
 * overrides on that path, only from ConfigLoader's own defaults/env).
 */
final readonly class SessionUserResolver
{
    public function __construct(
        private SessionRepository $repo,
    ) {}

    public function resolveLoggedUserId(string $cookieValue, bool $useIpAddressInKey): ?int
    {
        $raw = $this->repo->read(SessionService::remoteAddrHash($useIpAddressInKey) . $cookieValue);
        if ($raw === '') {
            return null;
        }

        // PHP's native session serialization format is `key|type:value;...` --
        // pwg_uid is always written as a plain top-level int
        // (Piwigo\Auth\AuthService::login(): `$_SESSION['pwg_uid'] = (int)
        // $userId;`), so `i:N;` is unambiguous regardless of whatever other
        // keys/values are also present in the raw session data.
        if (preg_match('/pwg_uid\|i:(\d+);/', $raw, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}

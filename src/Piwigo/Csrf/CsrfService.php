<?php

declare(strict_types=1);

namespace Piwigo\Csrf;

/**
 * CSRF token issuance and verification. Tokens are an HMAC of the current
 * session id keyed by the secret-key config, so they're stable for the
 * lifetime of a session and invalidated on logout.
 *
 * Reads `global $conf['secret_key']` directly, NOT
 * Piwigo\Config\Config::secretKey() -- confirmed empirically (recomputed a
 * real live pwg_token for a real session and it matched the empty-secret
 * hash, not the real DB-persisted secret_key) that Config::secretKey() was
 * silently inert on a live request: secret_key is inserted into the
 * piwigo_config DB table at install time (install/upgrade_1.6.2.php),
 * loaded into $conf by the legacy load_conf_from_db(), with no sync back
 * into Config::$data. This means every CSRF token issued before this fix
 * was signed with an empty string, not a real secret -- forgeable by
 * anyone who could observe or guess a session id. Fixed as a P18 follow-up
 * (found while building EphemeralKeyService, which copied this same
 * Config::secretKey() pattern before shipping and was caught first).
 *
 * check() returns bool rather than acting on failure itself (unlike the
 * reference implementation's later, Util-retirement-era CsrfService, which
 * constructor-injects HtmlService and calls accessDenied()/badRequest()
 * directly) -- Csrf lands in L2bExtendedDomain, Html lands in
 * L3Presentation, and L2b may not depend upward on L3 (the very "domain →
 * no Html/Template concretes" ratchet this phase establishes). The
 * free-function delegate (check_pwg_token(), staying procedural in
 * functions.inc.php) keeps deciding what to do on failure, exactly as
 * before.
 */
final class CsrfService
{
    /**
     * get pwg_token used to prevent csrf attacks.
     */
    public function getToken(): string
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $session_id = session_id();
        if ($session_id === false) {
            throw new \Exception('CsrfService::getToken(): no active session');
        }

        $secret_key = $conf['secret_key'] ?? '';
        $secret_key = is_scalar($secret_key) ? (string) $secret_key : '';

        return hash_hmac('md5', $session_id, $secret_key);
    }

    /**
     * Check token coming from form posted or get params to prevent csrf
     * attacks.
     *
     * Returns null when no token was submitted at all (caller must reject
     * with its own "missing token" response -- same "empty means the
     * action doesn't require a token yet the request omitted the required
     * field" distinction the original check_pwg_token() draws between its
     * two branches), true when the submitted token matches, false when it
     * doesn't.
     */
    public function check(): ?bool
    {
        $submitted = $_REQUEST['pwg_token'] ?? null;
        if (! is_string($submitted) || $submitted === '') {
            return null;
        }

        return $this->getToken() === $submitted;
    }
}

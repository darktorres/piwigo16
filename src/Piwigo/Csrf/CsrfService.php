<?php

declare(strict_types=1);

namespace Piwigo\Csrf;

/**
 * CSRF token issuance and verification. Tokens are an HMAC of the current
 * session id keyed by the secret-key config, so they're stable for the
 * lifetime of a session and invalidated on logout.
 *
 * Reads Piwigo\Config\Config::secretKey() directly -- safe since Legacy
 * Coupling Retirement Track A batch A4's ConfigDb fix (see
 * EphemeralKeyService's own docblock for the mechanism). Historically
 * (P18) CurrentConfig::secretKey() was silently inert on a live request: secret_key
 * is inserted into the piwigo_config DB table at install time
 * (install/upgrade_1.6.2.php), loaded into the legacy $conf global by
 * load_conf_from_db(), with no sync back into CurrentConfig::$data at the time.
 * Every CSRF token issued before that P18 fix was signed with an empty
 * string, not a real secret -- forgeable by anyone who could observe or
 * guess a session id. The P18 fix routed this class through the raw $conf
 * global as an interim workaround (found while building EphemeralKeyService,
 * which copied the same CurrentConfig::secretKey() pattern before shipping and was
 * caught first); A4 removed the need for that workaround by fixing
 * CurrentConfig::$data's own DB-sync timing instead.
 *
 * [SEC-11/SEC-12] getToken()/check() use sha256 + hash_equals(), matching
 * EphemeralKeyService/AuthService::calculateAutoLoginKey() (SEC-27/SEC-28) --
 * this class was the original trigger for finding that weak-hash-plus-
 * non-constant-time-comparison pattern (see the secret_key note above), but
 * only the secret_key-sourcing bug got fixed at the time; the hash algorithm
 * (md5) and comparison (===, both timing-unsafe) were missed until a
 * 2026-07-13 audit caught the same pattern still live here.
 *
 * check() returns bool rather than acting on failure itself (unlike the
 * reference implementation's later, Util-retirement-era CsrfService, which
 * constructor-injects HtmlService and calls accessDenied()/badRequest()
 * directly) -- Csrf lands in L2bExtendedDomain, Html lands in
 * L3Presentation, and L2b may not depend upward on L3 (the very "domain →
 * no Html/Template concretes" ratchet this phase establishes).
 *
 * P23 batch 8f-4: the old free-function delegate (check_pwg_token(),
 * include/functions.inc.php) that used to decide what to do on failure is
 * deleted; checkOrFail() below is its locally-complete replacement -- the
 * failure handling moves onto this class, with the renderer passed
 * per-method as Piwigo\Core\HtmlRenderingInterface (L1, legal downward
 * dep; same per-method-injection style as CategoryService's
 * ActivityLoggerInterface params). Every former check_pwg_token() call
 * site is L3/L4 and constructs the concrete HtmlService inline.
 * checkOrFail() also takes Piwigo\Core\RedirectServiceInterface per-method
 * (Legacy Coupling Retirement Phase 4b) -- accessDenied()/badRequest()
 * both need one to reach the former redirect_http()/redirect_html() free
 * functions now that they're retired.
 */
final class CsrfService
{
    /**
     * get pwg_token used to prevent csrf attacks.
     */
    public function getToken(): string
    {
        $session_id = session_id();
        if ($session_id === false) {
            throw new \Exception('CsrfService::getToken(): no active session');
        }

        $secret_key = \Piwigo\Config\CurrentConfig::secretKey();

        return hash_hmac('sha256', $session_id, $secret_key);
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

        return hash_equals($this->getToken(), $submitted);
    }

    /**
     * Check the token coming from form-posted or GET params and act on
     * failure, exactly as the deleted check_pwg_token() free function did:
     * no token submitted at all -> 400 "missing token"; a submitted token
     * that doesn't match -> access denied. Both renderer methods are
     * `never`-returning, so this only returns when the token is valid.
     */
    public function checkOrFail(\Piwigo\Core\HtmlRenderingInterface $renderer, \Piwigo\Core\RedirectServiceInterface $redirectService): void
    {
        $result = $this->check();
        if ($result === null) {
            $renderer->badRequest($redirectService, 'missing token');
        } elseif ($result === false) {
            $renderer->accessDenied($redirectService);
        }
    }
}

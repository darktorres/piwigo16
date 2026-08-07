<?php

declare(strict_types=1);

namespace Piwigo\Csrf;

use Exception;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Csrf\Request\CsrfTokenRequest;

/**
 * CSRF token issuance and verification. Tokens are an HMAC of the current
 * session id keyed by the secret-key config, so they're stable for the
 * lifetime of a session and invalidated on logout.
 *
 * getToken()/check() use sha256 + hash_equals() (constant-time comparison),
 * matching EphemeralKeyService/AuthService::calculateAutoLoginKey().
 *
 * check() returns bool rather than acting on failure itself: Csrf lands in
 * L2bExtendedDomain, Html lands in L3Presentation, and L2b may not depend
 * upward on L3.
 *
 * checkOrFail() takes both Piwigo\Core\HtmlRenderingInterface and
 * Piwigo\Core\RedirectServiceInterface as per-method parameters (L1, legal
 * downward dependencies) rather than constructor-injecting them, the same
 * per-method-injection style as CategoryService's ActivityLoggerInterface
 * params.
 */
final class CsrfService
{
    public function __construct(
        private readonly CurrentConfig $currentConfig,
    ) {}

    /**
     * get pwg_token used to prevent csrf attacks.
     */
    public function getToken(): string
    {
        $session_id = session_id();
        if ($session_id === false) {
            throw new Exception('CsrfService::getToken(): no active session');
        }

        $secret_key = $this->currentConfig->secretKey();

        return hash_hmac('sha256', $session_id, $secret_key);
    }

    /**
     * Check token coming from form posted or get params to prevent csrf
     * attacks.
     *
     * Returns null when no token was submitted at all (caller must reject
     * with its own "missing token" response), true when the submitted
     * token matches, false when it doesn't.
     */
    public function check(): ?bool
    {
        $submitted = CsrfTokenRequest::fromGlobals()->submittedToken;
        if ($submitted === null) {
            return null;
        }

        return hash_equals($this->getToken(), $submitted);
    }

    /**
     * Checks the token coming from form-posted or GET params and acts on
     * failure: no token submitted at all -> 400 "missing token"; a
     * submitted token that doesn't match -> access denied. Both renderer
     * methods are `never`-returning, so this only returns when the token
     * is valid.
     */
    public function checkOrFail(HtmlRenderingInterface $renderer, RedirectServiceInterface $redirectService): void
    {
        $result = $this->check();
        if ($result === null) {
            $renderer->badRequest($redirectService, 'missing token');
        } elseif ($result === false) {
            $renderer->accessDenied($redirectService);
        }
    }
}

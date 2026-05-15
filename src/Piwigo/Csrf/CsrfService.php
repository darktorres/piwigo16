<?php

declare(strict_types=1);

namespace Piwigo\Csrf;

use Piwigo\Config\Config;
use Piwigo\Html\HtmlService;

/**
 * CSRF token issuance and verification. Tokens are an HMAC of the current
 * session id keyed by the secret-key config (`$conf['secret_key']`), so
 * they're stable for the lifetime of a session and invalidated on logout.
 *
 * Tokens are emitted in templates as a hidden `pwg_token` form field and
 * verified by `check()` on state-changing request handlers.
 */
final readonly class CsrfService
{
    public function __construct(
        private HtmlService $htmlService,
    ) {
    }

    public function getToken(): string
    {
        return hash_hmac('md5', (string) session_id(), Config::secretKey());
    }

    public function check(): void
    {
        if (isset($_REQUEST['pwg_token']) && $_REQUEST['pwg_token'] !== '') {
            if ($this->getToken() !== $_REQUEST['pwg_token']) {
                $this->htmlService->accessDenied();
            }
        } else {
            $this->htmlService->badRequest('missing token');
        }
    }
}

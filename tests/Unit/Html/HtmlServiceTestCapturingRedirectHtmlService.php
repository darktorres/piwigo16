<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Html;

use Piwigo\Core\RedirectServiceInterface;

/**
 * Captures redirectHtml()'s 4 arguments then throws, same convention as
 * HtmlServiceTestCapturingRedirectHttpService above.
 */
final class HtmlServiceTestCapturingRedirectHtmlService implements RedirectServiceInterface
{
    public ?string $capturedUrl = null;

    public ?string $capturedMsg = null;

    public ?int $capturedRefreshTime = null;

    public ?int $capturedStatus = null;

    #[\Override]
    public function redirectHttp(string $url, int $status = 302): never
    {
        throw new \LogicException('not used by pageForbidden()');
    }

    #[\Override]
    public function redirectHtml(string $url, string $msg = '', int $refresh_time = 0, int $status = 200): never
    {
        $this->capturedUrl = $url;
        $this->capturedMsg = $msg;
        $this->capturedRefreshTime = $refresh_time;
        $this->capturedStatus = $status;
        throw new \RuntimeException('HTML_SERVICE_TEST_REDIRECT_HTML_MARKER');
    }

    #[\Override]
    public function redirect(string $url, string $msg = '', int $refresh_time = 0): never
    {
        throw new \LogicException('not used by pageForbidden()');
    }
}

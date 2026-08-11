<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Html;

use LogicException;
use Override;
use Piwigo\Core\RedirectServiceInterface;
use RuntimeException;

/**
 * Captures redirectHttp()'s url argument then throws, matching
 * CategoryServiceFakeHtmlRendererDeniesAccess's own established
 * "marker exception" convention for a `never`-typed interface method.
 */
final class HtmlServiceTestCapturingRedirectHttpService implements RedirectServiceInterface
{
    public ?string $capturedUrl = null;

    #[Override]
    public function redirectHttp(string $url, int $status = 302): never
    {
        $this->capturedUrl = $url;
        throw new RuntimeException('HTML_SERVICE_TEST_REDIRECT_HTTP_MARKER');
    }

    #[Override]
    public function redirectHtml(string $url, string $msg = '', int $refresh_time = 0, int $status = 200): never
    {
        throw new LogicException('not used by accessDenied()');
    }

    #[Override]
    public function redirect(string $url, string $msg = '', int $refresh_time = 0): never
    {
        throw new LogicException('not used by accessDenied()');
    }
}

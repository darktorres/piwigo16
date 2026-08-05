<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Url;

use Override;
use Piwigo\Core\RedirectServiceInterface;
use RuntimeException;

final class UrlServiceTestRedirectService implements RedirectServiceInterface
{
    #[Override]
    public function redirectHttp(string $url, int $status = 302): never
    {
        throw new RuntimeException('unexpected redirectHttp() call');
    }

    #[Override]
    public function redirectHtml(string $url, string $msg = '', int $refresh_time = 0, int $status = 200): never
    {
        throw new RuntimeException('unexpected redirectHtml() call');
    }

    #[Override]
    public function redirect(string $url, string $msg = '', int $refresh_time = 0): never
    {
        throw new RuntimeException('unexpected redirect() call');
    }
}

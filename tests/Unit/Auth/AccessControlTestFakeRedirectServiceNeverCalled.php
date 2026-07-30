<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Auth;

use Piwigo\Core\RedirectServiceInterface;

final class AccessControlTestFakeRedirectServiceNeverCalled implements RedirectServiceInterface
{
    #[\Override]
    public function redirectHttp(string $url, int $status = 302): never
    {
        throw new \LogicException('not used by checkStatus()');
    }

    #[\Override]
    public function redirectHtml(string $url, string $msg = '', int $refresh_time = 0, int $status = 200): never
    {
        throw new \LogicException('not used by checkStatus()');
    }

    #[\Override]
    public function redirect(string $url, string $msg = '', int $refresh_time = 0): never
    {
        throw new \LogicException('not used by checkStatus()');
    }
}

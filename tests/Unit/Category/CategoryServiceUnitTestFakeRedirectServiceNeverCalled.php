<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Category;

use LogicException;
use Override;
use Piwigo\Core\RedirectServiceInterface;

/**
 * checkRestrictions() only ever reaches accessDenied() -- this
 * RedirectServiceInterface fake is never actually invoked (the
 * HtmlRenderingInterface fake above throws before touching it), so every
 * method just documents that.
 */
final class CategoryServiceUnitTestFakeRedirectServiceNeverCalled implements RedirectServiceInterface
{
    #[Override]
    public function redirectHttp(string $url, int $status = 302): never
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function redirectHtml(string $url, string $msg = '', int $refresh_time = 0, int $status = 200): never
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function redirect(string $url, string $msg = '', int $refresh_time = 0): never
    {
        throw new LogicException('not used by checkRestrictions()');
    }
}

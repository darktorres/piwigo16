<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces one `admin/<slug>.php` include with a DI-resolved service,
 * dispatched by Piwigo\Bootstrap\AdminDispatcher. Reads input from the
 * injected PSR-7 request (SEC-19), not $_GET/$_POST directly.
 *
 * P43-F (in progress): handle() is migrating from directly assigning
 * AdminContentPageContext itself to returning its rendered content instead
 * -- AdminDispatcher::dispatch() is the one place that turns a returned
 * AdminPageResult into the ambient TemplatePageContext the "admin.latte"
 * shell reads. The return type stays nullable while this migration is
 * incomplete: a page not yet converted still assigns
 * AdminContentPageContext itself and returns null, which
 * AdminDispatcher::dispatch() treats as "nothing further to do" --  once
 * every implementer returns a real AdminPageResult, drop the `?`.
 */
interface AdminSubControllerInterface
{
    public function handle(ServerRequestInterface $request): ?AdminPageResult;
}

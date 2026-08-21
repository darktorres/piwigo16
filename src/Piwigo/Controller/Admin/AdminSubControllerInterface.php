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
 * handle() returns its rendered content instead of directly assigning
 * AdminContentPageContext itself -- AdminDispatcher::dispatch() is the
 * one place that turns the returned AdminPageResult into the ambient
 * TemplatePageContext the "admin.latte" shell reads.
 */
interface AdminSubControllerInterface
{
    public function handle(ServerRequestInterface $request): AdminPageResult;
}

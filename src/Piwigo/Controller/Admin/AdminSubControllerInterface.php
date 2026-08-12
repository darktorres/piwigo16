<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces one `admin/<slug>.php` include with a DI-resolved service,
 * dispatched by Piwigo\Bootstrap\AdminDispatcher. Reads input from the
 * injected PSR-7 request (SEC-19), not $_GET/$_POST directly. Still
 * mutates the legacy `global $template`/`$page` bridges to render its
 * output -- same "keep page/template glue on the legacy bridge, inject
 * only real domain services" split other renderers already use
 * (e.g. Piwigo\Menu\MenubarRenderer). Template rendering itself is the
 * part still deferred, not yet wired through DI.
 */
interface AdminSubControllerInterface
{
    public function handle(ServerRequestInterface $request): void;
}

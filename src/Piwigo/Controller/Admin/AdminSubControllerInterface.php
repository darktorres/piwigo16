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
 * only real domain services" split every P17-20 renderer already uses
 * (e.g. Piwigo\Menu\MenubarRenderer); the $GLOBALS bridges were already
 * retired in P24 (Track A) -- Smarty rendering itself is the part still
 * deferred, to P32, not this phase's job.
 */
interface AdminSubControllerInterface
{
    public function handle(ServerRequestInterface $request): void;
}

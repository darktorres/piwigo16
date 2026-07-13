<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Http\ControllerInterface;
use Piwigo\Ws\PwgServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces ws.php -- the web-service dispatcher entry point. Wraps the
 * existing PwgServer registration/dispatch/response-formatting mechanism
 * as-is; the ~100 web-service methods across include/ws_functions/*.php
 * (and their addMethod() registrations, moved verbatim to
 * include/ws_default_methods.inc.php -- see that file's own docblock for
 * why it couldn't just become a private method here) are explicitly NOT
 * decomposed this phase, matching this phase's own scoped plan.
 *
 * IN_WS deliberately stays defined in ws.php's own root file, not here:
 * include/user.inc.php (part of common.inc.php's own bootstrap chain)
 * checks defined('IN_WS'), so it must be set before common.inc.php runs
 * -- well before CommonBootstrap::run()/RequestPipeline::handle() reach
 * this controller. PHP constants are process-wide regardless of scope
 * once defined, so no `global`-style re-declaration is needed here; a
 * duplicate define() here would also violate the arch test forbidding
 * define() calls anywhere under src/Piwigo/.
 *
 * $service->run() calls header()/echo directly and does not return a
 * PSR-7 Response (the whole PwgServer protocol -- REST/XML-RPC/JSON/PHP
 * encoders -- predates this phase and writes its own response), so this
 * controller's return type is satisfied the same way check_status()'s
 * `never` exit paths are elsewhere in this phase: $service->run() is the
 * last statement, and PHP's own request lifecycle ends the script right
 * after -- no LegacyRenderCapture/ResponseFactory involved, since there's
 * no Smarty/Template rendering here at all.
 */
final class WsController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        check_status(AccessLevel::Free);

        if (! (bool) $conf['allow_web_services']) {
            page_forbidden('Web services are disabled');
        }

        include_once PHPWG_ROOT_PATH . 'include/ws_default_methods.inc.php';
        include_once PHPWG_ROOT_PATH . 'include/ws_init.inc.php';

        // ws_init.inc.php assigns $service with a bare (non-global)
        // `$service = new PwgServer();` -- include() shares the calling
        // scope for bare variable reads/writes, so the plain local read
        // below correctly sees it. A `global $service;` declaration here
        // would instead rebind to the empty $GLOBALS['service'] slot
        // (nothing ever writes there), since ws_init.inc.php's own
        // assignment was never `global`-qualified -- found live via a
        // real "Call to a member function run() on null" 500.
        /** @var PwgServer $service */
        $service->run();

        // Unreachable: PwgServer::run() always ends the response itself
        // (header()+echo, one of the 4 protocol encoders) and this
        // process-lifetime script exits right after -- there is no real
        // PSR-7 Response to construct. Kept only so this method's return
        // type matches ControllerInterface; ControllerInvokerMiddleware
        // never actually receives it.
        exit();
    }
}

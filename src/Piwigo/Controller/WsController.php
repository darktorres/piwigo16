<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Ws\WsInitializer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces ws.php -- the web-service dispatcher entry point. Wraps the
 * existing PwgServer registration/dispatch/response-formatting mechanism
 * as-is; the ~100 web-service methods that used to live across
 * include/ws_functions/*.php are now real Piwigo\Ws\* class methods
 * (P23 batch 8e), and their addMethod() registration catalog is
 * Piwigo\Ws\WsDefaultMethods::register() (P23 batch 8e-8) -- autoloaded
 * like every other class, no include_once needed here anymore.
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
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
    ) {}

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Free);

        if (! \Piwigo\Config\Config::allowWebServices()) {
            new HtmlService()
                ->pageForbidden($this->redirectService, 'Web services are disabled');
        }

        // Formerly `include_once PHPWG_ROOT_PATH . 'include/ws_init.inc.php';`
        // (deleted, P23 sub-batch 8f-5). WsInitializer::init() carries that
        // seam's include_once semantics: at most one PwgServer/default-event
        // registration per process, and the same shared instance returned
        // here even when UserBootstrap's api_key/uploadAsync branches
        // already initialized it earlier in this request (the include_once
        // era left this method's *local* $service unset in that case).
        $service = WsInitializer::init();
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

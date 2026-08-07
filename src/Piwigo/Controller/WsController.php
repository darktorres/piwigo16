<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
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
 * as-is. The ~100 web-service methods are real Piwigo\Ws\* class methods,
 * autoloaded like every other class; their addMethod() registration
 * catalog is Piwigo\Ws\WsDefaultMethods::register().
 *
 * IN_WS deliberately stays defined in ws.php's own root file, not here:
 * include/user.inc.php (part of common.inc.php's own bootstrap chain)
 * checks defined('IN_WS'), so it must be set before common.inc.php runs
 * -- well before RequestPipeline::handle() reaches this controller. PHP
 * constants are process-wide regardless of scope
 * once defined, so no `global`-style re-declaration is needed here; a
 * duplicate define() here would also violate the arch test forbidding
 * define() calls anywhere under src/Piwigo/.
 *
 * $service->run() calls header()/echo directly and does not return a
 * PSR-7 Response -- the PwgServer protocol (REST/XML-RPC/JSON/PHP
 * encoders) writes its own response. This controller's return type is
 * satisfied the same way check_status()'s `never` exit paths are:
 * $service->run() is the last statement, and PHP's own request lifecycle
 * ends the script right after -- no LegacyRenderCapture/ResponseFactory
 * involved, since there's no Smarty/Template rendering here at all.
 */
final class WsController implements ControllerInterface
{
    public function __construct(
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly HtmlService $htmlService,
        private readonly CurrentConfig $currentConfig,
        private readonly WsInitializer $wsInitializer,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->accessControl->checkStatus(AccessLevel::Free);

        if (! $this->currentConfig->allowWebServices()) {
            $this->htmlService
                ->pageForbidden($this->redirectService, 'Web services are disabled');
        }

        // WsInitializer::init() guarantees at most one PwgServer/default-event
        // registration per process, returning the same shared instance even
        // when UserBootstrap's api_key/uploadAsync branches already
        // initialized it earlier in this request.
        $service = $this->wsInitializer->init();
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

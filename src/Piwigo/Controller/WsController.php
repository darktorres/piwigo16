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
 * existing Server registration/dispatch/response-formatting mechanism
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
 */
final readonly class WsController implements ControllerInterface
{
    public function __construct(
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private HtmlService $htmlService,
        private CurrentConfig $currentConfig,
        private WsInitializer $wsInitializer,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->accessControl->checkStatus(AccessLevel::Free);

        if (! $this->currentConfig->allowWebServices) {
            $this->htmlService
                ->pageForbidden($this->redirectService, 'Web services are disabled');
        }

        // WsInitializer::init() guarantees at most one Server/default-event
        // registration per process, returning the same shared instance even
        // when UserBootstrap's api_key/uploadAsync branches already
        // initialized it earlier in this request.
        return $this->wsInitializer->init()
            ->run();
    }
}

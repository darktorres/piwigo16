<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Html\HtmlService;
use Piwigo\Http\RequestContext;
use Piwigo\Http\RequestContextRegistry;
use Piwigo\Http\ResponseFactory;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\PermissionService;
use Piwigo\Ws\OpenApi\SpecBuilder;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\PwgServerRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles all requests routed to /ws (web services API).
 *
 * Corresponds to the former ws.php entry-point.  The route is /ws{rest} so
 * $args['rest'] carries any path suffix (e.g. '/openapi.json', '/docs').
 *
 * This controller sends its own output (JSON, HTML) via echo/header() and
 * returns an empty 200 response for the emitter — the legacy PwgServer
 * dispatch model is preserved during the Wave-A transition.
 */
final readonly class WsController implements ControllerInterface
{
    public function __construct(
        private HtmlService $htmlService,
        private UrlGenerator $urlGenerator,
        private PermissionService $permissionService,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        RequestContextRegistry::set(RequestContext::Ws);

        $this->permissionService->checkStatus(AccessLevel::Free);

        if (!Config::allowWebServices()) {
            $this->htmlService->pageForbidden('Web services are disabled');
        }

        PwgServer::boot();

        $rest         = $args['rest'] ?? '';
        $openApiParam = is_string($_GET['_openapi'] ?? null) ? $_GET['_openapi'] : '';

        // OpenAPI spec: /ws/openapi.json  or  ?_openapi=json
        if ($rest === '/openapi.json' || $openApiParam === 'json') {
            $server = PwgServerRegistry::current();
            $server->populateMethods();

            $serverUrl = $this->urlGenerator->ws();

            $spec = new SpecBuilder($server, $serverUrl)->build();

            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                header('Access-Control-Allow-Origin: *');
                header('Cache-Control: no-store');
            }
            echo $spec->toJson();
            exit;
        }

        // Swagger UI: /ws/docs  or  ?_openapi=ui
        if ($rest === '/docs' || $openApiParam === 'ui') {
            $specUrl = $this->urlGenerator->ws() . '/openapi.json';

            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
            }
            $specUrlAttr = htmlspecialchars($specUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Piwigo API Docs</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
</head>
<body>
  <div id="swagger-ui" data-spec-url="' . $specUrlAttr . '"></div>
  <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script src="dev/swagger-init.js" defer></script>
</body>
</html>';
            exit;
        }

        // Normal WS dispatch — PwgServer sends its own JSON/XML response
        PwgServerRegistry::current()->run();

        return ResponseFactory::create(200);
    }
}

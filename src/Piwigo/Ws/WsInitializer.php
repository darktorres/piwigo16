<?php

declare(strict_types=1);

namespace Piwigo\Ws;

use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Ws\Event\WsAddMethods;
use Piwigo\Ws\Event\WsInvokeAllowed;
use Piwigo\Ws\History\Event\GetHistory;
use Piwigo\Ws\History\GetHistoryListener;
use Piwigo\Ws\Protocol\JsonEncoder;
use Piwigo\Ws\Protocol\RestEncoder;
use Piwigo\Ws\Protocol\RestRequestHandler;
use Piwigo\Ws\Protocol\SerialPhpEncoder;
use Piwigo\Ws\Protocol\XmlRpcEncoder;
use Piwigo\Ws\Request\WsFormatRequest;
use Psr\Container\ContainerInterface;

/**
 * Builds the per-request Server and registers the WS default event
 * handlers.
 *
 * `$server` memoizes the built Server on this instance; PHP-DI shares
 * this WsInitializer instance across every resolving caller (WsController
 * on every WS request; UserBootstrap's api_key-failure and
 * pwg.images.uploadAsync branches), so Server construction, the
 * default-event registrations and the (non-idempotent -- see
 * `UrlServiceInterface::setMakeFullUrl()`'s own stack-push docblock)
 * `setMakeFullUrl()` call run at most once per Server lifetime, and
 * every caller receives the same Server instance from init()'s return
 * value.
 *
 * Request-specific state (`requestFormat`/`responseFormat`/the handler
 * and encoder they select) is deliberately NOT part of that
 * once-only guard (P25 Stage 2 item 8) -- it used to be, which meant a
 * memoized Server captured whichever request's `?format=` happened to be
 * live the first time `init()` ran and kept it forever. Under today's
 * one-process-per-request execution that guard's scope and the process's
 * own lifetime coincide, so the bug was never observable; it would
 * surface the moment this class's container-shared instance survives
 * across requests (e.g. a future FrankenPHP worker-mode conversion --
 * see project memory `project_frankenphp_workers_planned.md`). Applying
 * `WsFormatRequest::fromGlobals()`'s result on every call keeps
 * `responseFormat`/`responseEncoder` correct for whichever request is
 * actually live, regardless of how many prior requests reused this same
 * `WsInitializer`/`Server` pair.
 *
 * `setMakeFullUrl()`'s own push is never popped for a WS request (no
 * `unsetMakeFullUrl()` call anywhere in the WS lifecycle) -- deliberately
 * sticky for the rest of a single request/process today, but a related,
 * NOT fixed here, worker-mode leak of its own: popping it correctly
 * needs a real end-of-request hook covering both `WsController`'s normal
 * return AND `UserBootstrap`'s `ResponseReadyException`-throwing failure
 * paths (which never reach `WsController` at all), not a change scoped
 * to this one class.
 *
 * Server is passed as a real parameter to
 * ImageFilterCriteriaBuilder::stdImageSqlFilterCriteria()/UploadService::addUploadedFile()
 * rather than read from a global.
 */
final class WsInitializer
{
    private ?Server $server = null;

    public function __construct(
        private readonly EventDispatcher $eventDispatcher,
        private readonly WsDefaultMethods $wsDefaultMethods,
        private readonly GetHistoryListener $getHistoryListener,
        private readonly UrlServiceInterface $urlService,
        private readonly WsInvokeAuthorizer $wsInvokeAuthorizer,
        private readonly AccessControl $accessControl,
        private readonly ApiKeyRequestFlag $apiKeyRequestFlag,
        private readonly CurrentConfig $currentConfig,
        private readonly ContainerInterface $container,
    ) {}

    public function init(): Server
    {
        if (! $this->server instanceof Server) {
            $this->eventDispatcher->addTypedHandler(WsAddMethods::class, $this->wsDefaultMethods->register(...));
            $this->eventDispatcher->addTypedHandler(WsInvokeAllowed::class, $this->wsInvokeAuthorizer->isInvokeAllowed(...));
            // Registers unconditionally, once per WS request -- must run before
            // History\SearchHandler dispatches its GetHistory event, since
            // GetHistoryListener needs to already be listening.
            $this->eventDispatcher->addTypedHandler(GetHistory::class, $this->getHistoryListener);

            $this->urlService->setMakeFullUrl();

            $this->server = new Server($this->eventDispatcher, $this->accessControl, $this->apiKeyRequestFlag, $this->currentConfig, $this->container);
        }

        $service = $this->server;

        $requestFormat = 'rest';
        $responseFormat = WsFormatRequest::fromGlobals()->responseFormat;

        // $requestFormat is hardcoded to 'rest' above; the format-selection
        // switch stays for parity with $responseFormat's structure and in case
        // more request formats are ever added.
        $handler = null;
        switch ($requestFormat) {
            case 'rest':
                $handler = new RestRequestHandler();
                break;
        }
        $service->setHandler($requestFormat, $handler);

        $encoder = null;
        switch ($responseFormat) {
            case 'rest':
                $encoder = new RestEncoder();
                break;
            case 'php':
                $encoder = new SerialPhpEncoder();
                break;
            case 'json':
                $encoder = new JsonEncoder();
                break;
            case 'xmlrpc':
                $encoder = new XmlRpcEncoder();
                break;
        }
        $service->setEncoder($responseFormat, $encoder);

        return $service;
    }
}

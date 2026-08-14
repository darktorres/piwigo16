<?php

declare(strict_types=1);

namespace Piwigo\Ws;

use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Ws\GetHistory;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Ws\Event\WsAddMethods;
use Piwigo\Ws\Event\WsInvokeAllowed;
use Piwigo\Ws\History\GetHistoryListener;
use Piwigo\Ws\Protocol\JsonEncoder;
use Piwigo\Ws\Protocol\RestEncoder;
use Piwigo\Ws\Protocol\RestRequestHandler;
use Piwigo\Ws\Protocol\SerialPhpEncoder;
use Piwigo\Ws\Protocol\XmlRpcEncoder;
use Piwigo\Ws\Request\WsFormatRequest;

/**
 * Builds the per-request Server and registers the WS default event
 * handlers.
 *
 * `$server` memoizes the built Server on this instance; PHP-DI shares
 * this WsInitializer instance across every resolving caller (WsController
 * on every WS request; UserBootstrap's api_key-failure and
 * pwg.images.uploadAsync branches), so Server construction and the
 * default-event registrations run at most once per process and every
 * caller receives the same Server instance from init()'s return value.
 *
 * Server is passed as a real parameter to
 * WsHelper::stdImageSqlFilterCriteria()/UploadService::addUploadedFile()
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
        private readonly WsHelper $wsHelper,
        private readonly AccessControl $accessControl,
        private readonly ApiKeyRequestFlag $apiKeyRequestFlag,
        private readonly CurrentConfig $currentConfig,
    ) {}

    public function init(): Server
    {
        if ($this->server instanceof Server) {
            return $this->server;
        }

        $this->eventDispatcher->addTypedHandler(WsAddMethods::class, $this->wsDefaultMethods->register(...));
        $this->eventDispatcher->addTypedHandler(WsInvokeAllowed::class, $this->wsHelper->isInvokeAllowed(...));
        // Registers unconditionally, once per WS request -- must run before
        // History\SearchHandler dispatches its GetHistory event, since
        // GetHistoryListener needs to already be listening.
        $this->eventDispatcher->addTypedHandler(GetHistory::class, $this->getHistoryListener);

        $requestFormat = 'rest';
        $responseFormat = WsFormatRequest::fromGlobals()->responseFormat;

        $service = new Server($this->eventDispatcher, $this->accessControl, $this->apiKeyRequestFlag, $this->currentConfig);

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

        $this->urlService->setMakeFullUrl();

        $this->server = $service;

        return $service;
    }
}

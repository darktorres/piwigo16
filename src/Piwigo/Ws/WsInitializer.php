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
use Piwigo\Ws\Protocol\PwgJsonEncoder;
use Piwigo\Ws\Protocol\PwgRestEncoder;
use Piwigo\Ws\Protocol\PwgRestRequestHandler;
use Piwigo\Ws\Protocol\PwgSerialPhpEncoder;
use Piwigo\Ws\Protocol\PwgXmlRpcEncoder;
use Piwigo\Ws\Request\WsFormatRequest;

/**
 * Builds the per-request PwgServer and registers the WS default event
 * handlers.
 *
 * `$server` memoizes the built PwgServer on this instance; PHP-DI shares
 * this WsInitializer instance across every resolving caller (WsController
 * on every WS request; UserBootstrap's api_key-failure and
 * pwg.images.uploadAsync branches), so PwgServer construction and the
 * default-event registrations run at most once per process and every
 * caller receives the same PwgServer instance from init()'s return value.
 *
 * PwgServer is passed as a real parameter to
 * WsHelper::stdImageSqlFilterCriteria()/UploadService::addUploadedFile()
 * rather than read from a global.
 */
final class WsInitializer
{
    private ?PwgServer $server = null;

    public function __construct(
        private readonly EventDispatcher $eventDispatcher,
        private readonly WsDefaultMethods $wsDefaultMethods,
        private readonly PwgCore $pwgCore,
        private readonly UrlServiceInterface $urlService,
        private readonly WsHelper $wsHelper,
        private readonly AccessControl $accessControl,
        private readonly ApiKeyRequestFlag $apiKeyRequestFlag,
        private readonly CurrentConfig $currentConfig,
    ) {}

    public function init(): PwgServer
    {
        if ($this->server instanceof PwgServer) {
            return $this->server;
        }

        $this->eventDispatcher->addTypedHandler(WsAddMethods::class, $this->wsDefaultMethods->register(...));
        $this->eventDispatcher->addTypedHandler(WsInvokeAllowed::class, $this->wsHelper->isInvokeAllowed(...));
        // Registers unconditionally, once per WS request -- must run before
        // PwgCore::historySearch() dispatches its GetHistory event, since
        // historyGet() needs to already be listening.
        $this->eventDispatcher->addTypedHandler(GetHistory::class, $this->pwgCore->historyGet(...));

        $requestFormat = 'rest';
        $responseFormat = WsFormatRequest::fromGlobals()->responseFormat;

        $service = new PwgServer($this->eventDispatcher, $this->accessControl, $this->apiKeyRequestFlag, $this->currentConfig);

        // $requestFormat is hardcoded to 'rest' above; the format-selection
        // switch stays for parity with $responseFormat's structure and in case
        // more request formats are ever added.
        $handler = null;
        switch ($requestFormat) {
            case 'rest':
                $handler = new PwgRestRequestHandler();
                break;
        }
        $service->setHandler($requestFormat, $handler);

        $encoder = null;
        switch ($responseFormat) {
            case 'rest':
                $encoder = new PwgRestEncoder();
                break;
            case 'php':
                $encoder = new PwgSerialPhpEncoder();
                break;
            case 'json':
                $encoder = new PwgJsonEncoder();
                break;
            case 'xmlrpc':
                $encoder = new PwgXmlRpcEncoder();
                break;
        }
        $service->setEncoder($responseFormat, $encoder);

        $this->urlService->setMakeFullUrl();

        $this->server = $service;

        return $service;
    }
}

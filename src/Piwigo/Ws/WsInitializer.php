<?php

declare(strict_types=1);

namespace Piwigo\Ws;

use Piwigo\Html\HtmlService;
use Piwigo\Url\UrlService;
use Piwigo\Ws\Protocol\PwgJsonEncoder;
use Piwigo\Ws\Protocol\PwgRestEncoder;
use Piwigo\Ws\Protocol\PwgRestRequestHandler;
use Piwigo\Ws\Protocol\PwgSerialPhpEncoder;
use Piwigo\Ws\Protocol\PwgXmlRpcEncoder;

/**
 * Builds the per-request PwgServer and registers the WS default event
 * handlers — the body of the deleted include/ws_init.inc.php (P23
 * sub-batch 8f-5), ported verbatim.
 *
 * init() is once-per-process (the static $server cache), faithfully
 * modeling the deleted seam's include_once semantics: the event
 * registrations and PwgServer construction ran at most once per request no
 * matter how many call sites reached the file (WsController on every WS
 * request; UserBootstrap's api_key-failure and pwg.images.uploadAsync
 * branches earlier in the same request). Note this closes a latent scope
 * bug of the include_once era: when UserBootstrap's uploadAsync branch
 * had already included the seam, WsController's later method-scoped
 * include_once was a no-op that left its *local* $service unset — every
 * caller now receives the same instance from init()'s return value
 * instead. Legacy Coupling Retirement Phase 8, 8m: the original top-level
 * `$service = new PwgServer();` global-scope contract this used to also
 * preserve via a `$GLOBALS['service'] = $service;` publish is gone --
 * WsHelper::stdImageSqlFilter()/UploadService::addUploadedFile() now take
 * PwgServer as a real parameter instead of reading the global, and
 * WsController/UserBootstrap were already reading init()'s return value
 * directly, never the global.
 */
final class WsInitializer
{
    private static ?PwgServer $server = null;

    public static function init(): PwgServer
    {
        if (self::$server instanceof PwgServer) {
            return self::$server;
        }

        // P23 batch 8e-8: WsDefaultMethods::register() replaces the old bare
        // ws_addDefaultMethods() free function (formerly include/
        // ws_default_methods.inc.php, include_once'd by WsController before
        // this ran); first-class-callable, same pattern as the 2
        // registrations below it.
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('ws_add_methods', WsDefaultMethods::register(...));
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('ws_invoke_allowed', WsHelper::isInvokeAllowed(...));
        // P23 batch 8e-4: relocated from include/ws_functions/pwg.php's own
        // top-level add_event_handler('get_history', 'get_history') call --
        // that file's lazy include_once (right before PwgServer::invoke()
        // calls a pwg.php-registered callback) guaranteed this ran before
        // PwgCore::historySearch()'s own trigger_change('get_history', ...)
        // call could fire; now that pwg.php's functions are class methods
        // (autoloaded, no include-time side effect to hook this to), it
        // registers here instead, unconditionally, once per WS request.
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('get_history', PwgCore::historyGet(...));

        $requestFormat = 'rest';
        $responseFormat = null;

        if (isset($_GET['format'])) {
            // cast defensively: PwgServer::setEncoder() requires a string, but
            // $_GET['format'] could be an array for a malformed ?format[]=x request
            $responseFormat = is_scalar($_GET['format']) ? (string) $_GET['format'] : '';
        }

        if (! isset($responseFormat)) {
            $responseFormat = $requestFormat;
        }

        $service = new PwgServer();

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

        // $responseFormat can never be null here: it's either $_GET['format']
        // or, per the isset() fallback above, $requestFormat ('rest').
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

        new UrlService(new HtmlService())
            ->setMakeFullUrl();

        self::$server = $service;

        return $service;
    }
}

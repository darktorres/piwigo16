<?php

declare(strict_types=1);

namespace Piwigo\Event\Ws;

use Piwigo\Ws\PwgServer;

/**
 * Dispatched once during [[PwgServer::populateMethods]] — after the two
 * reflection methods are pre-registered and before the natural sort.
 *
 * Listeners (core's [[\Piwigo\Ws\WsMethodRegistrar]] plus any plugin
 * subscriber) call `$event->server->register(new MethodDefinition(...))`
 * to add methods. The carried server is mutable (registration appends
 * to its internal `$_methods` array); the event itself stays readonly.
 *
 * Replaces the legacy `ws_add_methods` notify catalogued under
 * [[WsAddMethods]] — WsAddMethods still fires alongside this event so
 * legacy plugins listening on the `ws_add_methods` name (via
 * [[\Piwigo\Event\LegacyEventBridge]]) keep working until B17 retires
 * the bridge.
 */
final readonly class WsMethodsRegistering
{
    public function __construct(
        public PwgServer $server,
    ) {
    }
}

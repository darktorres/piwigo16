<?php

declare(strict_types=1);

namespace Piwigo\Ws\Event;

use Piwigo\Ws\Server;

/**
 * Typed event for the legacy `ws_add_methods` notification. Registered by
 * src/Piwigo/Ws/WsInitializer.php (WsDefaultMethods::register()) --
 * notify-shape, always readonly. Mutation happens through $server's own
 * register() calls, not by reassigning this property -- same
 * accumulator-object pattern as
 * Piwigo\Admin\Integrity\Event\ListCheckIntegrity.
 *
 * Namespace override (Piwigo\Ws\Event\, not the default Piwigo\Event\Ws\):
 * $server carries a real Piwigo\Ws\Server instance, a first-party
 * domain type deptrac's L0Data layer (Piwigo\Event\*) may not depend on.
 */
final readonly class WsAddMethods
{
    public function __construct(
        public Server $server,
    ) {}
}

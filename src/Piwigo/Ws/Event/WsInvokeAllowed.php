<?php

declare(strict_types=1);

namespace Piwigo\Ws\Event;

use Piwigo\Ws\PwgError;

/**
 * Typed event for the legacy `ws_invoke_allowed` filter. Registered by
 * src/Piwigo/Ws/WsInitializer.php (WsHelper::isInvokeAllowed()) at the
 * default priority -- mutable, per the currently-registered-handler rule.
 *
 * Namespace override (Piwigo\Ws\Event\, not the default Piwigo\Event\Ws\):
 * $value carries a real Piwigo\Ws\PwgError instance when access is
 * denied, a first-party domain type deptrac's L0Data layer
 * (Piwigo\Event\*) may not depend on.
 */
final class WsInvokeAllowed
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public bool|PwgError $value,
        public readonly string $methodName,
        public readonly array $params,
    ) {}
}

<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * Web-service parameter modifier flags (bitmask).
 *
 * Values match the WS_PARAM_* defines in include/ws_core.inc.php.
 */
enum WsParam: int
{
    case AcceptArray = 0x010000;
    case ForceArray  = 0x030000;
    case Optional    = 0x040000;
}

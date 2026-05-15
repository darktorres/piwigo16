<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * Web-service parameter type flags (bitmask).
 *
 * Values mirror the WS_TYPE_* runtime constants emitted by PwgServer::boot().
 * They are OR-combined at call sites: WsType::Int->value | WsType::Positive->value.
 */
enum WsType: int
{
    case Bool     = 0x01;
    case Int      = 0x02;
    case Float    = 0x04;
    case Positive = 0x10;
    case NotNull  = 0x20;

    /** Composite: Int | Positive | NotNull */
    case Id       = 0x32;
}

<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * Web-service error codes returned via PwgError. Custom Piwigo codes
 * (not HTTP statuses) — distinct from the bare 400/401/403/415/etc.
 * passed to PwgError elsewhere in the codebase.
 *
 * Values mirror the WS_ERR_* runtime constants formerly emitted by
 * PwgServer::boot() (retired in Phase 2a).
 */
enum WsError: int
{
    /** 501 — method name does not resolve to a registered handler. */
    case InvalidMethod = 501;

    /** 1002 — a required parameter was not supplied. */
    case MissingParam  = 1002;

    /** 1003 — a parameter value failed type/shape validation. */
    case InvalidParam  = 1003;
}

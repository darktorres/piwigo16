<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * Thrown by per-endpoint `Params::fromArray()` factories when the raw
 * request payload doesn't satisfy the endpoint's contract.
 *
 * `PwgServer::invoke()` catches this and converts it to a `PwgError` so
 * the WS response carries a clean 400 instead of a 500 / fatal.
 */
final class WsParamException extends \RuntimeException
{
}

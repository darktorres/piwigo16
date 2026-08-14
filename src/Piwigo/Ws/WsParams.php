<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

/**
 * Per-WS-method input contract (Group 19) -- a `WsAction` that consumes
 * structured request parameters parses the raw `$params` array (already
 * validated against the method's registered signature by
 * `Server::invoke()`) into typed properties via a `fromArray(array
 * $raw): static` static factory (throwing `WsParamException` on
 * failure).
 *
 * Deliberately a marker interface with no declared `fromArray()` --
 * every `Handler` calls its own `Params::fromArray()` directly against
 * the concrete class it already knows at compile time (there's no
 * generic, Server-driven dispatch over `WsParams` the way there is for
 * `WsAction`/`WsResult`), so an abstract declaration here would add no
 * real type-checking value beyond what the direct call site already
 * gets, while permanently tripping the dead-code checker (nothing ever
 * calls `fromArray()` *through* this interface).
 */
interface WsParams {}

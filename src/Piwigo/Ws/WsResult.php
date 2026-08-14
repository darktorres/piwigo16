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
 * Per-WS-method output contract (Group 19) -- a `WsAction` that produces
 * structured data may return a `WsResult` instance; `Server::invoke()`'s
 * `handlerClass` branch calls `toArray()` before passing the result on to
 * the response encoder. A handler may also still return a raw
 * array/scalar/`WsErrorResponse` directly -- the wire contract is
 * unchanged either way.
 */
interface WsResult
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}

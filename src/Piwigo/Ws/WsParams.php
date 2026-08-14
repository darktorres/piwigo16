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
 * `Server::invoke()`) into typed properties via `fromArray()`.
 */
interface WsParams
{
    /**
     * @param array<int|string, mixed> $raw
     * @throws WsParamException
     */
    public static function fromArray(array $raw): static;
}

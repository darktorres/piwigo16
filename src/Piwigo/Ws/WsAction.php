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
 * Per-WS-method action contract (Group 19) -- replaces one method of a
 * legacy god-class (`Images`, `Users`, etc.) with its own file, resolved
 * from the container and dispatched by `Server::invoke()`'s
 * `handlerClass` branch.
 */
interface WsAction
{
    /**
     * @param array<mixed> $params
     */
    public function __invoke(array $params): mixed;
}

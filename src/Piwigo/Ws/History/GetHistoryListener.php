<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\History;

use Piwigo\Event\Ws\GetHistory;
use Piwigo\History\HistoryService;

/**
 * Default `GetHistory` event handler, registered by `WsInitializer`
 * (not a `WsAction` -- this takes a typed event, not `(array $params)`,
 * so it's not one of the WS-registered `pwg.history.*` methods).
 * `SearchHandler` (this class's only real
 * caller) dispatches via `dispatchChange()` rather than calling this
 * directly, so a plugin can still override history search behavior by
 * registering its own `GetHistory` handler at a higher priority.
 */
final readonly class GetHistoryListener
{
    public function __construct(
        private HistoryService $historyService,
    ) {}

    public function __invoke(GetHistory $event): GetHistory
    {
        $event->data = $this->historyService
            ->getHistory($event->data, $event->search, $event->types);

        return $event;
    }
}

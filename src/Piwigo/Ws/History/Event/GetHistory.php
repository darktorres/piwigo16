<?php

declare(strict_types=1);

namespace Piwigo\Ws\History\Event;

/**
 * Typed event for the legacy `get_history` filter. Registered by
 * src/Piwigo/Ws/WsInitializer.php (Core::historyGet()) at the default
 * priority -- the only real handler on this branch, matching the
 * currently-registered-handler mutability rule. Co-located here from `Piwigo\Event\Ws\GetHistory` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class GetHistory
{
    /**
     * @param array<int, array<string, mixed>> $data
     * @param array<string, mixed> $search
     * @param list<string> $types
     */
    public function __construct(
        public array $data,
        public readonly array $search,
        public readonly array $types,
    ) {}
}

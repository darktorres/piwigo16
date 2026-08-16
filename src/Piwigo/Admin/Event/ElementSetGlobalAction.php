<?php

declare(strict_types=1);

namespace Piwigo\Admin\Event;

/**
 * Typed event for the legacy `element_set_global_action` notification.
 * No handler is registered for it anywhere today. Co-located here from `Piwigo\Event\Admin\ElementSetGlobalAction` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class ElementSetGlobalAction
{
    /**
     * @param list<int> $collection
     */
    public function __construct(
        public string $action,
        public array $collection,
    ) {}
}

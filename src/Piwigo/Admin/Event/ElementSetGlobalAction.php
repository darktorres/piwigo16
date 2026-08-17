<?php

declare(strict_types=1);

namespace Piwigo\Admin\Event;

/**
 * Typed event for the legacy `element_set_global_action` notification.
 * No handler is registered for it anywhere today.
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

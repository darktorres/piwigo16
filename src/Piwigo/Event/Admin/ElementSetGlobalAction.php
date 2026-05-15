<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for legacy `element_set_global_action` (notify).
 *
 * Dispatched from: src/Piwigo/Controller/Admin/BatchManagerController.php
 */
final readonly class ElementSetGlobalAction
{
    /**
     * @param array<mixed> $collection
     */
    public function __construct(
        public string $action,
        public array $collection,
    ) {
    }
}

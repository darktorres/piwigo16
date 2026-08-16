<?php

declare(strict_types=1);

namespace Piwigo\Html\Event;

/**
 * Typed event for the legacy `render_element_description` filter.
 * `$action` defaults to `''` -- `Ws\Core.php`'s own dispatch site omits
 * it entirely, matching `HtmlService::renderElementDescription()`'s own
 * `string $param = ''` default. Co-located here from `Piwigo\Event\Picture\RenderElementDescription` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class RenderElementDescription
{
    public function __construct(
        public string $elementDescription,
        public readonly string $action = '',
    ) {}
}

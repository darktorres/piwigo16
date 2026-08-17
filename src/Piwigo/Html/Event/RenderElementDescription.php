<?php

declare(strict_types=1);

namespace Piwigo\Html\Event;

/**
 * Typed event for the legacy `render_element_description` filter.
 * `$action` defaults to `''` -- `Controller\Api\History\
 * HistorySearchController`'s own dispatch site omits it entirely, matching
 * `HtmlService::renderElementDescription()`'s own `string $param = ''`
 * default.
 */
final class RenderElementDescription
{
    public function __construct(
        public string $elementDescription,
        public readonly string $action = '',
    ) {}
}

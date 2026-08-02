<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for the legacy `render_element_description` filter.
 * `$action` defaults to `''` -- `Ws\PwgCore.php`'s own dispatch site omits
 * it entirely, matching `HtmlService::renderElementDescription()`'s own
 * `string $param = ''` default.
 */
final class RenderElementDescription
{
    public function __construct(
        public string $elementDescription,
        public readonly string $action = '',
    ) {}
}

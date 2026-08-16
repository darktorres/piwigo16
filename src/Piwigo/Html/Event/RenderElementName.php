<?php

declare(strict_types=1);

namespace Piwigo\Html\Event;

/**
 * Typed event for the legacy `render_element_name` filter. No handler is
 * registered for it anywhere today -- a pure information carrier.
 * `$context` is a genuine union: `Html\HtmlService.php`'s dispatch site
 * passes the full element row (array), while every `Ws\*.php` dispatch
 * site passes `__FUNCTION__` (string) instead. Mutable on
 * `$elementName`; `$context` stays context. Co-located here from `Piwigo\Event\Picture\RenderElementName` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class RenderElementName
{
    /**
     * @param string|array<mixed> $context
     */
    public function __construct(
        public string $elementName,
        public readonly string|array $context,
    ) {}
}

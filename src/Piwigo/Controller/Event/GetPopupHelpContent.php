<?php

declare(strict_types=1);

namespace Piwigo\Controller\Event;

/**
 * Typed event for the legacy `get_popup_help_content` filter. No
 * handler is registered for it anywhere today. Mutable on `$content`;
 * `$rawPage` stays context. Co-located here from `Piwigo\Event\Admin\GetPopupHelpContent` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class GetPopupHelpContent
{
    public function __construct(
        public string $content,
        public readonly string $rawPage,
    ) {}
}

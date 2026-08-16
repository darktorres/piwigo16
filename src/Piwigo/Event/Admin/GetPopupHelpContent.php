<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for the legacy `get_popup_help_content` filter. No
 * handler is registered for it anywhere today. Mutable on `$content`;
 * `$rawPage` stays context.
 */
final class GetPopupHelpContent
{
    public function __construct(
        public string $content,
        public readonly string $rawPage,
    ) {}
}

<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for legacy `get_popup_help_content` (dispatch).
 *
 * Dispatched from: src/Piwigo/Controller/Admin/MiscController.php, src/Piwigo/Controller/PopuphelpController.php
 */
final readonly class GetPopupHelpContent
{
    public function __construct(
        public string $helpContent,
        public string $page,
    ) {
    }
}

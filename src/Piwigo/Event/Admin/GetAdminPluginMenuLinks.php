<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for legacy `get_admin_plugin_menu_links` (dispatch).
 *
 * use this trigger to add links into admin plugins menu
 *
 * Dispatched from: src/Piwigo/Controller/Admin/ExtensionsController.php
 */
final readonly class GetAdminPluginMenuLinks
{
    /**
     * @param array<mixed> $value
     */
    public function __construct(
        public array $value,
    ) {
    }
}

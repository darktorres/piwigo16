<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for the legacy `get_admin_plugin_menu_links` filter. No
 * handler is registered for it anywhere in src/Piwigo/ today (only a
 * throwaway fixture plugin in PluginsInstalledPageRendererTest.php).
 */
final readonly class GetAdminPluginMenuLinks
{
    /**
     * @param array<mixed> $value
     */
    public function __construct(
        public array $value,
    ) {}
}

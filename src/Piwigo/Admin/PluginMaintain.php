<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\Kernel;
use Piwigo\Users\PermissionService;

/**
 * Used to declare maintenance methods of a plugin.
 *
 * Signatures are intentionally untyped because vendor plugins (e.g.
 * piwigo-openstreetmap, piwigo-videojs) extend this class with
 * pre-PHP-7 signatures (no parameter types, no return types). Adding
 * declared types here breaks LSP and produces fatal-on-load.
 */
final class PluginMaintain
{
    /**
     * @param string $plugin_id
     */
    public function __construct(protected $plugin_id)
    {
    }

    /** @param array<mixed> $errors */
    public function install(string $plugin_version, array &$errors = []): null
    {
        return null;
    }

    /** @param array<mixed> $errors */
    public function activate(string $plugin_version, array &$errors = []): null
    {
        return null;
    }

    public function deactivate(): void
    {
    }

    public function uninstall(): void
    {
    }

    /** @param string $old_version
     *  @param string $new_version
     *  @param array<mixed> $errors */
    public function update($old_version, $new_version, &$errors = []): null
    {
        return null;
    }

    /**
     * @removed 2.7
     */
    public function autoUpdate(): void
    {
        if (Kernel::service(PermissionService::class)->isAdmin() && !defined('IN_WS')) {
            trigger_error('Function PluginMaintain::autoUpdate deprecated', E_USER_WARNING);
        }
    }
}

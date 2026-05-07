<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Users\PermissionService;

/**
 * Used to declare maintenance methods of a plugin.
 *
 * Signatures are intentionally untyped because vendor plugins (e.g.
 * piwigo-openstreetmap, piwigo-videojs) extend this class with
 * pre-PHP-7 signatures (no parameter types, no return types). Adding
 * declared types here breaks LSP and produces fatal-on-load.
 */
class PluginMaintain
{
    /**
     * @param string $plugin_id
     */
    public function __construct(protected $plugin_id)
    {
    }

    /** @param string $plugin_version
     *  @param array<mixed> $errors */
    public function install($plugin_version, &$errors = []): null
    {
        return null;
    }

    /** @param string $plugin_version
     *  @param array<mixed> $errors */
    public function activate($plugin_version, &$errors = []): null
    {
        return null;
    }

    /** @return void */
    public function deactivate()
    {
    }

    /** @return void */
    public function uninstall()
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
        if (PermissionService::get()->isAdmin() && !defined('IN_WS')) {
            trigger_error('Function PluginMaintain::autoUpdate deprecated', E_USER_WARNING);
        }
    }
}

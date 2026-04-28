<?php

declare(strict_types=1);

namespace Piwigo\Admin;

/**
 * Used to declare maintenance methods of a plugin.
 */
class PluginMaintain
{
    /**
     * @param string $plugin_id
     */
    public function __construct(protected $plugin_id)
    {
    }

    /**
     * @param string $plugin_version
     * @param array &$errors - used to return error messages
     */
    /** @param array<mixed> $errors */
    public function install(string $plugin_version, array &$errors = []): mixed
    {
        return null;
    }

    /**
     * @param string $plugin_version
     * @param array &$errors - used to return error messages
     */
    /** @param array<mixed> $errors */
    public function activate(string $plugin_version, array &$errors = []): mixed
    {
        return null;
    }

    public function deactivate(): void
    {
    }

    public function uninstall(): void
    {
    }

    /**
     * @param string $old_version
     * @param string $new_version
     * @param array &$errors - used to return error messages
     */
    /** @param array<mixed> $errors */
    public function update(string $old_version, string $new_version, array &$errors = []): mixed
    {
        return null;
    }

    /**
     * @removed 2.7
     */
    public function autoUpdate(): void
    {
        if (is_admin() && !defined('IN_WS')) {
            trigger_error('Function PluginMaintain::autoUpdate deprecated', E_USER_WARNING);
        }
    }
}

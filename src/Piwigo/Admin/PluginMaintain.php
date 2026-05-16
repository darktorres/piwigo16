<?php

declare(strict_types=1);

namespace Piwigo\Admin;

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

    /** @param array<mixed> $errors */
    public function update(string $old_version, string $new_version, array &$errors = []): null
    {
        return null;
    }

}

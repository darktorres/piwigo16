<?php

declare(strict_types=1);

namespace Piwigo\Admin;

/**
 * Used to declare maintenance methods of a theme.
 *
 * Signatures are intentionally untyped to match legacy vendor themes
 * that extend this class with pre-PHP-7 signatures (no parameter
 * types, no return types). Adding declared types here breaks LSP and
 * fatal-on-load. See PluginMaintain for the same reasoning.
 */
class ThemeMaintain
{
    /**
     * @param string $theme_id
     */
    public function __construct(protected $theme_id)
    {
    }

    /** @param string $theme_version
     *  @param array<mixed> $errors
     *  @return mixed */
    public function activate($theme_version, &$errors = [])
    {
        return null;
    }

    /** @return void */
    public function deactivate()
    {
    }

    /** @return void */
    public function delete()
    {
    }
}

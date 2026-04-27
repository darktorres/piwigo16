<?php

declare(strict_types=1);

namespace Piwigo\Admin;

/**
 * Used to declare maintenance methods of a theme.
 */
class ThemeMaintain
{
    /**
     * @param string $theme_id
     */
    public function __construct(protected $theme_id)
    {
    }

    /**
     * @param string $theme_version
     * @param array &$errors - used to return error messages
     */
    public function activate($theme_version, &$errors = [])
    {
    }

    public function deactivate()
    {
    }

    public function delete()
    {
    }
}

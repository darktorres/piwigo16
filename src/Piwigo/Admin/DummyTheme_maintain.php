<?php

declare(strict_types=1);

namespace Piwigo\Admin;

/**
 * class DummyTheme_maintain
 * used when a theme uses the old procedural declaration of maintenance methods.
 * Old-style themes define theme_activate(), theme_deactivate(), theme_delete() as global functions.
 */
class DummyTheme_maintain extends ThemeMaintain
{
    public function activate($theme_version, &$errors = [])
    {
        return theme_activate($this->theme_id, $theme_version, $errors);
    }
    public function deactivate()
    {
        return theme_deactivate($this->theme_id);
    }
    public function delete()
    {
        return theme_delete($this->theme_id);
    }
}

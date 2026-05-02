<?php

declare(strict_types=1);

namespace Piwigo\Admin;

/**
 * class DummyThemeMaintain
 * used when a theme uses the old procedural declaration of maintenance methods.
 * Old-style themes define theme_activate(), theme_deactivate(), theme_delete() as global functions.
 */
class DummyThemeMaintain extends ThemeMaintain
{
    /** @param array<mixed> $errors */
    public function activate(string $theme_version, array &$errors = []): mixed
    {
        trigger_error('theme_activate() is deprecated; extend ThemeMaintain instead', E_USER_DEPRECATED);
        return theme_activate($this->theme_id, $theme_version, $errors);
    }

    public function deactivate(): void
    {
        trigger_error('theme_deactivate() is deprecated; extend ThemeMaintain instead', E_USER_DEPRECATED);
        theme_deactivate($this->theme_id);
    }

    public function delete(): void
    {
        trigger_error('theme_delete() is deprecated; extend ThemeMaintain instead', E_USER_DEPRECATED);
        theme_delete($this->theme_id);
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Admin;

/**
 * class DummyThemeMaintain
 * used when a theme uses the old procedural declaration of maintenance methods.
 * Old-style themes define theme_activate(), theme_deactivate(), theme_delete() as global functions.
 *
 * See DummyPluginMaintain for the rationale on `\` qualification and
 * function_exists() guards.
 */
class DummyThemeMaintain extends ThemeMaintain
{
    /** @param string $theme_version
     *  @param array<mixed> $errors */
    public function activate($theme_version, &$errors = []): null
    {
        if (!function_exists('theme_activate')) {
            return null;
        }
        trigger_error('theme_activate() is deprecated; extend ThemeMaintain instead', E_USER_DEPRECATED);
        \theme_activate($this->theme_id, $theme_version, $errors);
        return null;
    }

    public function deactivate(): void
    {
        if (!function_exists('theme_deactivate')) {
            return;
        }
        trigger_error('theme_deactivate() is deprecated; extend ThemeMaintain instead', E_USER_DEPRECATED);
        \theme_deactivate($this->theme_id);
    }

    public function delete(): void
    {
        if (!function_exists('theme_delete')) {
            return;
        }
        trigger_error('theme_delete() is deprecated; extend ThemeMaintain instead', E_USER_DEPRECATED);
        \theme_delete($this->theme_id);
    }
}

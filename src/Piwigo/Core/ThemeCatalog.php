<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Event\Lifecycle\GetPwgThemes;

/**
 * P23 batch 8d: installed-theme listing relocated from
 * include/functions.inc.php -- no natural existing class home (theme
 * management itself lives in Piwigo\Admin, L4Integration, but
 * Piwigo\Users\UserService, L2aCoreDomain, is a real caller of
 * getPwgThemes()/checkThemeInstalled(), so this needs an L1Infrastructure
 * home, same reasoning as PaginationService's own move).
 *
 * Legacy Coupling Retirement: DI+DBAL migration Phase 1e -- `Piwigo\Db`
 * is the same L1Infrastructure layer as this class, so `DbConnection`
 * (constructed inline; static method, no instance state) is a legal,
 * same-layer dependency.
 */
final class ThemeCatalog
{
    /**
     * returns available themes
     *
     * @return array<int|string, string>
     */
    public static function getPwgThemes(bool $showMobile = false): array
    {

        $themes = [];

        $rows = new ThemeRepository(\Piwigo\Db\DbConnection::build())->findAllIdsAndNames();
        foreach ($rows as $row) {
            $id = $row['id'];
            $name = $row['name'];

            $mobile_theme = \Piwigo\Config\CurrentConfig::mobilTheme();
            if ($id === $mobile_theme) {
                if (! $showMobile) {
                    continue;
                }
                $name .= ' (' . Lang::t('Mobile') . ')';
            }
            if (self::checkThemeInstalled($id)) {
                $themes[$id] = $name;
            }
        }

        // plugins want remove some themes based on user status maybe?
        $themes = \Piwigo\PluginConfig\EventDispatcher::get()->dispatchChange(new GetPwgThemes($themes))->themes;

        $filtered_themes = [];
        foreach ($themes as $key => $value) {
            if (is_string($value)) {
                $filtered_themes[$key] = $value;
            }
        }

        return $filtered_themes;
    }

    /**
     * check if a theme is installed (directory exists)
     */
    public static function checkThemeInstalled(string $themeId): bool
    {

        // CurrentConfig::themesDir() is root-relative (Part II) -- compose with
        // CurrentPaths::get()->root for a real filesystem check, don't rely
        // on PHP's CWD (which tracks the executing script's directory, not
        // necessarily the install root -- this "happened" to still resolve
        // correctly pre-fix only because public/themes is itself a symlink
        // back to the real themes/, not because the CWD-relative read was
        // actually safe).
        $themes_dir = CurrentPaths::get()->root . \Piwigo\Config\CurrentConfig::themesDir();

        return file_exists($themes_dir . '/' . $themeId . '/themeconf.inc.php');
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Db\Tables;

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

        $query = '
SELECT
    id,
    name
  FROM ' . Tables::themes() . '
  ORDER BY name ASC
;';
        $rows = \Piwigo\Db\DbConnection::build()->fetchAllAssociative($query);
        foreach ($rows as $row) {
            $id = $row['id'];
            $name = $row['name'];
            if (! is_string($id) || ! is_string($name)) {
                continue;
            }

            $mobile_theme = \Piwigo\Config\Config::mobilTheme();
            if (is_string($mobile_theme) && $id === $mobile_theme) {
                if (! $showMobile) {
                    continue;
                }
                $name .= ' (' . l10n('Mobile') . ')';
            }
            if (self::checkThemeInstalled($id)) {
                $themes[$id] = $name;
            }
        }

        // plugins want remove some themes based on user status maybe?
        $themes = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('get_pwg_themes', $themes);
        if (! is_array($themes)) {
            return [];
        }

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

        $themes_dir = \Piwigo\Config\Config::themesDir();

        return file_exists($themes_dir . '/' . $themeId . '/themeconf.inc.php');
    }
}

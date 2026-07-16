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
        /** @var array<string, mixed> $conf */
        global $conf;

        $themes = [];

        $query = '
SELECT
    id,
    name
  FROM ' . Tables::themes() . '
  ORDER BY name ASC
;';
        $result = \Piwigo\Db\MysqliDb::query($query);
        while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
            $id = $row['id'];
            $name = $row['name'];
            if (! is_string($id) || ! is_string($name)) {
                continue;
            }

            $mobile_theme = $conf['mobile_theme'] ?? null;
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
        $themes = trigger_change('get_pwg_themes', $themes);
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
        /** @var array<string, mixed> $conf */
        global $conf;

        $themes_dir = $conf['themes_dir'];
        $themes_dir = is_string($themes_dir) ? $themes_dir : '';

        return file_exists($themes_dir . '/' . $themeId . '/themeconf.inc.php');
    }
}

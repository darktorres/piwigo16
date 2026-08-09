<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Config\CurrentConfig;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Lifecycle\GetPwgThemes;
use Piwigo\PluginConfig\EventDispatcher;

/**
 * Lives in `Piwigo\Core` (L1Infrastructure) rather than `Piwigo\Admin`
 * (L4Integration, where theme management otherwise lives), because
 * `Piwigo\Users\UserService` (L2aCoreDomain) is a real caller of
 * `getPwgThemes()`/`checkThemeInstalled()` and cannot depend upward on L4.
 *
 * `Piwigo\Db` is the same L1Infrastructure layer as this class, so
 * `DbConnection` (constructed inline; static method, no instance state) is
 * a legal, same-layer dependency.
 */
final class ThemeCatalog
{
    /**
     * returns available themes
     *
     * @return array<int|string, string>
     */
    public static function getPwgThemes(EventDispatcher $eventDispatcher, Paths $paths, CurrentConfig $currentConfig, Lang $lang, bool $showMobile = false): array
    {

        $themes = [];

        $conn = DbConnection::build();
        $rows = EntityManagerFactory::build($conn)->getRepository(ThemeEntity::class)->findAllIdsAndNames();
        foreach ($rows as $row) {
            $id = $row->id;
            $name = $row->name;

            $mobile_theme = $currentConfig->mobilTheme;
            if ($id === $mobile_theme) {
                if (! $showMobile) {
                    continue;
                }
                $name .= ' (' . $lang->t('Mobile') . ')';
            }
            if (self::checkThemeInstalled($id, $paths, $currentConfig)) {
                $themes[$id] = $name;
            }
        }

        // plugins want remove some themes based on user status maybe?
        $themes = $eventDispatcher->dispatchChange(new GetPwgThemes($themes))
            ->themes;

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
    public static function checkThemeInstalled(string $themeId, Paths $paths, CurrentConfig $currentConfig): bool
    {

        // CurrentConfig::themesDir() is root-relative (Part II) -- compose with
        // $paths->root for a real filesystem check, don't rely
        // on PHP's CWD (which tracks the executing script's directory, not
        // necessarily the install root -- this "happened" to still resolve
        // correctly pre-fix only because public/themes is itself a symlink
        // back to the real themes/, not because the CWD-relative read was
        // actually safe).
        $themes_dir = $paths->root . $currentConfig->themesDir;

        return file_exists($themes_dir . '/' . $themeId . '/themeconf.inc.php');
    }
}

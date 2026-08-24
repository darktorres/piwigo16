<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Projection\ThemeListing;
use Piwigo\Db\TypedRepository;

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
    public static function getPwgThemes(Paths $paths, CurrentConfig $currentConfig, Lang $lang, EntityManagerInterface $entityManager, bool $showMobile = false): array
    {

        $themes = [];

        $rows = TypedRepository::narrow($entityManager->getRepository(ThemeEntity::class), ThemeRepository::class)->findAllIdsAndNames();

        // AppInfo::DEFAULT_TEMPLATE is a base layer other themes load on
        // top of, never something a real install activates (see
        // ExtensionLifecycle::performThemeAction()'s own $id === 'default'
        // no-op guard) -- it deliberately never gets a real `themes` row.
        // Unlike upstream Piwigo (which never surfaces this: a real
        // account's theme is always the genuinely-activatable 'modus',
        // never the base theme), this fork ships no other theme yet, so
        // every account's theme is this one -- synthesize its row here,
        // ahead of the loop below, so it passes through the exact same
        // mobile-suffix/checkThemeInstalled() handling as a real row
        // rather than being merged in separately afterward.
        $hasDefaultRow = false;
        foreach ($rows as $row) {
            if ($row->id === AppInfo::DEFAULT_TEMPLATE) {
                $hasDefaultRow = true;

                break;
            }
        }

        if (! $hasDefaultRow) {
            $rows[] = new ThemeListing(AppInfo::DEFAULT_TEMPLATE, $lang->t('Default'));
        }

        foreach ($rows as $row) {
            $id = $row->id;
            $name = $row->name;

            $mobile_theme = $currentConfig->mobileTheme;
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

        return $themes;
    }

    /**
     * check if a theme is installed (directory exists)
     *
     * Checks for the PluginConfig\ExtensionInterface `theme.json`
     * manifest -- the only marker a real theme directory has in this
     * codebase; there is no `themeconf.inc.php` fallback.
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
        $theme_dir = $paths->root . $currentConfig->themesDir . '/' . $themeId;

        return file_exists($theme_dir . '/theme.json');
    }
}

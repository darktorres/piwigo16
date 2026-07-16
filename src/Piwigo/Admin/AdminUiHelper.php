<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\StringHelper;
use Piwigo\Db\Tables;

/**
 * Small, disparate admin-UI utility functions ported from
 * admin/include/functions.php (P23 batch 8d) -- no other natural domain
 * home; every real caller is under the Piwigo\Admin or
 * Piwigo\Controller\Admin namespace (L4Integration) or a legacy top-level
 * entry point (admin.php, install.php), so unlike this batch's
 * FilesystemHelper/PermissionService/UserCacheInvalidator, no deptrac
 * layer constraint forced a specific placement -- matches the
 * "administrative machinery" precedent already set by
 * PiwigoInfosSender/InstallationStats.
 */
final class AdminUiHelper
{
    /**
     * Returns a list of templates currently available in
     * template-extension. Each .tpl file is extracted from
     * template-extension.
     *
     * @param string $start (internal use)
     * @return string[]
     */
    public static function getExtents(string $start = ''): array
    {
        if ($start === '') {
            $start = './template-extension';
        }
        $extents = [];

        $dir = opendir($start);
        if ($dir === false) {
            return $extents;
        }

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' or $file === '..' or $file === '.svn') {
                continue;
            }
            $path = $start . '/' . $file;
            if (is_dir($path)) {
                $extents = array_merge($extents, self::getExtents($path));
            } elseif (! is_link($path) and file_exists($path)
                    and StringHelper::getExtension($path) === 'tpl') {
                $extents[] = substr($path, 21);
            }
        }
        closedir($dir);
        return $extents;
    }

    /**
     * Refer main Piwigo URLs (currently PHPWG_DOMAIN domain)
     *
     * @return string[]
     */
    public static function pwgUrl(): array
    {
        return [
            'HOME' => PHPWG_URL,
            'WIKI' => PHPWG_URL . '/doc',
            'DEMO' => PHPWG_URL . '/demo',
            'FORUM' => PHPWG_URL . '/forum',
            'BUGS' => PHPWG_URL . '/bugs',
            'EXTENSIONS' => PHPWG_URL . '/ext',
        ];
    }

    public static function getNewsletterSubscribeBaseUrl(string $language = 'en_UK'): string
    {
        return PHPWG_URL . '/announcement/subscribe/';
    }

    public static function getOldNewslettersBaseUrl(string $language = 'en_UK'): string
    {
        return PHPWG_URL . '/newsletter';
    }

    public static function getActiveMenu(string $menuPage): int
    {
        /** @var array<string, mixed> $page */
        global $page;

        if (isset($page['active_menu']) && is_int($page['active_menu'])) {
            return $page['active_menu'];
        }

        return match ($menuPage) {
            'photo', 'photos_add', 'rating', 'tags', 'batch_manager' => 0,
            'album', 'cat_list', 'albums', 'cat_options', 'cat_search', 'permalinks' => 1,
            'user_list', 'user_perm', 'group_list', 'group_perm', 'notification_by_mail', 'user_activity' => 2,
            'site_manager', 'site_update', 'stats', 'history', 'maintenance', 'comments', 'updates' => 3,
            'configuration', 'derivatives', 'extend_for_templates', 'menubar', 'themes', 'theme', 'languages' => 4,
            default => -1,
        };
    }

    public static function numberFormatHumanReadable(int|float $numbers): string
    {
        $readable = ['', 'k', 'M'];
        $index = 0;
        $numbers = ($numbers === 0 || $numbers === 0.0) ? 0 : $numbers;

        while ($numbers >= 1000) {
            $numbers /= 1000;
            $index++;

            if ($index > count($readable) - 1) {
                $index--;
                break;
            }
        }

        $decimals = 1;
        if ($readable[$index] === '') {
            $decimals = 0;
        }

        return number_format($numbers, $decimals) . $readable[$index];
    }

    /**
     * Returns keys to identify the state of main tables. A key consists of
     * the last modification timestamp and the total of items (separated
     * by a _). Additionally returns the hash of root path. Used to
     * invalidate LocalStorage cache on admin pages.
     *
     * @param string|string[] $requested list of keys to retrieve
     *   (categories,groups,images,tags,users)
     * @return string[]
     */
    public static function getAdminClientCacheKeys(string|array $requested = []): array
    {
        $tables = [
            'categories' => Tables::categories(),
            'groups' => Tables::groups(),
            'images' => Tables::images(),
            'tags' => Tables::tags(),
            'users' => Tables::userInfos(),
        ];

        if (! is_array($requested)) {
            $requested = [$requested];
        }
        if ($requested === []) {
            $requested = array_keys($tables);
        } else {
            $requested = array_intersect($requested, array_keys($tables));
        }

        $keys = [
            '_hash' => md5(get_absolute_root_url()),
        ];

        foreach ($requested as $item) {
            $query = '
SELECT CONCAT(
    UNIX_TIMESTAMP(MAX(lastmodified)),
    "_",
    COUNT(*)
  )
  FROM `' . $tables[$item] . '`
;';
            $row = pwg_db_fetch_row(pwg_query($query));
            assert($row !== null);
            $cache_key = $row[0];
            assert(is_string($cache_key));
            $keys[$item] = $cache_key;
        }

        return $keys;
    }
}

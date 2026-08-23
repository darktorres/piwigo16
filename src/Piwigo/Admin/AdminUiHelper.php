<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\AppInfo;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;

/**
 * Small, disparate admin-UI utility functions with no single natural
 * namespace home. Every real caller is under the Piwigo\Admin or
 * Piwigo\Controller\Admin namespace (L4Integration) or a legacy
 * top-level entry point (admin.php, install.php), so no deptrac layer
 * constraint forces a specific placement.
 */
final class AdminUiHelper
{
    /**
     * Refer main Piwigo URLs (currently PHPWG_DOMAIN domain)
     *
     * @return string[]
     */
    public static function pwgUrl(): array
    {
        return [
            'HOME' => AppInfo::URL,
            'WIKI' => AppInfo::URL . '/doc',
            'DEMO' => AppInfo::URL . '/demo',
            'FORUM' => AppInfo::URL . '/forum',
            'BUGS' => AppInfo::URL . '/bugs',
            'EXTENSIONS' => AppInfo::URL . '/ext',
        ];
    }

    public static function getNewsletterSubscribeBaseUrl(string $language = 'en_UK'): string
    {
        return AppInfo::URL . '/announcement/subscribe/';
    }

    public static function getOldNewslettersBaseUrl(string $language = 'en_UK'): string
    {
        return AppInfo::URL . '/newsletter';
    }

    public static function getActiveMenu(string $menuPage): int
    {
        return match ($menuPage) {
            'photo', 'photos_add', 'rating', 'tags', 'batch_manager' => 0,
            'album', 'cat_list', 'albums', 'cat_options', 'cat_search', 'permalinks' => 1,
            'user_list', 'user_perm', 'group_list', 'group_perm', 'notification_by_mail', 'user_activity' => 2,
            'site_manager', 'site_update', 'stats', 'history', 'maintenance', 'comments', 'updates' => 3,
            'configuration', 'derivatives', 'menubar', 'themes', 'theme', 'languages' => 4,
            default => -1,
        };
    }

    public static function numberFormatHumanReadable(int|float $numbers): string
    {
        $readable = ['', 'k', 'M'];
        $index = 0;
        // Mutation-testing note: this int/float-0 normalization is
        // behaviorally inert -- number_format(0, 0) and number_format(0.0, 0)
        // both render '0', so no test can ever distinguish either branch of
        // this ternary; not a coverage gap.
        $numbers = ($numbers === 0 || $numbers === 0.0) ? 0 : $numbers;

        while ($numbers >= 1000) {
            $numbers = (float) $numbers / 1000.0;
            $index++;

            if ($index > count($readable) - 1) {
                $index--;
                break;
            }
        }

        $decimals = 1;
        /**
         * @psalm-suppress InvalidArrayOffset $index is clamped back into
         *   $readable's own 3-element bound by the `$index--; break;` above
         *   whenever the loop would otherwise overshoot it; Psalm doesn't
         *   trace that loop-internal clamp through to here.
         */
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
    public static function getAdminClientCacheKeys(UrlServiceInterface $urlService, string|array $requested = []): array
    {
        $tables = [
            'categories' => 'categories',
            'groups' => 'groups',
            'images' => 'images',
            'tags' => 'tags',
            'users' => 'user_infos',
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
            '_hash' => md5($urlService->getAbsoluteRootUrl()),
        ];

        $dbInfo = new DbInfo(DbConnection::build());
        foreach ($requested as $item) {
            $keys[$item] = $dbInfo->getTableFingerprint($tables[$item]);
        }

        return $keys;
    }
}

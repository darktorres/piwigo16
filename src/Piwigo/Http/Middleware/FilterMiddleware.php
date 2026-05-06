<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Piwigo\Config\Config;
use Piwigo\Db\SqlExpr;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the "recent photos" filter state and populates $GLOBALS['filter'].
 *
 * Replaces the former include/filter.inc.php + the outer condition in
 * common.inc.php that loaded it.
 *
 * Runs after AuthMiddleware so that $GLOBALS['user'] (recent_period, id,
 * cache_update_time) is already populated when filter data is computed.
 */
final class FilterMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->bootstrap();
        return $handler->handle($request);
    }

    private function bootstrap(): void
    {
        /** @var array<string,mixed> $filter */
        $filter = &$GLOBALS['filter'];
        /** @var array<string,mixed> $user */
        $user = &$GLOBALS['user'];
        /** @var array<mixed> $header_notes */
        $header_notes = &$GLOBALS['header_notes'];

        if (empty(Config::filterPages()) || !get_filter_page_value('used')) {
            $filter['enabled'] = false;
            return;
        }

        // ── Determine whether filter is active ────────────────────────────────

        $recentPeriodFromUrl = null;

        if (!get_filter_page_value('cancel')) {
            if (isset($_GET['filter'])) {
                $urlMatches    = [];
                $filterEnabled = preg_match(
                    '/^start-recent-(\d+)$/',
                    is_scalar($_GET['filter']) ? (string) $_GET['filter'] : '',
                    $urlMatches
                ) === 1;
                $filter['enabled'] = $filterEnabled;
                if ($filterEnabled) {
                    $recentPeriodFromUrl = (int) $urlMatches[1];
                }
            } else {
                $filter['enabled'] = pwg_get_session_var('filter_enabled', false);
            }
        } else {
            $filter['enabled'] = false;
        }

        if (!(bool) $filter['enabled']) {
            if (!empty($_SESSION['pwg_filter_enabled'])) {
                pwg_unset_session_var('filter_enabled');
                pwg_unset_session_var('filter_check_key');
                pwg_unset_session_var('filter_categories');
                pwg_unset_session_var('filter_visible_categories');
                pwg_unset_session_var('filter_visible_images');
            }
            return;
        }

        // ── Load or recompute filter data ─────────────────────────────────────

        /** @var array{user: int, recent_period: int, time: int, date: string} $filterKey */
        $filterKey = pwg_get_session_var('filter_check_key', [
            'user' => 0, 'recent_period' => -1, 'time' => 0, 'date' => '',
        ]);

        if ($recentPeriodFromUrl !== null) {
            $recentPeriod = $recentPeriodFromUrl;
        } else {
            $recentPeriod = $filterKey['recent_period'] > 0
                ? $filterKey['recent_period']
                : (is_numeric($user['recent_period'] ?? null) ? (int) $user['recent_period'] : 0);
        }
        $filter['recent_period'] = $recentPeriod;

        $userId          = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;
        $cacheUpdateTime = is_numeric($user['cache_update_time'] ?? null) ? (int) $user['cache_update_time'] : 0;

        $needsRecompute =
            $filterKey['time']          <= $cacheUpdateTime
            || $filterKey['user']       != $userId
            || $filterKey['recent_period'] != $recentPeriod
            || $filterKey['date']       != date('Ymd');

        if ($needsRecompute) {
            $filterKey = [
                'user'          => $userId,
                'recent_period' => $recentPeriod,
                'time'          => time(),
                'date'          => date('Ymd'),
            ];

            $computedCategories    = get_computed_categories($user, $recentPeriod);
            $filter['categories']  = $computedCategories;

            $visibleCatKeys        = array_map(static fn (int|string $k): string => (string) $k, array_keys($computedCategories));
            $visibleCatStr         = implode(',', $visibleCatKeys);
            $filter['visible_categories'] = $visibleCatStr !== '' ? $visibleCatStr : -1;

            $catClause = $visibleCatStr !== ''
                ? "\n  category_id IN ($visibleCatStr) and"
                : '';
            $query = '
SELECT distinct image_id
FROM ' . IMAGE_CATEGORY_TABLE . ' INNER JOIN ' . IMAGES_TABLE . ' ON image_id = id
WHERE ' . $catClause . '
    date_available >= ' . SqlExpr::recentPeriodExpr($recentPeriod);

            $visibleImageStr = implode(',', array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0',
                array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'image_id')
            ));
            $filter['visible_images'] = $visibleImageStr !== '' ? $visibleImageStr : -1;

            pwg_set_session_var('filter_enabled',             $filter['enabled']);
            pwg_set_session_var('filter_check_key',           $filterKey);
            pwg_set_session_var('filter_categories',          serialize($computedCategories));
            pwg_set_session_var('filter_visible_categories',  $filter['visible_categories']);
            pwg_set_session_var('filter_visible_images',      $filter['visible_images']);
        } else {
            $filter['categories']         = unserialize(pwg_get_session_var('filter_categories', serialize([])));
            $filter['visible_categories'] = pwg_get_session_var('filter_visible_categories', '');
            $filter['visible_images']     = pwg_get_session_var('filter_visible_images', '');
        }

        if (get_filter_page_value('add_notes')) {
            $header_notes[] = l10n_dec(
                'Photos posted within the last %d day.',
                'Photos posted within the last %d days.',
                $recentPeriod
            );
        }

        require_once PHPWG_ROOT_PATH . 'include/functions_filter.inc.php';
    }
}

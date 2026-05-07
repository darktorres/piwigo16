<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\Util;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlExpr;
use Piwigo\Db\Tables;
use Piwigo\Lang\Translator;
use Piwigo\Session\SessionService;
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

        if (empty(Config::filterPages()) || !ServiceLocator::get(Util::class)->getFilterPageValue('used')) {
            $filter['enabled'] = false;
            return;
        }

        // ── Determine whether filter is active ────────────────────────────────

        $recentPeriodFromUrl = null;

        if (!ServiceLocator::get(Util::class)->getFilterPageValue('cancel')) {
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
                $filter['enabled'] = ServiceLocator::get(SessionService::class)->getSessionVar('filter_enabled', false);
            }
        } else {
            $filter['enabled'] = false;
        }

        if (!(bool) $filter['enabled']) {
            if (!empty($_SESSION['pwg_filter_enabled'])) {
                ServiceLocator::get(SessionService::class)->unsetSessionVar('filter_enabled');
                ServiceLocator::get(SessionService::class)->unsetSessionVar('filter_check_key');
                ServiceLocator::get(SessionService::class)->unsetSessionVar('filter_categories');
                ServiceLocator::get(SessionService::class)->unsetSessionVar('filter_visible_categories');
                ServiceLocator::get(SessionService::class)->unsetSessionVar('filter_visible_images');
            }
            return;
        }

        // ── Load or recompute filter data ─────────────────────────────────────

        /** @var array{user: int, recent_period: int, time: int, date: string} $filterKey */
        $filterKey = ServiceLocator::get(SessionService::class)->getSessionVar('filter_check_key', [
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

            $computedCategories    = ServiceLocator::get(CategoryService::class)->getComputedCategories($user, $recentPeriod);
            $filter['categories']  = $computedCategories;

            $visibleCatKeys        = array_map(static fn (int|string $k): string => (string) $k, array_keys($computedCategories));
            $visibleCatStr         = implode(',', $visibleCatKeys);
            $filter['visible_categories'] = $visibleCatStr !== '' ? $visibleCatStr : -1;

            $catClause = $visibleCatStr !== ''
                ? "\n  category_id IN ($visibleCatStr) and"
                : '';
            $query = '
SELECT distinct image_id
FROM ' . Tables::imageCategory() . ' INNER JOIN ' . Tables::images() . ' ON image_id = id
WHERE ' . $catClause . '
    date_available >= ' . SqlExpr::recentPeriodExpr($recentPeriod);

            $visibleImageStr = implode(',', array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0',
                array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'image_id')
            ));
            $filter['visible_images'] = $visibleImageStr !== '' ? $visibleImageStr : -1;

            ServiceLocator::get(SessionService::class)->setSessionVar('filter_enabled', $filter['enabled']);
            ServiceLocator::get(SessionService::class)->setSessionVar('filter_check_key', $filterKey);
            ServiceLocator::get(SessionService::class)->setSessionVar('filter_categories', serialize($computedCategories));
            ServiceLocator::get(SessionService::class)->setSessionVar('filter_visible_categories', $filter['visible_categories']);
            ServiceLocator::get(SessionService::class)->setSessionVar('filter_visible_images', $filter['visible_images']);
        } else {
            $rawCategories                = ServiceLocator::get(SessionService::class)->getSessionVar('filter_categories', serialize([]));
            $filter['categories']         = unserialize(is_string($rawCategories) ? $rawCategories : serialize([]));
            $filter['visible_categories'] = ServiceLocator::get(SessionService::class)->getSessionVar('filter_visible_categories', '');
            $filter['visible_images']     = ServiceLocator::get(SessionService::class)->getSessionVar('filter_visible_images', '');
        }

        if (ServiceLocator::get(Util::class)->getFilterPageValue('add_notes')) {
            $header_notes[] = Translator::get()->plural(
                'Photos posted within the last %d day.',
                'Photos posted within the last %d days.',
                $recentPeriod
            );
        }

    }
}

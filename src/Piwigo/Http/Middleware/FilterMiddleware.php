<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Doctrine\DBAL\Connection;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Core\PageState;
use Piwigo\Core\Util;
use Piwigo\Db\SqlExpr;
use Piwigo\Db\Tables;
use Piwigo\Filter\FilterContext;
use Piwigo\Filter\FilterContextRegistry;
use Piwigo\Lang\Translator;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the "recent photos" filter state and commits it to
 * FilterContextRegistry as a typed immutable FilterContext.
 *
 * Replaces the former include/filter.inc.php + the outer condition in
 * common.inc.php that loaded it.
 *
 * Runs after AuthMiddleware so CurrentUser::get() (recent_period, id,
 * cache_update_time) is already populated when filter data is computed.
 */
final readonly class FilterMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Connection $conn,
        private CategoryService $categoryService,
        private SessionService $sessionService,
        private Util $util,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->bootstrap();
        return $handler->handle($request);
    }

    private function bootstrap(): void
    {
        /** @var array<string,mixed> $user */
        $user = CurrentUser::get()->rawAttributes;

        if (empty(Config::filterPages()) || !$this->util->getFilterPageValue('used')) {
            FilterContextRegistry::set(new FilterContext(enabled: false));
            return;
        }

        // ── Determine whether filter is active ────────────────────────────────

        $recentPeriodFromUrl = null;
        $enabled             = false;

        if (!$this->util->getFilterPageValue('cancel')) {
            if (isset($_GET['filter'])) {
                $urlMatches = [];
                $rawFilter  = $_GET['filter'];
                $enabled    = preg_match(
                    '/^start-recent-(\d+)$/',
                    is_string($rawFilter) ? $rawFilter : '',
                    $urlMatches
                ) === 1;
                if ($enabled) {
                    $recentPeriodFromUrl = (int) $urlMatches[1];
                }
            } else {
                $enabled = (bool) $this->sessionService->getSessionVar('filter_enabled', false);
            }
        }

        if (!$enabled) {
            if (!empty($_SESSION['pwg_filter_enabled'])) {
                $this->sessionService->unsetSessionVar('filter_enabled');
                $this->sessionService->unsetSessionVar('filter_check_key');
                $this->sessionService->unsetSessionVar('filter_categories');
                $this->sessionService->unsetSessionVar('filter_visible_categories');
                $this->sessionService->unsetSessionVar('filter_visible_images');
            }
            FilterContextRegistry::set(new FilterContext(enabled: false));
            return;
        }

        // ── Load or recompute filter data ─────────────────────────────────────

        /** @var array{user: int, recent_period: int, time: int, date: string} $filterKey */
        $filterKey = $this->sessionService->getSessionVar('filter_check_key', [
            'user' => 0, 'recent_period' => -1, 'time' => 0, 'date' => '',
        ]);

        if ($recentPeriodFromUrl !== null) {
            $recentPeriod = $recentPeriodFromUrl;
        } else {
            $recentPeriod = $filterKey['recent_period'] > 0
                ? $filterKey['recent_period']
                : (is_numeric($user['recent_period'] ?? null) ? (int) $user['recent_period'] : 0);
        }

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

            $computedCategories = $this->categoryService->getComputedCategories($user, $recentPeriod);

            $visibleCatKeys    = array_map(static fn (string $k): string => $k, array_keys($computedCategories));
            $visibleCatStr     = implode(',', $visibleCatKeys);
            $visibleCategories = $visibleCatStr !== '' ? $visibleCatStr : '-1';

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
                array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'image_id')
            ));
            $visibleImages = $visibleImageStr !== '' ? $visibleImageStr : '-1';

            $this->sessionService->setSessionVar('filter_enabled', true);
            $this->sessionService->setSessionVar('filter_check_key', $filterKey);
            $this->sessionService->setSessionVar('filter_categories', serialize($computedCategories));
            $this->sessionService->setSessionVar('filter_visible_categories', $visibleCategories);
            $this->sessionService->setSessionVar('filter_visible_images', $visibleImages);
        } else {
            $rawCategories      = $this->sessionService->getSessionVar('filter_categories', serialize([]));
            $unserialized       = unserialize(is_string($rawCategories) ? $rawCategories : serialize([]));
            $computedCategories = [];
            if (is_array($unserialized)) {
                foreach ($unserialized as $catKey => $catRow) {
                    if (!is_array($catRow)) {
                        continue;
                    }
                    $row = [];
                    foreach ($catRow as $fieldKey => $fieldVal) {
                        $row[(string) $fieldKey] = $fieldVal;
                    }
                    $computedCategories[$catKey] = $row;
                }
            }
            $visibleCategoriesRaw = $this->sessionService->getSessionVar('filter_visible_categories', '');
            $visibleCategories    = is_scalar($visibleCategoriesRaw) ? (string) $visibleCategoriesRaw : '';
            $visibleImagesRaw     = $this->sessionService->getSessionVar('filter_visible_images', '');
            $visibleImages        = is_scalar($visibleImagesRaw) ? (string) $visibleImagesRaw : '';
        }

        if ($this->util->getFilterPageValue('add_notes')) {
            PageState::current()->headerNotes[] = Translator::get()->plural(
                'Photos posted within the last %d day.',
                'Photos posted within the last %d days.',
                $recentPeriod
            );
        }

        FilterContextRegistry::set(new FilterContext(
            enabled:           true,
            recentPeriod:      $recentPeriod,
            categories:        $computedCategories,
            visibleCategories: $visibleCategories,
            visibleImages:     $visibleImages,
        ));
    }
}

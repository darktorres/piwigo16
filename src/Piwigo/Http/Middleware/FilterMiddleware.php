<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Core\PageState;
use Piwigo\Filter\FilterContext;
use Piwigo\Filter\FilterContextRegistry;
use Piwigo\Filter\FilterService;
use Piwigo\Image\ImageRepository;
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
 * Runs after AuthMiddleware so CurrentUser::get() (recent_period, id,
 * cache_update_time) is already populated when filter data is computed.
 */
final readonly class FilterMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CategoryService $categoryService,
        private ImageRepository $imageRepository,
        private SessionService $sessionService,
        private FilterService $filterService,
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

        if (empty(Config::filterPages()) || !$this->filterService->getFilterPageValue('used')) {
            FilterContextRegistry::set(new FilterContext(enabled: false));
            return;
        }

        // ── Determine whether filter is active ────────────────────────────────

        $recentPeriodFromUrl = null;
        $enabled             = false;

        if (!$this->filterService->getFilterPageValue('cancel')) {
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

            $visibleCategories = array_map(
                static fn (mixed $k): int => is_numeric($k) ? (int) $k : 0,
                array_keys($computedCategories)
            );

            $visibleImages = $this->imageRepository->findRecentImageIdsByCategories($visibleCategories, $recentPeriod);
            // Empty result while filter is on: -1 sentinel so `IN (?)` matches nothing.
            if ($visibleCategories === []) {
                $visibleCategories = [-1];
            }
            if ($visibleImages === []) {
                $visibleImages = [-1];
            }

            $this->sessionService->setSessionVar('filter_enabled', true);
            $this->sessionService->setSessionVar('filter_check_key', $filterKey);
            $this->sessionService->setSessionVar('filter_categories', $computedCategories);
            $this->sessionService->setSessionVar('filter_visible_categories', $visibleCategories);
            $this->sessionService->setSessionVar('filter_visible_images', $visibleImages);
        } else {
            $rawCategories      = $this->sessionService->getSessionVar('filter_categories');
            $computedCategories = [];
            if (is_array($rawCategories)) {
                foreach ($rawCategories as $catKey => $catRow) {
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
            $visibleCategoriesRaw = $this->sessionService->getSessionVar('filter_visible_categories');
            $visibleCategories    = is_array($visibleCategoriesRaw)
                ? array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $visibleCategoriesRaw))
                : [];
            $visibleImagesRaw     = $this->sessionService->getSessionVar('filter_visible_images');
            $visibleImages        = is_array($visibleImagesRaw)
                ? array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $visibleImagesRaw))
                : [];
        }

        if ($this->filterService->getFilterPageValue('add_notes')) {
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

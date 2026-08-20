<?php

declare(strict_types=1);

namespace Piwigo\Filter;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilterState;
use Piwigo\Core\FilterUpdaterInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\LayoutState;
use Piwigo\Core\PageFilterHelper;
use Piwigo\Db\NoMatchSentinel;
use Piwigo\Db\SqlDialect;
use Piwigo\Filter\Request\RecentFilterRequest;
use Piwigo\Group\GroupEntity;
use Piwigo\Image\ImageEntity;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;

/**
 * Applies the current request's recent-content filter ($filter['enabled']/
 * $filter['categories'], built from the "start-recent-N" filter or
 * restored from session by initializeFromRequest() below) onto a list
 * of category rows freshly loaded from the DB, overwriting their
 * aggregate columns with the filtered equivalents.
 */
final readonly class FilterService implements FilterUpdaterInterface
{
    public function __construct(
        private FilterState $filterState,
        private SessionService $sessionService,
        private Translator $translator,
        private Lang $lang,
        private CurrentConfig $currentConfig,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * Built from this class's own already-required $entityManager/
     * $currentConfig/$filterState -- PermissionRepository/CategoryRepository
     * need only $entityManager/$currentConfig, GroupRepository is
     * $entityManager's own getRepository() call. $currentUser/
     * $accessLevelChecker aren't constructor properties (this class has no
     * general need for either), so both come in as params from this
     * method's one real caller below, matching Auth\AccessLevelChecker's
     * own "safe to construct eagerly" docblock.
     */
    private function permissionService(CurrentUser $currentUser, AccessLevelChecker $accessLevelChecker): PermissionService
    {
        return new PermissionService(
            new PermissionRepository($this->entityManager),
            $this->entityManager->getRepository(GroupEntity::class),
            new CategoryRepository($this->entityManager, $this->currentConfig),
            $currentUser,
            $this->filterState,
            $accessLevelChecker,
        );
    }

    /**
     * Builds the request's $filter global — the top-level body of the
     * deleted include/filter.inc.php, ported verbatim.
     * Called by Piwigo\Bootstrap\RequestBootstrap::finalize() when
     * \Piwigo\Config\CurrentConfig::filterPages() declares the current page filterable and the
     * 'used' page-filter flag is on, exactly like the old conditional
     * `include` (the caller keeps the `$filter['enabled'] = false;`
     * else-branch, matching the old not-included case, which deliberately
     * skipped this body's own session cleanup else-branch).
     *
     * $filter['enabled']: Filter is enabled
     * $filter['recent_period']: Recent period used to computed filter data
     * $filter['categories']: Computed data of filtered categories
     * $filter['visible_categories']:
     *  List of visible categories (count(visible) < count(forbidden) more often)
     * $filter['visible_images']: List of visible images
     */
    public function initializeFromRequest(LayoutState $layoutState, CurrentUser $currentUser): void
    {
        // $filter is a local scratch array for this method's own body only
        // -- the final computed values are published once, at each of this
        // method's 2 real termination points, to Piwigo\Core\FilterState,
        // the cross-file read target. Genuinely transient/heterogeneous
        // (enabled: bool, recent_period: int, categories: the
        // FilterState-typed rollup, visible_categories/visible_images:
        // string|int, matches: preg_match() captures) -- every field is
        // re-extracted into its own precisely-typed local before being
        // handed to FilterState::set(), so this scratch array's own
        // blanket type is intentionally left loose.
        /** @var array<string, mixed> */
        $filter = [];

        $user = $currentUser->get();

        $recentFilterRequest = RecentFilterRequest::fromGlobals();

        if (! (bool) PageFilterHelper::getFilterPageValue($this->currentConfig, 'cancel')) {
            if ($recentFilterRequest->filterPresent) {
                $filter['matches'] = [];
                $filter_get_param = $recentFilterRequest->filterValue;
                $filter['enabled'] =
                  $filter_get_param !== null
                  && preg_match('/^start-recent-(\d+)$/', $filter_get_param, $filter['matches']) === 1;
            } else {
                $filter['enabled'] = $this->sessionService->getFilterEnabled();
            }
        } else {
            $filter['enabled'] = false;
        }

        if ($filter['enabled']) {
            // Session data is only ever written below by this same method,
            // but guard against a missing/corrupted session value
            // defensively -- getFilterCheckKey() itself returns null for
            // anything that isn't a real array carrying all 4 keys.
            $filter_key = $this->sessionService->getFilterCheckKey() ?? [
                'user' => 0,
                'recent_period' => -1,
                'time' => 0,
                'date' => '',
            ];

            // $filter['matches'] was populated by preg_match()'s by-reference
            // $matches parameter above -- that call re-widens PHPStan's prior
            // narrowing of the array's value type, so re-narrow here.
            $filter_matches = is_array($filter['matches'] ?? null) ? $filter['matches'] : null;
            if ($filter_matches !== null) {
                // matches[1] always exists here: this branch is only reached when
                // $filter['enabled'] was set true via a successful preg_match()
                // above (see the single-capture-group regex), which always
                // populates both matches[0] and matches[1].
                $filter['recent_period'] = $filter_matches[1] ?? null;
            } else {
                $filter['recent_period'] = $filter_key['recent_period'] > 0 ? $filter_key['recent_period'] : $user->rawAttributes['recent_period'];
            }

            // $filter['recent_period'] above comes from an untyped regex capture,
            // the cached session value, or $user['recent_period'] -- all of unknown
            // origin -- narrow once to a definite int for every numeric use below.
            $filter_recent_period = is_numeric($filter['recent_period']) ? (int) $filter['recent_period'] : 0;

            $filter_key_time = is_numeric($filter_key['time']) ? (int) $filter_key['time'] : 0;

            if (
                // New filter
                ! $this->sessionService->getFilterEnabled() or
                // Same 30s staleness budget every other CachePools-backed
                // permission check uses -- long enough to avoid
                // recomputing on every request, short enough that a
                // permission change becomes visible well within one user
                // session.
                time() - $filter_key_time >= 30 or
                // Date, period, user are changed
                $filter_key['user'] !== $user->id->value or
                (is_numeric($filter_key['recent_period']) ? (int) $filter_key['recent_period'] : 0) !== $filter_recent_period or
                (is_string($filter_key['date']) ? $filter_key['date'] : '') !== date('Ymd')
            ) {
                // Need to compute dats
                $filter_key = [
                    'user' => $user->id->value,
                    'recent_period' => $filter_recent_period,
                    'time' => time(),
                    'date' => date('Ymd'),
                ];

                // getComputedCategories() does not mutate its $userdata
                // argument -- it returns the computed 'last_photo_date'
                // alongside the categories, re-synced onto CurrentUser (via
                // withRawAttribute(), the generic escape hatch: User has no
                // named lastPhotoDate property) so every other consumer
                // (e.g. CategoryCatsRenderer) observes the same value.
                $accessLevelChecker = new AccessLevelChecker($currentUser, $this->currentConfig);
                $computedCategories = new CategoryService(
                    $this->lang,
                    new CategoryRepository($this->entityManager, $this->currentConfig),
                    $this->permissionService($currentUser, $accessLevelChecker),
                    $this->currentConfig,
                    $this->eventDispatcher,
                    $this->translator,
                    $accessLevelChecker,
                    new UserRepository($this->entityManager, $this->eventDispatcher, $this->currentConfig)
                )->getComputedCategories($user->id->value, $user->level, $user->forbiddenCategories, $filter_recent_period);
                $filter['categories'] = $computedCategories['categories'];
                $currentUser->set($user->withRawAttribute('last_photo_date', $computedCategories['lastPhotoDate']));

                $filter['visible_categories'] = implode(',', array_keys($filter['categories']));
                if ($filter['visible_categories'] === '') {
                    // Must be not empty
                    $filter['visible_categories'] = NoMatchSentinel::ID;
                }

                // $filter['visible_categories'] is always non-empty here: either a
                // non-empty string (from the implode() above) or the NoMatchSentinel::ID
                // fallback set right above when that implode() was empty.
                $recentPeriodExpr = SqlDialect::getRecentPeriodExpression($filter_recent_period);
                $visibleCategoriesCsv = is_string($filter['visible_categories']) ? $filter['visible_categories'] : (string) $filter['visible_categories'];

                $visible_image_ids = array_map(
                    strval(...),
                    $this->entityManager->getRepository(ImageEntity::class)
                        ->findIdsVisibleInCategoriesRecentlyAvailable($visibleCategoriesCsv, $recentPeriodExpr)
                );
                $filter['visible_images'] = implode(',', $visible_image_ids);

                if ($filter['visible_images'] === '') {
                    // Must be not empty
                    $filter['visible_images'] = NoMatchSentinel::ID;
                }

                // Save filter data on session
                $this->sessionService->setSessionVar('filter_enabled', $filter['enabled']);
                $this->sessionService->setSessionVar('filter_check_key', $filter_key);
                $this->sessionService->setSessionVar('filter_categories', serialize($filter['categories']));
                $this->sessionService->setSessionVar('filter_visible_categories', $filter['visible_categories']);
                $this->sessionService->setSessionVar('filter_visible_images', $filter['visible_images']);
            } else {
                // Read only data
                $serialized_categories = $this->sessionService->getFilterCategoriesSerialized() ?? serialize([]);
                $filter['categories'] = unserialize($serialized_categories);
                $filter['visible_categories'] = $this->sessionService->getFilterVisibleCategories() ?? '';
                $filter['visible_images'] = $this->sessionService->getFilterVisibleImages() ?? '';
            }
            unset($filter_key);
            if ((bool) PageFilterHelper::getFilterPageValue($this->currentConfig, 'add_notes')) {
                $layoutState->addHeaderNote($this->translator->plural(
                    'Photos posted within the last %d day.',
                    'Photos posted within the last %d days.',
                    $filter_recent_period
                ));
            }

            $filter_visible_categories = $filter['visible_categories'];
            $filter_visible_images = $filter['visible_images'];
            // Guards against a corrupted/stale session unserialize() result
            // (see FilterState::$categories' own docblock) -- non-array
            // rows are dropped rather than trusted.
            $filter_categories_raw = $filter['categories'];
            $filter_categories = is_array($filter_categories_raw) ? array_filter($filter_categories_raw, is_array(...)) : [];
            $this->filterState->set(
                true,
                (string) $filter_visible_categories,
                (string) $filter_visible_images,
                $filter_categories
            );
        } else {
            if ($this->sessionService->getFilterEnabled()) {
                $this->sessionService->unsetSessionVar('filter_enabled');
                $this->sessionService->unsetSessionVar('filter_check_key');
                $this->sessionService->unsetSessionVar('filter_categories');
                $this->sessionService->unsetSessionVar('filter_visible_categories');
                $this->sessionService->unsetSessionVar('filter_visible_images');
            }

            $this->filterState->set(false);
        }
    }

    /**
     * Updates data of categories with filtered values. See
     * FilterUpdaterInterface's own docblock for $cats' real shape.
     *
     * @param array<int, array<string, mixed>> $cats
     */
    #[Override]
    public function updateCatsWithFilteredData(array &$cats): void
    {
        // isInitialized() is checked first (not just isEnabled()) to
        // preserve lenient `$filter['enabled'] ?? false` semantics -- a
        // request that never reaches RequestBootstrap::finalize() at all
        // (no FilterState::set() call yet) silently does nothing here.
        if (! $this->filterState->isInitialized() || ! $this->filterState->isEnabled()) {
            return;
        }

        $upd_fields = ['date_last', 'max_date_last', 'count_images', 'count_categories', 'nb_images'];

        $filter_categories = $this->filterState->categories();

        foreach ($cats as $cat_id => $category) {
            $ref_cat_id = $category['id'] ?? null;
            if (! is_int($ref_cat_id) && ! is_string($ref_cat_id)) {
                continue;
            }

            $filter_category = $filter_categories[$ref_cat_id] ?? null;
            if (! is_array($filter_category)) {
                continue;
            }

            foreach ($upd_fields as $upd_field) {
                $cats[$cat_id][$upd_field] = $filter_category[$upd_field] ?? null;
            }
        }
    }
}

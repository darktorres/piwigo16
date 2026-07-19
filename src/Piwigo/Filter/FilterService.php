<?php

declare(strict_types=1);

namespace Piwigo\Filter;

use Doctrine\DBAL\Connection;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Core\FilterUpdaterInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Session\SessionService;

/**
 * Applies the current request's recent-content filter ($filter['enabled']/
 * $filter['categories'], built from the "start-recent-N" filter or
 * restored from session by initializeFromRequest() below) onto a list
 * of category rows freshly loaded from the DB, overwriting their
 * aggregate columns with the filtered equivalents.
 */
final class FilterService implements FilterUpdaterInterface
{
    public function __construct(
        private ?Connection $conn = null,
    ) {}

    /**
     * Builds the request's $filter global — the top-level body of the
     * deleted include/filter.inc.php (P23 sub-batch 8f-5), ported verbatim.
     * Called by Piwigo\Bootstrap\RequestBootstrap::finalize() when
     * \Piwigo\Config\Config::filterPages() declares the current page filterable and the
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
    public function initializeFromRequest(): void
    {
        /**
         * @var array<string, mixed>
         */
        global $filter;

        $currentUser = \Piwigo\Users\CurrentUser::get();

        if (! (bool) \Piwigo\Core\PageFilterHelper::getFilterPageValue('cancel')) {
            if (isset($_GET['filter'])) {
                $filter['matches'] = [];
                $filter_get_param = $_GET['filter'];
                $filter['enabled'] =
                  is_string($filter_get_param)
                  && preg_match('/^start-recent-(\d+)$/', $filter_get_param, $filter['matches']) === 1;
            } else {
                $filter['enabled'] = SessionService::get()->getSessionVar('filter_enabled', false);
            }
        } else {
            $filter['enabled'] = false;
        }

        if ((bool) $filter['enabled']) {
            $filter_key = SessionService::get()->getSessionVar('filter_check_key', [
                'user' => 0,
                'recent_period' => -1,
                'time' => 0,
                'date' => '',
            ]);
            if (
                ! is_array($filter_key)
                || ! isset($filter_key['user'], $filter_key['recent_period'], $filter_key['time'], $filter_key['date'])
            ) {
                // Session data is only ever written below by this same method,
                // but guard against a missing/corrupted session value defensively.
                $filter_key = [
                    'user' => 0,
                    'recent_period' => -1,
                    'time' => 0,
                    'date' => '',
                ];
            }

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
                $filter['recent_period'] = $filter_key['recent_period'] > 0 ? $filter_key['recent_period'] : $currentUser->rawAttributes['recent_period'];
            }

            // $filter['recent_period'] above comes from an untyped regex capture,
            // the cached session value, or $user['recent_period'] -- all of unknown
            // origin -- narrow once to a definite int for every numeric use below.
            $filter_recent_period = is_numeric($filter['recent_period']) ? (int) $filter['recent_period'] : 0;

            if (
                // New filter
                ! (bool) SessionService::get()->getSessionVar('filter_enabled', false) or
                // Cache data updated
                $filter_key['time'] <= $currentUser->cacheUpdateTime or
                // Date, period, user are changed
                $filter_key['user'] !== $currentUser->id or
                $filter_key['recent_period'] != $filter['recent_period'] or
                $filter_key['date'] != date('Ymd')
            ) {
                // Need to compute dats
                $filter_key = [
                    'user' => $currentUser->id,
                    'recent_period' => $filter_recent_period,
                    'time' => time(),
                    'date' => date('Ymd'),
                ];

                $categoryConn = $this->conn ??= DbConnection::build();
                // getComputedCategories() mutates its $userdata argument by
                // reference (sets 'last_photo_date') -- pass the still-live
                // legacy array (dual-write source of truth alongside
                // CurrentUser, see RequestBootstrap) so the mutation is
                // real, then re-sync CurrentUser so every other retargeted
                // consumer (e.g. CategoryCatsRenderer) observes the same
                // value.
                /** @var array<string, mixed> $user */
                global $user;
                $filter['categories'] = new CategoryService(
                    new CategoryRepository($categoryConn),
                    new PermissionService(new PermissionRepository($categoryConn), new GroupRepository($categoryConn))
                )->getComputedCategories($user, $filter_recent_period);
                \Piwigo\Users\CurrentUser::set(\Piwigo\Users\User::fromUserArray($user));

                $filter['visible_categories'] = implode(',', array_keys($filter['categories']));
                if (empty($filter['visible_categories'])) {
                    // Must be not empty
                    $filter['visible_categories'] = -1;
                }

                $query = '
SELECT
  distinct image_id
FROM ' .
  Tables::imageCategory() . ' INNER JOIN ' . Tables::images() . ' ON image_id = id
WHERE ';
                // $filter['visible_categories'] is always non-empty here: either a
                // non-empty string (from the implode() above) or the literal -1
                // fallback set right above when that implode() was empty.
                $query .= '
  category_id  IN (' . $filter['visible_categories'] . ') and';
                $query .= '
    date_available >= ' . SqlDialect::getRecentPeriodExpression($filter_recent_period);

                $visible_image_ids = array_map(
                    static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                    $categoryConn->fetchFirstColumn($query)
                );
                $filter['visible_images'] = implode(',', $visible_image_ids);

                if (empty($filter['visible_images'])) {
                    // Must be not empty
                    $filter['visible_images'] = -1;
                }

                // Save filter data on session
                SessionService::get()->setSessionVar('filter_enabled', $filter['enabled']);
                SessionService::get()->setSessionVar('filter_check_key', $filter_key);
                SessionService::get()->setSessionVar('filter_categories', serialize($filter['categories']));
                SessionService::get()->setSessionVar('filter_visible_categories', $filter['visible_categories']);
                SessionService::get()->setSessionVar('filter_visible_images', $filter['visible_images']);
            } else {
                // Read only data
                $serialized_categories = SessionService::get()->getSessionVar('filter_categories', serialize([]));
                $filter['categories'] = is_string($serialized_categories) ? unserialize($serialized_categories) : [];
                $filter['visible_categories'] = SessionService::get()->getSessionVar('filter_visible_categories', '');
                $filter['visible_images'] = SessionService::get()->getSessionVar('filter_visible_images', '');
            }
            unset($filter_key);
            if ((bool) \Piwigo\Core\PageFilterHelper::getFilterPageValue('add_notes')) {
                \Piwigo\Core\PageState::current()->addHeaderNote(l10n_dec(
                    'Photos posted within the last %d day.',
                    'Photos posted within the last %d days.',
                    $filter_recent_period
                ));
            }
        } else {
            if ((bool) SessionService::get()->getSessionVar('filter_enabled', false)) {
                SessionService::get()->unsetSessionVar('filter_enabled');
                SessionService::get()->unsetSessionVar('filter_check_key');
                SessionService::get()->unsetSessionVar('filter_categories');
                SessionService::get()->unsetSessionVar('filter_visible_categories');
                SessionService::get()->unsetSessionVar('filter_visible_images');
            }
        }
    }

    /**
     * Updates data of categories with filtered values.
     *
     * @param array<int, array<string, mixed>> $cats
     */
    #[\Override]
    public function updateCatsWithFilteredData(array &$cats): void
    {
        /** @var array<string, mixed> $filter */
        global $filter;

        if (! (bool) ($filter['enabled'] ?? false)) {
            return;
        }

        $upd_fields = ['date_last', 'max_date_last', 'count_images', 'count_categories', 'nb_images'];

        // $filter['categories'] is populated either from
        // get_computed_categories() (returns array<int|string, array<string,
        // mixed>>) or from unserialize() of a previously stored copy of that
        // same data (see include/filter.inc.php) -- unserialize() is not
        // provably array-shaped to PHPStan, so narrow defensively at runtime.
        $filter_categories = $filter['categories'] ?? null;
        if (! is_array($filter_categories)) {
            return;
        }

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

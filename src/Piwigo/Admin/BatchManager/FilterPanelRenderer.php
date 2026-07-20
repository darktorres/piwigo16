<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager;

use Piwigo\Category\CategoryRepository;
use Piwigo\Core\Lang;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Template\Template;

/**
 * Folds admin/include/batch_manager_filters.inc.php (the filter-panel body
 * shared by both batch_manager tabs) into a class. Its only 2 real callers
 * (BatchManagerGlobalPageRenderer, BatchManagerUnitPageRenderer) are both
 * ported in this same P23 batch 6g -- unlike admin/include/
 * albums_tab.inc.php's 3rd caller (Piwigo\Controller\Admin\
 * PermalinksSubController, P23 batch 6j-1), which is why that file stayed
 * a raw include while this one is fully folded into a shared class
 * instead.
 *
 * $baseUrl/$collection/$catElementsId/$pageStart are passed explicitly
 * instead of read via `global` -- both callers already compute their own
 * local, narrowed copies of these before calling render(), so this avoids
 * re-narrowing the same $page offsets a second time (the original legacy
 * include did its own independent narrowing of $page['cat_elements_id']/
 * $page['start'], separate from -- but identical in value to -- the
 * narrowing each calling file did again later in its own body).
 */
final class FilterPanelRenderer
{
    /**
     * @param array<mixed> $collection
     * @param array<mixed> $catElementsId
     */
    public function render(
        Template $template,
        string $baseUrl,
        array $collection,
        array $catElementsId,
        int $pageStart,
    ): void {
        $conn = DbConnection::build();

        /** @var array<string, mixed> $bulk_manager_filter */
        $bulk_manager_filter = isset($_SESSION['bulk_manager_filter']) && is_array($_SESSION['bulk_manager_filter']) ? $_SESSION['bulk_manager_filter'] : [];

        $prefilters = [
            [
                'ID' => 'caddie',
                'NAME' => l10n('Caddie'),
            ],
            [
                'ID' => 'favorites',
                'NAME' => l10n('Your favorites'),
            ],
            [
                'ID' => 'last_import',
                'NAME' => l10n('Last import'),
            ],
            [
                'ID' => 'no_album',
                'NAME' => l10n('With no album') . ' (' . l10n('Orphans') . ')',
            ],
            [
                'ID' => 'no_tag',
                'NAME' => l10n('With no tag'),
            ],
            [
                'ID' => 'duplicates',
                'NAME' => l10n('Duplicates'),
            ],
            [
                'ID' => 'all_photos',
                'NAME' => l10n('All'),
            ],
        ];

        if (\Piwigo\Config\Config::enableSynchronization()) {
            $prefilters[] = [
                'ID' => 'no_virtual_album',
                'NAME' => l10n('With no virtual album'),
            ];
            $prefilters[] = [
                'ID' => 'no_sync_md5sum',
                'NAME' => l10n('With no checksum'),
            ];
        }

        $changed_prefilters = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('get_batch_manager_prefilters', $prefilters);

        // Plugins may return anything from this modifier event; only accept a real
        // array of arrays back, otherwise keep the built-in prefilter list above.
        if (is_array($changed_prefilters)) {
            $prefilters = array_filter($changed_prefilters, is_array(...));
        }

        // Sort prefilters by localized name.
        usort($prefilters, self::compareByName(...));

        $template->assign(
            [
                'conf_checksum_compute_blocksize' => \Piwigo\Config\Config::checksumComputeBlocksize(),
                'prefilters' => $prefilters,
                'filter' => $bulk_manager_filter,
                'selection' => $collection,
                'all_elements' => $catElementsId,
                'START' => $pageStart,
                'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
                'U_DISPLAY' => $baseUrl . get_query_string_diff(['display']),
                'F_ACTION' => $baseUrl . get_query_string_diff(['cat', 'start', 'tag', 'filter']),
                'ADMIN_PAGE_TITLE' => l10n('Batch Manager'),
            ]
        );

        // Legacy Coupling Retirement Track A batch A5.2i: the original
        // docblock here mis-described admin/site_update.php (actually
        // Controller\Admin\SiteUpdateSubController, a *reader* of this same
        // value, not a writer) as "the only other writer" -- AdminShell is
        // the sole real writer, computed once per admin request and shared
        // by both this filter panel and SiteUpdateSubController's own
        // save-error notice, hence PageState rather than a per-caller param.
        $no_md5sum_number = \Piwigo\Core\PageState::current()->noMd5sumNumber;
        if ($no_md5sum_number !== null) {
            $template->assign(
                [
                    'NB_NO_MD5SUM' => $no_md5sum_number,
                ]
            );
        } else {
            $template->assign('NB_NO_MD5SUM', '');
        }

        // privacy level
        $available_permission_levels = \Piwigo\Config\Config::availablePermissionLevels();

        $level_options = [];
        foreach ($available_permission_levels as $level) {
            // config_default.inc.php seeds this as [0, 1, 2, 4, 8] (always int); a
            // non-int entry would come from a broken custom config override and has
            // no meaningful privacy level to render.
            if (! is_int($level)) {
                continue;
            }

            $level_options[$level] = l10n(sprintf('Level %d', $level));

            if ($level === 0) {
                $level_options[$level] = l10n('Everybody');
            }
        }
        $template->assign(
            [
                'filter_level_options' => $level_options,
                'filter_level_options_selected' => $bulk_manager_filter['level']
                  ?? 0,
            ]
        );

        // tags
        $filter_tags = [];

        if (is_array($bulk_manager_filter['tags'] ?? null) && count($bulk_manager_filter['tags']) > 0) {
            $filter_tags_ids = array_filter($bulk_manager_filter['tags'], is_scalar(...));

            $query = '
SELECT
    id,
    name
  FROM ' . Tables::tags() . '
  WHERE id IN (' . implode(',', $filter_tags_ids) . ')
;';

            $filter_tags = new TagService(new TagRepository($conn), new PermissionService(new PermissionRepository($conn), new GroupRepository($conn), new CategoryRepository($conn)), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository($conn)))
                ->getTagList($query, new HtmlService());
        }

        $template->assign('filter_tags', $filter_tags);

        // in the filter box, which category to select by default
        $selected_category = null;
        $selected_category_name = '';

        if (isset($bulk_manager_filter['category']) && is_numeric($bulk_manager_filter['category'])) {
            $selected_category = intval($bulk_manager_filter['category']);
            $selected_category_name = new HtmlService()
                ->getCatDisplayNameFromId($selected_category);
        }

        $template->assign('filter_category_selected_name', strip_tags($selected_category_name));
        $template->assign('filter_category_selected', $selected_category);

        // Dissociate from a category : categories listed for dissociation can only
        // represent virtual links. We can't create orphans. Links to physical
        // categories can't be broken.
        $associated_categories = [];

        if (count($catElementsId) > 0) {
            $cat_elements_id_for_sql = array_map(strval(...), array_filter($catElementsId, is_scalar(...)));

            $query = '
SELECT
    DISTINCT(category_id) AS id
  FROM ' . Tables::imageCategory() . ' AS ic
    JOIN ' . Tables::images() . ' AS i ON i.id = ic.image_id
  WHERE ic.image_id IN (' . implode(',', $cat_elements_id_for_sql) . ')
    AND (
      ic.category_id != i.storage_category_id
      OR i.storage_category_id IS NULL
    )
;';

            $associated_categories = array_column($conn->fetchAllAssociative($query), 'id', 'id');
        }

        $template->assign('associated_categories', $associated_categories);

        Lang::load('help_quick_search.lang');
    }

    /**
     * usort()'s callable contract requires accepting any array key type (not
     * just string), even though $prefilters entries are always string-keyed
     * in practice -- narrowing the @param here would make this incompatible
     * with usort's expected callable(array<mixed>, array<mixed>): int shape.
     *
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    private static function compareByName(array $a, array $b): int
    {
        $a_name = is_string($a['NAME']) ? $a['NAME'] : '';
        $b_name = is_string($b['NAME']) ? $b['NAME'] : '';

        return strcmp(strtolower($a_name), strtolower($b_name));
    }
}

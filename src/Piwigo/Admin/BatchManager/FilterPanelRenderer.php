<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Admin\GetBatchManagerPrefilters;
use Piwigo\Html\HtmlService;
use Piwigo\Image\ImageEntity;
use Piwigo\PluginConfig\EventDispatcher;
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
     * $collection is genuinely heterogeneous across its 3 producing
     * branches in the 2 real callers (an all-null placeholder array sized
     * by a photo count, a narrowed list<string> of ids, or a raw
     * $_POST['selection']/session array) -- this method never reads its
     * elements, only hands the whole thing to `$template->assign()` as the
     * 'selection' Smarty variable, so there's no real narrowing to do
     * beyond array<mixed>.
     *
     * @param array<mixed> $collection
     * @param array<array-key, int|string|float|bool> $catElementsId a
     *   scalar-filtered image id set -- see
     *   {@see \Piwigo\Controller\Admin\BatchManagerSubController::computeCurrentSet()}
     */
    public function render(
        Lang $lang,
        Template $template,
        string $baseUrl,
        array $collection,
        array $catElementsId,
        int $pageStart,
        UrlServiceInterface $urlService,
        EventDispatcher $eventDispatcher,
        PageState $pageState,
        TagService $tagService,
        HtmlService $htmlService,
        CurrentConfig $currentConfig,
    ): void {
        $conn = DbConnection::build();

        /** @var array<string, mixed> $bulk_manager_filter */
        $bulk_manager_filter = isset($_SESSION['bulk_manager_filter']) && is_array($_SESSION['bulk_manager_filter']) ? $_SESSION['bulk_manager_filter'] : [];

        $prefilters = [
            [
                'ID' => 'caddie',
                'NAME' => $lang->t('Caddie'),
            ],
            [
                'ID' => 'favorites',
                'NAME' => $lang->t('Your favorites'),
            ],
            [
                'ID' => 'last_import',
                'NAME' => $lang->t('Last import'),
            ],
            [
                'ID' => 'no_album',
                'NAME' => $lang->t('With no album') . ' (' . $lang->t('Orphans') . ')',
            ],
            [
                'ID' => 'no_tag',
                'NAME' => $lang->t('With no tag'),
            ],
            [
                'ID' => 'duplicates',
                'NAME' => $lang->t('Duplicates'),
            ],
            [
                'ID' => 'all_photos',
                'NAME' => $lang->t('All'),
            ],
        ];

        if ($currentConfig->enableSynchronization()) {
            $prefilters[] = [
                'ID' => 'no_virtual_album',
                'NAME' => $lang->t('With no virtual album'),
            ];
            $prefilters[] = [
                'ID' => 'no_sync_md5sum',
                'NAME' => $lang->t('With no checksum'),
            ];
        }

        $prefiltersEvent = $eventDispatcher->dispatchChange(new GetBatchManagerPrefilters($prefilters));

        // A misbehaving third-party handler could still populate this with
        // non-array elements PHP's own type system can't catch at runtime
        // -- only accept a real array of arrays back, otherwise keep the
        // built-in prefilter list above.
        $prefilters = array_filter($prefiltersEvent->prefilters, is_array(...));

        // Sort prefilters by localized name.
        usort($prefilters, self::compareByName(...));

        $template->assign(
            [
                'conf_checksum_compute_blocksize' => $currentConfig->checksumComputeBlocksize(),
                'prefilters' => $prefilters,
                'filter' => $bulk_manager_filter,
                'selection' => $collection,
                'all_elements' => $catElementsId,
                'START' => $pageStart,
                'PWG_TOKEN' => new CsrfService()
                    ->getToken(),
                'U_DISPLAY' => $baseUrl . $urlService->getQueryStringDiff(['display']),
                'F_ACTION' => $baseUrl . $urlService->getQueryStringDiff(['cat', 'start', 'tag', 'filter']),
                'ADMIN_PAGE_TITLE' => $lang->t('Batch Manager'),
            ]
        );

        // Legacy Coupling Retirement Track A batch A5.2i: the original
        // docblock here mis-described admin/site_update.php (actually
        // Controller\Admin\SiteUpdateSubController, a *reader* of this same
        // value, not a writer) as "the only other writer" -- AdminShell is
        // the sole real writer, computed once per admin request and shared
        // by both this filter panel and SiteUpdateSubController's own
        // save-error notice, hence PageState rather than a per-caller param.
        $no_md5sum_number = $pageState->noMd5sumNumber;
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
        $available_permission_levels = $currentConfig->availablePermissionLevels();

        $level_options = [];
        foreach ($available_permission_levels as $level) {
            $level_options[$level] = $lang->t(sprintf('Level %d', $level));

            if ($level === 0) {
                $level_options[$level] = $lang->t('Everybody');
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

            $filter_tags = $tagService
                ->getTagListByIds(
                    array_map(intval(...), array_values($filter_tags_ids)),
                    $htmlService,
                );
        }

        $template->assign('filter_tags', $filter_tags);

        // in the filter box, which category to select by default
        $selected_category = null;
        $selected_category_name = '';

        if (isset($bulk_manager_filter['category']) && is_numeric($bulk_manager_filter['category'])) {
            $selected_category = intval($bulk_manager_filter['category']);
            $selected_category_name = $htmlService
                ->getCatDisplayNameFromId($selected_category);
        }

        $template->assign('filter_category_selected_name', strip_tags($selected_category_name));
        $template->assign('filter_category_selected', $selected_category);

        // Dissociate from a category : categories listed for dissociation can only
        // represent virtual links. We can't create orphans. Links to physical
        // categories can't be broken.
        $associated_categories = [];

        if (count($catElementsId) > 0) {
            $cat_elements_id_for_sql = array_filter($catElementsId, is_scalar(...));

            $associated_categories = array_column(
                EntityManagerFactory::build($conn)->getRepository(ImageEntity::class)
                    ->findVirtuallyAssociatedCategoryRows($cat_elements_id_for_sql),
                'id',
                'id'
            );
        }

        $template->assign('associated_categories', $associated_categories);

        $lang->load('help_quick_search.lang');
    }

    /**
     * $prefilters is only reliably list<array{ID: string, NAME: string}>
     * for this class's own 7-9 built-in entries -- the
     * 'get_batch_manager_prefilters' filter above lets plugins splice in
     * their own entries, only checked for is_array() (not this specific
     * shape), so a real plugin-injected row could carry any array
     * structure. Read defensively, same as any other plugin-extensible
     * list.
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

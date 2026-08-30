<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\BatchManager\Event\GetBatchManagerPrefilters;
use Piwigo\Admin\BatchManager\Projection\BatchManagerPrefilter;
use Piwigo\Admin\BatchManager\Projection\BulkManagerFilter;
use Piwigo\Admin\BatchManager\Projection\FilterPanelPageContext;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\TypedRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageRepository;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tag\TagService;
use Piwigo\Template\Template;

/**
 * Renders the filter panel shared by the batch_manager tabs
 * (BatchManagerGlobalPageRenderer, BatchManagerUnitPageRenderer).
 *
 * $baseUrl/$collection/$catElementsId/$pageStart are passed explicitly
 * instead of read via `global`: both callers already compute their own
 * local, narrowed copies of these before calling render(), so this avoids
 * re-narrowing the same $page offsets a second time.
 */
final class FilterPanelRenderer
{
    /**
     * $collection is genuinely heterogeneous across its 3 producing
     * branches in the 2 real callers (an all-null placeholder array sized
     * by a photo count, a narrowed list<string> of ids, or a raw
     * $_POST['selection']/session array) -- this method never reads its
     * elements, only hands the whole thing to `assignContext()` as the
     * 'selection' Latte variable, so there's no real narrowing to do
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
        EntityManagerInterface $entityManager,
        CsrfService $csrfService,
    ): void {

        /** @var array<string, mixed> $bulk_manager_filter */
        $bulk_manager_filter = isset($_SESSION['bulk_manager_filter']) && is_array($_SESSION['bulk_manager_filter']) ? $_SESSION['bulk_manager_filter'] : [];
        // $bulk_manager_filter itself stays raw array below -- FilterPanelPageContext::$filter
        // is a confirmed boundary (batch_manager_filter.inc.latte drives checkbox/selected
        // state off raw isset($filter['xxx']) key-presence checks for nearly every field).
        $filter = BulkManagerFilter::fromArray($bulk_manager_filter);

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

        if ($currentConfig->enableSynchronization) {
            $prefilters[] = [
                'ID' => 'no_virtual_album',
                'NAME' => $lang->t('With no virtual album'),
            ];
            $prefilters[] = [
                'ID' => 'no_sync_md5sum',
                'NAME' => $lang->t('With no checksum'),
            ];
        }

        $prefiltersEvent = $eventDispatcher->dispatch(new GetBatchManagerPrefilters($prefilters));

        // A misbehaving third-party handler could still populate this with
        // non-array elements PHP's own type system can't catch at runtime,
        // and even a real array row is not guaranteed to carry the
        // ID/NAME strings the built-in entries use -- normalize to the VO
        // and drop whatever cannot supply them, rather than letting a
        // malformed row reach the template as an <option value=""> a user
        // could actually select (P58-B3).
        $prefilters = array_values(array_filter(array_map(
            static fn (mixed $row): ?BatchManagerPrefilter => is_array($row) ? BatchManagerPrefilter::tryFromArray($row) : null,
            $prefiltersEvent->prefilters
        )));

        // Sort prefilters by localized name.
        usort($prefilters, self::compareByName(...));

        // AdminShell is the sole writer of this value, computed once per
        // admin request and shared by both this filter panel and
        // Controller\Admin\SiteUpdateSubController's own save-error notice,
        // hence PageState rather than a per-caller param.
        $no_md5sum_number = $pageState->noMd5sumNumber;
        $nb_no_md5sum = $no_md5sum_number ?? '';

        // privacy level
        $available_permission_levels = $currentConfig->availablePermissionLevels;

        $level_options = [];
        foreach ($available_permission_levels as $level) {
            $level_options[$level] = $lang->t(sprintf('Level %d', $level));

            if ($level === 0) {
                $level_options[$level] = $lang->t('Everybody');
            }
        }
        $filter_level_options_selected = $filter->level ?? 0;

        // tags
        $filter_tags = [];

        if ($filter->tags !== []) {
            $filter_tags = $tagService
                ->getTagListByIds(
                    $filter->tags,
                    $htmlService,
                );
        }

        // in the filter box, which category to select by default
        $selected_category = null;
        $selected_category_name = '';

        if ($filter->category !== null) {
            $selected_category = $filter->category;
            $selected_category_name = $htmlService
                ->getCatDisplayNameFromId($selected_category);
        }

        // Dissociate from a category : categories listed for dissociation can only
        // represent virtual links. We can't create orphans. Links to physical
        // categories can't be broken.
        $associated_categories = [];

        if (count($catElementsId) > 0) {
            $cat_elements_id_for_sql = array_filter($catElementsId, is_scalar(...));

            $associated_categories = array_column(
                TypedRepository::narrow($entityManager->getRepository(ImageEntity::class), ImageRepository::class)
                    ->findVirtuallyAssociatedCategoryRows($cat_elements_id_for_sql),
                'id',
                'id'
            );
        }

        $template->assignContext(new FilterPanelPageContext(
            confChecksumComputeBlocksize: $currentConfig->checksumComputeBlocksize,
            prefilters: $prefilters,
            filter: $filter,
            selection: $collection,
            allElements: $catElementsId,
            start: $pageStart,
            pwgToken: $csrfService
                ->getToken(),
            uDisplay: $baseUrl . $urlService->getQueryStringDiff(['display']),
            fAction: $baseUrl . $urlService->getQueryStringDiff(['cat', 'start', 'tag', 'filter']),
            adminPageTitle: $lang->t('Batch Manager'),
            nbNoMd5sum: $nb_no_md5sum,
            filterLevelOptions: $level_options,
            filterLevelOptionsSelected: $filter_level_options_selected,
            filterTags: $filter_tags,
            filterCategorySelectedName: strip_tags($selected_category_name),
            filterCategorySelected: $selected_category,
            filterSearchQuery: $filter->searchQuery,
            associatedCategories: $associated_categories,
        ));

        $lang->load('help_quick_search.lang');
    }

    /**
     * Sorts by localized name. The defensive `is_string()` narrowing this
     * used to carry moved to {@see BatchManagerPrefilter::tryFromArray()},
     * which is now the single place a plugin-supplied row is validated.
     */
    private static function compareByName(BatchManagerPrefilter $a, BatchManagerPrefilter $b): int
    {
        return strcmp(strtolower($a->name), strtolower($b->name));
    }
}

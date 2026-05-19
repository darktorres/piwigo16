<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager;

use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Csrf\CsrfService;
use Piwigo\Event\Admin\GetBatchManagerPrefilters;
use Piwigo\Html\HtmlService;
use Piwigo\Lang\LangService;
use Piwigo\Session\Session;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class FilterResolver
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private HtmlService $htmlService,
        private TagAdminService $tagAdminService,
        private CsrfService $csrfService,
        private LangService $langService,
        private Session $session,
        private UrlService $urlService,
        private EventDispatcherInterface $dispatcher,
    ) {
    }
    /**
     * @param array<mixed>  $collection
     * @param list<string>  $catElementsId
     */
    public function render(array $collection, string $baseUrl, array $catElementsId = [], int $start = 0): void
    {
        $tpl = TemplateRegistry::current();

        $prefilters = [
            ['ID' => 'caddie', 'NAME' => Lang::t('Caddie')],
            ['ID' => 'favorites', 'NAME' => Lang::t('Your favorites')],
            ['ID' => 'last_import', 'NAME' => Lang::t('Last import')],
            ['ID' => 'no_album', 'NAME' => Lang::t('With no album') . ' (' . Lang::t('Orphans') . ')'],
            ['ID' => 'no_tag', 'NAME' => Lang::t('With no tag')],
            ['ID' => 'duplicates', 'NAME' => Lang::t('Duplicates')],
            ['ID' => 'all_photos', 'NAME' => Lang::t('All')],
        ];

        if (Config::enableSynchronization()) {
            $prefilters[] = ['ID' => 'no_virtual_album', 'NAME' => Lang::t('With no virtual album')];
            $prefilters[] = ['ID' => 'no_sync_md5sum', 'NAME' => Lang::t('With no checksum')];
        }

        $prefilterEvent = new GetBatchManagerPrefilters($prefilters);
        $this->dispatcher->dispatch($prefilterEvent);
        /** @var list<array<string, string>> $prefilters */
        $prefilters = $prefilterEvent->prefilters;

        usort($prefilters, fn (array $a, array $b): int => strcmp(strtolower((string) $a['NAME']), strtolower((string) $b['NAME'])));

        $bulk_manager_filter = $this->session->bulkManagerFilter ?? [];

        $tpl->assign([
            'conf_checksum_compute_blocksize' => Config::checksumComputeBlocksize(),
            'prefilters' => $prefilters,
            'filter' => $bulk_manager_filter,
            'selection' => $collection,
            'all_elements' => $catElementsId,
            'START' => $start,
            'PWG_TOKEN' => $this->csrfService->getToken(),
            'U_DISPLAY' => $baseUrl . $this->urlService->getQueryStringDiff(['display']),
            'F_ACTION' => $baseUrl . $this->urlService->getQueryStringDiff(['cat', 'start', 'tag', 'filter']),
            'ADMIN_PAGE_TITLE' => Lang::t('Batch Manager'),
        ]);

        $level_options = [];
        foreach (Config::availablePermissionLevels() as $level) {
            $level_options[$level] = Lang::t(sprintf('Level %d', $level));
            if (0 == $level) {
                $level_options[$level] = Lang::t('Everybody');
            }
        }
        $tpl->assign([
            'filter_level_options' => $level_options,
            'filter_level_options_selected' => $bulk_manager_filter['level'] ?? 0,
        ]);

        $filter_tags = [];
        $filter_tags_raw = $bulk_manager_filter['tags'] ?? null;
        if ($filter_tags_raw !== null && is_array($filter_tags_raw) && count($filter_tags_raw) > 0) {
            $tagIds      = array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $filter_tags_raw));
            $filter_tags = $this->tagAdminService->getTaglistForIds($tagIds);
        }
        $tpl->assign('filter_tags', $filter_tags);

        $selected_category = null;
        $selected_category_name = '';
        $filterCategory = $bulk_manager_filter['category'] ?? null;
        if (isset($filterCategory)) {
            $selected_category = is_numeric($filterCategory) ? (int) $filterCategory : 0;
            $selected_category_name = $this->htmlService->getCatDisplayNameFromId($selected_category);
        }
        $tpl->assign('filter_category_selected_name', strip_tags($selected_category_name));
        $tpl->assign('filter_category_selected', $selected_category);

        $associated_categories = [];
        if (count($catElementsId) > 0) {
            $imageIdsInt = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $catElementsId);
            $associated_categories = $this->categoryRepository->findDistinctVirtualAssociatedCategoryIds($imageIdsInt);
        }
        $tpl->assign('associated_categories', $associated_categories);

        $this->langService->loadLanguage('help_quick_search.lang');
    }
}

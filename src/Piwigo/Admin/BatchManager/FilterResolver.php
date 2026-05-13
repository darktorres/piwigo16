<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Core\Util;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Lang\LangService;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;

final class FilterResolver
{
    public function __construct(
        private readonly Connection $conn,
        private readonly HtmlService $htmlService,
        private readonly TagAdminService $tagAdminService,
        private readonly Util $util,
    ) {
    }
    /**
     * @param array<mixed> $collection
     */
    public function render(array $collection, string $baseUrl): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];

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

        /** @var list<array<string, string>> $prefilters */
        $prefilters = EventDispatcher::dispatch('get_batch_manager_prefilters', $prefilters);

        usort($prefilters, fn (array $a, array $b): int => strcmp(strtolower((string) $a['NAME']), strtolower((string) $b['NAME'])));

        $bulk_manager_filter = is_array($_SESSION['bulk_manager_filter'] ?? null) ? $_SESSION['bulk_manager_filter'] : [];
        /** @var array<mixed> $catElementsId */
        $catElementsId = is_array($page['cat_elements_id'] ?? null) ? $page['cat_elements_id'] : [];
        $start = is_int($page['start'] ?? null) ? $page['start'] : 0;

        $tpl->assign([
            'conf_checksum_compute_blocksize' => Config::checksumComputeBlocksize(),
            'prefilters' => $prefilters,
            'filter' => $bulk_manager_filter,
            'selection' => $collection,
            'all_elements' => $catElementsId,
            'START' => $start,
            'PWG_TOKEN' => $this->util->getPwgToken(),
            'U_DISPLAY' => $baseUrl . UrlService::get()->getQueryStringDiff(['display']),
            'F_ACTION' => $baseUrl . UrlService::get()->getQueryStringDiff(['cat', 'start', 'tag', 'filter']),
            'ADMIN_PAGE_TITLE' => Lang::t('Batch Manager'),
        ]);

        if (isset($page['no_md5sum_number'])) {
            $tpl->assign(['NB_NO_MD5SUM' => $page['no_md5sum_number']]);
        } else {
            $tpl->assign('NB_NO_MD5SUM', '');
        }

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
            $query = '
SELECT
    id,
    name
  FROM ' . Tables::tags() . '
  WHERE id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $filter_tags_raw)) . ')
;';
            $filter_tags = $this->tagAdminService->getTaglist($query);
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
            $query = '
SELECT
    DISTINCT(category_id) AS id
  FROM ' . Tables::imageCategory() . ' AS ic
    JOIN ' . Tables::images() . ' AS i ON i.id = ic.image_id
  WHERE ic.image_id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $catElementsId)) . ')
    AND (
      ic.category_id != i.storage_category_id
      OR i.storage_category_id IS NULL
    )
;';
            $associated_categories = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'id', 'id');
        }
        $tpl->assign('associated_categories', $associated_categories);

        LangService::get()->loadLanguage('help_quick_search.lang');
    }
}

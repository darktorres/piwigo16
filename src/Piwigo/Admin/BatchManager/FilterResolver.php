<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager;

use Piwigo\Template\TemplateRegistry;

final class FilterResolver
{
    /**
     * @param array<mixed> $collection
     */
    public function render(array $collection, string $baseUrl): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];

        $prefilters = [
            ['ID' => 'caddie', 'NAME' => l10n('Caddie')],
            ['ID' => 'favorites', 'NAME' => l10n('Your favorites')],
            ['ID' => 'last_import', 'NAME' => l10n('Last import')],
            ['ID' => 'no_album', 'NAME' => l10n('With no album') . ' (' . l10n('Orphans') . ')'],
            ['ID' => 'no_tag', 'NAME' => l10n('With no tag')],
            ['ID' => 'duplicates', 'NAME' => l10n('Duplicates')],
            ['ID' => 'all_photos', 'NAME' => l10n('All')],
        ];

        if (\Piwigo\Config\Config::enableSynchronization()) {
            $prefilters[] = ['ID' => 'no_virtual_album', 'NAME' => l10n('With no virtual album')];
            $prefilters[] = ['ID' => 'no_sync_md5sum', 'NAME' => l10n('With no checksum')];
        }

        /** @var list<array<string, string>> $prefilters */
        $prefilters = trigger_change('get_batch_manager_prefilters', $prefilters);

        usort($prefilters, fn (array $a, array $b): int => strcmp(strtolower((string) $a['NAME']), strtolower((string) $b['NAME'])));

        $bulk_manager_filter = is_array($_SESSION['bulk_manager_filter'] ?? null) ? $_SESSION['bulk_manager_filter'] : [];
        /** @var array<mixed> $catElementsId */
        $catElementsId = is_array($page['cat_elements_id'] ?? null) ? $page['cat_elements_id'] : [];
        $start = is_int($page['start'] ?? null) ? $page['start'] : 0;

        $tpl->assign([
            'conf_checksum_compute_blocksize' => \Piwigo\Config\Config::checksumComputeBlocksize(),
            'prefilters' => $prefilters,
            'filter' => $bulk_manager_filter,
            'selection' => $collection,
            'all_elements' => $catElementsId,
            'START' => $start,
            'PWG_TOKEN' => get_pwg_token(),
            'U_DISPLAY' => $baseUrl . get_query_string_diff(['display']),
            'F_ACTION' => $baseUrl . get_query_string_diff(['cat', 'start', 'tag', 'filter']),
            'ADMIN_PAGE_TITLE' => l10n('Batch Manager'),
        ]);

        if (isset($page['no_md5sum_number'])) {
            $tpl->assign(['NB_NO_MD5SUM' => $page['no_md5sum_number']]);
        } else {
            $tpl->assign('NB_NO_MD5SUM', '');
        }

        $level_options = [];
        foreach (\Piwigo\Config\Config::availablePermissionLevels() as $level) {
            $level_options[$level] = l10n(sprintf('Level %d', $level));
            if (0 == $level) {
                $level_options[$level] = l10n('Everybody');
            }
        }
        $tpl->assign([
            'filter_level_options' => $level_options,
            'filter_level_options_selected' => $bulk_manager_filter['level'] ?? 0,
        ]);

        $filter_tags = [];
        $filter_tags_raw = $bulk_manager_filter['tags'] ?? null;
        if (!empty($filter_tags_raw) && is_array($filter_tags_raw)) {
            $query = '
SELECT
    id,
    name
  FROM ' . TAGS_TABLE . '
  WHERE id IN (' . implode(',', array_map(fn ($v) => is_scalar($v) ? (string) $v : '0', $filter_tags_raw)) . ')
;';
            $filter_tags = get_taglist($query);
        }
        $tpl->assign('filter_tags', $filter_tags);

        $selected_category = null;
        $selected_category_name = '';
        $filterCategory = $bulk_manager_filter['category'] ?? null;
        if (isset($filterCategory)) {
            $selected_category = is_numeric($filterCategory) ? (int) $filterCategory : 0;
            $selected_category_name = get_cat_display_name_from_id($selected_category);
        }
        $tpl->assign('filter_category_selected_name', strip_tags($selected_category_name));
        $tpl->assign('filter_category_selected', $selected_category);

        $associated_categories = [];
        if (count($catElementsId) > 0) {
            $query = '
SELECT
    DISTINCT(category_id) AS id
  FROM ' . IMAGE_CATEGORY_TABLE . ' AS ic
    JOIN ' . IMAGES_TABLE . ' AS i ON i.id = ic.image_id
  WHERE ic.image_id IN (' . implode(',', array_map(fn ($v): string => is_scalar($v) ? (string) $v : '0', $catElementsId)) . ')
    AND (
      ic.category_id != i.storage_category_id
      OR i.storage_category_id IS NULL
    )
;';
            $associated_categories = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id', 'id');
        }
        $tpl->assign('associated_categories', $associated_categories);

        load_language('help_quick_search.lang');
    }
}

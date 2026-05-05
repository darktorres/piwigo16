<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Config\Config;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageStdParams;
use Piwigo\Template\TemplateRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the gallery index page: categories, thumbnails, tags, search,
 * favorites, recent, best-rated, most-visited, and calendar views.
 *
 * Corresponds to the former index.php entry-point (lines 26-405).
 * Reads the bootstrapped globals via $GLOBALS; section routing is done by
 * include/section_init.inc.php (not yet migrated to a typed SectionInitializer).
 */
final class GalleryController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        // Populate $GLOBALS['page'], $GLOBALS['user'], $GLOBALS['filter'] from URL tokens
        require PHPWG_ROOT_PATH . 'include/section_init.inc.php';

        check_status(ACCESS_GUEST);

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        // Extract commonly-used typed locals to avoid repeated mixed-type access
        $items       = is_array($page['items'] ?? null) ? $page['items'] : [];
        $start       = is_scalar($page['start'] ?? null) ? (int) $page['start'] : 0;
        $nbImagePage = is_scalar($page['nb_image_page'] ?? null) ? (int) $page['nb_image_page'] : 0;
        $section     = is_string($page['section'] ?? null) ? $page['section'] : 'categories';
        $category    = is_array($page['category'] ?? null) ? $page['category'] : null;
        $catId       = $category !== null && is_scalar($category['id'] ?? null) ? (int) $category['id'] : 0;
        $countCats   = $category !== null && is_scalar($category['count_categories'] ?? null)
            ? (int) $category['count_categories'] : null;

        if ($category !== null) {
            check_restrictions($catId);
        }
        if ($start > 0 && $start >= count($items)) {
            page_not_found('', duplicate_index_url(['start' => 0]));
        }

        trigger_notify('loc_begin_index');

        // Image display-order change
        $imageOrder = input_int('image_order', null, $_GET);
        if ($imageOrder !== null) {
            if ($imageOrder > 0) {
                pwg_set_session_var('image_order', $imageOrder);
            } else {
                pwg_unset_session_var('image_order');
            }
            redirect(duplicate_index_url([], ['start']));
        }

        $display = input_string('display', null, $_GET);
        if ($display !== null) {
            $metaRobots             = is_array($page['meta_robots'] ?? null) ? $page['meta_robots'] : [];
            $metaRobots['noindex']  = 1;
            $page['meta_robots']    = $metaRobots;
            if (array_key_exists($display, ImageStdParams::get_defined_type_map())) {
                pwg_set_session_var('index_deriv', $display);
            }
        }

        $tpl = TemplateRegistry::current();

        // Navigation bar
        $page['navigation_bar'] = [];
        if (count($items) > $nbImagePage) {
            $page['navigation_bar'] = create_navigation_bar(
                duplicate_index_url([], ['start']),
                count($items),
                $start,
                $nbImagePage,
                true,
                'start'
            );
        }
        $tpl->assign('thumb_navbar', $page['navigation_bar']);

        // Caddie filling
        if (input_string('caddie', null, $_GET) !== null) {
            fill_caddie(array_map(static fn (mixed $i): int => is_scalar($i) ? (int) $i : 0, $items));
            redirect(duplicate_index_url());
        }

        // Canonical URL
        if (isset($page['is_homepage']) && $page['is_homepage']) {
            $canonicalUrl = get_gallery_home_url();
        } else {
            $safeStart = $nbImagePage > 0 ? (int) ($nbImagePage * round($start / $nbImagePage)) : 0;
            if ($safeStart > 0 && $safeStart >= count($items)) {
                $safeStart -= $nbImagePage;
            }
            $canonicalUrl = duplicate_index_url(['start' => $safeStart]);
        }
        $tpl->assign('U_CANONICAL', $canonicalUrl);
        $tpl->assign('use_standard_pages', conf_get_param('use_standard_pages', false));

        // Page title
        $tpl->assign('TITLE', is_string($page['section_title'] ?? null) ? $page['section_title'] : '');
        $tpl->assign('NB_ITEMS', count($items));

        // Menubar
        require PHPWG_ROOT_PATH . 'include/menubar.inc.php';

        $tpl->set_filename('index', 'index.tpl');

        if (empty($page['is_external'])) {
            $page['body_id'] = 'theCategoryPage';

            if (isset($page['flat']) || isset($page['chronology_field'])) {
                $tpl->assign('U_MODE_NORMAL', duplicate_index_url([], ['chronology_field', 'start', 'flat']));
            }

            if (Config::indexFlatIcon() && !isset($page['flat']) && $section === 'categories') {
                $tpl->assign('U_MODE_FLAT', duplicate_index_url(['flat' => ''], ['start', 'chronology_field']));
            }

            if (!isset($page['chronology_field'])) {
                $chronoParams = [
                    'chronology_field' => 'created',
                    'chronology_style' => 'monthly',
                    'chronology_view'  => 'list',
                ];
                if (Config::indexCreatedDateIcon()) {
                    $tpl->assign('U_MODE_CREATED', duplicate_index_url($chronoParams, ['start', 'flat']));
                }
                if (Config::indexPostedDateIcon()) {
                    $chronoParams['chronology_field'] = 'posted';
                    $tpl->assign('U_MODE_POSTED', duplicate_index_url($chronoParams, ['start', 'flat']));
                }
            } else {
                $chronoField = is_string($page['chronology_field']) && $page['chronology_field'] === 'created'
                    ? 'posted' : 'created';
                if (Config::raw('index_' . $chronoField . '_date_icon')) {
                    $url = duplicate_index_url(
                        ['chronology_field' => $chronoField],
                        ['chronology_date', 'start', 'flat']
                    );
                    $tpl->assign('U_MODE_' . strtoupper($chronoField), $url);
                }
            }

            require PHPWG_ROOT_PATH . 'include/search_filters.inc.php';

            if ($section === 'categories' && $category !== null && !isset($page['combined_categories'])) {
                $tpl->assign([
                    'SEARCH_IN_SET_BUTTON' => Config::indexSearchInSetButton(),
                    'SEARCH_IN_SET_ACTION' => Config::indexSearchInSetAction(),
                    'SEARCH_IN_SET_URL'    => get_root_url() . 'search.php?cat_id=' . $catId,
                ]);
            }

            // Tag-related context (tags page only)
            $bodyDataArr = is_array($page['body_data'] ?? null) ? $page['body_data'] : [];
            if (is_array($bodyDataArr['tag_ids'] ?? null)) {
                $pageTagIds = is_array($page['tag_ids'] ?? null)
                    ? array_map(static fn (mixed $i): int => is_scalar($i) ? (int) $i : 0, $page['tag_ids'])
                    : [];
                $pageTags = is_array($page['tags'] ?? null) ? $page['tags'] : [];

                $tags        = get_common_tags($items, Config::menubarTagCloudItemsNumber(), $pageTagIds);
                $tags        = add_level_to_tags($tags);
                $relatedTags = [];
                foreach ($tags as $tag) {
                    $tagArr = is_array($tag) ? $tag : [];
                    $relatedTags[] = array_merge($tagArr, [
                        'U_ADD' => make_index_url(['tags' => array_merge($pageTags, [$tag])]),
                        'URL'   => make_index_url(['tags' => [$tag]]),
                    ]);
                }
                usort($relatedTags, static fn (array $a, array $b): int =>
                    (is_numeric($b['counter'] ?? null) ? (int) $b['counter'] : 0)
                    <=> (is_numeric($a['counter'] ?? null) ? (int) $a['counter'] : 0)
                );

                require_once PHPWG_ROOT_PATH . 'include/selected_tags.inc.php';

                $tagIds = $bodyDataArr['tag_ids'];
                $tpl->assign([
                    'SEARCH_IN_SET_BUTTON' => Config::indexSearchInSetButton(),
                    'SEARCH_IN_SET_ACTION' => Config::indexSearchInSetAction(),
                    'SEARCH_IN_SET_URL'    => get_root_url() . 'search.php?tag_id='
                        . implode(',', array_map(static fn (mixed $id): int => is_scalar($id) ? (int) $id : 0, $tagIds)),
                    'COMBINABLE_TAGS' => $relatedTags,
                ]);
            }

            if ($category !== null && is_admin() && Config::indexEditIcon()) {
                $tpl->assign('U_EDIT', get_root_url() . 'admin.php?page=album-' . $catId);
            }

            if (is_admin() && !empty($items) && Config::indexCaddieIcon()) {
                $tpl->assign('U_CADDIE', add_url_params(duplicate_index_url(), ['caddie' => 1]));
            }

            // Search results hints
            if ($section === 'search' && $start === 0 && !isset($page['chronology_field']) && isset($page['qsearch_details'])) {
                $qd   = is_array($page['qsearch_details']) ? $page['qsearch_details'] : [];
                $cats = array_merge(
                    is_array($qd['matching_cats_no_images'] ?? null) ? $qd['matching_cats_no_images'] : [],
                    is_array($qd['matching_cats'] ?? null) ? $qd['matching_cats'] : []
                );
                if (count($cats) > 0) {
                    usort($cats, static fn (mixed $a, mixed $b): int => name_compare(
                        is_array($a) ? $a : [],
                        is_array($b) ? $b : []
                    ));
                    $hints = [];
                    foreach ($cats as $cat) {
                        $hints[] = get_cat_display_name([$cat], '');
                    }
                    $tpl->assign('category_search_results', $hints);
                }
                $searchTags = is_array($qd['matching_tags'] ?? null) ? $qd['matching_tags'] : [];
                foreach ($searchTags as $tag) {
                    if (!is_array($tag)) {
                        continue;
                    }
                    $tag['URL'] = make_index_url(['tags' => [$tag]]);
                    $tpl->append('tag_search_results', $tag);
                }
                if (empty($items)) {
                    $tpl->append('no_search_results', htmlspecialchars(is_scalar($qd['q'] ?? null) ? (string) $qd['q'] : ''));
                } elseif (!empty($qd['unmatched_terms'])) {
                    $unmatched = is_array($qd['unmatched_terms']) ? $qd['unmatched_terms'] : [];
                    $tpl->assign('no_search_results', array_map(
                        static fn (mixed $t): string => htmlspecialchars(is_scalar($t) ? (string) $t : ''),
                        $unmatched
                    ));
                }
            }

            // Image-order selector
            if (Config::indexSortOrderInput() && count($items) > 0 && $section !== 'most_visited' && $section !== 'best_rated') {
                $preferredOrders = get_category_preferred_image_orders();
                $orderIdx        = (int) pwg_get_session_var('image_order', 0);
                $firstOrder      = trim(substr((string) Config::orderBy(), 9));
                if (($pos = strpos($firstOrder, ',')) !== false) {
                    $firstOrder = substr($firstOrder, 0, $pos);
                }
                $firstOrder    = trim($firstOrder);
                $url           = add_url_params(duplicate_index_url(), ['image_order' => '']);
                $tplOrders     = [];
                $orderSelected = false;
                foreach ($preferredOrders as $orderId => $order) {
                    if (!is_array($order) || !$order[2]) {
                        continue;
                    }
                    if (!$orderSelected && $order[1] === $firstOrder) {
                        $orderIdx      = (int) $orderId;
                        $orderSelected = true;
                    }
                    $tplOrders[(int) $orderId] = [
                        'DISPLAY'  => $order[0],
                        'URL'      => $url . $orderId,
                        'SELECTED' => $orderIdx === (int) $orderId,
                    ];
                }
                $tplOrders[0]['SELECTED'] = !$orderSelected;
                $tpl->assign('image_orders', $tplOrders);
            }

            // Category description
            if (($start === 0 || Config::albumDescriptionOnAllPages())
                && !isset($page['chronology_field'])
                && !empty($page['comment'])
            ) {
                $tpl->assign('CONTENT_DESCRIPTION', $page['comment']);
            }

            if ($countCats === 0) {
                $tpl->clear_assign('U_MODE_FLAT');
            }

            // Sub-category grid
            if ($start === 0
                && !isset($page['flat'])
                && !isset($page['chronology_field'])
                && ($section === 'recent_cats' || $section === 'categories')
                && ($countCats === null || $countCats > 0)
            ) {
                require PHPWG_ROOT_PATH . 'include/category_cats.inc.php';
            }

            if (!empty($items)) {
                require PHPWG_ROOT_PATH . 'include/category_default.inc.php';

                if (Config::indexSizesIcon()) {
                    $url        = add_url_params(duplicate_index_url(), ['display' => '']);
                    $derivObj   = $tpl->get_template_vars('derivative_params');
                    $selType    = is_object($derivObj) ? (string) ($derivObj->type ?? '') : '';
                    $tpl->clear_assign('derivative_params');
                    $typeMap = ImageStdParams::get_defined_type_map();
                    unset($typeMap[IMG_XXLARGE], $typeMap[IMG_XLARGE]);
                    foreach ($typeMap as $params) {
                        $tpl->append('image_derivatives', [
                            'DISPLAY'  => l10n($params->type),
                            'URL'      => $url . $params->type,
                            'SELECTED' => $params->type === $selType,
                        ]);
                    }
                }
            }

            // Slideshow
            if (!empty($page['cat_slideshow_url'])) {
                $slideshowUrl = is_string($page['cat_slideshow_url']) ? $page['cat_slideshow_url'] : '';
                if (input_string('slideshow', null, $_GET) !== null) {
                    redirect($slideshowUrl);
                } elseif (Config::indexSlideShowIcon()) {
                    $tpl->assign('U_SLIDESHOW', $slideshowUrl);
                }
            }

            // Related tags (for non-tags sections)
            if (!empty($items) && ($bodyDataArr['section'] ?? '') !== 'tags') {
                $selection  = array_slice($items, $start, $nbImagePage);
                $commonTags = add_level_to_tags(get_common_tags($selection, Config::contentTagCloudItemsNumber()));
                $relTags    = [];
                foreach ($commonTags as $tag) {
                    $relTags[] = array_merge(is_array($tag) ? $tag : [], [
                        'URL' => make_index_url(['tags' => [$tag]]),
                    ]);
                }
                $tpl->assign([
                    'RELATED_TAGS_ACTION' => !empty($relTags),
                    'RELATED_TAGS'        => $relTags,
                ]);
            }
        }

        // Render page (outputs directly — legacy Smarty model)
        require PHPWG_ROOT_PATH . 'include/page_header.php';
        trigger_notify('loc_end_index');
        flush_page_messages();
        $tpl->parse_index_buttons();
        $tpl->pparse('index');

        pwg_log();
        require PHPWG_ROOT_PATH . 'include/page_tail.php';

        return ResponseFactory::create(200);
    }
}

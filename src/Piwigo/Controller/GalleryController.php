<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Category\CategoryCatsRenderer;
use Piwigo\Category\CategoryDefaultRenderer;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Core\AccessLevel;
use Piwigo\Db\DbConnection;
use Piwigo\Filter\FilterService;
use Piwigo\Group\GroupRepository;
use Piwigo\History\HistoryRepository;
use Piwigo\History\HistoryService;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageStdParams;
use Piwigo\Mail\MailService;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Search\SearchFilterRenderer;
use Piwigo\Section\SectionPopulator;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Template\Template;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces index.php -- the main gallery browsing page (categories,
 * thumbnails, search results, calendar, tags, etc.), picking up
 * P20's SectionInitializer's own deferred note ("the $page/$template
 * population half stays procedural, P22 scope") -- that half was absorbed
 * into Piwigo\Section\SectionPopulator in P23 batch 4d.
 *
 * Unlike every other P22 controller, SectionPopulator::populate() must run
 * BEFORE check_status() -- check_restrictions() (called right after)
 * depends on $page['category'] already being populated. populate()
 * declares its own `global $page, $conf, ...;` at its top level, so it
 * binds to the real $GLOBALS table correctly regardless of nesting depth.
 *
 * check_status()/check_restrictions()/page_not_found() all stay outside the
 * captured closure. Everything else -- including the interleaved
 * image_order/caddie/slideshow redirect() calls -- is bundled into one
 * closure matching TagsController's precedent for this page shape: none of
 * those redirects happen after any real echo (page_header.php isn't
 * included until the very end), so PHP's documented output-buffer-auto-
 * flush-on-exit() behavior makes wrapping them safe, and it avoids having
 * to thread several dozen interdependent local variables across a manual
 * split point.
 */
final class GalleryController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /**
         * @var array<string, mixed>
         */
        global $conf;
        /**
         * @var array<string, mixed>
         */
        global $page;
        $template = \Piwigo\Template\CurrentTemplate::get();

        new SectionPopulator(new MailService(), new HtmlService(), $template)
            ->populate();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Guest);

        // Real invariant: $page['items'] always holds a list of numeric
        // image ids (array_from_query()/\Piwigo\Db\MysqliDb::query2Array() single-column
        // results in SectionPopulator::populate()), possibly as numeric
        // strings coming straight from the database.
        $page_items = [];
        if (is_array($page['items'])) {
            foreach ($page['items'] as $page_item_id) {
                if (is_numeric($page_item_id)) {
                    $page_items[] = (int) $page_item_id;
                }
            }
        }

        // Real invariant: $page['start'] is either the int 0 default set
        // in SectionPopulator::populate(), or a numeric string captured
        // from the URL (see the preg_match capture group in
        // include/functions_url.inc.php).
        $page_start = is_numeric($page['start']) ? (int) $page['start'] : 0;

        // Real invariant: $page['nb_image_page'] comes from
        // $user['nb_image_page'], a numeric value read from the database
        // (see SectionPopulator::populate()).
        $page_nb_image_page = is_numeric($page['nb_image_page']) ? (int) $page['nb_image_page'] : 0;

        // access authorization check
        if (isset($page['category']) && is_array($page['category']) && is_numeric($page['category']['id'] ?? null)) {
            $categoryConn = DbConnection::build();
            new CategoryService(
                new CategoryRepository($categoryConn),
                new PermissionService(new PermissionRepository($categoryConn), new GroupRepository($categoryConn))
            )->checkRestrictions((int) $page['category']['id'], new HtmlService());
        }
        if ($page_start > 0 && $page_start >= count($page_items)) {
            new HtmlService()
                ->pageNotFound('', duplicate_index_url([
                    'start' => 0,
                ]));
        }

        $body = LegacyRenderCapture::capture(static function () use ($page_items, $page_start, $page_nb_image_page): void {
            /**
             * @var array<string, mixed>
             */
            global $conf;
            /**
             * @var array<string, mixed>
             */
            global $page;
            global $title;
            $template = \Piwigo\Template\CurrentTemplate::get();

            $tagConn = DbConnection::build();
            $tagService = new TagService(new TagRepository($tagConn), new PermissionService(new PermissionRepository($tagConn), new GroupRepository($tagConn)), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())));
            $categoryService = new CategoryService(new CategoryRepository($tagConn), new PermissionService(new PermissionRepository($tagConn), new GroupRepository($tagConn)));

            trigger_notify('loc_begin_index');

            // ---------------------------------------- change of image display order
            if (isset($_GET['image_order'])) {
                if (is_numeric($_GET['image_order']) && (int) $_GET['image_order'] > 0) {
                    SessionService::get()->setSessionVar('image_order', (int) $_GET['image_order']);
                } else {
                    SessionService::get()->unsetSessionVar('image_order');
                }
                redirect(
                    duplicate_index_url(
                        [],        // nothing to redefine
                        ['start']  // changing display order goes back to section first page
                    )
                );
            }
            if (isset($_GET['display'])) {
                if (! isset($page['meta_robots']) || ! is_array($page['meta_robots'])) {
                    $page['meta_robots'] = [];
                }
                $page['meta_robots']['noindex'] = 1;
                if (is_string($_GET['display']) && array_key_exists($_GET['display'], ImageStdParams::get_defined_type_map())) {
                    SessionService::get()->setSessionVar('index_deriv', $_GET['display']);
                }
            }

            // -------------------------------------------------- initialization
            // navigation bar
            $page['navigation_bar'] = [];
            if (count($page_items) > $page_nb_image_page) {
                $page['navigation_bar'] = (new \Piwigo\Core\PaginationService())->createNavigationBar(duplicate_index_url([], ['start']), count($page_items), $page_start, $page_nb_image_page, true, 'start');
            }

            $template->assign('thumb_navbar', $page['navigation_bar']);

            // caddie filling :-)
            if (isset($_GET['caddie'])) {
                \Piwigo\Caddie\CaddieService::fillCurrentUserCaddie($page_items);
                redirect(duplicate_index_url());
            }

            if (isset($page['is_homepage']) and (bool) $page['is_homepage']) {
                $canonical_url = get_gallery_home_url();
            } else {
                $start = $page_nb_image_page * round($page_start / $page_nb_image_page);
                if ($start > 0 && $start >= count($page_items)) {
                    $start -= $page_nb_image_page;
                }
                $canonical_url = duplicate_index_url([
                    'start' => $start,
                ]);
            }
            $template->assign('U_CANONICAL', $canonical_url);

            // Standard Pages
            // Some themes will want to use standard pages so this will let
            // them know
            $template->assign('use_standard_pages', \Piwigo\Config\ConfigDb::confGetParam('use_standard_pages', false));

            // -------------------------------------------------- page title
            $title = $page['title'];
            $template_title = $page['section_title'];
            $nb_items = count($page_items);
            $template->assign('TITLE', $template_title);
            $template->assign('NB_ITEMS', $nb_items);

            // -------------------------------------------------- menubar
            new MenubarRenderer()
                ->render();

            $template->set_filename('index', 'index.tpl');

            // +-----------------------------------------------------------------+
            // |  index page (categories, thumbnails, search, calendar, etc.)    |
            // +-----------------------------------------------------------------+
            $page_is_external = $page['is_external'] ?? null;
            if ($page_is_external === null || $page_is_external === '' || $page_is_external === '0' || $page_is_external === false || $page_is_external === 0) {
                // ------------------------------------------------- template init
                $page['body_id'] = 'theCategoryPage';

                if (isset($page['flat']) or isset($page['chronology_field'])) {
                    $template->assign(
                        'U_MODE_NORMAL',
                        duplicate_index_url([], ['chronology_field', 'start', 'flat'])
                    );
                }

                if (\Piwigo\Config\Config::indexFlatIcon() and ! isset($page['flat']) and $page['section'] === 'categories') {
                    $template->assign(
                        'U_MODE_FLAT',
                        duplicate_index_url([
                            'flat' => '',
                        ], ['start', 'chronology_field'])
                    );
                }

                if (! isset($page['chronology_field'])) {
                    $chronology_params = [
                        'chronology_field' => 'created',
                        'chronology_style' => 'monthly',
                        'chronology_view' => 'list',
                    ];
                    if (\Piwigo\Config\Config::indexCreatedDateIcon()) {
                        $template->assign(
                            'U_MODE_CREATED',
                            duplicate_index_url($chronology_params, ['start', 'flat'])
                        );
                    }
                    if (\Piwigo\Config\Config::indexPostedDateIcon()) {
                        $chronology_params['chronology_field'] = 'posted';
                        $template->assign(
                            'U_MODE_POSTED',
                            duplicate_index_url($chronology_params, ['start', 'flat'])
                        );
                    }
                } else {
                    if ($page['chronology_field'] === 'created') {
                        $chronology_field = 'posted';
                    } else {
                        $chronology_field = 'created';
                    }
                    if ((bool) (\Piwigo\Config\Config::all()['index_' . $chronology_field . '_date_icon'] ?? null)) {
                        $url = duplicate_index_url(
                            [
                                'chronology_field' => $chronology_field,
                            ],
                            ['chronology_date', 'start', 'flat']
                        );
                        $template->assign(
                            'U_MODE_' . strtoupper($chronology_field),
                            $url
                        );
                    }
                }

                new SearchFilterRenderer(new MailService(), new HtmlService(), $template)
                    ->render();

                if ($page['section'] === 'categories' and isset($page['category']) and is_array($page['category']) and ! isset($page['combined_categories'])) {
                    $template->assign(
                        [
                            'SEARCH_IN_SET_BUTTON' => \Piwigo\Config\Config::indexSearchInSetButton(),
                            'SEARCH_IN_SET_ACTION' => \Piwigo\Config\Config::indexSearchInSetAction(),
                            'SEARCH_IN_SET_URL' => get_root_url() . 'search.php?cat_id=' . (is_numeric($page['category']['id'] ?? null) ? (int) $page['category']['id'] : 0),
                        ]
                    );
                }

                if (isset($page['body_data']) and is_array($page['body_data']) and isset($page['body_data']['tag_ids']) and is_array($page['body_data']['tag_ids'])) {
                    // get tags for related tags "button", with the
                    // possibility to combine them
                    //
                    // NB: the excluded tag ids intentionally come from
                    // $page['tag_ids'] (the tags currently being viewed,
                    // only set on the "tags" section), not from
                    // $page['body_data']['tag_ids'] (a broader,
                    // always-present mirror of the same data used for
                    // JS/body attributes).
                    $excluded_tag_ids = [];
                    if (isset($page['tag_ids']) and is_array($page['tag_ids'])) {
                        foreach ($page['tag_ids'] as $excluded_tag_id) {
                            if (is_numeric($excluded_tag_id)) {
                                $excluded_tag_ids[] = (int) $excluded_tag_id;
                            }
                        }
                    }

                    $tags = $tagService->getCommonTags(
                        $page_items,
                        is_numeric(\Piwigo\Config\Config::menubarTagCloudItemsNumber()) ? (int) \Piwigo\Config\Config::menubarTagCloudItemsNumber() : 0,
                        new HtmlService(),
                        $excluded_tag_ids
                    );

                    $tags = $tagService->addLevelToTags($tags);

                    $page_tags = is_array($page['tags']) ? $page['tags'] : [];

                    $related_tags = [];

                    foreach ($tags as $tag) {
                        $related_tags[] = array_merge(
                            $tag,
                            [
                                'U_ADD' => make_index_url(
                                    [
                                        'tags' => array_merge(
                                            $page_tags,
                                            [$tag]
                                        ),
                                    ]
                                ),
                                'URL' => make_index_url(
                                    [
                                        'tags' => [$tag],
                                    ]
                                ),
                            ]
                        );
                    }

                    // We sort the array here because we want them sorted by
                    // counter and not alphabetically like before.
                    usort(
                        $related_tags,
                        fn (array $a, array $b): int => (is_numeric($b['counter'] ?? null) ? (int) $b['counter'] : 0)
                            <=> (is_numeric($a['counter'] ?? null) ? (int) $a['counter'] : 0)
                    );

                    $selected_related_tags_info = [];

                    // $page['tags'] is only known as mixed even though $page
                    // itself is typed -- narrow it once here and reuse the
                    // local var everywhere below. It is set in
                    // include/functions_url.inc.php to either [] or
                    // find_tags()'s list of tag rows (int-keyed, one assoc
                    // row per tag).
                    /** @var array<int, array<string, mixed>> $selectedTags */
                    $selectedTags = is_array($page['tags'] ?? null) ? $page['tags'] : [];

                    foreach ($selectedTags as $selectedTagKey => $selectedTag) {
                        $otherSelectedTags = $selectedTags;
                        unset($otherSelectedTags[$selectedTagKey]);

                        $selected_related_tags_info[$selectedTagKey] =
                        [
                            'tag_name' => trigger_change('render_tag_name', $selectedTag['name'], $selectedTag),
                            'item_count' => '',
                            'index_url' => make_index_url(
                                [
                                    'tags' => [$selectedTag],
                                ]
                            ),
                            'remove_url' => make_index_url(
                                [
                                    'tags' => $otherSelectedTags,
                                ]
                            ),
                        ];
                    }

                    $template->assign(
                        [
                            'SELECT_RELATED_TAGS' => $selected_related_tags_info,
                        ]
                    );

                    $template->set_filename('selected_tags', 'include/selected_tags.inc.tpl');
                    $template->assign_var_from_handle('SELECTED_TAGS_TEMPLATE', 'selected_tags');

                    $body_data_tag_ids = array_values(array_filter($page['body_data']['tag_ids'], is_scalar(...)));

                    $template->assign(
                        [
                            'SEARCH_IN_SET_BUTTON' => \Piwigo\Config\Config::indexSearchInSetButton(),
                            'SEARCH_IN_SET_ACTION' => \Piwigo\Config\Config::indexSearchInSetAction(),
                            'SEARCH_IN_SET_URL' => get_root_url() . 'search.php?tag_id=' . implode(',', $body_data_tag_ids),
                            'COMBINABLE_TAGS' => $related_tags,
                        ]
                    );
                }

                if (isset($page['category']) and is_array($page['category']) and \Piwigo\Auth\AccessControl::isAdmin() and \Piwigo\Config\Config::indexEditIcon()) {
                    $template->assign(
                        'U_EDIT',
                        get_root_url() . 'admin.php?page=album-' . (is_numeric($page['category']['id'] ?? null) ? (int) $page['category']['id'] : 0)
                    );
                }

                if (\Piwigo\Auth\AccessControl::isAdmin() and $page_items !== [] and \Piwigo\Config\Config::indexCaddieIcon()) {
                    $template->assign(
                        'U_CADDIE',
                        add_url_params(duplicate_index_url(), [
                            'caddie' => 1,
                        ])
                    );
                }

                if ($page['section'] === 'search' and $page_start === 0 and
                    ! isset($page['chronology_field']) and isset($page['qsearch_details'])) {
                    $matching_cats_no_images = [];
                    $matching_cats = [];
                    if (is_array($page['qsearch_details'])) {
                        if (is_array($page['qsearch_details']['matching_cats_no_images'] ?? null)) {
                            $matching_cats_no_images = array_values(array_filter($page['qsearch_details']['matching_cats_no_images'], is_array(...)));
                        }
                        if (is_array($page['qsearch_details']['matching_cats'] ?? null)) {
                            $matching_cats = array_values(array_filter($page['qsearch_details']['matching_cats'], is_array(...)));
                        }
                    }
                    /**
                     * @var array<int, array<string, mixed>> $matching_cats_no_images
                     * @var array<int, array<string, mixed>> $matching_cats
                     */
                    $cats = array_merge($matching_cats_no_images, $matching_cats);
                    if ($cats !== []) {
                        usort($cats, new HtmlService()->nameCompare(...));
                        $hints = [];
                        foreach ($cats as $cat) {
                            $hints[] = new HtmlService()->getCatDisplayName([$cat], '');
                        }
                        $template->assign('category_search_results', $hints);
                    }

                    $matching_tags = [];
                    if (is_array($page['qsearch_details']) and is_array($page['qsearch_details']['matching_tags'] ?? null)) {
                        $matching_tags = array_values(array_filter($page['qsearch_details']['matching_tags'], is_array(...)));
                    }
                    /** @var array<int, array<string, mixed>> $matching_tags */
                    foreach ($matching_tags as $tag) {
                        $tag['URL'] = make_index_url([
                            'tags' => [$tag],
                        ]);
                        $template->append('tag_search_results', $tag);
                    }

                    if ($page_items === []) {
                        $search_query = is_array($page['qsearch_details']) ? ($page['qsearch_details']['q'] ?? null) : null;
                        $template->append('no_search_results', htmlspecialchars(is_string($search_query) ? $search_query : ''));
                    } else {
                        $unmatched_terms = is_array($page['qsearch_details']) ? ($page['qsearch_details']['unmatched_terms'] ?? null) : null;
                        if (is_array($unmatched_terms) && $unmatched_terms !== []) {
                            /** @var list<string> $unmatched_terms */
                            $unmatched_terms = array_values(array_filter($unmatched_terms, is_string(...)));
                            $template->assign('no_search_results', array_map(htmlspecialchars(...), $unmatched_terms));
                        }
                    }
                }

                // image order
                if (\Piwigo\Config\Config::indexSortOrderInput()
                    and count($page_items) > 0
                    and $page['section'] !== 'most_visited'
                    and $page['section'] !== 'best_rated') {
                    $preferred_image_orders = $categoryService->getPreferredImageOrders();
                    $order_idx = SessionService::get()->getSessionVar('image_order', 0);

                    // get first order field and direction
                    $order_by = is_string((\Piwigo\Config\Config::all()['order_by'] ?? null)) ? (\Piwigo\Config\Config::all()['order_by'] ?? null) : '';
                    $first_order = substr($order_by, 9);
                    if (($pos = strpos($first_order, ',')) !== false) {
                        $first_order = substr($first_order, 0, $pos);
                    }
                    $first_order = trim($first_order);

                    $url = add_url_params(
                        duplicate_index_url(),
                        [
                            'image_order' => '',
                        ]
                    );
                    $tpl_orders = [];
                    $order_selected = false;

                    foreach ($preferred_image_orders as $order_id => $order) {
                        if ($order[2]) {
                            // force select if the field is the first field
                            // of order_by
                            if (! $order_selected && $order[1] === $first_order) {
                                $order_idx = $order_id;
                                $order_selected = true;
                            }

                            $tpl_orders[$order_id] = [
                                'DISPLAY' => $order[0],
                                'URL' => $url . $order_id,
                                'SELECTED' => (is_scalar($order_idx) ? (string) $order_idx : '') === (string) $order_id,
                            ];
                        }
                    }

                    $tpl_orders[0]['SELECTED'] = ! $order_selected; // unselect "Default" if another one is selected
                    $template->assign('image_orders', $tpl_orders);
                }

                // category comment
                $page_comment = $page['comment'] ?? null;
                $page_comment_present = is_string($page_comment) && $page_comment !== '' && $page_comment !== '0';
                if (($page_start === 0 or \Piwigo\Config\Config::albumDescriptionOnAllPages()) and ! isset($page['chronology_field']) and $page_comment_present) {
                    $template->assign('CONTENT_DESCRIPTION', $page['comment']);
                }

                if (isset($page['category']) and is_array($page['category']) and isset($page['category']['count_categories']) and $page['category']['count_categories'] === 0) {// count_categories might be computed by menubar - if the case unassign flat link if no sub albums
                    $template->clear_assign('U_MODE_FLAT');
                }

                // -------------------------------------------- main part : thumbnails
                if ($page_start === 0
                  and ! isset($page['flat'])
                  and ! isset($page['chronology_field'])
                  and ($page['section'] === 'recent_cats' or $page['section'] === 'categories')
                  and (! isset($page['category']) or ! is_array($page['category']) or ! isset($page['category']['count_categories']) or $page['category']['count_categories'] > 0)
                ) {
                    new CategoryCatsRenderer(new FilterService(), new HtmlService(), $template)
                        ->render();
                }

                if ($page_items !== []) {
                    new CategoryDefaultRenderer(new HtmlService(), $template)
                        ->render();

                    if (\Piwigo\Config\Config::indexSizesIcon()) {
                        $url = add_url_params(
                            duplicate_index_url(),
                            [
                                'display' => '',
                            ]
                        );

                        $derivative_params_var = $template->get_template_vars('derivative_params');
                        $selected_type = ($derivative_params_var instanceof DerivativeParams) ? $derivative_params_var->type : null;
                        $template->clear_assign('derivative_params');
                        $type_map = ImageStdParams::get_defined_type_map();
                        unset($type_map[ImageStdParams::XXLARGE], $type_map[ImageStdParams::XLARGE]);

                        foreach ($type_map as $params) {
                            $template->append(
                                'image_derivatives',
                                [
                                    'DISPLAY' => l10n($params->type),
                                    'URL' => $url . $params->type,
                                    'SELECTED' => $params->type === $selected_type,
                                ]
                            );
                        }
                    }
                }

                // slideshow
                // execute after init thumbs in order to have all picture
                // informations
                $slideshow_url = $page['cat_slideshow_url'] ?? null;
                $slideshow_url_present = is_string($slideshow_url) && $slideshow_url !== '' && $slideshow_url !== '0';
                if ($slideshow_url_present) {
                    if (isset($_GET['slideshow'])) {
                        redirect($slideshow_url);
                    } elseif (\Piwigo\Config\Config::indexSlideShowIcon()) {
                        $template->assign('U_SLIDESHOW', $slideshow_url);
                    }
                }

                // We want all pages that display thumbnails, except on the
                // tags page
                // Fill related tags action
                $body_data_section = is_array($page['body_data'] ?? null) ? ($page['body_data']['section'] ?? null) : null;
                if ($page_items !== [] and is_array($page['body_data'] ?? null) and $body_data_section !== 'tags') {
                    $selection = array_slice($page_items, $page_start, $page_nb_image_page);
                    $tags = $tagService->addLevelToTags($tagService->getCommonTags($selection, is_numeric(\Piwigo\Config\Config::contentTagCloudItemsNumber()) ? (int) \Piwigo\Config\Config::contentTagCloudItemsNumber() : 0, new HtmlService()));
                    $related_tags = [];
                    foreach ($tags as $tag) {
                        $related_tags[] =
                        array_merge(
                            $tag,
                            [
                                'URL' => make_index_url([
                                    'tags' => [$tag],
                                ]),
                            ]
                        );
                    }

                    $template->assign(
                        [
                            'RELATED_TAGS_ACTION' => $related_tags !== [],
                            'RELATED_TAGS' => $related_tags,
                        ]
                    );
                }
            }

            // ---------------------------------------------------------- end
            new \Piwigo\Page\PageHeaderRenderer()
                ->render($title);
            trigger_notify('loc_end_index');
            new HtmlService()
                ->flushPageMessages();
            $template->parse_index_buttons();
            $template->pparse('index');

            // ------------------------------------------------ log informations
            new HistoryService(new HistoryRepository(DbConnection::build()))->logVisit();
            \Piwigo\Bootstrap\PageTail::render();
        });

        return ResponseFactory::html($body);
    }
}

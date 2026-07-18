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
use Piwigo\Section\SectionContext;
use Piwigo\Section\SectionContextRegistry;
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
        $template = \Piwigo\Template\CurrentTemplate::get();

        new SectionPopulator(new MailService(), new HtmlService(), $template)
            ->populate();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Guest);

        // Legacy Coupling Retirement Track A batch A5.2e: populate() always
        // calls SectionContextRegistry::set() as the very last thing it
        // does, so this is guaranteed non-null by the time this controller
        // (its one real caller besides PictureController) runs -- a real
        // guard, not dead code, since the type itself is nullable.
        $section_context = SectionContextRegistry::current();
        if (! $section_context instanceof SectionContext) {
            throw new \RuntimeException('SectionContextRegistry::current() is null after SectionPopulator::populate()');
        }

        $page_items = array_values(array_filter(array_map(
            static fn (int|string $id): ?int => is_numeric($id) ? (int) $id : null,
            $section_context->items
        ), static fn (?int $id): bool => $id !== null));

        $page_start = $section_context->start;
        $page_nb_image_page = $section_context->nbImagePage;

        // access authorization check
        if ($section_context->category !== null && is_numeric($section_context->category['id'] ?? null)) {
            $categoryConn = DbConnection::build();
            new CategoryService(
                new CategoryRepository($categoryConn),
                new PermissionService(new PermissionRepository($categoryConn), new GroupRepository($categoryConn))
            )->checkRestrictions((int) $section_context->category['id'], new HtmlService());
        }
        if ($page_start > 0 && $page_start >= count($page_items)) {
            new HtmlService()
                ->pageNotFound('', duplicate_index_url([
                    'start' => 0,
                ]));
        }

        $body = LegacyRenderCapture::capture(static function () use ($page_items, $page_start, $page_nb_image_page, $section_context): void {
            // body_id (this controller's own write)/cat_slideshow_url
            // (written by CategoryDefaultRenderer::render(), called later
            // in this same closure) are the only real remaining reasons
            // this closure still needs the global.
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
                \Piwigo\Core\PageState::current()->setMetaRobotsFlag('noindex');
                if (is_string($_GET['display']) && array_key_exists($_GET['display'], ImageStdParams::get_defined_type_map())) {
                    SessionService::get()->setSessionVar('index_deriv', $_GET['display']);
                }
            }

            // -------------------------------------------------- initialization
            // navigation bar
            $navigationBar = [];
            if (count($page_items) > $page_nb_image_page) {
                $navigationBar = new \Piwigo\Core\PaginationService()
                    ->createNavigationBar(duplicate_index_url([], ['start']), count($page_items), $page_start, $page_nb_image_page, true, 'start');
            }

            $template->assign('thumb_navbar', $navigationBar);

            // caddie filling :-)
            if (isset($_GET['caddie'])) {
                \Piwigo\Caddie\CaddieService::fillCurrentUserCaddie($page_items);
                redirect(duplicate_index_url());
            }

            if ($section_context->isHomepage) {
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
            $title = $section_context->title;
            $template_title = $section_context->sectionTitle;
            $nb_items = count($page_items);
            $template->assign('TITLE', $template_title);
            $template->assign('NB_ITEMS', $nb_items);

            // -------------------------------------------------- menubar
            $categoryCountCategories = new MenubarRenderer()
                ->render();

            $template->set_filename('index', 'index.tpl');

            // +-----------------------------------------------------------------+
            // |  index page (categories, thumbnails, search, calendar, etc.)    |
            // +-----------------------------------------------------------------+
            // This whole section used to be guarded by an "is_external"
            // check (isset($page['is_external'])) that is confirmed dead in
            // the 16.x-rewrite reference too (SectionContext::$isExternal
            // defaults false and nothing ever passes true) -- not a 17.x
            // porting gap, so the guard is dropped rather than ported.
            // ------------------------------------------------- template init
            $page['body_id'] = 'theCategoryPage';

            if ($section_context->flat or $section_context->chronologyField !== null) {
                $template->assign(
                    'U_MODE_NORMAL',
                    duplicate_index_url([], ['chronology_field', 'start', 'flat'])
                );
            }

            if (\Piwigo\Config\Config::indexFlatIcon() and ! $section_context->flat and $section_context->section === 'categories') {
                $template->assign(
                    'U_MODE_FLAT',
                    duplicate_index_url([
                        'flat' => '',
                    ], ['start', 'chronology_field'])
                );
            }

            if ($section_context->chronologyField === null) {
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
                if ($section_context->chronologyField === 'created') {
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

            $resolved_search_id = new SearchFilterRenderer(new MailService(), new HtmlService(), $template)
                ->render($section_context);

            if ($section_context->section === 'categories' and $section_context->category !== null and $section_context->combinedCategories === null) {
                $template->assign(
                    [
                        'SEARCH_IN_SET_BUTTON' => \Piwigo\Config\Config::indexSearchInSetButton(),
                        'SEARCH_IN_SET_ACTION' => \Piwigo\Config\Config::indexSearchInSetAction(),
                        'SEARCH_IN_SET_URL' => get_root_url() . 'search.php?cat_id=' . (is_numeric($section_context->category['id'] ?? null) ? (int) $section_context->category['id'] : 0),
                    ]
                );
            }

            $bodyData = \Piwigo\Core\PageState::current()->bodyData;
            if (isset($bodyData['tag_ids']) and is_array($bodyData['tag_ids'])) {
                // get tags for related tags "button", with the
                // possibility to combine them
                //
                // NB: the excluded tag ids intentionally come from
                // $section_context->tagIds (the tags currently being
                // viewed, only set on the "tags" section), not from
                // PageState::bodyData['tag_ids'] (a broader,
                // always-present mirror of the same data used for
                // JS/body attributes).
                $excluded_tag_ids = $section_context->tagIds;

                $tags = $tagService->getCommonTags(
                    $page_items,
                    is_numeric(\Piwigo\Config\Config::menubarTagCloudItemsNumber()) ? (int) \Piwigo\Config\Config::menubarTagCloudItemsNumber() : 0,
                    new HtmlService(),
                    $excluded_tag_ids
                );

                $tags = $tagService->addLevelToTags($tags);

                $page_tags = $section_context->tags;

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

                $selectedTags = $section_context->tags;

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

                $body_data_tag_ids = array_values(array_filter($bodyData['tag_ids'], is_scalar(...)));

                $template->assign(
                    [
                        'SEARCH_IN_SET_BUTTON' => \Piwigo\Config\Config::indexSearchInSetButton(),
                        'SEARCH_IN_SET_ACTION' => \Piwigo\Config\Config::indexSearchInSetAction(),
                        'SEARCH_IN_SET_URL' => get_root_url() . 'search.php?tag_id=' . implode(',', $body_data_tag_ids),
                        'COMBINABLE_TAGS' => $related_tags,
                    ]
                );
            }

            if ($section_context->category !== null and \Piwigo\Auth\AccessControl::isAdmin() and \Piwigo\Config\Config::indexEditIcon()) {
                $template->assign(
                    'U_EDIT',
                    get_root_url() . 'admin.php?page=album-' . (is_numeric($section_context->category['id'] ?? null) ? (int) $section_context->category['id'] : 0)
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

            if ($section_context->section === 'search' and $page_start === 0 and
                $section_context->chronologyField === null and $section_context->qsearchDetails !== []) {
                $qsearchDetails = $section_context->qsearchDetails;

                $matching_cats_no_images = is_array($qsearchDetails['matching_cats_no_images'] ?? null) ? array_values(array_filter($qsearchDetails['matching_cats_no_images'], is_array(...))) : [];
                $matching_cats = is_array($qsearchDetails['matching_cats'] ?? null) ? array_values(array_filter($qsearchDetails['matching_cats'], is_array(...))) : [];
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

                $matching_tags = is_array($qsearchDetails['matching_tags'] ?? null) ? array_values(array_filter($qsearchDetails['matching_tags'], is_array(...))) : [];
                /** @var array<int, array<string, mixed>> $matching_tags */
                foreach ($matching_tags as $tag) {
                    $tag['URL'] = make_index_url([
                        'tags' => [$tag],
                    ]);
                    $template->append('tag_search_results', $tag);
                }

                if ($page_items === []) {
                    $search_query = $qsearchDetails['q'] ?? null;
                    $template->append('no_search_results', htmlspecialchars(is_string($search_query) ? $search_query : ''));
                } else {
                    $unmatched_terms = $qsearchDetails['unmatched_terms'] ?? null;
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
                and $section_context->section !== 'most_visited'
                and $section_context->section !== 'best_rated') {
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
            $page_comment = $section_context->comment;
            $page_comment_present = $page_comment !== '' && $page_comment !== '0';
            if (($page_start === 0 or \Piwigo\Config\Config::albumDescriptionOnAllPages()) and $section_context->chronologyField === null and $page_comment_present) {
                $template->assign('CONTENT_DESCRIPTION', $section_context->comment);
            }

            if ($section_context->category !== null and $categoryCountCategories === 0) {// count_categories might be computed by menubar - if the case unassign flat link if no sub albums
                $template->clear_assign('U_MODE_FLAT');
            }

            // -------------------------------------------- main part : thumbnails
            if ($page_start === 0
              and ! $section_context->flat
              and $section_context->chronologyField === null
              and ($section_context->section === 'recent_cats' or $section_context->section === 'categories')
              and ($section_context->category === null or $categoryCountCategories === null or $categoryCountCategories > 0)
            ) {
                new CategoryCatsRenderer(new FilterService(), new HtmlService(), $template)
                    ->render($section_context->section, $section_context->category, $section_context->startcat);
            }

            if ($page_items !== []) {
                new CategoryDefaultRenderer(new HtmlService(), $template)
                    ->render($page_items, $page_start, $page_nb_image_page, $section_context->section);

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
            $body_data_section = \Piwigo\Core\PageState::current()->bodyData['section'] ?? null;
            if ($page_items !== [] and $body_data_section !== 'tags') {
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

            // ---------------------------------------------------------- end
            new \Piwigo\Page\PageHeaderRenderer()
                ->render($title);
            trigger_notify('loc_end_index');
            new HtmlService()
                ->flushPageMessages();
            $template->parse_index_buttons();
            $template->pparse('index');

            // ------------------------------------------------ log informations
            new HistoryService(new HistoryRepository(DbConnection::build()))->logVisit(
                section: $section_context->section,
                category: $section_context->category,
                tagIds: $section_context->tagIds,
                searchId: $resolved_search_id,
            );
            \Piwigo\Bootstrap\PageTail::render();
        });

        return ResponseFactory::html($body);
    }
}

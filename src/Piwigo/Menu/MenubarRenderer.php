<?php

declare(strict_types=1);

namespace Piwigo\Menu;

use Piwigo\Category\CategoryService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Filter\FilterService;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;

/**
 * Builds the main menubar's blocks. Injects nothing on its own
 * constructor -- same "no constructor deps" shape as
 * Html\HtmlService/Url\UrlService: cross-domain calls
 * (AccessControl::isAGuest()/isAuthorizeStatus()/isAdmin(),
 * PageFilterHelper::getFilterPageValue()/scriptBasename(), Lang::t(),
 * CommentService::getNbAvailableComments()) already call the real
 * migrated OOP classes directly, not plain global-function wrappers.
 * get_available_tags()/get_nb_available_tags()/tags_counter_compare()
 * (functions_tag.inc.php) and get_categories_menu()/
 * get_related_categories_menu() (functions_category.inc.php) were ported
 * to real TagService/CategoryService methods in P23 batch 8c -- both
 * constructed locally in render() since this renderer itself takes no
 * constructor deps. Legacy Coupling Retirement Phase 4c: render() takes
 * UrlServiceInterface as a method parameter instead (its own former
 * add_url_params()/make_index_url()/get_root_url() calls, 24 real sites).
 *
 * The `eval($url_data['eval_visible'])` call for the external-links block
 * is preserved unmodified -- the reference doc's own fix for this
 * (replacing the plugin `eval_visible` contract with a Closure-based
 * visibility API) targets `PluginInterface`, which doesn't exist until
 * P31 (SEC-49). Not this phase's job.
 */
final class MenubarRenderer
{
    /**
     * Legacy Coupling Retirement Track A batch A5.2e: the gallery-
     * navigation-context reads below (section/items/category/
     * combined_categories/qsearch_details) come from
     * SectionContextRegistry::current() instead of `global $page;` --
     * nullable here (unlike GalleryController/PictureController's own
     * guaranteed-non-null read) since this renderer is also called from
     * 9 other, non-gallery controllers that never run
     * SectionPopulator::populate() at all, matching every one of those
     * keys' own former `?? null`/`isset()` fallback behavior.
     *
     * The categories-menu block also used to have
     * CategoryService::getCategoriesMenu() write `count_categories` back
     * onto the real global `$page['category']` for callers reading it
     * later (see that method's own docblock) -- with no global left to
     * write to, this method returns that value instead; every caller but
     * GalleryController ignores it.
     */
    public function render(UrlServiceInterface $urlService, \Piwigo\Core\FilterState $filterState, \Piwigo\Section\SectionContextRegistry $sectionContextRegistry, SessionService $sessionService, \Piwigo\Config\DeploymentPolicy $deploymentPolicy, \Piwigo\Users\CurrentUser $currentUser, \Piwigo\Template\CurrentTemplate $currentTemplate): ?int
    {
        $template = $currentTemplate->get();
        $section_context = $sectionContextRegistry->current();

        $conn = DbConnection::build();
        // Built once, reused below -- was the same PermissionService recipe
        // repeated verbatim at 2 sites in this method (Phase 1k DI-chain audit).
        $permissionService = new PermissionService(new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($conn)), \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Group\GroupEntity::class), \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Category\CategoryEntity::class));
        $tagService = new TagService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Tag\TagEntity::class), $permissionService, new \Piwigo\Activity\ActivityService(\Piwigo\Db\EntityManagerFactory::build(\Piwigo\Db\DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)), \Piwigo\PluginConfig\EventDispatcher::get(), $currentUser);
        $categoryService = new CategoryService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Category\CategoryEntity::class), $permissionService);

        $menu = new BlockManager('menubar', \Piwigo\PluginConfig\EventDispatcher::get(), $currentTemplate);

        // if guest_access is disabled, we only display the menus if the user is identified
        if (\Piwigo\Config\CurrentConfig::guestAccess() or ! \Piwigo\Auth\AccessControl::isAGuest()) {
            $menu->load_registered_blocks();
        }
        $menu->prepare_display();

        if ($section_context !== null && $section_context->section === 'search' && $section_context->qsearchDetails !== []) {
            $qsearch_q = $section_context->qsearchDetails['q'] ?? '';
            $qsearch_q = is_string($qsearch_q) ? $qsearch_q : '';
            $template->assign('QUERY_SEARCH', htmlspecialchars($qsearch_q));
        }

        // --------------------------------------------------------------- external links
        if ((bool) ($block = $menu->get_block('mbLinks')) and ! self::emptyValue(\Piwigo\Config\CurrentConfig::links())) {
            $block->data = [];
            foreach (\Piwigo\Config\CurrentConfig::links() as $url => $url_data) {
                if (! is_array($url_data)) {
                    $url_data = [
                        'label' => $url_data,
                    ];
                }

                if (
                    (! isset($url_data['eval_visible']))
                    or
                    (eval($url_data['eval_visible']))
                ) {
                    $tpl_var = [
                        'URL' => $url,
                        'LABEL' => $url_data['label'],
                    ];

                    if (! isset($url_data['new_window']) or (bool) $url_data['new_window']) {
                        $tpl_var['new_window'] =
                          [
                              'NAME' => ($url_data['nw_name'] ?? ''),
                              'FEATURES' => ($url_data['nw_features'] ?? ''),
                          ];
                    }
                    $block->data[] = $tpl_var;
                }
            }
            if (! self::emptyValue($block->data)) {
                $block->template = 'menubar_links.tpl';
            }
        }

        // -------------------------------------------------------------- categories
        $block = $menu->get_block('mbCategories');
        // ------------------------------------------------------------------------ filter
        if (\Piwigo\Config\CurrentConfig::menubarFilterIcon() and ! self::emptyValue(\Piwigo\Config\CurrentConfig::filterPages()) and (bool) \Piwigo\Core\PageFilterHelper::getFilterPageValue('used')) {
            if ($filterState->isEnabled()) {
                $template->assign(
                    'U_STOP_FILTER',
                    $urlService->addUrlParams($urlService->makeIndexUrl([]), [
                        'filter' => 'stop',
                    ])
                );
            } else {
                $recent_period = $currentUser->get()
                    ->rawAttributes['recent_period'] ?? null;
                $recent_period = is_numeric($recent_period) ? (int) $recent_period : (is_string($recent_period) ? $recent_period : 0);
                $template->assign(
                    'U_START_FILTER',
                    $urlService->addUrlParams($urlService->makeIndexUrl([]), [
                        'filter' => 'start-recent-' . $recent_period,
                    ])
                );
            }
        }

        $categoryCountCategories = null;
        if ($block !== null) {
            $categoriesMenu = $categoryService->getCategoriesMenu($section_context?->category, new FilterService($filterState, $sessionService, Translator::get()), $urlService, $filterState, $currentUser);
            $categoryCountCategories = $categoriesMenu['categoryCountCategories'];
            $block->data = [
                'NB_PICTURE' => $currentUser->get()
                    ->rawAttributes['nb_total_images'] ?? null,
                'MENU_CATEGORIES' => $categoriesMenu['menu'],
                'U_CATEGORIES' => $urlService->makeIndexUrl([
                    'section' => 'categories',
                ]),
            ];
            $block->template = 'menubar_categories.tpl';
        }

        // ------------------------------------------------------------ related categories
        $block = $menu->get_block('mbRelatedCategories');

        $page_items = $section_context?->items;

        if (
            $section_context !== null
            and is_array($page_items)
            and count($page_items) < \Piwigo\Config\CurrentConfig::relatedAlbumsMaximumItemsToCompute()
            and $block !== null
            and $page_items !== []
        ) {
            $exclude_cat_ids = [];
            $page_category = $section_context->category;
            $page_category_id = $page_category['id'] ?? null;
            $combined_categories = $section_context->combinedCategories;
            if (is_int($page_category_id) or is_string($page_category_id)) {
                $exclude_cat_ids = [$page_category_id];
                if ($combined_categories !== null) {
                    foreach ($combined_categories as $cat) {
                        $cat_id = $cat['id'] ?? null;
                        if (is_int($cat_id) or is_string($cat_id)) {
                            $exclude_cat_ids[] = $cat_id;
                        }
                    }
                }
            }

            $related_items = $page_items;

            $block->data = [
                'MENU_CATEGORIES' => $categoryService->getRelatedCategoriesMenuWithUrls($related_items, $urlService, $exclude_cat_ids, $page_category, $combined_categories),
            ];

            if (! self::emptyValue($block->data['MENU_CATEGORIES'])) {
                $block->template = 'menubar_related_categories.tpl';
            }
        }

        // ------------------------------------------------------------------------ tags
        $block = $menu->get_block('mbTags');
        if ($block !== null and \Piwigo\Core\PageFilterHelper::scriptBasename() !== 'picture') {
            $block->data = [];
            $tags = $tagService->getAvailableTags();
            usort($tags, $tagService->tagsCounterCompare(...));
            $tag_cloud_items_number = \Piwigo\Config\CurrentConfig::menubarTagCloudItemsNumber();
            $tags = array_slice($tags, 0, $tag_cloud_items_number);
            foreach ($tags as $tag) {
                $block->data[] = array_merge(
                    $tag,
                    [
                        'URL' => $urlService->makeIndexUrl([
                            'tags' => [$tag],
                        ]),
                    ]
                );
            }

            if (! self::emptyValue($block->data)) {
                $block->template = 'menubar_tags.tpl';
            }
        }

        // ----------------------------------------------------------- special categories
        if (($block = $menu->get_block('mbSpecials')) !== null) {
            $block->data = [];
            if (! \Piwigo\Auth\AccessControl::isAGuest()) {// favorites
                $block->data['favorites'] =
                  [
                      'URL' => $urlService->makeIndexUrl([
                          'section' => 'favorites',
                      ]),
                      'TITLE' => Lang::t('display your favorites photos'),
                      'NAME' => Lang::t('Your favorites'),
                  ];
            }

            $block->data['most_visited'] =
              [
                  'URL' => $urlService->makeIndexUrl([
                      'section' => 'most_visited',
                  ]),
                  'TITLE' => Lang::t('display most visited photos'),
                  'NAME' => Lang::t('Most visited'),
              ];

            if (\Piwigo\Config\CurrentConfig::rateEnabled()) {
                $block->data['best_rated'] =
                 [
                     'URL' => $urlService->makeIndexUrl([
                         'section' => 'best_rated',
                     ]),
                     'TITLE' => Lang::t('display best rated photos'),
                     'NAME' => Lang::t('Best rated'),
                 ];
            }

            $block->data['recent_pics'] =
              [
                  'URL' => $urlService->makeIndexUrl([
                      'section' => 'recent_pics',
                  ]),
                  'TITLE' => Lang::t('display most recent photos'),
                  'NAME' => Lang::t('Recent photos'),
              ];

            $block->data['recent_cats'] =
              [
                  'URL' => $urlService->makeIndexUrl([
                      'section' => 'recent_cats',
                  ]),
                  'TITLE' => Lang::t('display recently updated albums'),
                  'NAME' => Lang::t('Recent albums'),
              ];

            $block->data['random'] =
              [
                  'URL' => $urlService->getRootUrl() . 'random.php',
                  'TITLE' => Lang::t('display a set of random photos'),
                  'NAME' => Lang::t('Random photos'),
                  'REL' => 'rel="nofollow"',
              ];

            $block->data['calendar'] =
              [
                  'URL' => $urlService->makeIndexUrl(
                      [
                          'chronology_field' => (\Piwigo\Config\CurrentConfig::calendarDatefield() === 'date_available'
                                                  ? 'posted' : 'created'),
                          'chronology_style' => 'monthly',
                          'chronology_view' => 'calendar',
                      ]
                  ),
                  'TITLE' => Lang::t('display each day with photos, month per month'),
                  'NAME' => Lang::t('Calendar'),
                  'REL' => 'rel="nofollow"',
              ];
            $block->template = 'menubar_specials.tpl';
        }

        // ---------------------------------------------------------------------- summary
        if (($block = $menu->get_block('mbMenu')) !== null) {
            $block->data = [];
            // quick search block will be displayed only if data['qsearch'] is set
            // to "yes"
            $block->data['qsearch'] = true;

            // tags link
            $block->data['tags'] =
              [
                  'TITLE' => Lang::t('display available tags'),
                  'NAME' => Lang::t('Tags'),
                  'URL' => $urlService->getRootUrl() . 'tags.php',
                  'COUNTER' => $tagService->getNbAvailableTags(),
              ];

            // search link
            $block->data['search'] =
              [
                  'TITLE' => Lang::t('search'),
                  'NAME' => Lang::t('Search'),
                  'URL' => $urlService->getRootUrl() . 'search.php',
                  'REL' => 'rel="search"',
              ];

            if (\Piwigo\Config\CurrentConfig::activateComments()) {
                // comments link
                $block->data['comments'] =
                  [
                      'TITLE' => Lang::t('display last user comments'),
                      'NAME' => Lang::t('Comments'),
                      'URL' => $urlService->getRootUrl() . 'comments.php',
                      'COUNTER' => \Piwigo\Comment\CommentService::getNbAvailableComments(),
                  ];
            }

            // about link
            $block->data['about'] =
              [
                  'TITLE' => Lang::t('About Piwigo'),
                  'NAME' => Lang::t('About'),
                  'URL' => $urlService->getRootUrl() . 'about.php',
              ];

            // notification
            $block->data['rss'] =
              [
                  'TITLE' => Lang::t('RSS feed'),
                  'NAME' => Lang::t('Notification'),
                  'URL' => $urlService->getRootUrl() . 'notification.php',
                  'REL' => 'rel="nofollow"',
              ];
            $block->template = 'menubar_menu.tpl';
        }

        // --------------------------------------------------------------- identification
        if (\Piwigo\Auth\AccessControl::isAGuest()) {
            $template->assign(
                [
                    'U_LOGIN' => $urlService->getRootUrl() . 'identification.php',
                    'U_LOST_PASSWORD' => $urlService->getRootUrl() . 'password.php',
                    'AUTHORIZE_REMEMBERING' => \Piwigo\Config\CurrentConfig::authorizeRemembering(),
                ]
            );
            if (\Piwigo\Config\CurrentConfig::allowUserRegistration()) {
                $template->assign('U_REGISTER', $urlService->getRootUrl() . 'register.php');
            }
        } else {
            $username = $currentUser->get()
                ->username;
            $template->assign('USERNAME', stripslashes($username));
            if (\Piwigo\Auth\AccessControl::isAuthorizeStatus(AccessLevel::Classic)) {
                $template->assign('U_PROFILE', $urlService->getRootUrl() . 'profile.php');
            }

            // the logout link has no meaning with Apache authentication : it is not
            // possible to logout with this kind of authentication.
            if (! $deploymentPolicy->apacheAuthentication) {
                $template->assign('U_LOGOUT', $urlService->getRootUrl() . '?act=logout');
            }
            if (\Piwigo\Auth\AccessControl::isAdmin()) {
                $template->assign('U_ADMIN', $urlService->getRootUrl() . 'admin.php');
            }
        }
        if (($block = $menu->get_block('mbIdentification')) !== null) {
            $block->template = 'menubar_identification.tpl';
        }
        $menu->apply('MENUBAR', 'menubar.tpl');

        return $categoryCountCategories;
    }

    /**
     * Matches empty()'s exact truthiness semantics -- required since
     * empty() itself is disallowed by this project's strict PHPStan rules.
     */
    private static function emptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === 0.0 || $value === '0' || $value === false || $value === [];
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Menu;

use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Core\AccessLevel;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Template\Template;

/**
 * Builds the main menubar's blocks. Injects nothing -- same "no
 * constructor deps" shape as Html\HtmlService/Url\UrlService: cross-
 * domain calls (is_a_guest(), get_filter_page_value(), add_url_params(),
 * make_index_url(), script_basename(), l10n(), get_root_url(),
 * get_nb_available_comments(), is_autorize_status(), is_admin()) stay as
 * plain global-function calls to modules migrated in earlier phases.
 * get_available_tags()/get_nb_available_tags()/tags_counter_compare()
 * (functions_tag.inc.php) and get_categories_menu()/
 * get_related_categories_menu() (functions_category.inc.php) were ported
 * to real TagService/CategoryService methods in P23 batch 8c -- both
 * constructed locally in render() since this renderer itself takes no
 * constructor deps.
 *
 * The `eval($url_data['eval_visible'])` call for the external-links block
 * is preserved unmodified -- the reference doc's own fix for this
 * (replacing the plugin `eval_visible` contract with a Closure-based
 * visibility API) targets `PluginInterface`, which doesn't exist until
 * P31 (SEC-49). Not this phase's job.
 */
final class MenubarRenderer
{
    public function render(): void
    {
        /**
         * @var array<string, mixed> $page
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $user
         * @var Template $template
         * @var array<string, mixed> $filter
         */
        global $page, $conf, $user, $template, $filter;

        $conn = DbConnection::build();
        $tagService = new TagService(new TagRepository($conn), new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)));
        $categoryService = new CategoryService(new CategoryRepository($conn), new PermissionService(new PermissionRepository($conn), new GroupRepository($conn)));

        $menu = new BlockManager('menubar');

        // if guest_access is disabled, we only display the menus if the user is identified
        if ((bool) $conf['guest_access'] or ! \Piwigo\Auth\AccessControl::isAGuest()) {
            $menu->load_registered_blocks();
        }
        $menu->prepare_display();

        if (($page['section'] ?? null) === 'search' and isset($page['qsearch_details']) and is_array($page['qsearch_details'])) {
            $qsearch_q = $page['qsearch_details']['q'] ?? '';
            $qsearch_q = is_string($qsearch_q) ? $qsearch_q : '';
            $template->assign('QUERY_SEARCH', htmlspecialchars($qsearch_q));
        }

        // --------------------------------------------------------------- external links
        if ((bool) ($block = $menu->get_block('mbLinks')) and ! self::emptyValue($conf['links']) and is_array($conf['links'])) {
            $block->data = [];
            foreach ($conf['links'] as $url => $url_data) {
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
        if ((bool) $conf['menubar_filter_icon'] and ! self::emptyValue($conf['filter_pages']) and (bool) \Piwigo\Core\PageFilterHelper::getFilterPageValue('used')) {
            if ((bool) $filter['enabled']) {
                $template->assign(
                    'U_STOP_FILTER',
                    add_url_params(make_index_url([]), [
                        'filter' => 'stop',
                    ])
                );
            } else {
                $recent_period = $user['recent_period'] ?? null;
                $recent_period = is_numeric($recent_period) ? (int) $recent_period : (is_string($recent_period) ? $recent_period : 0);
                $template->assign(
                    'U_START_FILTER',
                    add_url_params(make_index_url([]), [
                        'filter' => 'start-recent-' . $recent_period,
                    ])
                );
            }
        }

        if ($block !== null) {
            $block->data = [
                'NB_PICTURE' => $user['nb_total_images'],
                'MENU_CATEGORIES' => $categoryService->getCategoriesMenu(),
                'U_CATEGORIES' => make_index_url([
                    'section' => 'categories',
                ]),
            ];
            $block->template = 'menubar_categories.tpl';
        }

        // ------------------------------------------------------------ related categories
        $block = $menu->get_block('mbRelatedCategories');

        $page_items = $page['items'] ?? null;

        if (
            is_array($page_items)
            and count($page_items) < $conf['related_albums_maximum_items_to_compute']
            and $block !== null
            and $page_items !== []
        ) {
            $exclude_cat_ids = [];
            $page_category = $page['category'] ?? null;
            $page_category_id = is_array($page_category) ? ($page_category['id'] ?? null) : null;
            if (is_int($page_category_id) or is_string($page_category_id)) {
                $exclude_cat_ids = [$page_category_id];
                $combined_categories = $page['combined_categories'] ?? null;
                if (is_array($combined_categories)) {
                    foreach ($combined_categories as $cat) {
                        $cat_id = is_array($cat) ? ($cat['id'] ?? null) : null;
                        if (is_int($cat_id) or is_string($cat_id)) {
                            $exclude_cat_ids[] = $cat_id;
                        }
                    }
                }
            }

            $related_items = array_values(array_filter(
                $page_items,
                static fn (mixed $item): bool => is_int($item) or is_string($item)
            ));

            $block->data = [
                'MENU_CATEGORIES' => $categoryService->getRelatedCategoriesMenuWithUrls($related_items, $exclude_cat_ids),
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
            $tag_cloud_items_number = $conf['menubar_tag_cloud_items_number'] ?? null;
            $tag_cloud_items_number = is_numeric($tag_cloud_items_number) ? (int) $tag_cloud_items_number : null;
            $tags = array_slice($tags, 0, $tag_cloud_items_number);
            foreach ($tags as $tag) {
                $block->data[] = array_merge(
                    $tag,
                    [
                        'URL' => make_index_url([
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
                      'URL' => make_index_url([
                          'section' => 'favorites',
                      ]),
                      'TITLE' => l10n('display your favorites photos'),
                      'NAME' => l10n('Your favorites'),
                  ];
            }

            $block->data['most_visited'] =
              [
                  'URL' => make_index_url([
                      'section' => 'most_visited',
                  ]),
                  'TITLE' => l10n('display most visited photos'),
                  'NAME' => l10n('Most visited'),
              ];

            if ((bool) $conf['rate']) {
                $block->data['best_rated'] =
                 [
                     'URL' => make_index_url([
                         'section' => 'best_rated',
                     ]),
                     'TITLE' => l10n('display best rated photos'),
                     'NAME' => l10n('Best rated'),
                 ];
            }

            $block->data['recent_pics'] =
              [
                  'URL' => make_index_url([
                      'section' => 'recent_pics',
                  ]),
                  'TITLE' => l10n('display most recent photos'),
                  'NAME' => l10n('Recent photos'),
              ];

            $block->data['recent_cats'] =
              [
                  'URL' => make_index_url([
                      'section' => 'recent_cats',
                  ]),
                  'TITLE' => l10n('display recently updated albums'),
                  'NAME' => l10n('Recent albums'),
              ];

            $block->data['random'] =
              [
                  'URL' => get_root_url() . 'random.php',
                  'TITLE' => l10n('display a set of random photos'),
                  'NAME' => l10n('Random photos'),
                  'REL' => 'rel="nofollow"',
              ];

            $block->data['calendar'] =
              [
                  'URL' => make_index_url(
                      [
                          'chronology_field' => ($conf['calendar_datefield'] === 'date_available'
                                                  ? 'posted' : 'created'),
                          'chronology_style' => 'monthly',
                          'chronology_view' => 'calendar',
                      ]
                  ),
                  'TITLE' => l10n('display each day with photos, month per month'),
                  'NAME' => l10n('Calendar'),
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
                  'TITLE' => l10n('display available tags'),
                  'NAME' => l10n('Tags'),
                  'URL' => get_root_url() . 'tags.php',
                  'COUNTER' => $tagService->getNbAvailableTags(),
              ];

            // search link
            $block->data['search'] =
              [
                  'TITLE' => l10n('search'),
                  'NAME' => l10n('Search'),
                  'URL' => get_root_url() . 'search.php',
                  'REL' => 'rel="search"',
              ];

            if ((bool) $conf['activate_comments']) {
                // comments link
                $block->data['comments'] =
                  [
                      'TITLE' => l10n('display last user comments'),
                      'NAME' => l10n('Comments'),
                      'URL' => get_root_url() . 'comments.php',
                      'COUNTER' => get_nb_available_comments(),
                  ];
            }

            // about link
            $block->data['about'] =
              [
                  'TITLE' => l10n('About Piwigo'),
                  'NAME' => l10n('About'),
                  'URL' => get_root_url() . 'about.php',
              ];

            // notification
            $block->data['rss'] =
              [
                  'TITLE' => l10n('RSS feed'),
                  'NAME' => l10n('Notification'),
                  'URL' => get_root_url() . 'notification.php',
                  'REL' => 'rel="nofollow"',
              ];
            $block->template = 'menubar_menu.tpl';
        }

        // --------------------------------------------------------------- identification
        if (\Piwigo\Auth\AccessControl::isAGuest()) {
            $template->assign(
                [
                    'U_LOGIN' => get_root_url() . 'identification.php',
                    'U_LOST_PASSWORD' => get_root_url() . 'password.php',
                    'AUTHORIZE_REMEMBERING' => $conf['authorize_remembering'],
                ]
            );
            if ((bool) $conf['allow_user_registration']) {
                $template->assign('U_REGISTER', get_root_url() . 'register.php');
            }
        } else {
            $username = $user['username'] ?? null;
            $username = is_string($username) ? $username : '';
            $template->assign('USERNAME', stripslashes($username));
            if (\Piwigo\Auth\AccessControl::isAuthorizeStatus(AccessLevel::Classic)) {
                $template->assign('U_PROFILE', get_root_url() . 'profile.php');
            }

            // the logout link has no meaning with Apache authentication : it is not
            // possible to logout with this kind of authentication.
            if (! (bool) $conf['apache_authentication']) {
                $template->assign('U_LOGOUT', get_root_url() . '?act=logout');
            }
            if (\Piwigo\Auth\AccessControl::isAdmin()) {
                $template->assign('U_ADMIN', get_root_url() . 'admin.php');
            }
        }
        if (($block = $menu->get_block('mbIdentification')) !== null) {
            $block->template = 'menubar_identification.tpl';
        }
        $menu->apply('MENUBAR', 'menubar.tpl');
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

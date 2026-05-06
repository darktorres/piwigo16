<?php

declare(strict_types=1);

use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Menu\BlockManager;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\CurrentUser;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\menubar
 */

initialize_menu();

/**
 * Setups each block the main menubar.
 */
function initialize_menu(): void
{
    $filter = is_array($GLOBALS['filter'] ?? null) ? $GLOBALS['filter'] : [];
    $template = TemplateRegistry::current();
    $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
    $user = CurrentUser::get()->rawAttributes;

    $menu = new BlockManager('menubar');

    // if guest_access is disabled, we only display the menus if the user is identified
    if (Config::guestAccess() or !is_a_guest()) {
        $menu->load_registered_blocks();
    }
    $menu->prepare_display();

    if (($page['section'] ?? null) == 'search' && isset($page['qsearch_details']) && is_array($page['qsearch_details'])) {
        $qsearchQ = is_scalar($page['qsearch_details']['q'] ?? null) ? (string) $page['qsearch_details']['q'] : '';
        $template->assign('QUERY_SEARCH', htmlspecialchars($qsearchQ));
    }

    //--------------------------------------------------------------- external links
    if (($block = $menu->get_block('mbLinks')) and !empty(Config::links())) {
        $block->data = [];
        foreach (Config::links() as $url => $url_data) {
            if (!is_array($url_data)) {
                $url_data = ['label' => $url_data];
            }

            if (
                (!isset($url_data['eval_visible']))
                or
                (eval($url_data['eval_visible']))
            ) {
                $tpl_var = [
                    'URL' => $url,
                    'LABEL' => $url_data['label'],
                  ];

                if (!isset($url_data['new_window']) or $url_data['new_window']) {
                    $tpl_var['new_window'] =
                      [
                        'NAME' => ($url_data['nw_name'] ?? ''),
                        'FEATURES' => ($url_data['nw_features'] ?? ''),
                      ];
                }
                $block->data[] = $tpl_var;
            }
        }
        if (!empty($block->data)) {
            $block->template = 'menubar_links.tpl';
        }
    }

    //-------------------------------------------------------------- categories
    $block = $menu->get_block('mbCategories');
    //------------------------------------------------------------------------ filter
    if (Config::menubarFilterIcon() and !empty(Config::filterPages()) and get_filter_page_value('used')) {
        if ($filter['enabled']) {
            $template->assign(
                'U_STOP_FILTER',
                add_url_params(make_index_url([]), ['filter' => 'stop'])
            );
        } else {
            $template->assign(
                'U_START_FILTER',
                add_url_params(make_index_url([]), ['filter' => 'start-recent-'.(is_scalar($user['recent_period'] ?? null) ? (string) $user['recent_period'] : '')])
            );
        }
    }

    if ($block != null) {
        $block->data = [
          'NB_PICTURE' => $user['nb_total_images'] ?? 0,
          'MENU_CATEGORIES' => get_categories_menu(),
          'U_CATEGORIES' => make_index_url(['section' => 'categories']),
        ];
        $block->template = 'menubar_categories.tpl';
    }

    //------------------------------------------------------------ related categories
    $block = $menu->get_block('mbRelatedCategories');

    $items = is_array($page['items'] ?? null) ? $page['items'] : [];
    if (
        $items !== []
        and count($items) < Config::relatedAlbumsMaximumItemsToCompute()
        and $block != null
    ) {
        /** @var list<int> $exclude_cat_ids */
        $exclude_cat_ids = [];
        $category = is_array($page['category'] ?? null) ? $page['category'] : null;
        if ($category !== null) {
            if (isset($category['id']) && is_numeric($category['id'])) {
                $exclude_cat_ids[] = (int) $category['id'];
            }
            $combined = is_array($page['combined_categories'] ?? null) ? $page['combined_categories'] : [];
            foreach ($combined as $cat) {
                if (is_array($cat) && isset($cat['id']) && is_numeric($cat['id'])) {
                    $exclude_cat_ids[] = (int) $cat['id'];
                }
            }
        }

        $block->data = [
          'MENU_CATEGORIES' => get_related_categories_menu($items, $exclude_cat_ids),
        ];

        if (!empty($block->data['MENU_CATEGORIES'])) {
            $block->template = 'menubar_related_categories.tpl';
        }
    }

    //------------------------------------------------------------------------ tags
    $block = $menu->get_block('mbTags');
    if ($block != null and 'picture' != script_basename()) {
        $tags = get_available_tags();
        usort($tags, fn (mixed $a, mixed $b): int => tags_counter_compare(is_array($a) ? $a : [], is_array($b) ? $b : []));
        $tags = array_slice($tags, 0, Config::menubarTagCloudItemsNumber());
        foreach ($tags as $tag) {
            $tagArr = is_array($tag) ? $tag : [];
            $block->data[] = array_merge(
                $tagArr,
                [
                'URL' => make_index_url([ 'tags' => [$tag] ]),
        ]
            );
        }

        if (!empty($block->data)) {
            $block->template = 'menubar_tags.tpl';
        }
    }

    //----------------------------------------------------------- special categories
    if (($block = $menu->get_block('mbSpecials')) != null) {
        if (!is_a_guest()) {// favorites
            $block->data['favorites'] =
              [
                'URL' => make_index_url(['section' => 'favorites']),
                'TITLE' => l10n('display your favorites photos'),
                'NAME' => l10n('Your favorites'),
                ];
        }

        $block->data['most_visited'] =
          [
            'URL' => make_index_url(['section' => 'most_visited']),
            'TITLE' => l10n('display most visited photos'),
            'NAME' => l10n('Most visited'),
          ];

        if (Config::rateEnabled()) {
            $block->data['best_rated'] =
             [
               'URL' => make_index_url(['section' => 'best_rated']),
               'TITLE' => l10n('display best rated photos'),
               'NAME' => l10n('Best rated'),
             ];
        }

        $block->data['recent_pics'] =
          [
            'URL' => make_index_url(['section' => 'recent_pics']),
            'TITLE' => l10n('display most recent photos'),
            'NAME' => l10n('Recent photos'),
          ];

        $block->data['recent_cats'] =
          [
            'URL' => make_index_url(['section' => 'recent_cats']),
            'TITLE' => l10n('display recently updated albums'),
            'NAME' => l10n('Recent albums'),
          ];

        $block->data['random'] =
          [
            'URL' => ServiceLocator::get(UrlGenerator::class)->random(),
            'TITLE' => l10n('display a set of random photos'),
            'NAME' => l10n('Random photos'),
            'REL' => 'rel="nofollow"',
          ];

        $block->data['calendar'] =
          [
            'URL' =>
              make_index_url(
                  [
                  'chronology_field' => (Config::calendarDatefield() == 'date_available'
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


    //---------------------------------------------------------------------- summary
    if (($block = $menu->get_block('mbMenu')) != null) {
        // quick search block will be displayed only if data['qsearch'] is set
        // to "yes"
        $block->data['qsearch'] = true;

        // tags link
        $block->data['tags'] =
          [
            'TITLE' => l10n('display available tags'),
            'NAME' => l10n('Tags'),
            'URL' => ServiceLocator::get(UrlGenerator::class)->tagsPage(),
            'COUNTER' => get_nb_available_tags(),
          ];

        // search link
        $block->data['search'] =
          [
            'TITLE' => l10n('search'),
            'NAME' => l10n('Search'),
            'URL' => ServiceLocator::get(UrlGenerator::class)->searchPage(),
            'REL' => 'rel="search"',
          ];

        if (Config::activateComments()) {
            // comments link
            $block->data['comments'] =
              [
                'TITLE' => l10n('display last user comments'),
                'NAME' => l10n('Comments'),
                'URL' => ServiceLocator::get(UrlGenerator::class)->comments(),
                'COUNTER' => get_nb_available_comments(),
              ];
        }

        // about link
        $block->data['about'] =
          [
            'TITLE'     => l10n('About Piwigo'),
            'NAME'      => l10n('About'),
            'URL' => get_root_url().'about.php',
          ];

        // notification
        $block->data['rss'] =
          [
            'TITLE' => l10n('RSS feed'),
            'NAME' => l10n('Notification'),
            'URL' => ServiceLocator::get(UrlGenerator::class)->notification(),
            'REL' => 'rel="nofollow"',
          ];
        $block->template = 'menubar_menu.tpl';
    }


    //--------------------------------------------------------------- identification
    if (is_a_guest()) {
        $template->assign(
            [
              'U_LOGIN' => ServiceLocator::get(UrlGenerator::class)->identification(),
              'U_LOST_PASSWORD' => ServiceLocator::get(UrlGenerator::class)->password(),
              'AUTHORIZE_REMEMBERING' => Config::authorizeRemembering(),
            ]
        );
        if (Config::allowUserRegistration()) {
            $template->assign('U_REGISTER', ServiceLocator::get(UrlGenerator::class)->register());
        }
    } else {
        $template->assign('USERNAME', stripslashes(CurrentUser::get()->username));
        if (is_autorize_status(ACCESS_CLASSIC)) {
            $template->assign('U_PROFILE', ServiceLocator::get(UrlGenerator::class)->profile());
        }

        // the logout link has no meaning with Apache authentication : it is not
        // possible to logout with this kind of authentication.
        if (!Config::apacheAuthentication()) {
            $template->assign('U_LOGOUT', get_root_url().'?act=logout');
        }
        if (is_admin()) {
            $template->assign('U_ADMIN', ServiceLocator::get(UrlGenerator::class)->admin());
        }
    }
    if (($block = $menu->get_block('mbIdentification')) != null) {
        $block->template = 'menubar_identification.tpl';
    }
    $menu->apply('MENUBAR', 'menubar.tpl');
}

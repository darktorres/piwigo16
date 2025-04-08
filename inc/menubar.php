<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

final class menubar
{
    /**
     * Setups each block the main menubar.
     */
    public static function initialize_menu(): void
    {
        global $page, $conf, $user, $template, $filter;

        $menu = new BlockManager('menubar');

        // if guest_access is disabled, we only display the menus if the user is identified
        if ($conf->guest_access ||
            ! functions_user::is_a_guest()
        ) {
            $menu->load_registered_blocks();
        }

        $menu->prepare_display();

        if (($page['section'] ?? null) == 'search' &&
            isset($page['qsearch_details'])
        ) {
            $template->assign('QUERY_SEARCH', htmlspecialchars($page['qsearch_details']['q']));
        }

        //--------------------------------------------------------------- external links
        $block = $menu->get_block('mbLinks');
        if ($block &&
            $conf->links !== []
        ) {
            $block->data = [];

            foreach ($conf->links as $url => $url_data) {
                if (! is_array($url_data)) {
                    $url_data = [
                        'label' => $url_data,
                    ];
                }

                if (! isset($url_data['eval_visible']) ||
                    eval($url_data['eval_visible'])
                ) {
                    $tpl_var = [
                        'URL' => $url,
                        'LABEL' => $url_data['label'],
                    ];

                    if (! isset($url_data['new_window']) ||
                        $url_data['new_window']
                    ) {
                        $tpl_var['new_window'] =
                          [
                              'NAME' => ($url_data['nw_name'] ?? ''),
                              'FEATURES' => ($url_data['nw_features'] ?? ''),
                          ];
                    }

                    $block->data[] = $tpl_var;
                }
            }

            if ($block->data !== []) {
                $block->template = 'menubar_links.tpl';
            }
        }

        //-------------------------------------------------------------- categories
        $block = $menu->get_block('mbCategories');

        //------------------------------------------------------------------------ filter
        if ($conf->menubar_filter_icon &&
            $conf->filter_pages !== [] &&
            functions::get_filter_page_value('used')
        ) {
            if ($filter['enabled']) {
                $template->assign(
                    'U_STOP_FILTER',
                    functions_url::add_url_params(functions_url::make_index_url(), [
                        'filter' => 'stop',
                    ])
                );
            } else {
                $template->assign(
                    'U_START_FILTER',
                    functions_url::add_url_params(functions_url::make_index_url(), [
                        'filter' => 'start-recent-' . $user['recent_period'],
                    ])
                );
            }
        }

        if ($block != null) {
            $block->data = [
                'NB_PICTURE' => $user['nb_total_images'],
                'MENU_CATEGORIES' => functions_category::get_categories_menu(),
                'U_CATEGORIES' => functions_url::make_index_url([
                    'section' => 'categories',
                ]),
            ];
            $block->template = 'menubar_categories.tpl';
        }

        //------------------------------------------------------------ related categories
        $block = $menu->get_block('mbRelatedCategories');

        if (isset($page['items']) &&
            count($page['items']) < $conf->related_albums_maximum_items_to_compute &&
            $block != null &&
            ! empty($page['items'])
        ) {
            $exclude_cat_ids = [];

            if (isset($page['category'])) {
                $exclude_cat_ids = [$page['category']['id']];

                if (isset($page['combined_categories'])) {
                    foreach ($page['combined_categories'] as $cat) {
                        $exclude_cat_ids[] = $cat['id'];
                    }
                }
            }

            $block->data = [
                'MENU_CATEGORIES' => functions_category::get_related_categories_menu($page['items'], $exclude_cat_ids),
            ];

            if (! empty($block->data['MENU_CATEGORIES'])) {
                $block->template = 'menubar_related_categories.tpl';
            }
        }

        //------------------------------------------------------------------------ tags
        $block = $menu->get_block('mbTags');

        if ($block != null &&
            functions::script_basename() !== 'picture'
        ) {
            if (($page['section'] ?? null) == 'tags') {
                $tags = functions_tag::get_common_tags(
                    $page['items'],
                    $conf->menubar_tag_cloud_items_number,
                    $page['tag_ids']
                );
                $tags = functions_tag::add_level_to_tags($tags);

                foreach ($tags as $tag) {
                    $block->data[] = array_merge(
                        $tag,
                        [
                            'U_ADD' => functions_url::make_index_url(
                                [
                                    'tags' => array_merge(
                                        $page['tags'],
                                        [$tag]
                                    ),
                                ]
                            ),
                            'URL' => functions_url::make_index_url(
                                [
                                    'tags' => [$tag],
                                ]
                            ),
                        ]
                    );
                }

                $template->assign('IS_RELATED', false);
            }
            //displays all tags available for the current user
            elseif ($conf->menubar_tag_cloud_content === 'always_all' ||
                   ($conf->menubar_tag_cloud_content === 'all_or_current' && empty($page['items']))
            ) {
                $tags = functions_tag::get_available_tags();
                usort($tags, functions_tag::tags_counter_compare(...));
                $tags = array_slice($tags, 0, $conf->menubar_tag_cloud_items_number);

                foreach ($tags as $tag) {
                    $block->data[] = array_merge(
                        $tag,
                        [
                            'URL' => functions_url::make_index_url([
                                'tags' => [$tag],
                            ]),
                        ]
                    );
                }

                $template->assign('IS_RELATED', false);
            }
            // displays only the tags available from the current thumbnails displayed
            elseif (! empty($page['items']) &&
                   ($conf->menubar_tag_cloud_content === 'current_only' || $conf->menubar_tag_cloud_content === 'all_or_current')
            ) {
                $selection = array_slice($page['items'], (int) $page['start'], (int) $page['nb_image_page']);
                $tags = functions_tag::add_level_to_tags(functions_tag::get_common_tags($selection, $conf->content_tag_cloud_items_number));

                foreach ($tags as $tag) {
                    $block->data[] =
                    array_merge(
                        $tag,
                        [
                            'URL' => functions_url::make_index_url([
                                'tags' => [$tag],
                            ]),
                        ]
                    );
                }

                $template->assign('IS_RELATED', true);
            }

            if ($block->data !== []) {
                $block->template = 'menubar_tags.tpl';
            }
        }

        //----------------------------------------------------------- special categories
        $block = $menu->get_block('mbSpecials');

        if ($block != null) {
            if (! functions_user::is_a_guest()) { // favorites
                $block->data['favorites'] =
                  [
                      'URL' => functions_url::make_index_url([
                          'section' => 'favorites',
                      ]),
                      'TITLE' => functions::l10n('display your favorites photos'),
                      'NAME' => functions::l10n('Your favorites'),
                  ];
            }

            $block->data['most_visited'] =
              [
                  'URL' => functions_url::make_index_url([
                      'section' => 'most_visited',
                  ]),
                  'TITLE' => functions::l10n('display most visited photos'),
                  'NAME' => functions::l10n('Most visited'),
              ];

            if ($conf->rate) {
                $block->data['best_rated'] =
                  [
                      'URL' => functions_url::make_index_url([
                          'section' => 'best_rated',
                      ]),
                      'TITLE' => functions::l10n('display best rated photos'),
                      'NAME' => functions::l10n('Best rated'),
                  ];
            }

            $block->data['recent_pics'] =
              [
                  'URL' => functions_url::make_index_url([
                      'section' => 'recent_pics',
                  ]),
                  'TITLE' => functions::l10n('display most recent photos'),
                  'NAME' => functions::l10n('Recent photos'),
              ];

            $block->data['recent_cats'] =
              [
                  'URL' => functions_url::make_index_url([
                      'section' => 'recent_cats',
                  ]),
                  'TITLE' => functions::l10n('display recently updated albums'),
                  'NAME' => functions::l10n('Recent albums'),
              ];

            $block->data['random'] =
              [
                  'URL' => functions_url::get_root_url() . 'random.php',
                  'TITLE' => functions::l10n('display a set of random photos'),
                  'NAME' => functions::l10n('Random photos'),
                  'REL' => 'rel="nofollow"',
              ];

            $block->data['calendar'] =
              [
                  'URL' =>
                    functions_url::make_index_url(
                        [
                            'chronology_field' => ($conf->calendar_datefield === 'date_available'
                                                    ? 'posted' : 'created'),
                            'chronology_style' => 'monthly',
                            'chronology_view' => 'calendar',
                        ]
                    ),
                  'TITLE' => functions::l10n('display each day with photos, month per month'),
                  'NAME' => functions::l10n('Calendar'),
                  'REL' => 'rel="nofollow"',
              ];
            $block->template = 'menubar_specials.tpl';
        }

        //---------------------------------------------------------------------- summary
        $block = $menu->get_block('mbMenu');

        if ($block != null) {
            // quick search block will be displayed only if data['qsearch'] is set
            // to "yes"
            $block->data['qsearch'] = true;

            // tags link
            $block->data['tags'] =
              [
                  'TITLE' => functions::l10n('display available tags'),
                  'NAME' => functions::l10n('Tags'),
                  'URL' => functions_url::get_root_url() . 'tags.php',
                  'COUNTER' => functions_tag::get_nb_available_tags(),
              ];

            // search link
            $block->data['search'] =
              [
                  'TITLE' => functions::l10n('search'),
                  'NAME' => functions::l10n('Search'),
                  'URL' => functions_url::get_root_url() . 'search.php',
                  'REL' => 'rel="search"',
              ];

            if ($conf->activate_comments) {
                // comments link
                $block->data['comments'] =
                  [
                      'TITLE' => functions::l10n('display last user comments'),
                      'NAME' => functions::l10n('Comments'),
                      'URL' => functions_url::get_root_url() . 'comments.php',
                      'COUNTER' => functions::get_nb_available_comments(),
                  ];
            }

            // about link
            $block->data['about'] =
              [
                  'TITLE' => functions::l10n('About Piwigo'),
                  'NAME' => functions::l10n('About'),
                  'URL' => functions_url::get_root_url() . 'about.php',
              ];

            // notification
            $block->data['rss'] =
              [
                  'TITLE' => functions::l10n('RSS feed'),
                  'NAME' => functions::l10n('Notification'),
                  'URL' => functions_url::get_root_url() . 'notification.php',
                  'REL' => 'rel="nofollow"',
              ];
            $block->template = 'menubar_menu.tpl';
        }

        //--------------------------------------------------------------- identification
        if (functions_user::is_a_guest()) {
            $template->assign(
                [
                    'U_LOGIN' => functions_url::get_root_url() . 'identification.php',
                    'U_LOST_PASSWORD' => functions_url::get_root_url() . 'password.php',
                    'AUTHORIZE_REMEMBERING' => $conf->authorize_remembering,
                ]
            );

            if ($conf->allow_user_registration) {
                $template->assign('U_REGISTER', functions_url::get_root_url() . 'register.php');
            }
        } else {
            $template->assign('USERNAME', stripslashes($user['username']));

            if (functions_user::is_authorized_status(ACCESS_CLASSIC)) {
                $template->assign('U_PROFILE', functions_url::get_root_url() . 'profile.php');
            }

            // the logout link has no meaning with Apache authentication : it is not
            // possible to logout with this kind of authentication.
            if (! $conf->apache_authentication) {
                $template->assign('U_LOGOUT', functions_url::get_root_url() . '?act=logout');
            }

            if (functions_user::is_admin()) {
                $template->assign('U_ADMIN', functions_url::get_root_url() . 'admin.php');
            }
        }

        $block = $menu->get_block('mbIdentification');

        if ($block != null) {
            $block->template = 'menubar_identification.tpl';
        }

        $menu->apply('MENUBAR', 'menubar.tpl');
    }
}

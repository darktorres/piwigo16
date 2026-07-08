<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

add_event_handler('tabsheet_before_select', 'add_core_tabs');

/**
 * @param array<string, array{caption: string, url: string}> $sheets
 * @return array<string, array{caption: string, url: string}>
 */
function add_core_tabs(array $sheets, mixed $tab_id): array
{
    global $conf;

    switch ($tab_id) {
        case 'admin_home':
            $sheets[''] = [
                'caption' => l10n('Administration Home'),
                'url' => 'admin.php',
            ];
            break;

        case 'tags':
            global $my_base_url;
            $sheets[''] = [
                'caption' => '<span class="icon-menu"></span>' . l10n('List'),
                'url' => $my_base_url . 'tags',
            ];
            break;

        case 'album':
            global $admin_album_base_url;
            $sheets['properties'] = [
                'caption' => '<span class="icon-pencil"></span>' . l10n('Properties'),
                'url' => $admin_album_base_url . '-properties',
            ];
            $sheets['sort_order'] = [
                'caption' => '<span class="icon-shuffle"></span>' . l10n('Manage photo ranks'),
                'url' => $admin_album_base_url . '-sort_order',
            ];
            $sheets['permissions'] = [
                'caption' => '<span class="icon-lock"></span>' . l10n('Permissions'),
                'url' => $admin_album_base_url . '-permissions',
            ];
            $sheets['notification'] = [
                'caption' => '<span class="icon-mail-alt"></span>' . l10n('Notification'),
                'url' => $admin_album_base_url . '-notification',
            ];
            break;

        case 'albums':
            global $my_base_url;
            $sheets['list'] = [
                'caption' => '<span class="icon-menu"></span>' . l10n('List'),
                'url' => $my_base_url . 'albums',
            ];
            $sheets['permalinks'] = [
                'caption' => '<span class="icon-link-1"></span>' . l10n('Permalinks'),
                'url' => $my_base_url . 'permalinks',
            ];
            break;

        case 'users':
            global $my_base_url;
            $sheets['user_list'] = [
                'caption' => '<span class="icon-menu"></span>' . l10n('List'),
                'url' => $my_base_url . 'user_list',
            ];
            $sheets['user_activity'] = [
                'caption' => '<span class="icon-pulse"></span>' . l10n('Activity'),
                'url' => $my_base_url . 'user_activity',
            ];
            break;

        case 'batch_manager':
            global $manager_link;
            $sheets['global'] = [
                'caption' => '<span class="icon-th"></span>' . l10n('global mode'),
                'url' => $manager_link . 'global',
            ];
            $sheets['unit'] = [
                'caption' => '<span class="icon-th-list"></span>' . l10n('unit mode'),
                'url' => $manager_link . 'unit',
            ];
            break;

        case 'cat_options':
            global $link_start;
            $sheets['status'] = [
                'caption' => '<span class="icon-lock"></span>' . l10n('Public / Private'),
                'url' => $link_start . 'cat_options&amp;section=status',
            ];
            $sheets['visible'] = [
                'caption' => '<span class="icon-block"></span>' . l10n('Lock'),
                'url' => $link_start . 'cat_options&amp;section=visible',
            ];
            if ($conf['activate_comments']) {
                $sheets['comments'] = [
                    'caption' => '<span class="icon-chat"></span>' . l10n('Comments'),
                    'url' => $link_start . 'cat_options&amp;section=comments',
                ];
            }
            if ($conf['allow_random_representative']) {
                $sheets['representative'] = [
                    'caption' => l10n('Representative'),
                    'url' => $link_start . 'cat_options&amp;section=representative',
                ];
            }
            break;

        case 'comments':
            global $my_base_url;
            $sheets[''] = [
                'caption' => '<span class="icon-menu"></span>' . l10n('List'),
                'url' => $my_base_url . 'comments',
            ];
            break;

        case 'users':
            global $my_base_url;
            $sheets[''] = [
                'caption' => '<span class="icon-users"> </span>' . l10n('User list'),
                'url' => $my_base_url . 'user_list',
            ];
            break;

        case 'groups':
            global $my_base_url;
            $sheets[''] = [
                'caption' => '<span class="icon-menu"> </span>' . l10n('List'),
                'url' => $my_base_url . 'group_list',
            ];
            break;

        case 'configuration':
            global $conf_link;
            $sheets['main'] = [
                'caption' => '<span class="icon-cog"></span>' . l10n('General'),
                'url' => $conf_link . 'main',
            ];
            $sheets['sizes'] = [
                'caption' => '<span class="icon-zoom-square"></span>' . l10n('Photo sizes'),
                'url' => $conf_link . 'sizes',
            ];
            $sheets['watermark'] = [
                'caption' => '<span class="icon-file-image"></span>' . l10n('Watermark'),
                'url' => $conf_link . 'watermark',
            ];
            $sheets['display'] = [
                'caption' => '<span class="icon-television"></span>' . l10n('Display'),
                'url' => $conf_link . 'display',
            ];
            $sheets['comments'] = [
                'caption' => '<span class="icon-chat"></span>' . l10n('Comments'),
                'url' => $conf_link . 'comments',
            ];
            $sheets['search'] = [
                'caption' => '<span class="icon-search"></span>' . l10n('Search'),
                'url' => $conf_link . 'search',
            ];
            // $sheets['default'] = array('caption' => l10n('Guest Settings'), 'url' => $conf_link.'default');
            break;

        case 'help':
            global $help_link;
            $sheets['add_photos'] = [
                'caption' => l10n('Add Photos'),
                'url' => $help_link . 'add_photos',
            ];
            $sheets['permissions'] = [
                'caption' => l10n('Permissions'),
                'url' => $help_link . 'permissions',
            ];
            $sheets['groups'] = [
                'caption' => l10n('Groups'),
                'url' => $help_link . 'groups',
            ];
            $sheets['virtual_links'] = [
                'caption' => l10n('Virtual Links'),
                'url' => $help_link . 'virtual_links',
            ];
            $sheets['misc'] = [
                'caption' => l10n('Miscellaneous'),
                'url' => $help_link . 'misc',
            ];
            break;

        case 'history':
            global $link_start;
            $sheets['stats'] = [
                'caption' => '<span class="icon-signal"></span>' . l10n('Statistics'),
                'url' => $link_start . 'stats',
            ];
            $sheets['history'] = [
                'caption' => '<span class="icon-search"></span>' . l10n('Search'),
                'url' => $link_start . 'history',
            ];
            break;

        case 'languages':
            global $my_base_url;
            $sheets['installed'] = [
                'caption' => '<span class="icon-menu"></span>' . l10n('List'),
                'url' => $my_base_url . '&amp;tab=installed',
            ];
            if ($conf['enable_extensions_install']) {
                $sheets['update'] = [
                    'caption' => '<span class="icon-arrows-cw"></span>' . l10n('Check for updates'),
                    'url' => $my_base_url . '&amp;tab=update',
                ];
                $sheets['new'] = [
                    'caption' => '<span class="icon-plus-circled"></span>' . l10n('Add New Language'),
                    'url' => $my_base_url . '&amp;tab=new',
                ];
            }
            break;

        case 'menus':
            global $my_base_url;
            $sheets[''] = [
                'caption' => '<span class="icon-menu"></span>' . l10n('List'),
                'url' => $my_base_url . 'menubar',
            ];
            break;

        case 'nbm':
            global $base_url;
            $sheets['param'] = [
                'caption' => l10n('Parameter'),
                'url' => $base_url . '?page=notification_by_mail&amp;mode=param',
            ];
            $sheets['subscribe'] = [
                'caption' => l10n('Subscribe'),
                'url' => $base_url . '?page=notification_by_mail&amp;mode=subscribe',
            ];
            $sheets['send'] = [
                'caption' => l10n('Send'),
                'url' => $base_url . '?page=notification_by_mail&amp;mode=send',
            ];
            break;

        case 'photo':
            global $admin_photo_base_url, $conf;
            $sheets['properties'] = [
                'caption' => '<span class="icon-file-image"></span>' . l10n('Properties'),
                'url' => $admin_photo_base_url . '-properties',
            ];
            $sheets['coi'] = [
                'caption' => '<span class="icon-crop"></span>' . l10n('Center of interest'),
                'url' => $admin_photo_base_url . '-coi',
            ];
            if ($conf['enable_formats']) {
                $sheets['formats'] = [
                    'caption' => '<span class="icon-docs"></span>' . l10n('Formats'),
                    'url' => $admin_photo_base_url . '-formats',
                ];
            }
            break;

        case 'photos_add':
            $sheets['direct'] = [
                'caption' => '<span class="icon-upload"></span>' . l10n('Web Form'),
                'url' => PHOTOS_ADD_BASE_URL . '&amp;section=direct',
            ];
            $sheets['applications'] = [
                'caption' => '<span class="icon-network"></span>' . l10n('Applications'),
                'url' => PHOTOS_ADD_BASE_URL . '&amp;section=applications',
            ];
            if ($conf['enable_synchronization']) {
                $sheets['ftp'] = [
                    'caption' => '<span class="icon-exchange"></span>' . l10n('FTP + Synchronization'),
                    'url' => PHOTOS_ADD_BASE_URL . '&amp;section=ftp',
                ];
            }
            break;

        case 'plugins':
            global $my_base_url;
            $sheets['installed'] = [
                'caption' => '<span class="icon-menu"></span>' . l10n('List'),
                'url' => $my_base_url . '&amp;tab=installed',
            ];
            if ($conf['enable_extensions_install']) {
                $sheets['update'] = [
                    'caption' => '<span class="icon-arrows-cw"></span>' . l10n('Check for updates'),
                    'url' => $my_base_url . '&amp;tab=update',
                ];
                $sheets['new'] = [
                    'caption' => '<span class="icon-plus-circled"></span>' . l10n('Add New Plugin'),
                    'url' => $my_base_url . '&amp;tab=new',
                ];
            }
            break;

        case 'rating':
            $sheets['rating'] = [
                'caption' => l10n('Photos'),
                'url' => get_root_url() . 'admin.php?page=rating',
            ];
            $sheets['rating_user'] = [
                'caption' => l10n('Users'),
                'url' => get_root_url() . 'admin.php?page=rating_user',
            ];
            break;

        case 'themes':
            global $my_base_url;
            $sheets['installed'] = [
                'caption' => '<span class="icon-menu"></span>' . l10n('List'),
                'url' => $my_base_url . '&amp;tab=installed',
            ];
            if ($conf['enable_extensions_install']) {
                $sheets['update'] = [
                    'caption' => '<span class="icon-arrows-cw"></span>' . l10n('Check for updates'),
                    'url' => $my_base_url . '&amp;tab=update',
                ];
                $sheets['new'] = [
                    'caption' => '<span class="icon-plus-circled"></span>' . l10n('Add New Theme'),
                    'url' => $my_base_url . '&amp;tab=new',
                ];
            }
            $sheets['standard_pages'] = [
                'caption' => '<span class="icon-cog-alt"></span>' . l10n('Standard pages'),
                'url' => $my_base_url . '&amp;tab=standard_pages',
            ];
            break;

        case 'updates':
            global $my_base_url;

            if ($conf['enable_core_update']) {
                $sheets['pwg'] = [
                    'caption' => l10n('Piwigo core'),
                    'url' => $my_base_url,
                ];
            }

            if ($conf['enable_extensions_install']) {
                $sheets['ext'] = [
                    'caption' => l10n('Extensions'),
                    'url' => $my_base_url . '&amp;tab=ext',
                ];
            }
            break;
        case 'site_update':
            global $my_base_url;
            $sheets['synchronization'] = [
                'caption' => '<span class="icon-exchange"></span>' . l10n('Synchronization'),
                'url' => $my_base_url . 'site_update&site=1',
            ];
            $sheets['site_maager'] = [
                'caption' => '<span class="icon-flow-branch"></span>' . l10n('Site manager'),
                'url' => $my_base_url . 'site_manager',
            ];
            break;
        case 'maintenance':
            global $my_base_url;
            $sheets['actions'] = [
                'caption' => '<span class="icon-tools"></span>' . l10n('Actions'),
                'url' => $my_base_url . 'maintenance&tab=actions',
            ];
            $sheets['env'] = [
                'caption' => '<span class="icon-television"></span>' . l10n('Environment'),
                'url' => $my_base_url . 'maintenance&tab=env',
            ];
            $sheets['sys'] = [
                'caption' => '<span class="icon-pulse"></span>' . l10n('System Activities'),
                'url' => $my_base_url . 'maintenance&tab=sys',
            ];
            break;
    }

    return $sheets;
}

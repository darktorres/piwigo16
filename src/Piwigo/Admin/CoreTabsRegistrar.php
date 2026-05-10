<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Core\ServiceLocator;
use Piwigo\Url\UrlGenerator;

final class CoreTabsRegistrar
{
    /**
     * @param array<mixed> $sheets
     * @return array<mixed>
     */
    public static function addCoreTabs(array $sheets, string $tab_id): array
    {
        $my_base_url          = is_string($GLOBALS['my_base_url'] ?? null) ? $GLOBALS['my_base_url'] : '';
        $admin_album_base_url = is_string($GLOBALS['admin_album_base_url'] ?? null) ? $GLOBALS['admin_album_base_url'] : '';
        $admin_photo_base_url = is_string($GLOBALS['admin_photo_base_url'] ?? null) ? $GLOBALS['admin_photo_base_url'] : '';
        $manager_link         = is_string($GLOBALS['manager_link'] ?? null) ? $GLOBALS['manager_link'] : '';
        $link_start           = is_string($GLOBALS['link_start'] ?? null) ? $GLOBALS['link_start'] : '';
        $conf_link            = is_string($GLOBALS['conf_link'] ?? null) ? $GLOBALS['conf_link'] : '';
        $help_link            = is_string($GLOBALS['help_link'] ?? null) ? $GLOBALS['help_link'] : '';
        $base_url             = is_string($GLOBALS['base_url'] ?? null) ? $GLOBALS['base_url'] : '';

        switch ($tab_id) {
            case 'admin_home':
                $sheets[''] = ['caption' => Lang::t('Administration Home'), 'url' => ServiceLocator::get(UrlGenerator::class)->admin()];
                break;

            case 'tags':
                $sheets[''] = ['caption' => '<span class="icon-menu"></span>'.Lang::t('List'), 'url' => $my_base_url.'tags'];
                break;

            case 'album':
                $sheets['properties'] = ['caption' => '<span class="icon-pencil"></span>'.Lang::t('Properties'), 'url' => $admin_album_base_url.'-properties'];
                $sheets['sort_order'] = ['caption' => '<span class="icon-shuffle"></span>'.Lang::t('Manage photo ranks'), 'url' => $admin_album_base_url.'-sort_order'];
                $sheets['permissions'] = ['caption' => '<span class="icon-lock"></span>'.Lang::t('Permissions'), 'url' => $admin_album_base_url.'-permissions'];
                $sheets['notification'] = ['caption' => '<span class="icon-mail-alt"></span>'.Lang::t('Notification'), 'url' => $admin_album_base_url.'-notification'];
                break;

            case 'albums':
                $sheets['list'] = ['caption' => '<span class="icon-menu"></span>'.Lang::t('List'), 'url' => $my_base_url.'albums'];
                $sheets['permalinks'] = ['caption' => '<span class="icon-link-1"></span>'.Lang::t('Permalinks'), 'url' => $my_base_url.'permalinks'];
                break;

            case 'users':
                $sheets['user_list'] = ['caption' => '<span class="icon-menu"></span>'.Lang::t('List'), 'url' => $my_base_url.'user_list'];
                $sheets['user_activity'] = ['caption' => '<span class="icon-pulse"></span>'.Lang::t('Activity'), 'url' => $my_base_url.'user_activity'];
                break;

            case 'batch_manager':
                $sheets['global'] = ['caption' => '<span class="icon-th"></span>'.Lang::t('global mode'), 'url' => $manager_link.'global'];
                $sheets['unit'] = ['caption' => '<span class="icon-th-list"></span>'.Lang::t('unit mode'), 'url' => $manager_link.'unit'];
                break;

            case 'cat_options':
                $sheets['status'] = ['caption' => '<span class="icon-lock"></span>'.Lang::t('Public / Private'), 'url' => $link_start.'cat_options&section=status'];
                $sheets['visible'] = ['caption' => '<span class="icon-block"></span>'.Lang::t('Lock'), 'url' => $link_start.'cat_options&section=visible'];
                if (Config::activateComments()) {
                    $sheets['comments'] = ['caption' => '<span class="icon-chat"></span>'.Lang::t('Comments'), 'url' => $link_start.'cat_options&section=comments'];
                }
                if (Config::allowRandomRepresentative()) {
                    $sheets['representative'] = ['caption' => Lang::t('Representative'), 'url' => $link_start.'cat_options&section=representative'];
                }
                break;

            case 'comments':
                $sheets[''] = ['caption' => '<span class="icon-menu"></span>'.Lang::t('List'), 'url' => $my_base_url.'comments'];
                break;

            case 'groups':
                $sheets[''] = ['caption' => '<span class="icon-menu"> </span>'.Lang::t('List'), 'url' => $my_base_url.'group_list'];
                break;

            case 'configuration':
                $sheets['main'] = ['caption' => '<span class="icon-cog"></span>'.Lang::t('General'), 'url' => $conf_link.'main'];
                $sheets['sizes'] = ['caption' => '<span class="icon-zoom-square"></span>'.Lang::t('Photo sizes'), 'url' => $conf_link.'sizes'];
                $sheets['watermark'] = ['caption' => '<span class="icon-file-image"></span>'.Lang::t('Watermark'), 'url' => $conf_link.'watermark'];
                $sheets['display'] = ['caption' => '<span class="icon-television"></span>'.Lang::t('Display'), 'url' => $conf_link.'display'];
                $sheets['comments'] = ['caption' => '<span class="icon-chat"></span>'.Lang::t('Comments'), 'url' => $conf_link.'comments'];
                $sheets['search'] = ['caption' => '<span class="icon-search"></span>'.Lang::t('Search'), 'url' => $conf_link.'search'];
                break;

            case 'help':
                $sheets['add_photos'] = ['caption' => Lang::t('Add Photos'), 'url' => $help_link.'add_photos'];
                $sheets['permissions'] = ['caption' => Lang::t('Permissions'), 'url' => $help_link.'permissions'];
                $sheets['groups'] = ['caption' => Lang::t('Groups'), 'url' => $help_link.'groups'];
                $sheets['virtual_links'] = ['caption' => Lang::t('Virtual Links'), 'url' => $help_link.'virtual_links'];
                $sheets['misc'] = ['caption' => Lang::t('Miscellaneous'), 'url' => $help_link.'misc'];
                break;

            case 'history':
                $sheets['stats'] = ['caption' => '<span class="icon-signal"></span>'.Lang::t('Statistics'), 'url' => $link_start.'stats'];
                $sheets['history'] = ['caption' => '<span class="icon-search"></span>'.Lang::t('Search'), 'url' => $link_start.'history'];
                break;

            case 'languages':
                $sheets['installed'] = ['caption' => '<span class="icon-menu"></span>'.Lang::t('List'), 'url' => $my_base_url.'&tab=installed'];
                if (Config::enableExtensionsInstall()) {
                    $sheets['update'] = ['caption' => '<span class="icon-arrows-cw"></span>'.Lang::t('Check for updates'), 'url' => $my_base_url.'&tab=update'];
                    $sheets['new'] = ['caption' => '<span class="icon-plus-circled"></span>'.Lang::t('Add New Language'), 'url' => $my_base_url.'&tab=new'];
                }
                break;

            case 'menus':
                $sheets[''] = ['caption' => '<span class="icon-menu"></span>'.Lang::t('List'), 'url' => $my_base_url.'menubar'];
                break;

            case 'nbm':
                $sheets['param'] = ['caption' => Lang::t('Parameter'), 'url' => $base_url.'&page=notification_by_mail&mode=param'];
                $sheets['subscribe'] = ['caption' => Lang::t('Subscribe'), 'url' => $base_url.'&page=notification_by_mail&mode=subscribe'];
                $sheets['send'] = ['caption' => Lang::t('Send'), 'url' => $base_url.'&page=notification_by_mail&mode=send'];
                break;

            case 'photo':
                $sheets['properties'] = ['caption' => '<span class="icon-file-image"></span>'.Lang::t('Properties'), 'url' => $admin_photo_base_url.'-properties'];
                $sheets['coi'] = ['caption' => '<span class="icon-crop"></span>'.Lang::t('Center of interest'), 'url' => $admin_photo_base_url.'-coi'];
                if (Config::isFormatsEnabled()) {
                    $sheets['formats'] = ['caption' => '<span class="icon-docs"></span>'.Lang::t('Formats'), 'url' => $admin_photo_base_url.'-formats'];
                }
                break;

            case 'photos_add':
                $sheets['direct'] = ['caption' => '<span class="icon-upload"></span>'.Lang::t('Web Form'), 'url' => PHOTOS_ADD_BASE_URL.'&section=direct'];
                $sheets['applications'] = ['caption' => '<span class="icon-network"></span>'.Lang::t('Applications'), 'url' => PHOTOS_ADD_BASE_URL.'&section=applications'];
                if (Config::enableSynchronization()) {
                    $sheets['ftp'] = ['caption' => '<span class="icon-exchange"></span>'.Lang::t('FTP + Synchronization'), 'url' => PHOTOS_ADD_BASE_URL.'&section=ftp'];
                }
                break;

            case 'plugins':
                $sheets['installed'] = ['caption' => '<span class="icon-menu"></span>'.Lang::t('List'), 'url' => $my_base_url.'&tab=installed'];
                if (Config::enableExtensionsInstall()) {
                    $sheets['update'] = ['caption' => '<span class="icon-arrows-cw"></span>'.Lang::t('Check for updates'), 'url' => $my_base_url.'&tab=update'];
                    $sheets['new'] = ['caption' => '<span class="icon-plus-circled"></span>'.Lang::t('Add New Plugin'), 'url' => $my_base_url.'&tab=new'];
                }
                break;

            case 'rating':
                $sheets['rating'] = ['caption' => Lang::t('Photos'), 'url' => ServiceLocator::get(UrlGenerator::class)->admin('rating')];
                $sheets['rating_user'] = ['caption' => Lang::t('Users'), 'url' => ServiceLocator::get(UrlGenerator::class)->admin('rating_user')];
                break;

            case 'themes':
                $sheets['installed'] = ['caption' => '<span class="icon-menu"></span>'.Lang::t('List'), 'url' => $my_base_url.'&tab=installed'];
                if (Config::enableExtensionsInstall()) {
                    $sheets['update'] = ['caption' => '<span class="icon-arrows-cw"></span>'.Lang::t('Check for updates'), 'url' => $my_base_url.'&tab=update'];
                    $sheets['new'] = ['caption' => '<span class="icon-plus-circled"></span>'.Lang::t('Add New Theme'), 'url' => $my_base_url.'&tab=new'];
                }
                $sheets['standard_pages'] = ['caption' => '<span class="icon-cog-alt"></span>'.Lang::t('Standard pages'), 'url' => $my_base_url.'&tab=standard_pages'];
                break;

            case 'updates':
                if (Config::enableCoreUpdate()) {
                    $sheets['pwg'] = ['caption' => Lang::t('Piwigo core'), 'url' => $my_base_url];
                }
                if (Config::enableExtensionsInstall()) {
                    $sheets['ext'] = ['caption' => Lang::t('Extensions'), 'url' => $my_base_url.'&tab=ext'];
                }
                break;

            case 'site_update':
                $sheets['synchronization'] = ['caption' => '<span class="icon-exchange"></span>'.Lang::t('Synchronization'), 'url' => $my_base_url.'site_update&site=1'];
                $sheets['site_maager'] = ['caption' => '<span class="icon-flow-branch"></span>'.Lang::t('Site manager'), 'url' => $my_base_url.'site_manager'];
                break;

            case 'maintenance':
                $sheets['actions'] = ['caption' => '<span class="icon-tools"></span>'.Lang::t('Actions'), 'url' => $my_base_url.'maintenance&tab=actions'];
                $sheets['env'] = ['caption' => '<span class="icon-television"></span>'.Lang::t('Environment'), 'url' => $my_base_url.'maintenance&tab=env'];
                $sheets['sys'] = ['caption' => '<span class="icon-pulse"></span>'.Lang::t('System Activities'), 'url' => $my_base_url.'maintenance&tab=sys'];
                break;
        }

        return $sheets;
    }
}

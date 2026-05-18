<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Config\Config;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Url\UrlGenerator;

final class CoreTabsRegistrar
{
    /**
     * @param array<mixed> $sheets
     * @return array<mixed>
     */
    public static function addCoreTabs(array $sheets, string $tab_id): array
    {
        $ug                   = Kernel::service(UrlGenerator::class);
        $rawImageId           = $_GET['image_id'] ?? null;
        $admin_photo_base_url = $ug->admin('photo-' . (is_string($rawImageId) ? $rawImageId : ''));
        $rawCatId             = $_GET['cat_id'] ?? null;
        $admin_album_base_url = $ug->admin('album-' . (is_string($rawCatId) ? $rawCatId : ''));

        switch ($tab_id) {
            case 'admin_home':
                $sheets[''] = ['caption' => Lang::t('Administration Home'), 'url' => $ug->admin()];
                break;

            case 'tags':
                $sheets[''] = ['caption' => '<span class="icon-menu"></span>'.Lang::t('List'), 'url' => $ug->admin('tags')];
                break;

            case 'album':
                $sheets['properties'] = ['caption' => '<span class="icon-pencil"></span>'.Lang::t('Properties'), 'url' => $admin_album_base_url.'-properties'];
                $sheets['sort_order'] = ['caption' => '<span class="icon-shuffle"></span>'.Lang::t('Manage photo ranks'), 'url' => $admin_album_base_url.'-sort_order'];
                $sheets['permissions'] = ['caption' => '<span class="icon-lock"></span>'.Lang::t('Permissions'), 'url' => $admin_album_base_url.'-permissions'];
                $sheets['notification'] = ['caption' => '<span class="icon-mail-alt"></span>'.Lang::t('Notification'), 'url' => $admin_album_base_url.'-notification'];
                break;

            case 'albums':
                $sheets['list'] = ['caption' => '<span class="icon-menu"></span>'.Lang::t('List'), 'url' => $ug->admin('albums')];
                $sheets['permalinks'] = ['caption' => '<span class="icon-link-1"></span>'.Lang::t('Permalinks'), 'url' => $ug->admin('permalinks')];
                break;

            case 'users':
                $sheets['user_list'] = ['caption' => '<span class="icon-menu"></span>'.Lang::t('List'), 'url' => $ug->admin('user_list')];
                $sheets['user_activity'] = ['caption' => '<span class="icon-pulse"></span>'.Lang::t('Activity'), 'url' => $ug->admin('user_activity')];
                break;

            case 'batch_manager':
                $sheets['global'] = ['caption' => '<span class="icon-th"></span>'.Lang::t('global mode'), 'url' => $ug->admin('batch_manager').'&mode=global'];
                $sheets['unit'] = ['caption' => '<span class="icon-th-list"></span>'.Lang::t('unit mode'), 'url' => $ug->admin('batch_manager').'&mode=unit'];
                break;

            case 'cat_options':
                $sheets['status'] = ['caption' => '<span class="icon-lock"></span>'.Lang::t('Public / Private'), 'url' => $ug->admin('cat_options').'&section=status'];
                $sheets['visible'] = ['caption' => '<span class="icon-block"></span>'.Lang::t('Lock'), 'url' => $ug->admin('cat_options').'&section=visible'];
                if (Config::activateComments()) {
                    $sheets['comments'] = ['caption' => '<span class="icon-chat"></span>'.Lang::t('Comments'), 'url' => $ug->admin('cat_options').'&section=comments'];
                }
                if (Config::allowRandomRepresentative()) {
                    $sheets['representative'] = ['caption' => Lang::t('Representative'), 'url' => $ug->admin('cat_options').'&section=representative'];
                }
                break;

            case 'comments':
                $sheets[''] = ['caption' => '<span class="icon-menu"></span>'.Lang::t('List'), 'url' => $ug->admin('comments')];
                break;

            case 'groups':
                $sheets[''] = ['caption' => '<span class="icon-menu"> </span>'.Lang::t('List'), 'url' => $ug->admin('group_list')];
                break;

            case 'configuration':
                $sheets['main'] = ['caption' => '<span class="icon-cog"></span>'.Lang::t('General'), 'url' => $ug->admin('configuration').'&section=main'];
                $sheets['sizes'] = ['caption' => '<span class="icon-zoom-square"></span>'.Lang::t('Photo sizes'), 'url' => $ug->admin('configuration').'&section=sizes'];
                $sheets['watermark'] = ['caption' => '<span class="icon-file-image"></span>'.Lang::t('Watermark'), 'url' => $ug->admin('configuration').'&section=watermark'];
                $sheets['display'] = ['caption' => '<span class="icon-television"></span>'.Lang::t('Display'), 'url' => $ug->admin('configuration').'&section=display'];
                $sheets['comments'] = ['caption' => '<span class="icon-chat"></span>'.Lang::t('Comments'), 'url' => $ug->admin('configuration').'&section=comments'];
                $sheets['search'] = ['caption' => '<span class="icon-search"></span>'.Lang::t('Search'), 'url' => $ug->admin('configuration').'&section=search'];
                break;

            case 'help':
                $sheets['add_photos'] = ['caption' => Lang::t('Add Photos'), 'url' => 'add_photos'];
                $sheets['permissions'] = ['caption' => Lang::t('Permissions'), 'url' => 'permissions'];
                $sheets['groups'] = ['caption' => Lang::t('Groups'), 'url' => 'groups'];
                $sheets['virtual_links'] = ['caption' => Lang::t('Virtual Links'), 'url' => 'virtual_links'];
                $sheets['misc'] = ['caption' => Lang::t('Miscellaneous'), 'url' => 'misc'];
                break;

            case 'history':
                $sheets['stats'] = ['caption' => '<span class="icon-signal"></span>'.Lang::t('Statistics'), 'url' => $ug->admin('stats')];
                $sheets['history'] = ['caption' => '<span class="icon-search"></span>'.Lang::t('Search'), 'url' => $ug->admin('history')];
                break;

            case 'languages':
                $sheets['installed'] = ['caption' => '<span class="icon-menu"></span>'.Lang::t('List'), 'url' => $ug->admin('languages').'&tab=installed'];
                if (Config::enableExtensionsInstall()) {
                    $sheets['update'] = ['caption' => '<span class="icon-arrows-cw"></span>'.Lang::t('Check for updates'), 'url' => $ug->admin('languages').'&tab=update'];
                    $sheets['new'] = ['caption' => '<span class="icon-plus-circled"></span>'.Lang::t('Add New Language'), 'url' => $ug->admin('languages').'&tab=new'];
                }
                break;

            case 'menus':
                $sheets[''] = ['caption' => '<span class="icon-menu"></span>'.Lang::t('List'), 'url' => $ug->admin('menubar')];
                break;

            case 'nbm':
                $sheets['param'] = ['caption' => Lang::t('Parameter'), 'url' => $ug->admin('notification_by_mail').'&mode=param'];
                $sheets['subscribe'] = ['caption' => Lang::t('Subscribe'), 'url' => $ug->admin('notification_by_mail').'&mode=subscribe'];
                $sheets['send'] = ['caption' => Lang::t('Send'), 'url' => $ug->admin('notification_by_mail').'&mode=send'];
                break;

            case 'photo':
                $sheets['properties'] = ['caption' => '<span class="icon-file-image"></span>'.Lang::t('Properties'), 'url' => $admin_photo_base_url.'-properties'];
                $sheets['coi'] = ['caption' => '<span class="icon-crop"></span>'.Lang::t('Center of interest'), 'url' => $admin_photo_base_url.'-coi'];
                if (Config::isFormatsEnabled()) {
                    $sheets['formats'] = ['caption' => '<span class="icon-docs"></span>'.Lang::t('Formats'), 'url' => $admin_photo_base_url.'-formats'];
                }
                break;

            case 'photos_add':
                $photosAddUrl = $ug->admin('photos_add');
                $sheets['direct'] = ['caption' => '<span class="icon-upload"></span>'.Lang::t('Web Form'), 'url' => $photosAddUrl.'&section=direct'];
                $sheets['applications'] = ['caption' => '<span class="icon-network"></span>'.Lang::t('Applications'), 'url' => $photosAddUrl.'&section=applications'];
                if (Config::enableSynchronization()) {
                    $sheets['ftp'] = ['caption' => '<span class="icon-exchange"></span>'.Lang::t('FTP + Synchronization'), 'url' => $photosAddUrl.'&section=ftp'];
                }
                break;

            case 'plugins':
                $sheets['installed'] = ['caption' => '<span class="icon-menu"></span>'.Lang::t('List'), 'url' => $ug->admin('plugins').'&tab=installed'];
                if (Config::enableExtensionsInstall()) {
                    $sheets['update'] = ['caption' => '<span class="icon-arrows-cw"></span>'.Lang::t('Check for updates'), 'url' => $ug->admin('plugins').'&tab=update'];
                    $sheets['new'] = ['caption' => '<span class="icon-plus-circled"></span>'.Lang::t('Add New Plugin'), 'url' => $ug->admin('plugins').'&tab=new'];
                }
                break;

            case 'rating':
                $sheets['rating'] = ['caption' => Lang::t('Photos'), 'url' => $ug->admin('rating')];
                $sheets['rating_user'] = ['caption' => Lang::t('Users'), 'url' => $ug->admin('rating_user')];
                break;

            case 'themes':
                $sheets['installed'] = ['caption' => '<span class="icon-menu"></span>'.Lang::t('List'), 'url' => $ug->admin('themes').'&tab=installed'];
                if (Config::enableExtensionsInstall()) {
                    $sheets['update'] = ['caption' => '<span class="icon-arrows-cw"></span>'.Lang::t('Check for updates'), 'url' => $ug->admin('themes').'&tab=update'];
                    $sheets['new'] = ['caption' => '<span class="icon-plus-circled"></span>'.Lang::t('Add New Theme'), 'url' => $ug->admin('themes').'&tab=new'];
                }
                $sheets['standard_pages'] = ['caption' => '<span class="icon-cog-alt"></span>'.Lang::t('Standard pages'), 'url' => $ug->admin('themes').'&tab=standard_pages'];
                break;

            case 'updates':
                if (Config::enableCoreUpdate()) {
                    $sheets['pwg'] = ['caption' => Lang::t('Piwigo core'), 'url' => $ug->admin('updates')];
                }
                if (Config::enableExtensionsInstall()) {
                    $sheets['ext'] = ['caption' => Lang::t('Extensions'), 'url' => $ug->admin('updates').'&tab=ext'];
                }
                break;

            case 'site_update':
                $sheets['synchronization'] = ['caption' => '<span class="icon-exchange"></span>'.Lang::t('Synchronization'), 'url' => $ug->admin('site_update').'&site=1'];
                $sheets['site_maager'] = ['caption' => '<span class="icon-flow-branch"></span>'.Lang::t('Site manager'), 'url' => $ug->admin('site_manager')];
                break;

            case 'maintenance':
                $sheets['actions'] = ['caption' => '<span class="icon-tools"></span>'.Lang::t('Actions'), 'url' => $ug->admin('maintenance').'&tab=actions'];
                $sheets['env'] = ['caption' => '<span class="icon-television"></span>'.Lang::t('Environment'), 'url' => $ug->admin('maintenance').'&tab=env'];
                $sheets['sys'] = ['caption' => '<span class="icon-pulse"></span>'.Lang::t('System Activities'), 'url' => $ug->admin('maintenance').'&tab=sys'];
                break;
        }

        return $sheets;
    }
}

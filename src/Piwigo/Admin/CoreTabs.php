<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;

/**
 * Ported from admin/include/add_core_tabs.inc.php (P23 batch 8b-6). Its
 * one function is reached exclusively via the `tabsheet_before_select`
 * event -- confirmed via a direct grep, zero direct callers anywhere --
 * fired synchronously inside every `Tabsheet::select()` call. Registered
 * once per admin request from admin.php, before AdminDispatcher::dispatch()
 * runs, same bootstrap-time-registration shape as P23 batch 7's
 * `Piwigo\Admin\PluginLoader`.
 *
 * Every `case` arm reads a different page-specific base-URL global
 * (`$my_base_url`, `$admin_album_base_url`, `$manager_link`, `$link_start`,
 * `$conf_link`, `$help_link`, `$base_url`, `$admin_photo_base_url`), set by
 * whichever controller is currently running -- unchanged from the original
 * file, this class's whole purpose is bridging those globals into tabsheet
 * entries, not something to redesign here.
 *
 * Dropped one genuinely dead block: the original file had two
 * `case 'users':` blocks in the same switch (a duplicate case value). PHP's
 * switch matches the *first* case with an equal value, so the second block
 * (setting a single `$sheets['']` entry) could never execute -- confirmed
 * via a direct read, not a static-analysis finding (this logic sat under
 * `admin/include/`, outside any dead-code check, until this fold). The
 * first block (setting `$sheets['user_list']`/`$sheets['user_activity']`)
 * is the one actually consumed by `UserListPageRenderer`/
 * `UserActivityPageRenderer`'s own `Tabsheet::set_id('users')` calls.
 */
final class CoreTabs
{
    private static ?UrlServiceInterface $urlService = null;

    private static ?CoreTabsContext $context = null;

    /**
     * Set once by Piwigo\Admin\AdminShell::run(), right before registering
     * addCoreTabs() as the 'tabsheet_before_select' event handler --
     * addCoreTabs() is only ever reached via that event (fixed callback
     * signature, no room for a new method param), so it needs the same
     * static-setter shape as Image\SrcImage::setUrlService() (Legacy
     * Coupling Retirement Phase 4c).
     */
    public static function setUrlService(UrlServiceInterface $urlService): void
    {
        self::$urlService = $urlService;
    }

    private static function urlService(): UrlServiceInterface
    {
        if (! self::$urlService instanceof UrlServiceInterface) {
            throw new \RuntimeException('CoreTabs: no URL service set (AdminShell::run() not run yet?)');
        }

        return self::$urlService;
    }

    /**
     * Called by each real writer file (10 *SubController.php, 8
     * *PageRenderer.php called from a SubController) right before it
     * constructs its own Tabsheet -- see CoreTabsContext's own docblock
     * for why this can't be a real addCoreTabs() parameter. Worker-mode
     * safe the same way $urlService already is: exactly one
     * SubController's flow reaches each context-reading `case` below, and
     * that SubController always calls setContext() first, so there is
     * nothing to reset between requests.
     */
    public static function setContext(CoreTabsContext $context): void
    {
        self::$context = $context;
    }

    private static function context(): CoreTabsContext
    {
        if (! self::$context instanceof CoreTabsContext) {
            throw new \RuntimeException('CoreTabs: no context set (writer file forgot CoreTabs::setContext()?)');
        }

        return self::$context;
    }

    /**
     * Every CoreTabsContext field is nullable (only the ONE field the
     * current page family needs is ever set), but each `case` arm below
     * only ever reads the one field its own tab id genuinely needs -- a
     * null here means the writer file called setContext() with the wrong
     * field populated, a real bug to surface immediately, not paper over
     * with a silent '' fallback.
     */
    private static function contextField(?string $value, string $fieldName): string
    {
        if ($value === null) {
            throw new \RuntimeException("CoreTabs: CoreTabsContext::\${$fieldName} was not set for this tab");
        }

        return $value;
    }

    /**
     * $tabId is genuinely ?string, not mixed -- this is only ever reached
     * as the 'tabsheet_before_select' event handler, whose sole trigger
     * site (Tabsheet::select()) passes its own $this->uniqid (?string,
     * confirmed via every real read/write site on that property).
     *
     * @param array<string, array{caption: string, url: string}> $sheets
     * @return array<string, array{caption: string, url: string}>
     */
    public static function addCoreTabs(array $sheets, ?string $tabId): array
    {
        switch ($tabId) {
            case 'admin_home':
                $sheets[''] = [
                    'caption' => Lang::t('Administration Home'),
                    'url' => 'admin.php',
                ];
                break;

            case 'tags':
                $my_base_url = self::contextField(self::context()->myBaseUrl, 'myBaseUrl');
                $sheets[''] = [
                    'caption' => '<span class="icon-menu"></span>' . Lang::t('List'),
                    'url' => $my_base_url . 'tags',
                ];
                break;

            case 'album':
                $admin_album_base_url = self::contextField(self::context()->adminAlbumBaseUrl, 'adminAlbumBaseUrl');
                $sheets['properties'] = [
                    'caption' => '<span class="icon-pencil"></span>' . Lang::t('Properties'),
                    'url' => $admin_album_base_url . '-properties',
                ];
                $sheets['sort_order'] = [
                    'caption' => '<span class="icon-shuffle"></span>' . Lang::t('Manage photo ranks'),
                    'url' => $admin_album_base_url . '-sort_order',
                ];
                $sheets['permissions'] = [
                    'caption' => '<span class="icon-lock"></span>' . Lang::t('Permissions'),
                    'url' => $admin_album_base_url . '-permissions',
                ];
                $sheets['notification'] = [
                    'caption' => '<span class="icon-mail-alt"></span>' . Lang::t('Notification'),
                    'url' => $admin_album_base_url . '-notification',
                ];
                break;

            case 'albums':
                $my_base_url = self::contextField(self::context()->myBaseUrl, 'myBaseUrl');
                $sheets['list'] = [
                    'caption' => '<span class="icon-menu"></span>' . Lang::t('List'),
                    'url' => $my_base_url . 'albums',
                ];
                $sheets['permalinks'] = [
                    'caption' => '<span class="icon-link-1"></span>' . Lang::t('Permalinks'),
                    'url' => $my_base_url . 'permalinks',
                ];
                break;

            case 'users':
                $my_base_url = self::contextField(self::context()->myBaseUrl, 'myBaseUrl');
                $sheets['user_list'] = [
                    'caption' => '<span class="icon-menu"></span>' . Lang::t('List'),
                    'url' => $my_base_url . 'user_list',
                ];
                $sheets['user_activity'] = [
                    'caption' => '<span class="icon-pulse"></span>' . Lang::t('Activity'),
                    'url' => $my_base_url . 'user_activity',
                ];
                break;

            case 'batch_manager':
                $manager_link = self::contextField(self::context()->managerLink, 'managerLink');
                $sheets['global'] = [
                    'caption' => '<span class="icon-th"></span>' . Lang::t('global mode'),
                    'url' => $manager_link . 'global',
                ];
                $sheets['unit'] = [
                    'caption' => '<span class="icon-th-list"></span>' . Lang::t('unit mode'),
                    'url' => $manager_link . 'unit',
                ];
                break;

            case 'cat_options':
                $link_start = self::contextField(self::context()->linkStart, 'linkStart');
                $sheets['status'] = [
                    'caption' => '<span class="icon-lock"></span>' . Lang::t('Public / Private'),
                    'url' => $link_start . 'cat_options&amp;section=status',
                ];
                $sheets['visible'] = [
                    'caption' => '<span class="icon-block"></span>' . Lang::t('Lock'),
                    'url' => $link_start . 'cat_options&amp;section=visible',
                ];
                if (\Piwigo\Config\CurrentConfig::activateComments()) {
                    $sheets['comments'] = [
                        'caption' => '<span class="icon-chat"></span>' . Lang::t('Comments'),
                        'url' => $link_start . 'cat_options&amp;section=comments',
                    ];
                }
                if (\Piwigo\Config\CurrentConfig::allowRandomRepresentative()) {
                    $sheets['representative'] = [
                        'caption' => Lang::t('Representative'),
                        'url' => $link_start . 'cat_options&amp;section=representative',
                    ];
                }
                break;

            case 'comments':
                $my_base_url = self::contextField(self::context()->myBaseUrl, 'myBaseUrl');
                $sheets[''] = [
                    'caption' => '<span class="icon-menu"></span>' . Lang::t('List'),
                    'url' => $my_base_url . 'comments',
                ];
                break;

            case 'groups':
                $my_base_url = self::contextField(self::context()->myBaseUrl, 'myBaseUrl');
                $sheets[''] = [
                    'caption' => '<span class="icon-menu"> </span>' . Lang::t('List'),
                    'url' => $my_base_url . 'group_list',
                ];
                break;

            case 'configuration':
                $conf_link = self::contextField(self::context()->confLink, 'confLink');
                $sheets['main'] = [
                    'caption' => '<span class="icon-cog"></span>' . Lang::t('General'),
                    'url' => $conf_link . 'main',
                ];
                $sheets['sizes'] = [
                    'caption' => '<span class="icon-zoom-square"></span>' . Lang::t('Photo sizes'),
                    'url' => $conf_link . 'sizes',
                ];
                $sheets['watermark'] = [
                    'caption' => '<span class="icon-file-image"></span>' . Lang::t('Watermark'),
                    'url' => $conf_link . 'watermark',
                ];
                $sheets['display'] = [
                    'caption' => '<span class="icon-television"></span>' . Lang::t('Display'),
                    'url' => $conf_link . 'display',
                ];
                $sheets['comments'] = [
                    'caption' => '<span class="icon-chat"></span>' . Lang::t('Comments'),
                    'url' => $conf_link . 'comments',
                ];
                $sheets['search'] = [
                    'caption' => '<span class="icon-search"></span>' . Lang::t('Search'),
                    'url' => $conf_link . 'search',
                ];
                // $sheets['default'] = array('caption' => l10n('Guest Settings'), 'url' => $conf_link.'default');
                break;

            case 'help':
                $help_link = self::contextField(self::context()->helpLink, 'helpLink');
                $sheets['add_photos'] = [
                    'caption' => Lang::t('Add Photos'),
                    'url' => $help_link . 'add_photos',
                ];
                $sheets['permissions'] = [
                    'caption' => Lang::t('Permissions'),
                    'url' => $help_link . 'permissions',
                ];
                $sheets['groups'] = [
                    'caption' => Lang::t('Groups'),
                    'url' => $help_link . 'groups',
                ];
                $sheets['virtual_links'] = [
                    'caption' => Lang::t('Virtual Links'),
                    'url' => $help_link . 'virtual_links',
                ];
                $sheets['misc'] = [
                    'caption' => Lang::t('Miscellaneous'),
                    'url' => $help_link . 'misc',
                ];
                break;

            case 'history':
                $link_start = self::contextField(self::context()->linkStart, 'linkStart');
                $sheets['stats'] = [
                    'caption' => '<span class="icon-signal"></span>' . Lang::t('Statistics'),
                    'url' => $link_start . 'stats',
                ];
                $sheets['history'] = [
                    'caption' => '<span class="icon-search"></span>' . Lang::t('Search'),
                    'url' => $link_start . 'history',
                ];
                break;

            case 'languages':
                $my_base_url = self::contextField(self::context()->myBaseUrl, 'myBaseUrl');
                $sheets['installed'] = [
                    'caption' => '<span class="icon-menu"></span>' . Lang::t('List'),
                    'url' => $my_base_url . '&amp;tab=installed',
                ];
                if (\Piwigo\Config\CurrentConfig::enableExtensionsInstall()) {
                    $sheets['update'] = [
                        'caption' => '<span class="icon-arrows-cw"></span>' . Lang::t('Check for updates'),
                        'url' => $my_base_url . '&amp;tab=update',
                    ];
                    $sheets['new'] = [
                        'caption' => '<span class="icon-plus-circled"></span>' . Lang::t('Add New Language'),
                        'url' => $my_base_url . '&amp;tab=new',
                    ];
                }
                break;

            case 'menus':
                $my_base_url = self::contextField(self::context()->myBaseUrl, 'myBaseUrl');
                $sheets[''] = [
                    'caption' => '<span class="icon-menu"></span>' . Lang::t('List'),
                    'url' => $my_base_url . 'menubar',
                ];
                break;

            case 'nbm':
                $base_url = self::contextField(self::context()->baseUrl, 'baseUrl');
                $sheets['param'] = [
                    'caption' => Lang::t('Parameter'),
                    'url' => $base_url . '?page=notification_by_mail&amp;mode=param',
                ];
                $sheets['subscribe'] = [
                    'caption' => Lang::t('Subscribe'),
                    'url' => $base_url . '?page=notification_by_mail&amp;mode=subscribe',
                ];
                $sheets['send'] = [
                    'caption' => Lang::t('Send'),
                    'url' => $base_url . '?page=notification_by_mail&amp;mode=send',
                ];
                break;

            case 'photo':
                $admin_photo_base_url = self::contextField(self::context()->adminPhotoBaseUrl, 'adminPhotoBaseUrl');
                $sheets['properties'] = [
                    'caption' => '<span class="icon-file-image"></span>' . Lang::t('Properties'),
                    'url' => $admin_photo_base_url . '-properties',
                ];
                $sheets['coi'] = [
                    'caption' => '<span class="icon-crop"></span>' . Lang::t('Center of interest'),
                    'url' => $admin_photo_base_url . '-coi',
                ];
                if (\Piwigo\Config\CurrentConfig::isFormatsEnabled()) {
                    $sheets['formats'] = [
                        'caption' => '<span class="icon-docs"></span>' . Lang::t('Formats'),
                        'url' => $admin_photo_base_url . '-formats',
                    ];
                }
                break;

            case 'photos_add':
                $sheets['direct'] = [
                    'caption' => '<span class="icon-upload"></span>' . Lang::t('Web Form'),
                    'url' => PhotosAddDirectPageRenderer::baseUrl(self::urlService()) . '&amp;section=direct',
                ];
                $sheets['applications'] = [
                    'caption' => '<span class="icon-network"></span>' . Lang::t('Applications'),
                    'url' => PhotosAddDirectPageRenderer::baseUrl(self::urlService()) . '&amp;section=applications',
                ];
                if (\Piwigo\Config\CurrentConfig::enableSynchronization()) {
                    $sheets['ftp'] = [
                        'caption' => '<span class="icon-exchange"></span>' . Lang::t('FTP + Synchronization'),
                        'url' => PhotosAddDirectPageRenderer::baseUrl(self::urlService()) . '&amp;section=ftp',
                    ];
                }
                break;

            case 'plugins':
                $my_base_url = self::contextField(self::context()->myBaseUrl, 'myBaseUrl');
                $sheets['installed'] = [
                    'caption' => '<span class="icon-menu"></span>' . Lang::t('List'),
                    'url' => $my_base_url . '&amp;tab=installed',
                ];
                if (\Piwigo\Config\CurrentConfig::enableExtensionsInstall()) {
                    $sheets['update'] = [
                        'caption' => '<span class="icon-arrows-cw"></span>' . Lang::t('Check for updates'),
                        'url' => $my_base_url . '&amp;tab=update',
                    ];
                    $sheets['new'] = [
                        'caption' => '<span class="icon-plus-circled"></span>' . Lang::t('Add New Plugin'),
                        'url' => $my_base_url . '&amp;tab=new',
                    ];
                }
                break;

            case 'rating':
                $sheets['rating'] = [
                    'caption' => Lang::t('Photos'),
                    'url' => self::urlService()->getRootUrl() . 'admin.php?page=rating',
                ];
                $sheets['rating_user'] = [
                    'caption' => Lang::t('Users'),
                    'url' => self::urlService()->getRootUrl() . 'admin.php?page=rating_user',
                ];
                break;

            case 'themes':
                $my_base_url = self::contextField(self::context()->myBaseUrl, 'myBaseUrl');
                $sheets['installed'] = [
                    'caption' => '<span class="icon-menu"></span>' . Lang::t('List'),
                    'url' => $my_base_url . '&amp;tab=installed',
                ];
                if (\Piwigo\Config\CurrentConfig::enableExtensionsInstall()) {
                    $sheets['update'] = [
                        'caption' => '<span class="icon-arrows-cw"></span>' . Lang::t('Check for updates'),
                        'url' => $my_base_url . '&amp;tab=update',
                    ];
                    $sheets['new'] = [
                        'caption' => '<span class="icon-plus-circled"></span>' . Lang::t('Add New Theme'),
                        'url' => $my_base_url . '&amp;tab=new',
                    ];
                }
                $sheets['standard_pages'] = [
                    'caption' => '<span class="icon-cog-alt"></span>' . Lang::t('Standard pages'),
                    'url' => $my_base_url . '&amp;tab=standard_pages',
                ];
                break;

            case 'updates':
                $my_base_url = self::contextField(self::context()->myBaseUrl, 'myBaseUrl');

                if (\Piwigo\Config\CurrentConfig::enableCoreUpdate()) {
                    $sheets['pwg'] = [
                        'caption' => Lang::t('Piwigo core'),
                        'url' => $my_base_url,
                    ];
                }

                if (\Piwigo\Config\CurrentConfig::enableExtensionsInstall()) {
                    $sheets['ext'] = [
                        'caption' => Lang::t('Extensions'),
                        'url' => $my_base_url . '&amp;tab=ext',
                    ];
                }
                break;
            case 'site_update':
                $my_base_url = self::contextField(self::context()->myBaseUrl, 'myBaseUrl');
                $sheets['synchronization'] = [
                    'caption' => '<span class="icon-exchange"></span>' . Lang::t('Synchronization'),
                    'url' => $my_base_url . 'site_update&site=1',
                ];
                $sheets['site_maager'] = [
                    'caption' => '<span class="icon-flow-branch"></span>' . Lang::t('Site manager'),
                    'url' => $my_base_url . 'site_manager',
                ];
                break;
            case 'maintenance':
                $my_base_url = self::contextField(self::context()->myBaseUrl, 'myBaseUrl');
                $sheets['actions'] = [
                    'caption' => '<span class="icon-tools"></span>' . Lang::t('Actions'),
                    'url' => $my_base_url . 'maintenance&tab=actions',
                ];
                $sheets['env'] = [
                    'caption' => '<span class="icon-television"></span>' . Lang::t('Environment'),
                    'url' => $my_base_url . 'maintenance&tab=env',
                ];
                $sheets['sys'] = [
                    'caption' => '<span class="icon-pulse"></span>' . Lang::t('System Activities'),
                    'url' => $my_base_url . 'maintenance&tab=sys',
                ];
                break;
        }

        return $sheets;
    }
}

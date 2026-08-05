<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Admin\TabsheetBeforeSelect;

/**
 * Ported from admin/include/add_core_tabs.inc.php (P23 batch 8b-6). Its
 * one function is reached exclusively via the `tabsheet_before_select`
 * event -- confirmed via a direct grep, zero direct callers anywhere --
 * fired synchronously inside every `Tabsheet::select()` call. Registered
 * once per admin request from admin.php, before AdminDispatcher::dispatch()
 * runs, same bootstrap-time-registration shape as P23 batch 7's
 * `Piwigo\Admin\PluginLoader`.
 *
 * Singleton/service-locator elimination campaign, Phase 3: container-shared
 * instance now (`UrlServiceInterface` constructor-injected, autowired --
 * already bound in config/container.php), not a static-setter collaborator.
 * `context` is ordinary instance state, written once per request by
 * whichever writer file (`*SubController`/`*PageRenderer`) runs, read by
 * `addCoreTabs()` on `$this` -- every real writer file and the event
 * registration itself (`AdminShell::runDispatch()`) resolve this same
 * shared instance from the container, so no transitional shim is needed at
 * all (every real caller already has a constructor-injectable path to it).
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
    private ?CoreTabsContext $context = null;

    public function __construct(
        private readonly Lang $lang,
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\Config\CurrentConfig $currentConfig,
    ) {}

    /**
     * Called by each real writer file (13 *SubController.php, 12
     * *PageRenderer.php called from a SubController) right before it
     * constructs its own Tabsheet -- see CoreTabsContext's own docblock
     * for why this can't be a real addCoreTabs() parameter. Every writer
     * file always calls this before its own Tabsheet::select() reaches
     * addCoreTabs() below, so there is nothing to reset between requests.
     */
    public function setContext(CoreTabsContext $context): void
    {
        $this->context = $context;
    }

    private function context(): CoreTabsContext
    {
        if (! $this->context instanceof CoreTabsContext) {
            throw new \RuntimeException('CoreTabs: no context set (writer file forgot CoreTabs::setContext()?)');
        }

        return $this->context;
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

    public function addCoreTabs(TabsheetBeforeSelect $event): TabsheetBeforeSelect
    {
        $sheets = $event->sheets;
        switch ($event->tabsheetId) {
            case 'admin_home':
                $sheets[''] = [
                    'caption' => $this->lang->t('Administration Home'),
                    'url' => 'admin.php',
                ];
                break;

            case 'tags':
                $my_base_url = self::contextField($this->context()->myBaseUrl, 'myBaseUrl');
                $sheets[''] = [
                    'caption' => '<span class="icon-menu"></span>' . $this->lang->t('List'),
                    'url' => $my_base_url . 'tags',
                ];
                break;

            case 'album':
                $admin_album_base_url = self::contextField($this->context()->adminAlbumBaseUrl, 'adminAlbumBaseUrl');
                $sheets['properties'] = [
                    'caption' => '<span class="icon-pencil"></span>' . $this->lang->t('Properties'),
                    'url' => $admin_album_base_url . '-properties',
                ];
                $sheets['sort_order'] = [
                    'caption' => '<span class="icon-shuffle"></span>' . $this->lang->t('Manage photo ranks'),
                    'url' => $admin_album_base_url . '-sort_order',
                ];
                $sheets['permissions'] = [
                    'caption' => '<span class="icon-lock"></span>' . $this->lang->t('Permissions'),
                    'url' => $admin_album_base_url . '-permissions',
                ];
                $sheets['notification'] = [
                    'caption' => '<span class="icon-mail-alt"></span>' . $this->lang->t('Notification'),
                    'url' => $admin_album_base_url . '-notification',
                ];
                break;

            case 'albums':
                $my_base_url = self::contextField($this->context()->myBaseUrl, 'myBaseUrl');
                $sheets['list'] = [
                    'caption' => '<span class="icon-menu"></span>' . $this->lang->t('List'),
                    'url' => $my_base_url . 'albums',
                ];
                $sheets['permalinks'] = [
                    'caption' => '<span class="icon-link-1"></span>' . $this->lang->t('Permalinks'),
                    'url' => $my_base_url . 'permalinks',
                ];
                break;

            case 'users':
                $my_base_url = self::contextField($this->context()->myBaseUrl, 'myBaseUrl');
                $sheets['user_list'] = [
                    'caption' => '<span class="icon-menu"></span>' . $this->lang->t('List'),
                    'url' => $my_base_url . 'user_list',
                ];
                $sheets['user_activity'] = [
                    'caption' => '<span class="icon-pulse"></span>' . $this->lang->t('Activity'),
                    'url' => $my_base_url . 'user_activity',
                ];
                break;

            case 'batch_manager':
                $manager_link = self::contextField($this->context()->managerLink, 'managerLink');
                $sheets['global'] = [
                    'caption' => '<span class="icon-th"></span>' . $this->lang->t('global mode'),
                    'url' => $manager_link . 'global',
                ];
                $sheets['unit'] = [
                    'caption' => '<span class="icon-th-list"></span>' . $this->lang->t('unit mode'),
                    'url' => $manager_link . 'unit',
                ];
                break;

            case 'cat_options':
                $link_start = self::contextField($this->context()->linkStart, 'linkStart');
                $sheets['status'] = [
                    'caption' => '<span class="icon-lock"></span>' . $this->lang->t('Public / Private'),
                    'url' => $link_start . 'cat_options&amp;section=status',
                ];
                $sheets['visible'] = [
                    'caption' => '<span class="icon-block"></span>' . $this->lang->t('Lock'),
                    'url' => $link_start . 'cat_options&amp;section=visible',
                ];
                if ($this->currentConfig->activateComments()) {
                    $sheets['comments'] = [
                        'caption' => '<span class="icon-chat"></span>' . $this->lang->t('Comments'),
                        'url' => $link_start . 'cat_options&amp;section=comments',
                    ];
                }
                if ($this->currentConfig->allowRandomRepresentative()) {
                    $sheets['representative'] = [
                        'caption' => $this->lang->t('Representative'),
                        'url' => $link_start . 'cat_options&amp;section=representative',
                    ];
                }
                break;

            case 'comments':
                $my_base_url = self::contextField($this->context()->myBaseUrl, 'myBaseUrl');
                $sheets[''] = [
                    'caption' => '<span class="icon-menu"></span>' . $this->lang->t('List'),
                    'url' => $my_base_url . 'comments',
                ];
                break;

            case 'groups':
                $my_base_url = self::contextField($this->context()->myBaseUrl, 'myBaseUrl');
                $sheets[''] = [
                    'caption' => '<span class="icon-menu"> </span>' . $this->lang->t('List'),
                    'url' => $my_base_url . 'group_list',
                ];
                break;

            case 'configuration':
                $conf_link = self::contextField($this->context()->confLink, 'confLink');
                $sheets['main'] = [
                    'caption' => '<span class="icon-cog"></span>' . $this->lang->t('General'),
                    'url' => $conf_link . 'main',
                ];
                $sheets['sizes'] = [
                    'caption' => '<span class="icon-zoom-square"></span>' . $this->lang->t('Photo sizes'),
                    'url' => $conf_link . 'sizes',
                ];
                $sheets['watermark'] = [
                    'caption' => '<span class="icon-file-image"></span>' . $this->lang->t('Watermark'),
                    'url' => $conf_link . 'watermark',
                ];
                $sheets['display'] = [
                    'caption' => '<span class="icon-television"></span>' . $this->lang->t('Display'),
                    'url' => $conf_link . 'display',
                ];
                $sheets['comments'] = [
                    'caption' => '<span class="icon-chat"></span>' . $this->lang->t('Comments'),
                    'url' => $conf_link . 'comments',
                ];
                $sheets['search'] = [
                    'caption' => '<span class="icon-search"></span>' . $this->lang->t('Search'),
                    'url' => $conf_link . 'search',
                ];
                // $sheets['default'] = array('caption' => l10n('Guest Settings'), 'url' => $conf_link.'default');
                break;

            case 'help':
                $help_link = self::contextField($this->context()->helpLink, 'helpLink');
                $sheets['add_photos'] = [
                    'caption' => $this->lang->t('Add Photos'),
                    'url' => $help_link . 'add_photos',
                ];
                $sheets['permissions'] = [
                    'caption' => $this->lang->t('Permissions'),
                    'url' => $help_link . 'permissions',
                ];
                $sheets['groups'] = [
                    'caption' => $this->lang->t('Groups'),
                    'url' => $help_link . 'groups',
                ];
                $sheets['virtual_links'] = [
                    'caption' => $this->lang->t('Virtual Links'),
                    'url' => $help_link . 'virtual_links',
                ];
                $sheets['misc'] = [
                    'caption' => $this->lang->t('Miscellaneous'),
                    'url' => $help_link . 'misc',
                ];
                break;

            case 'history':
                $link_start = self::contextField($this->context()->linkStart, 'linkStart');
                $sheets['stats'] = [
                    'caption' => '<span class="icon-signal"></span>' . $this->lang->t('Statistics'),
                    'url' => $link_start . 'stats',
                ];
                $sheets['history'] = [
                    'caption' => '<span class="icon-search"></span>' . $this->lang->t('Search'),
                    'url' => $link_start . 'history',
                ];
                break;

            case 'languages':
                $my_base_url = self::contextField($this->context()->myBaseUrl, 'myBaseUrl');
                $sheets['installed'] = [
                    'caption' => '<span class="icon-menu"></span>' . $this->lang->t('List'),
                    'url' => $my_base_url . '&amp;tab=installed',
                ];
                if ($this->currentConfig->enableExtensionsInstall()) {
                    $sheets['update'] = [
                        'caption' => '<span class="icon-arrows-cw"></span>' . $this->lang->t('Check for updates'),
                        'url' => $my_base_url . '&amp;tab=update',
                    ];
                    $sheets['new'] = [
                        'caption' => '<span class="icon-plus-circled"></span>' . $this->lang->t('Add New Language'),
                        'url' => $my_base_url . '&amp;tab=new',
                    ];
                }
                break;

            case 'menus':
                $my_base_url = self::contextField($this->context()->myBaseUrl, 'myBaseUrl');
                $sheets[''] = [
                    'caption' => '<span class="icon-menu"></span>' . $this->lang->t('List'),
                    'url' => $my_base_url . 'menubar',
                ];
                break;

            case 'nbm':
                $base_url = self::contextField($this->context()->baseUrl, 'baseUrl');
                $sheets['param'] = [
                    'caption' => $this->lang->t('Parameter'),
                    'url' => $base_url . '?page=notification_by_mail&amp;mode=param',
                ];
                $sheets['subscribe'] = [
                    'caption' => $this->lang->t('Subscribe'),
                    'url' => $base_url . '?page=notification_by_mail&amp;mode=subscribe',
                ];
                $sheets['send'] = [
                    'caption' => $this->lang->t('Send'),
                    'url' => $base_url . '?page=notification_by_mail&amp;mode=send',
                ];
                break;

            case 'photo':
                $admin_photo_base_url = self::contextField($this->context()->adminPhotoBaseUrl, 'adminPhotoBaseUrl');
                $sheets['properties'] = [
                    'caption' => '<span class="icon-file-image"></span>' . $this->lang->t('Properties'),
                    'url' => $admin_photo_base_url . '-properties',
                ];
                $sheets['coi'] = [
                    'caption' => '<span class="icon-crop"></span>' . $this->lang->t('Center of interest'),
                    'url' => $admin_photo_base_url . '-coi',
                ];
                if ($this->currentConfig->isFormatsEnabled()) {
                    $sheets['formats'] = [
                        'caption' => '<span class="icon-docs"></span>' . $this->lang->t('Formats'),
                        'url' => $admin_photo_base_url . '-formats',
                    ];
                }
                break;

            case 'photos_add':
                $sheets['direct'] = [
                    'caption' => '<span class="icon-upload"></span>' . $this->lang->t('Web Form'),
                    'url' => PhotosAddDirectPageRenderer::baseUrl($this->urlService) . '&amp;section=direct',
                ];
                $sheets['applications'] = [
                    'caption' => '<span class="icon-network"></span>' . $this->lang->t('Applications'),
                    'url' => PhotosAddDirectPageRenderer::baseUrl($this->urlService) . '&amp;section=applications',
                ];
                if ($this->currentConfig->enableSynchronization()) {
                    $sheets['ftp'] = [
                        'caption' => '<span class="icon-exchange"></span>' . $this->lang->t('FTP + Synchronization'),
                        'url' => PhotosAddDirectPageRenderer::baseUrl($this->urlService) . '&amp;section=ftp',
                    ];
                }
                break;

            case 'plugins':
                $my_base_url = self::contextField($this->context()->myBaseUrl, 'myBaseUrl');
                $sheets['installed'] = [
                    'caption' => '<span class="icon-menu"></span>' . $this->lang->t('List'),
                    'url' => $my_base_url . '&amp;tab=installed',
                ];
                if ($this->currentConfig->enableExtensionsInstall()) {
                    $sheets['update'] = [
                        'caption' => '<span class="icon-arrows-cw"></span>' . $this->lang->t('Check for updates'),
                        'url' => $my_base_url . '&amp;tab=update',
                    ];
                    $sheets['new'] = [
                        'caption' => '<span class="icon-plus-circled"></span>' . $this->lang->t('Add New Plugin'),
                        'url' => $my_base_url . '&amp;tab=new',
                    ];
                }
                break;

            case 'rating':
                $sheets['rating'] = [
                    'caption' => $this->lang->t('Photos'),
                    'url' => $this->urlService->getRootUrl() . 'admin.php?page=rating',
                ];
                $sheets['rating_user'] = [
                    'caption' => $this->lang->t('Users'),
                    'url' => $this->urlService->getRootUrl() . 'admin.php?page=rating_user',
                ];
                break;

            case 'themes':
                $my_base_url = self::contextField($this->context()->myBaseUrl, 'myBaseUrl');
                $sheets['installed'] = [
                    'caption' => '<span class="icon-menu"></span>' . $this->lang->t('List'),
                    'url' => $my_base_url . '&amp;tab=installed',
                ];
                if ($this->currentConfig->enableExtensionsInstall()) {
                    $sheets['update'] = [
                        'caption' => '<span class="icon-arrows-cw"></span>' . $this->lang->t('Check for updates'),
                        'url' => $my_base_url . '&amp;tab=update',
                    ];
                    $sheets['new'] = [
                        'caption' => '<span class="icon-plus-circled"></span>' . $this->lang->t('Add New Theme'),
                        'url' => $my_base_url . '&amp;tab=new',
                    ];
                }
                $sheets['standard_pages'] = [
                    'caption' => '<span class="icon-cog-alt"></span>' . $this->lang->t('Standard pages'),
                    'url' => $my_base_url . '&amp;tab=standard_pages',
                ];
                break;

            case 'updates':
                $my_base_url = self::contextField($this->context()->myBaseUrl, 'myBaseUrl');

                if ($this->currentConfig->enableCoreUpdate()) {
                    $sheets['pwg'] = [
                        'caption' => $this->lang->t('Piwigo core'),
                        'url' => $my_base_url,
                    ];
                }

                if ($this->currentConfig->enableExtensionsInstall()) {
                    $sheets['ext'] = [
                        'caption' => $this->lang->t('Extensions'),
                        'url' => $my_base_url . '&amp;tab=ext',
                    ];
                }
                break;
            case 'site_update':
                $my_base_url = self::contextField($this->context()->myBaseUrl, 'myBaseUrl');
                $sheets['synchronization'] = [
                    'caption' => '<span class="icon-exchange"></span>' . $this->lang->t('Synchronization'),
                    'url' => $my_base_url . 'site_update&site=1',
                ];
                $sheets['site_maager'] = [
                    'caption' => '<span class="icon-flow-branch"></span>' . $this->lang->t('Site manager'),
                    'url' => $my_base_url . 'site_manager',
                ];
                break;
            case 'maintenance':
                $my_base_url = self::contextField($this->context()->myBaseUrl, 'myBaseUrl');
                $sheets['actions'] = [
                    'caption' => '<span class="icon-tools"></span>' . $this->lang->t('Actions'),
                    'url' => $my_base_url . 'maintenance&tab=actions',
                ];
                $sheets['env'] = [
                    'caption' => '<span class="icon-television"></span>' . $this->lang->t('Environment'),
                    'url' => $my_base_url . 'maintenance&tab=env',
                ];
                $sheets['sys'] = [
                    'caption' => '<span class="icon-pulse"></span>' . $this->lang->t('System Activities'),
                    'url' => $my_base_url . 'maintenance&tab=sys',
                ];
                break;
        }

        $event->sheets = $sheets;

        return $event;
    }
}

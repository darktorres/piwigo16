<?php

declare(strict_types=1);

use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Admin\TabsheetBeforeSelect;
use Piwigo\Lang\Translator;
use Piwigo\Url\RootPathOverride;

/**
 * Piwigo\Admin\CoreTabs::addCoreTabs() -- the pure 'tabsheet_before_select'
 * handler that builds each admin tabsheet group's own tab list, reached
 * indirectly (via Tabsheet::select() -> EventDispatcher::dispatchChange())
 * by every real admin page, but fully exercisable directly here as a pure
 * function of ($sheets, $tabId).
 *
 * CoreTabs is a container-shared instance with no static state to reset,
 * so each test below constructs its own fresh instance directly (same
 * shape as SectionContextRegistryTest.php) instead of seeding a static via
 * beforeEach().
 */
function coreTabsUrlService(): UrlServiceInterface
{
    return UrlServiceTestFactory::build();
}

/**
 * This plain Unit test never boots a Kernel, so each call site below
 * needs its own throwaway, DB-free Lang instance -- with no data loaded,
 * t() returns the raw key, matching this file's own assertions.
 */
function coreTabsLang(): Lang
{
    return new Lang(new Translator(new CurrentConfig()), HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag());
}

/**
 * Same "fresh, DB-free instance, no Kernel booted" reasoning as
 * coreTabsLang() above; CurrentConfig's own defaults are the correct
 * value to read before a real config table has loaded.
 */
function coreTabsCurrentConfig(): CurrentConfig
{
    return new CurrentConfig();
}

/**
 * TabsheetBeforeSelect::$sheets is deliberately loosely typed
 * (array<mixed>, see that class's own docblock) since a misbehaving
 * plugin handler could hand back a malformed shape -- but addCoreTabs()
 * itself is well-behaved and this file's own $sheets fixtures are always
 * the real, precise shape, so this helper re-validates and narrows back
 * to it for every well-behaved call site below.
 *
 * @param array<string, array{caption: string, url: string}> $sheets
 * @return array<string, array{caption: string, url: string}>
 */
function coreTabsTestAddCoreTabs(CoreTabs $coreTabs, array $sheets, ?string $tabId): array
{
    $result = $coreTabs->addCoreTabs(new TabsheetBeforeSelect($sheets, $tabId))->sheets;

    $typed = [];
    foreach ($result as $key => $entry) {
        if (! is_string($key) || ! is_array($entry) || ! is_string($entry['caption'] ?? null) || ! is_string($entry['url'] ?? null)) {
            throw new RuntimeException('addCoreTabs() returned a shape coreTabsTestAddCoreTabs() cannot handle');
        }
        $typed[$key] = ['caption' => $entry['caption'], 'url' => $entry['url']];
    }

    return $typed;
}

test('an unrecognized tab id returns the sheets unchanged', function (): void {
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());

    expect(coreTabsTestAddCoreTabs($coreTabs, ['existing' => ['caption' => 'X', 'url' => '/x']], 'not-a-real-tab-id'))
        ->toBe(['existing' => ['caption' => 'X', 'url' => '/x']]);
    expect(coreTabsTestAddCoreTabs($coreTabs, [], null))->toBe([]);
});

test('admin_home needs no context and adds the Administration Home tab', function (): void {
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());

    $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'admin_home');

    expect($sheets['']['url'])->toBe('admin.php')
        ->and($sheets['']['caption'])->toBe(coreTabsLang()->t('Administration Home'));
});

test('tags reads myBaseUrl and adds a single List tab', function (): void {
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());
    $coreTabs->setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page='));

    $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'tags');

    expect($sheets['']['url'])->toBe('/admin.php?page=tags')
        ->and($sheets['']['caption'])->toBe('<span class="icon-menu"></span>' . coreTabsLang()->t('List'));
});

test('tags throws when myBaseUrl was not set in the context', function (): void {
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());
    $coreTabs->setContext(new CoreTabsContext());

    expect(fn () => coreTabsTestAddCoreTabs($coreTabs, [], 'tags'))->toThrow(RuntimeException::class);
});

test('a context-needing tab throws its own distinct exception when setContext() was never called at all', function (): void {
    // Distinct from the sibling test above: that one has a real
    // CoreTabsContext (setContext() was called), just with myBaseUrl left
    // null -- contextField()'s own guard. A fresh CoreTabs instance's
    // own $context starts at null, so this forces context()'s own "no
    // context set" guard instead, with no reflection needed at all now
    // that each test gets its own isolated instance.
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());

    expect(fn () => coreTabsTestAddCoreTabs($coreTabs, [], 'tags'))
        ->toThrow(RuntimeException::class, 'CoreTabs: no context set (writer file forgot CoreTabs::setContext()?)');
});

test('album reads adminAlbumBaseUrl and adds properties/sort_order/permissions/notification', function (): void {
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());
    $coreTabs->setContext(new CoreTabsContext(adminAlbumBaseUrl: '/admin.php?page=album-5'));

    $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'album');

    expect(array_keys($sheets))->toBe(['properties', 'sort_order', 'permissions', 'notification']);
    expect($sheets['properties']['url'])->toBe('/admin.php?page=album-5-properties');
    expect($sheets['sort_order']['url'])->toBe('/admin.php?page=album-5-sort_order');
    expect($sheets['permissions']['url'])->toBe('/admin.php?page=album-5-permissions');
    expect($sheets['notification']['url'])->toBe('/admin.php?page=album-5-notification');
    expect($sheets['properties']['caption'])->toBe('<span class="icon-pencil"></span>' . coreTabsLang()->t('Properties'));
    expect($sheets['sort_order']['caption'])->toBe('<span class="icon-shuffle"></span>' . coreTabsLang()->t('Manage photo ranks'));
    expect($sheets['permissions']['caption'])->toBe('<span class="icon-lock"></span>' . coreTabsLang()->t('Permissions'));
    expect($sheets['notification']['caption'])->toBe('<span class="icon-mail-alt"></span>' . coreTabsLang()->t('Notification'));
});

test('albums reads myBaseUrl and adds list/permalinks', function (): void {
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());
    $coreTabs->setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page='));

    $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'albums');

    expect(array_keys($sheets))->toBe(['list', 'permalinks']);
    expect($sheets['list']['url'])->toBe('/admin.php?page=albums');
    expect($sheets['permalinks']['url'])->toBe('/admin.php?page=permalinks');
    expect($sheets['list']['caption'])->toBe('<span class="icon-menu"></span>' . coreTabsLang()->t('List'));
    expect($sheets['permalinks']['caption'])->toBe('<span class="icon-link-1"></span>' . coreTabsLang()->t('Permalinks'));
});

test('users reads myBaseUrl and adds user_list/user_activity, not the dead duplicate case', function (): void {
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());
    $coreTabs->setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page='));

    $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'users');

    expect(array_keys($sheets))->toBe(['user_list', 'user_activity']);
    expect($sheets['user_list']['url'])->toBe('/admin.php?page=user_list');
    expect($sheets['user_activity']['url'])->toBe('/admin.php?page=user_activity');
    expect($sheets['user_list']['caption'])->toBe('<span class="icon-menu"></span>' . coreTabsLang()->t('List'));
    expect($sheets['user_activity']['caption'])->toBe('<span class="icon-pulse"></span>' . coreTabsLang()->t('Activity'));
});

test('batch_manager reads managerLink and adds global/unit', function (): void {
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());
    $coreTabs->setContext(new CoreTabsContext(managerLink: '/admin.php?page=batch_manager&mode='));

    $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'batch_manager');

    expect($sheets['global']['url'])->toBe('/admin.php?page=batch_manager&mode=global');
    expect($sheets['unit']['url'])->toBe('/admin.php?page=batch_manager&mode=unit');
    expect($sheets['global']['caption'])->toBe('<span class="icon-th"></span>' . coreTabsLang()->t('global mode'));
    expect($sheets['unit']['caption'])->toBe('<span class="icon-th-list"></span>' . coreTabsLang()->t('unit mode'));
});

test('cat_options always adds status/visible, plus comments/representative only when their config is enabled', function (): void {
    $currentConfig = coreTabsCurrentConfig();
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), $currentConfig);
    $coreTabs->setContext(new CoreTabsContext(linkStart: '/admin.php?page='));
    $currentConfig->setActivateComments(false);
    $currentConfig->setAllowRandomRepresentative(false);

    try {
        $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'cat_options');
        expect(array_keys($sheets))->toBe(['status', 'visible']);
        expect($sheets['status']['url'])->toBe('/admin.php?page=cat_options&amp;section=status');
        expect($sheets['visible']['url'])->toBe('/admin.php?page=cat_options&amp;section=visible');
        expect($sheets['status']['caption'])->toBe('<span class="icon-lock"></span>' . coreTabsLang()->t('Public / Private'));
        expect($sheets['visible']['caption'])->toBe('<span class="icon-block"></span>' . coreTabsLang()->t('Lock'));

        $currentConfig->setActivateComments(true);
        $currentConfig->setAllowRandomRepresentative(true);
        $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'cat_options');
        expect(array_keys($sheets))->toBe(['status', 'visible', 'comments', 'representative']);
        expect($sheets['comments']['url'])->toBe('/admin.php?page=cat_options&amp;section=comments');
        expect($sheets['representative']['url'])->toBe('/admin.php?page=cat_options&amp;section=representative');
        expect($sheets['comments']['caption'])->toBe('<span class="icon-chat"></span>' . coreTabsLang()->t('Comments'));
        expect($sheets['representative']['caption'])->toBe(coreTabsLang()->t('Representative'));
    } finally {
        $currentConfig->setActivateComments(false);
        $currentConfig->setAllowRandomRepresentative(false);
    }
});

test('comments reads myBaseUrl and adds a single List tab', function (): void {
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());
    $coreTabs->setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page='));

    $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'comments');

    expect($sheets['']['url'])->toBe('/admin.php?page=comments')
        ->and($sheets['']['caption'])->toBe('<span class="icon-menu"></span>' . coreTabsLang()->t('List'));
});

test('groups reads myBaseUrl and adds a single List tab pointing at group_list', function (): void {
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());
    $coreTabs->setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page='));

    $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'groups');

    expect($sheets['']['url'])->toBe('/admin.php?page=group_list')
        ->and($sheets['']['caption'])->toBe('<span class="icon-menu"> </span>' . coreTabsLang()->t('List'));
});

test('configuration reads confLink and adds main/sizes/watermark/display/comments/search', function (): void {
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());
    $coreTabs->setContext(new CoreTabsContext(confLink: '/admin.php?page=configuration&section='));

    $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'configuration');

    expect(array_keys($sheets))->toBe(['main', 'sizes', 'watermark', 'display', 'comments', 'search']);
    expect($sheets['main']['url'])->toBe('/admin.php?page=configuration&section=main');
    expect($sheets['sizes']['url'])->toBe('/admin.php?page=configuration&section=sizes');
    expect($sheets['watermark']['url'])->toBe('/admin.php?page=configuration&section=watermark');
    expect($sheets['display']['url'])->toBe('/admin.php?page=configuration&section=display');
    expect($sheets['comments']['url'])->toBe('/admin.php?page=configuration&section=comments');
    expect($sheets['search']['url'])->toBe('/admin.php?page=configuration&section=search');
    expect($sheets['main']['caption'])->toBe('<span class="icon-cog"></span>' . coreTabsLang()->t('General'));
    expect($sheets['sizes']['caption'])->toBe('<span class="icon-zoom-square"></span>' . coreTabsLang()->t('Photo sizes'));
    expect($sheets['watermark']['caption'])->toBe('<span class="icon-file-image"></span>' . coreTabsLang()->t('Watermark'));
    expect($sheets['display']['caption'])->toBe('<span class="icon-television"></span>' . coreTabsLang()->t('Display'));
    expect($sheets['comments']['caption'])->toBe('<span class="icon-chat"></span>' . coreTabsLang()->t('Comments'));
    expect($sheets['search']['caption'])->toBe('<span class="icon-search"></span>' . coreTabsLang()->t('Search'));
});

test('help reads helpLink and adds add_photos/permissions/groups/virtual_links/misc', function (): void {
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());
    $coreTabs->setContext(new CoreTabsContext(helpLink: '/admin.php?page=help&section='));

    $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'help');

    expect(array_keys($sheets))->toBe(['add_photos', 'permissions', 'groups', 'virtual_links', 'misc']);
    expect($sheets['add_photos']['url'])->toBe('/admin.php?page=help&section=add_photos');
    expect($sheets['permissions']['url'])->toBe('/admin.php?page=help&section=permissions');
    expect($sheets['groups']['url'])->toBe('/admin.php?page=help&section=groups');
    expect($sheets['virtual_links']['url'])->toBe('/admin.php?page=help&section=virtual_links');
    expect($sheets['misc']['url'])->toBe('/admin.php?page=help&section=misc');
    expect($sheets['add_photos']['caption'])->toBe(coreTabsLang()->t('Add Photos'));
    expect($sheets['permissions']['caption'])->toBe(coreTabsLang()->t('Permissions'));
    expect($sheets['groups']['caption'])->toBe(coreTabsLang()->t('Groups'));
    expect($sheets['virtual_links']['caption'])->toBe(coreTabsLang()->t('Virtual Links'));
    expect($sheets['misc']['caption'])->toBe(coreTabsLang()->t('Miscellaneous'));
});

test('history reads linkStart and adds stats/history', function (): void {
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());
    $coreTabs->setContext(new CoreTabsContext(linkStart: '/admin.php?page='));

    $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'history');

    expect($sheets['stats']['url'])->toBe('/admin.php?page=stats');
    expect($sheets['history']['url'])->toBe('/admin.php?page=history');
    expect($sheets['stats']['caption'])->toBe('<span class="icon-signal"></span>' . coreTabsLang()->t('Statistics'));
    expect($sheets['history']['caption'])->toBe('<span class="icon-search"></span>' . coreTabsLang()->t('Search'));
});

test('languages always adds installed, plus update/new only when extension installs are enabled', function (): void {
    $currentConfig = coreTabsCurrentConfig();
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), $currentConfig);
    $coreTabs->setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page=languages'));
    $currentConfig->setEnableExtensionsInstall(false);

    try {
        $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'languages');
        expect(array_keys($sheets))->toBe(['installed']);
        expect($sheets['installed']['url'])->toBe('/admin.php?page=languages&amp;tab=installed');
        expect($sheets['installed']['caption'])->toBe('<span class="icon-menu"></span>' . coreTabsLang()->t('List'));

        $currentConfig->setEnableExtensionsInstall(true);
        $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'languages');
        expect(array_keys($sheets))->toBe(['installed', 'update', 'new']);
        expect($sheets['update']['url'])->toBe('/admin.php?page=languages&amp;tab=update');
        expect($sheets['new']['url'])->toBe('/admin.php?page=languages&amp;tab=new');
        expect($sheets['update']['caption'])->toBe('<span class="icon-arrows-cw"></span>' . coreTabsLang()->t('Check for updates'));
        expect($sheets['new']['caption'])->toBe('<span class="icon-plus-circled"></span>' . coreTabsLang()->t('Add New Language'));
    } finally {
        $currentConfig->setEnableExtensionsInstall(false);
    }
});

test('menus reads myBaseUrl and adds a single List tab pointing at menubar', function (): void {
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());
    $coreTabs->setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page='));

    $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'menus');

    expect($sheets['']['url'])->toBe('/admin.php?page=menubar')
        ->and($sheets['']['caption'])->toBe('<span class="icon-menu"></span>' . coreTabsLang()->t('List'));
});

test('nbm reads baseUrl and adds param/subscribe/send', function (): void {
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());
    $coreTabs->setContext(new CoreTabsContext(baseUrl: '/root'));

    $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'nbm');

    expect(array_keys($sheets))->toBe(['param', 'subscribe', 'send']);
    expect($sheets['param']['url'])->toBe('/root?page=notification_by_mail&amp;mode=param');
    expect($sheets['subscribe']['url'])->toBe('/root?page=notification_by_mail&amp;mode=subscribe');
    expect($sheets['send']['url'])->toBe('/root?page=notification_by_mail&amp;mode=send');
    expect($sheets['param']['caption'])->toBe(coreTabsLang()->t('Parameter'));
    expect($sheets['subscribe']['caption'])->toBe(coreTabsLang()->t('Subscribe'));
    expect($sheets['send']['caption'])->toBe(coreTabsLang()->t('Send'));
});

test('photo reads adminPhotoBaseUrl and adds properties/coi, plus formats only when the multi-format feature is enabled', function (): void {
    $currentConfig = coreTabsCurrentConfig();
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), $currentConfig);
    $coreTabs->setContext(new CoreTabsContext(adminPhotoBaseUrl: '/admin.php?page=photo-42'));
    $currentConfig->setIsFormatsEnabled(false);

    try {
        $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'photo');
        expect(array_keys($sheets))->toBe(['properties', 'coi']);
        expect($sheets['properties']['url'])->toBe('/admin.php?page=photo-42-properties');
        expect($sheets['coi']['url'])->toBe('/admin.php?page=photo-42-coi');
        expect($sheets['properties']['caption'])->toBe('<span class="icon-file-image"></span>' . coreTabsLang()->t('Properties'));
        expect($sheets['coi']['caption'])->toBe('<span class="icon-crop"></span>' . coreTabsLang()->t('Center of interest'));

        $currentConfig->setIsFormatsEnabled(true);
        $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'photo');
        expect(array_keys($sheets))->toBe(['properties', 'coi', 'formats']);
        expect($sheets['formats']['url'])->toBe('/admin.php?page=photo-42-formats');
        expect($sheets['formats']['caption'])->toBe('<span class="icon-docs"></span>' . coreTabsLang()->t('Formats'));
    } finally {
        $currentConfig->setIsFormatsEnabled(false);
    }
});

test('photos_add needs no context, adds direct/applications, plus ftp only when synchronization is enabled', function (): void {
    $currentConfig = coreTabsCurrentConfig();
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), $currentConfig);
    $currentConfig->setEnableSynchronization(false);

    try {
        $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'photos_add');
        expect(array_keys($sheets))->toBe(['direct', 'applications']);
        expect($sheets['direct']['url'])->toBe('admin.php?page=photos_add&amp;section=direct');
        expect($sheets['applications']['url'])->toBe('admin.php?page=photos_add&amp;section=applications');
        expect($sheets['direct']['caption'])->toBe('<span class="icon-upload"></span>' . coreTabsLang()->t('Web Form'));
        expect($sheets['applications']['caption'])->toBe('<span class="icon-network"></span>' . coreTabsLang()->t('Applications'));

        $currentConfig->setEnableSynchronization(true);
        $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'photos_add');
        expect(array_keys($sheets))->toBe(['direct', 'applications', 'ftp']);
        expect($sheets['ftp']['url'])->toBe('admin.php?page=photos_add&amp;section=ftp');
        expect($sheets['ftp']['caption'])->toBe('<span class="icon-exchange"></span>' . coreTabsLang()->t('FTP + Synchronization'));
    } finally {
        $currentConfig->setEnableSynchronization(false);
    }
});

test('plugins always adds installed, plus update/new only when extension installs are enabled', function (): void {
    $currentConfig = coreTabsCurrentConfig();
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), $currentConfig);
    $coreTabs->setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page=plugins'));
    $currentConfig->setEnableExtensionsInstall(true);

    try {
        $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'plugins');
        expect(array_keys($sheets))->toBe(['installed', 'update', 'new']);
        expect($sheets['installed']['url'])->toBe('/admin.php?page=plugins&amp;tab=installed');
        expect($sheets['update']['url'])->toBe('/admin.php?page=plugins&amp;tab=update');
        expect($sheets['new']['url'])->toBe('/admin.php?page=plugins&amp;tab=new');
        expect($sheets['installed']['caption'])->toBe('<span class="icon-menu"></span>' . coreTabsLang()->t('List'));
        expect($sheets['update']['caption'])->toBe('<span class="icon-arrows-cw"></span>' . coreTabsLang()->t('Check for updates'));
        expect($sheets['new']['caption'])->toBe('<span class="icon-plus-circled"></span>' . coreTabsLang()->t('Add New Plugin'));
    } finally {
        $currentConfig->setEnableExtensionsInstall(false);
    }
});

test('rating needs no context and adds rating/rating_user', function (): void {
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());

    $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'rating');

    expect(array_keys($sheets))->toBe(['rating', 'rating_user']);
    expect($sheets['rating']['url'])->toBe('admin.php?page=rating');
    expect($sheets['rating_user']['url'])->toBe('admin.php?page=rating_user');
    expect($sheets['rating']['caption'])->toBe(coreTabsLang()->t('Photos'));
    expect($sheets['rating_user']['caption'])->toBe(coreTabsLang()->t('Users'));
});

test('rating prefixes each url with the resolved root url from UrlService', function (): void {
    // The sibling 'rating needs no context...' test above builds its
    // UrlService via coreTabsUrlService() -> UrlServiceTestFactory::build()
    // with no active RootPathOverride, whose getRootUrl() resolves to ''
    // (RequestMountDepth defaults to 0, so str_repeat('../', 0) === '') --
    // that test's expected 'admin.php?page=rating' is identical whether or
    // not the '. $this->urlService->getRootUrl()' concatenation runs at
    // all, so it can't distinguish a mutant that drops/reorders it. A
    // pushed RootPathOverride gives getRootUrl() a real, distinctive,
    // non-empty value the concatenation's presence/position/direction can
    // actually be observed through.
    $rootPathOverride = new RootPathOverride();
    $rootPathOverride->push('/piwigo-root/');

    try {
        $urlService = UrlServiceTestFactory::build(rootPathOverride: $rootPathOverride);
        $coreTabs = new CoreTabs(coreTabsLang(), $urlService, coreTabsCurrentConfig());

        $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'rating');

        expect($sheets['rating']['url'])->toBe('/piwigo-root/admin.php?page=rating');
        expect($sheets['rating_user']['url'])->toBe('/piwigo-root/admin.php?page=rating_user');
    } finally {
        $rootPathOverride->reset();
    }
});

test('themes always adds installed/standard_pages, plus update/new only when extension installs are enabled', function (): void {
    $currentConfig = coreTabsCurrentConfig();
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), $currentConfig);
    $coreTabs->setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page=themes'));
    $currentConfig->setEnableExtensionsInstall(false);

    try {
        $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'themes');
        expect(array_keys($sheets))->toBe(['installed', 'standard_pages']);
        expect($sheets['installed']['url'])->toBe('/admin.php?page=themes&amp;tab=installed');
        expect($sheets['standard_pages']['url'])->toBe('/admin.php?page=themes&amp;tab=standard_pages');
        expect($sheets['installed']['caption'])->toBe('<span class="icon-menu"></span>' . coreTabsLang()->t('List'));
        expect($sheets['standard_pages']['caption'])->toBe('<span class="icon-cog-alt"></span>' . coreTabsLang()->t('Standard pages'));

        $currentConfig->setEnableExtensionsInstall(true);
        $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'themes');
        expect($sheets['update']['url'])->toBe('/admin.php?page=themes&amp;tab=update');
        expect($sheets['new']['url'])->toBe('/admin.php?page=themes&amp;tab=new');
        expect($sheets['update']['caption'])->toBe('<span class="icon-arrows-cw"></span>' . coreTabsLang()->t('Check for updates'));
        expect($sheets['new']['caption'])->toBe('<span class="icon-plus-circled"></span>' . coreTabsLang()->t('Add New Theme'));
    } finally {
        $currentConfig->setEnableExtensionsInstall(false);
    }
});

test('updates adds pwg/ext independently, gated by their own separate config flags', function (): void {
    $currentConfig = coreTabsCurrentConfig();
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), $currentConfig);
    $coreTabs->setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page=updates'));
    $currentConfig->setEnableCoreUpdate(false);
    $currentConfig->setEnableExtensionsInstall(false);

    try {
        expect(array_keys(coreTabsTestAddCoreTabs($coreTabs, [], 'updates')))->toBe([]);

        $currentConfig->setEnableCoreUpdate(true);
        $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'updates');
        expect(array_keys($sheets))->toBe(['pwg']);
        expect($sheets['pwg']['url'])->toBe('/admin.php?page=updates');
        expect($sheets['pwg']['caption'])->toBe(coreTabsLang()->t('Piwigo core'));

        $currentConfig->setEnableCoreUpdate(false);
        $currentConfig->setEnableExtensionsInstall(true);
        $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'updates');
        expect(array_keys($sheets))->toBe(['ext']);
        expect($sheets['ext']['url'])->toBe('/admin.php?page=updates&amp;tab=ext');
        expect($sheets['ext']['caption'])->toBe(coreTabsLang()->t('Extensions'));

        $currentConfig->setEnableCoreUpdate(true);
        expect(array_keys(coreTabsTestAddCoreTabs($coreTabs, [], 'updates')))->toBe(['pwg', 'ext']);
    } finally {
        $currentConfig->setEnableCoreUpdate(false);
        $currentConfig->setEnableExtensionsInstall(false);
    }
});

test('site_update reads myBaseUrl and adds synchronization/site_maager', function (): void {
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());
    $coreTabs->setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page='));

    $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'site_update');

    expect(array_keys($sheets))->toBe(['synchronization', 'site_maager']);
    expect($sheets['synchronization']['url'])->toBe('/admin.php?page=site_update&site=1');
    expect($sheets['site_maager']['url'])->toBe('/admin.php?page=site_manager');
    expect($sheets['synchronization']['caption'])->toBe('<span class="icon-exchange"></span>' . coreTabsLang()->t('Synchronization'));
    expect($sheets['site_maager']['caption'])->toBe('<span class="icon-flow-branch"></span>' . coreTabsLang()->t('Site manager'));
});

test('maintenance reads myBaseUrl and adds actions/env/sys', function (): void {
    $coreTabs = new CoreTabs(coreTabsLang(), coreTabsUrlService(), coreTabsCurrentConfig());
    $coreTabs->setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page='));

    $sheets = coreTabsTestAddCoreTabs($coreTabs, [], 'maintenance');

    expect(array_keys($sheets))->toBe(['actions', 'env', 'sys']);
    expect($sheets['actions']['url'])->toBe('/admin.php?page=maintenance&tab=actions');
    expect($sheets['env']['url'])->toBe('/admin.php?page=maintenance&tab=env');
    expect($sheets['sys']['url'])->toBe('/admin.php?page=maintenance&tab=sys');
    expect($sheets['actions']['caption'])->toBe('<span class="icon-tools"></span>' . coreTabsLang()->t('Actions'));
    expect($sheets['env']['caption'])->toBe('<span class="icon-television"></span>' . coreTabsLang()->t('Environment'));
    expect($sheets['sys']['caption'])->toBe('<span class="icon-pulse"></span>' . coreTabsLang()->t('System Activities'));
});

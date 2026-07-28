<?php

declare(strict_types=1);

use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Html\HtmlService;
use Piwigo\Url\UrlService;

/**
 * Piwigo\Admin\CoreTabs::addCoreTabs() -- the pure 'tabsheet_before_select'
 * handler that builds each admin tabsheet group's own tab list, reached
 * indirectly (via Tabsheet::select() -> EventDispatcher::triggerChange())
 * by every real admin page, but fully exercisable directly here as a pure
 * function of ($sheets, $tabId) plus its 2 static setters.
 */
function coreTabsUrlService(): UrlServiceInterface
{
    return new UrlService(new HtmlService(), null);
}

beforeEach(function (): void {
    CoreTabs::setUrlService(coreTabsUrlService());
});

test('an unrecognized tab id returns the sheets unchanged', function (): void {
    expect(CoreTabs::addCoreTabs(['existing' => ['caption' => 'X', 'url' => '/x']], 'not-a-real-tab-id'))
        ->toBe(['existing' => ['caption' => 'X', 'url' => '/x']]);
    expect(CoreTabs::addCoreTabs([], null))->toBe([]);
});

test('admin_home needs no context and adds the Administration Home tab', function (): void {
    $sheets = CoreTabs::addCoreTabs([], 'admin_home');

    expect($sheets['']['url'])->toBe('admin.php');
});

test('tags reads myBaseUrl and adds a single List tab', function (): void {
    CoreTabs::setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page='));

    $sheets = CoreTabs::addCoreTabs([], 'tags');

    expect($sheets['']['url'])->toBe('/admin.php?page=tags');
});

test('tags throws when myBaseUrl was not set in the context', function (): void {
    CoreTabs::setContext(new CoreTabsContext());

    expect(fn () => CoreTabs::addCoreTabs([], 'tags'))->toThrow(RuntimeException::class);
});

test('a context-needing tab throws its own distinct exception when setContext() was never called at all', function (): void {
    // Distinct from the sibling test above: that one has a real
    // CoreTabsContext (setContext() was called), just with myBaseUrl left
    // null -- contextField()'s own guard. This test forces context()'s own
    // "self::$context is null" guard instead, which every other test in
    // this suite (including 'admin_home', which never reads context() at
    // all) would otherwise make unreachable for the rest of this process.
    $property = new ReflectionProperty(CoreTabs::class, 'context');
    $previous = $property->getValue();
    $property->setValue(null, null);

    try {
        expect(fn () => CoreTabs::addCoreTabs([], 'tags'))
            ->toThrow(RuntimeException::class, 'CoreTabs: no context set (writer file forgot CoreTabs::setContext()?)');
    } finally {
        $property->setValue(null, $previous);
    }
});

test('album reads adminAlbumBaseUrl and adds properties/sort_order/permissions/notification', function (): void {
    CoreTabs::setContext(new CoreTabsContext(adminAlbumBaseUrl: '/admin.php?page=album-5'));

    $sheets = CoreTabs::addCoreTabs([], 'album');

    expect(array_keys($sheets))->toBe(['properties', 'sort_order', 'permissions', 'notification']);
    expect($sheets['properties']['url'])->toBe('/admin.php?page=album-5-properties');
    expect($sheets['notification']['url'])->toBe('/admin.php?page=album-5-notification');
});

test('albums reads myBaseUrl and adds list/permalinks', function (): void {
    CoreTabs::setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page='));

    $sheets = CoreTabs::addCoreTabs([], 'albums');

    expect(array_keys($sheets))->toBe(['list', 'permalinks']);
    expect($sheets['list']['url'])->toBe('/admin.php?page=albums');
});

test('users reads myBaseUrl and adds user_list/user_activity, not the dead duplicate case', function (): void {
    CoreTabs::setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page='));

    $sheets = CoreTabs::addCoreTabs([], 'users');

    expect(array_keys($sheets))->toBe(['user_list', 'user_activity']);
});

test('batch_manager reads managerLink and adds global/unit', function (): void {
    CoreTabs::setContext(new CoreTabsContext(managerLink: '/admin.php?page=batch_manager&mode='));

    $sheets = CoreTabs::addCoreTabs([], 'batch_manager');

    expect($sheets['global']['url'])->toBe('/admin.php?page=batch_manager&mode=global');
    expect($sheets['unit']['url'])->toBe('/admin.php?page=batch_manager&mode=unit');
});

test('cat_options always adds status/visible, plus comments/representative only when their config is enabled', function (): void {
    CoreTabs::setContext(new CoreTabsContext(linkStart: '/admin.php?page='));
    CurrentConfig::setActivateComments(false);
    CurrentConfig::setAllowRandomRepresentative(false);

    try {
        $sheets = CoreTabs::addCoreTabs([], 'cat_options');
        expect(array_keys($sheets))->toBe(['status', 'visible']);

        CurrentConfig::setActivateComments(true);
        CurrentConfig::setAllowRandomRepresentative(true);
        $sheets = CoreTabs::addCoreTabs([], 'cat_options');
        expect(array_keys($sheets))->toBe(['status', 'visible', 'comments', 'representative']);
    } finally {
        CurrentConfig::setActivateComments(false);
        CurrentConfig::setAllowRandomRepresentative(false);
    }
});

test('comments reads myBaseUrl and adds a single List tab', function (): void {
    CoreTabs::setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page='));

    $sheets = CoreTabs::addCoreTabs([], 'comments');

    expect($sheets['']['url'])->toBe('/admin.php?page=comments');
});

test('groups reads myBaseUrl and adds a single List tab pointing at group_list', function (): void {
    CoreTabs::setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page='));

    $sheets = CoreTabs::addCoreTabs([], 'groups');

    expect($sheets['']['url'])->toBe('/admin.php?page=group_list');
});

test('configuration reads confLink and adds main/sizes/watermark/display/comments/search', function (): void {
    CoreTabs::setContext(new CoreTabsContext(confLink: '/admin.php?page=configuration&section='));

    $sheets = CoreTabs::addCoreTabs([], 'configuration');

    expect(array_keys($sheets))->toBe(['main', 'sizes', 'watermark', 'display', 'comments', 'search']);
});

test('help reads helpLink and adds add_photos/permissions/groups/virtual_links/misc', function (): void {
    CoreTabs::setContext(new CoreTabsContext(helpLink: '/admin.php?page=help&section='));

    $sheets = CoreTabs::addCoreTabs([], 'help');

    expect(array_keys($sheets))->toBe(['add_photos', 'permissions', 'groups', 'virtual_links', 'misc']);
});

test('history reads linkStart and adds stats/history', function (): void {
    CoreTabs::setContext(new CoreTabsContext(linkStart: '/admin.php?page='));

    $sheets = CoreTabs::addCoreTabs([], 'history');

    expect($sheets['stats']['url'])->toBe('/admin.php?page=stats');
    expect($sheets['history']['url'])->toBe('/admin.php?page=history');
});

test('languages always adds installed, plus update/new only when extension installs are enabled', function (): void {
    CoreTabs::setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page=languages'));
    CurrentConfig::setEnableExtensionsInstall(false);

    try {
        $sheets = CoreTabs::addCoreTabs([], 'languages');
        expect(array_keys($sheets))->toBe(['installed']);

        CurrentConfig::setEnableExtensionsInstall(true);
        $sheets = CoreTabs::addCoreTabs([], 'languages');
        expect(array_keys($sheets))->toBe(['installed', 'update', 'new']);
    } finally {
        CurrentConfig::setEnableExtensionsInstall(false);
    }
});

test('menus reads myBaseUrl and adds a single List tab pointing at menubar', function (): void {
    CoreTabs::setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page='));

    $sheets = CoreTabs::addCoreTabs([], 'menus');

    expect($sheets['']['url'])->toBe('/admin.php?page=menubar');
});

test('nbm reads baseUrl and adds param/subscribe/send', function (): void {
    CoreTabs::setContext(new CoreTabsContext(baseUrl: '/root'));

    $sheets = CoreTabs::addCoreTabs([], 'nbm');

    expect(array_keys($sheets))->toBe(['param', 'subscribe', 'send']);
    expect($sheets['param']['url'])->toBe('/root?page=notification_by_mail&amp;mode=param');
});

test('photo reads adminPhotoBaseUrl and adds properties/coi, plus formats only when the multi-format feature is enabled', function (): void {
    CoreTabs::setContext(new CoreTabsContext(adminPhotoBaseUrl: '/admin.php?page=photo-42'));
    CurrentConfig::setIsFormatsEnabled(false);

    try {
        $sheets = CoreTabs::addCoreTabs([], 'photo');
        expect(array_keys($sheets))->toBe(['properties', 'coi']);

        CurrentConfig::setIsFormatsEnabled(true);
        $sheets = CoreTabs::addCoreTabs([], 'photo');
        expect(array_keys($sheets))->toBe(['properties', 'coi', 'formats']);
    } finally {
        CurrentConfig::setIsFormatsEnabled(false);
    }
});

test('photos_add needs no context, adds direct/applications, plus ftp only when synchronization is enabled', function (): void {
    CurrentConfig::setEnableSynchronization(false);

    try {
        $sheets = CoreTabs::addCoreTabs([], 'photos_add');
        expect(array_keys($sheets))->toBe(['direct', 'applications']);

        CurrentConfig::setEnableSynchronization(true);
        $sheets = CoreTabs::addCoreTabs([], 'photos_add');
        expect(array_keys($sheets))->toBe(['direct', 'applications', 'ftp']);
    } finally {
        CurrentConfig::setEnableSynchronization(false);
    }
});

test('plugins always adds installed, plus update/new only when extension installs are enabled', function (): void {
    CoreTabs::setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page=plugins'));
    CurrentConfig::setEnableExtensionsInstall(true);

    try {
        $sheets = CoreTabs::addCoreTabs([], 'plugins');
        expect(array_keys($sheets))->toBe(['installed', 'update', 'new']);
    } finally {
        CurrentConfig::setEnableExtensionsInstall(false);
    }
});

test('rating needs no context and adds rating/rating_user', function (): void {
    $sheets = CoreTabs::addCoreTabs([], 'rating');

    expect(array_keys($sheets))->toBe(['rating', 'rating_user']);
});

test('themes always adds installed/standard_pages, plus update/new only when extension installs are enabled', function (): void {
    CoreTabs::setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page=themes'));
    CurrentConfig::setEnableExtensionsInstall(false);

    try {
        $sheets = CoreTabs::addCoreTabs([], 'themes');
        expect(array_keys($sheets))->toBe(['installed', 'standard_pages']);
    } finally {
        CurrentConfig::setEnableExtensionsInstall(false);
    }
});

test('updates adds pwg/ext independently, gated by their own separate config flags', function (): void {
    CoreTabs::setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page=updates'));
    CurrentConfig::setEnableCoreUpdate(false);
    CurrentConfig::setEnableExtensionsInstall(false);

    try {
        expect(array_keys(CoreTabs::addCoreTabs([], 'updates')))->toBe([]);

        CurrentConfig::setEnableCoreUpdate(true);
        expect(array_keys(CoreTabs::addCoreTabs([], 'updates')))->toBe(['pwg']);

        CurrentConfig::setEnableCoreUpdate(false);
        CurrentConfig::setEnableExtensionsInstall(true);
        expect(array_keys(CoreTabs::addCoreTabs([], 'updates')))->toBe(['ext']);

        CurrentConfig::setEnableCoreUpdate(true);
        expect(array_keys(CoreTabs::addCoreTabs([], 'updates')))->toBe(['pwg', 'ext']);
    } finally {
        CurrentConfig::setEnableCoreUpdate(false);
        CurrentConfig::setEnableExtensionsInstall(false);
    }
});

test('site_update reads myBaseUrl and adds synchronization/site_maager', function (): void {
    CoreTabs::setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page='));

    $sheets = CoreTabs::addCoreTabs([], 'site_update');

    expect(array_keys($sheets))->toBe(['synchronization', 'site_maager']);
    expect($sheets['synchronization']['url'])->toBe('/admin.php?page=site_update&site=1');
});

test('maintenance reads myBaseUrl and adds actions/env/sys', function (): void {
    CoreTabs::setContext(new CoreTabsContext(myBaseUrl: '/admin.php?page='));

    $sheets = CoreTabs::addCoreTabs([], 'maintenance');

    expect(array_keys($sheets))->toBe(['actions', 'env', 'sys']);
});

test('urlService() throws when setUrlService() was never called', function (): void {
    $method = new ReflectionMethod(CoreTabs::class, 'urlService');
    $property = new ReflectionProperty(CoreTabs::class, 'urlService');
    $property->setValue(null, null);

    expect(fn () => $method->invoke(null))->toThrow(RuntimeException::class);

    CoreTabs::setUrlService(coreTabsUrlService());
});
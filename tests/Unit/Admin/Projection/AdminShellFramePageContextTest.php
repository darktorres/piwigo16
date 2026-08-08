<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\AdminShellFramePageContext;

test('toArray flattens every fixed property, and omits U_UPDATES/U_COMMENTS/NB_PENDING_COMMENTS when null', function (): void {
    $context = new AdminShellFramePageContext(
        username: 'admin',
        enableSynchronization: true,
        uSiteManager: '/admin.php?page=site_manager',
        uHistoryStat: '/admin.php?page=stats',
        uFaq: '/admin.php?page=help',
        uMaintenance: '/admin.php?page=maintenance',
        uNotificationByMail: '/admin.php?page=notification_by_mail',
        uConfigGeneral: '/admin.php?page=configuration',
        uConfigDisplay: '/admin.php?page=configuration&section=default',
        uConfigExtents: '/admin.php?page=extend_for_templates',
        uConfigMenubar: '/admin.php?page=menubar',
        uConfigLanguages: '/admin.php?page=languages',
        uConfigThemes: '/admin.php?page=themes',
        uCategories: '/admin.php?page=cat_list',
        uAlbums: '/admin.php?page=albums',
        uCatOptions: '/admin.php?page=cat_options',
        uCatUpdate: '/admin.php?page=site_update&site=1',
        uRating: '/admin.php?page=rating',
        uRecentSet: '/admin.php?page=batch_manager&filter=prefilter-last_import',
        uBatch: '/admin.php?page=batch_manager',
        uTags: '/admin.php?page=tags',
        uUsers: '/admin.php?page=user_list',
        uGroups: '/admin.php?page=group_list',
        uReturn: '/',
        uAdmin: '/admin.php',
        uLogout: '/index.php?act=logout',
        uPlugins: '/admin.php?page=plugins',
        uAddPhotos: '/admin.php?page=photos_add',
        uChangeTheme: '/admin.php?change_theme=1',
        adminPageTitle: 'Piwigo Administration Page',
        adminPageObjectId: '',
        uShowTemplateTab: true,
        showRating: true,
        uUpdates: null,
        uComments: null,
        nbPendingComments: null,
        nbPhotosInCaddie: 0,
        uCaddie: '',
        nbOrphans: 0,
        uOrphans: '/admin.php?page=batch_manager&filter=prefilter-no_album',
        showWhatsNew: false,
        whatsNewMajorVersion: '16',
        releaseNoteUrl: 'https://piwigo.example/releases/16.0.0',
        whatsNewImgs: ['1' => 'a.png'],
        displayBell: false,
    );

    $result = $context->toArray();

    expect($result)->not->toHaveKeys(['U_UPDATES', 'U_COMMENTS', 'NB_PENDING_COMMENTS'])
        ->and($result['USERNAME'])->toBe('admin')
        ->and($result['NB_PHOTOS_IN_CADDIE'])->toBe(0)
        ->and($result['U_CADDIE'])->toBe('');
});

test('toArray includes U_UPDATES/U_COMMENTS/NB_PENDING_COMMENTS when set', function (): void {
    $context = new AdminShellFramePageContext(
        username: null,
        enableSynchronization: false,
        uSiteManager: '/admin.php?page=site_manager',
        uHistoryStat: '/admin.php?page=stats',
        uFaq: '/admin.php?page=help',
        uMaintenance: '/admin.php?page=maintenance',
        uNotificationByMail: '/admin.php?page=notification_by_mail',
        uConfigGeneral: '/admin.php?page=configuration',
        uConfigDisplay: '/admin.php?page=configuration&section=default',
        uConfigExtents: '/admin.php?page=extend_for_templates',
        uConfigMenubar: '/admin.php?page=menubar',
        uConfigLanguages: '/admin.php?page=languages',
        uConfigThemes: '/admin.php?page=themes',
        uCategories: '/admin.php?page=cat_list',
        uAlbums: '/admin.php?page=albums',
        uCatOptions: '/admin.php?page=cat_options',
        uCatUpdate: '/admin.php?page=site_update&site=1',
        uRating: '/admin.php?page=rating',
        uRecentSet: '/admin.php?page=batch_manager&filter=prefilter-last_import',
        uBatch: '/admin.php?page=batch_manager',
        uTags: '/admin.php?page=tags',
        uUsers: '/admin.php?page=user_list',
        uGroups: '/admin.php?page=group_list',
        uReturn: '/',
        uAdmin: '/admin.php',
        uLogout: '/index.php?act=logout',
        uPlugins: '/admin.php?page=plugins',
        uAddPhotos: '/admin.php?page=photos_add',
        uChangeTheme: '/admin.php?change_theme=1',
        adminPageTitle: 'Piwigo Administration Page',
        adminPageObjectId: '',
        uShowTemplateTab: false,
        showRating: false,
        uUpdates: '/admin.php?page=updates',
        uComments: '/admin.php?page=comments',
        nbPendingComments: 4,
        nbPhotosInCaddie: 2,
        uCaddie: '/admin.php?page=batch_manager&filter=prefilter-caddie',
        nbOrphans: 1,
        uOrphans: '/admin.php?page=batch_manager&filter=prefilter-no_album',
        showWhatsNew: true,
        whatsNewMajorVersion: '16',
        releaseNoteUrl: 'https://piwigo.example/releases/16.0.0',
        whatsNewImgs: ['1' => 'a.png'],
        displayBell: true,
    );

    $result = $context->toArray();

    expect($result['U_UPDATES'])->toBe('/admin.php?page=updates')
        ->and($result['U_COMMENTS'])->toBe('/admin.php?page=comments')
        ->and($result['NB_PENDING_COMMENTS'])->toBe(4);
});

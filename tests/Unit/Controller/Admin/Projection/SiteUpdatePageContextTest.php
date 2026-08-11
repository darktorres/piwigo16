<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\SiteUpdatePageContext;

test('toArray flattens every property, and omits footer_elements when null', function (): void {
    $context = new SiteUpdatePageContext(
        siteUrl: '/var/www/html/galleries',
        siteManagerUrl: '/admin.php?page=site_manager',
        resultUpdateLabel: 'Search for new images in the directories',
        resultMetadataLabel: 'Metadata synchronization results',
        metadataList: 'iptc, exif',
        helpUrl: '/admin/popuphelp.php?page=synchronize',
        adminPageTitle: 'Synchronize',
        pwgToken: 'abc123',
        footerElements: null,
    );

    expect($context->toArray())
        ->toBe([
            'SITE_URL' => '/var/www/html/galleries',
            'U_SITE_MANAGER' => '/admin.php?page=site_manager',
            'L_RESULT_UPDATE' => 'Search for new images in the directories',
            'L_RESULT_METADATA' => 'Metadata synchronization results',
            'METADATA_LIST' => 'iptc, exif',
            'U_HELP' => '/admin/popuphelp.php?page=synchronize',
            'ADMIN_PAGE_TITLE' => 'Synchronize',
            'CSRF_TOKEN' => 'abc123',
        ]);
});

test('toArray includes footer_elements when set', function (): void {
    $context = new SiteUpdatePageContext(
        siteUrl: '/var/www/html/galleries',
        siteManagerUrl: '/admin.php?page=site_manager',
        resultUpdateLabel: 'Search for new images in the directories',
        resultMetadataLabel: 'Metadata synchronization results',
        metadataList: 'iptc, exif',
        helpUrl: '/admin/popuphelp.php?page=synchronize',
        adminPageTitle: 'Synchronize',
        pwgToken: 'abc123',
        footerElements: ['<!-- scanning dirs : 0.12 s -->'],
    );

    expect($context->toArray()['footer_elements'])->toBe(['<!-- scanning dirs : 0.12 s -->']);
});

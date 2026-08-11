<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\AlbumNotificationPageContext;

test('toArray flattens every fixed property, and omits the 6 optional keys when null', function (): void {
    $context = new AlbumNotificationPageContext(
        saveSuccess: null,
        categoriesNav: 'Home / Holidays',
        fAction: '/admin.php?page=album-5-notification',
        pwgToken: 'abc123',
        authKeyDuration: null,
        noGroupInGallery: null,
        permissionUrl: null,
        groupMailOptions: null,
        userOptions: null,
    );

    expect($context->toArray())
        ->toBe([
            'CATEGORIES_NAV' => 'Home / Holidays',
            'F_ACTION' => '/admin.php?page=album-5-notification',
            'PWG_TOKEN' => 'abc123',
        ]);
});

test('toArray includes every optional key when set', function (): void {
    $context = new AlbumNotificationPageContext(
        saveSuccess: '1 mail was sent. (jane)',
        categoriesNav: 'Home / Holidays',
        fAction: '/admin.php?page=album-5-notification',
        pwgToken: 'abc123',
        authKeyDuration: '2 hours',
        noGroupInGallery: true,
        permissionUrl: '/admin.php?page=album-5-permissions',
        groupMailOptions: [
            1 => 'Family',
        ],
        userOptions: [
            2 => 'jane',
        ],
    );

    expect($context->toArray())
        ->toBe([
            'CATEGORIES_NAV' => 'Home / Holidays',
            'F_ACTION' => '/admin.php?page=album-5-notification',
            'PWG_TOKEN' => 'abc123',
            'save_success' => '1 mail was sent. (jane)',
            'auth_key_duration' => '2 hours',
            'no_group_in_gallery' => true,
            'permission_url' => '/admin.php?page=album-5-permissions',
            'group_mail_options' => [
                1 => 'Family',
            ],
            'user_options' => [
                2 => 'jane',
            ],
        ]);
});

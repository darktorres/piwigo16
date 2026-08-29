<?php

declare(strict_types=1);

use Piwigo\Menu\Projection\MenubarIdentificationPageContext;

test('toArray carries only the unconditional login redirect when every optional field is null', function (): void {
    $context = new MenubarIdentificationPageContext(
        querySearch: null,
        uStopFilter: null,
        uStartFilter: null,
        uLogin: null,
        uLostPassword: null,
        authorizeRemembering: null,
        uRegister: null,
        username: null,
        uProfile: null,
        uLogout: null,
        uAdmin: null,
        loginRedirect: '/index.php?/category/1',
    );

    expect($context->toArray())
        ->toBe([
            'LOGIN_REDIRECT' => '/index.php?/category/1',
        ]);
});

test('toArray includes the guest-branch keys when set', function (): void {
    $context = new MenubarIdentificationPageContext(
        querySearch: 'sunset',
        uStopFilter: null,
        uStartFilter: '/index.php?filter=start-recent-30',
        uLogin: '/identification.php',
        uLostPassword: '/password.php',
        authorizeRemembering: true,
        uRegister: '/register.php',
        username: null,
        uProfile: null,
        uLogout: null,
        uAdmin: null,
        loginRedirect: '/index.php?/category/1',
    );

    expect($context->toArray())
        ->toBe([
            'LOGIN_REDIRECT' => '/index.php?/category/1',
            'QUERY_SEARCH' => 'sunset',
            'U_START_FILTER' => '/index.php?filter=start-recent-30',
            'U_LOGIN' => '/identification.php',
            'U_LOST_PASSWORD' => '/password.php',
            'AUTHORIZE_REMEMBERING' => true,
            'U_REGISTER' => '/register.php',
        ]);
});

test('toArray includes the identified-user-branch keys when set', function (): void {
    $context = new MenubarIdentificationPageContext(
        querySearch: null,
        uStopFilter: '/index.php?filter=stop',
        uStartFilter: null,
        uLogin: null,
        uLostPassword: null,
        authorizeRemembering: null,
        uRegister: null,
        username: 'jane',
        uProfile: '/profile.php',
        uLogout: '/?act=logout',
        uAdmin: '/admin.php',
        loginRedirect: '/index.php?/category/1',
    );

    expect($context->toArray())
        ->toBe([
            'LOGIN_REDIRECT' => '/index.php?/category/1',
            'U_STOP_FILTER' => '/index.php?filter=stop',
            'USERNAME' => 'jane',
            'U_PROFILE' => '/profile.php',
            'U_LOGOUT' => '/?act=logout',
            'U_ADMIN' => '/admin.php',
        ]);
});

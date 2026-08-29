<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Menu\Projection\MenubarGuestIdentity;
use Piwigo\Menu\Projection\MenubarIdentificationView;
use Piwigo\Menu\Projection\MenubarUserIdentity;

function makeGuestIdentity(?string $registerUrl = '/register.php'): MenubarGuestIdentity
{
    return new MenubarGuestIdentity(
        loginUrl: '/identification.php',
        lostPasswordUrl: '/password.php',
        authorizeRemembering: true,
        registerUrl: $registerUrl,
    );
}

/**
 * The union is the point of this view: the guest and identified-user
 * halves used to reach the template as eight correlated nullables whose
 * exclusivity lived in a docblock. Constructing with one half must leave
 * the other null, because that is what every `n:if` in
 * menubar_identification.latte now narrows on.
 */
test('a guest identity populates $guest and leaves $user null', function (): void {
    $view = new MenubarIdentificationView(makeGuestIdentity(), loginRedirect: '/index.php?/category/1');

    expect($view->guest)
        ->toEqual(makeGuestIdentity());
    expect($view->user)
        ->toBeNull();
});

test('an identified-user identity populates $user and leaves $guest null', function (): void {
    $user = new MenubarUserIdentity(
        username: 'jane',
        profileUrl: '/profile.php',
        logoutUrl: '/?act=logout',
        adminUrl: '/admin.php',
    );

    $view = new MenubarIdentificationView($user, loginRedirect: '/index.php?/category/1');

    expect($view->user)
        ->toEqual($user);
    expect($view->guest)
        ->toBeNull();
});

/**
 * Registration can be disabled while the login form still renders, which
 * is why $registerUrl is the one nullable field on the guest half.
 */
test('a guest with registration disabled still carries the login form URLs', function (): void {
    $view = new MenubarIdentificationView(makeGuestIdentity(registerUrl: null), loginRedirect: '/');

    expect($view->guest?->registerUrl)
        ->toBeNull();
    expect($view->guest?->loginUrl)
        ->toBe('/identification.php');
    expect($view->guest?->lostPasswordUrl)
        ->toBe('/password.php');
});

/**
 * The raw request URI, un-encoded: the template applies its own
 * `|urlencode` at the hidden field, matching HtmlService::accessDenied().
 */
test('the login redirect is carried verbatim for the template to encode', function (): void {
    $view = new MenubarIdentificationView(makeGuestIdentity(), loginRedirect: '/index.php?/category/1&x=2');

    expect($view->loginRedirect)
        ->toBe('/index.php?/category/1&x=2');
});

/**
 * Moved here with the markup from MenubarView::pageAssets()'s former
 * `match ($block->template)` -- that dispatch keyed off a field this block
 * no longer sets, and the block that owns the CSS should register it.
 */
test('pageAssets registers this block own CSS', function (): void {
    $view = new MenubarIdentificationView(makeGuestIdentity(), loginRedirect: '/');

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::css('themes/default/css/components/menubar_identification.css', id: 'menubar_identification'),
        ]);
});

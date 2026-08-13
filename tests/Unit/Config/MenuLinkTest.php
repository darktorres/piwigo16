<?php

declare(strict_types=1);

use Piwigo\Config\MenuLink;

test('fromArray builds a plain-string shorthand link with default new_window and no visibility gate', function (): void {
    $link = MenuLink::fromArray('Plain Label');

    expect($link->label)
        ->toBe('Plain Label')
        ->and($link->visibilityLinkId)
        ->toBeNull()
        ->and($link->newWindow)
        ->toBeTrue()
        ->and($link->nwName)
        ->toBe('')
        ->and($link->nwFeatures)
        ->toBe('');
});

test('fromArray populates every field from a fully-populated array', function (): void {
    $link = MenuLink::fromArray([
        'label' => 'Array Label',
        'visibility_link_id' => 'ct-menu-link-visibility-probe',
        'new_window' => false,
        'nw_name' => 'popup',
        'nw_features' => 'width=400,height=300',
    ]);

    expect($link->label)
        ->toBe('Array Label')
        ->and($link->visibilityLinkId)
        ->toBe('ct-menu-link-visibility-probe')
        ->and($link->newWindow)
        ->toBeFalse()
        ->and($link->nwName)
        ->toBe('popup')
        ->and($link->nwFeatures)
        ->toBe('width=400,height=300');
});

test('fromArray defaults visibilityLinkId to null when the array has no visibility_link_id key at all', function (): void {
    // SEC-49: null is the "always visible" sentinel -- MenubarRenderer
    // skips dispatching CheckMenuLinkVisibility entirely for this case,
    // so a link the admin never opted into visibility-gating stays
    // visible without needing any plugin subscriber.
    $link = MenuLink::fromArray([
        'label' => 'No Gate Label',
    ]);

    expect($link->visibilityLinkId)
        ->toBeNull();
});

test('fromArray defaults new_window to true when the key is entirely absent, not just falsy', function (): void {
    $link = MenuLink::fromArray([
        'label' => 'No Key Label',
    ]);

    expect($link->newWindow)
        ->toBeTrue();
});

test('fromArray coerces a non-string visibility_link_id down to null instead of throwing', function (): void {
    $link = MenuLink::fromArray([
        'label' => 'Bad Type Label',
        'visibility_link_id' => 42,
    ]);

    expect($link->visibilityLinkId)
        ->toBeNull();
});

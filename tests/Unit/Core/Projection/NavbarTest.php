<?php

declare(strict_types=1);

use Piwigo\Core\Projection\Navbar;

/**
 * `isEmpty()` is what every template guard asks now that the flatten is
 * gone (P58-A). It replaced `{if !empty($navbar)}` over the flattened
 * array, and the two have to agree exactly: `empty()` on an object is
 * always false, so an `isEmpty()` that answered false for a genuinely
 * empty Navbar would put a navigation bar on every single-page listing --
 * and nothing static would report it.
 */
test('isEmpty is true for the no-navigation-needed instance', function (): void {
    expect(Navbar::none()->isEmpty())
        ->toBeTrue();
    expect(new Navbar()->isEmpty())
        ->toBeTrue();
    expect(Navbar::fromLegacyArray([])->isEmpty())
        ->toBeTrue();
});

test('isEmpty is false when any single field is set', function (): void {
    // One case per field, so no field can be dropped from the check
    // without a failure -- the whole point is that it is exhaustive.
    expect(new Navbar(currentPage: 1)->isEmpty())
        ->toBeFalse();
    expect(new Navbar(urlFirst: '/index.php')->isEmpty())
        ->toBeFalse();
    expect(new Navbar(urlPrev: '/index.php')->isEmpty())
        ->toBeFalse();
    expect(new Navbar(urlNext: '/index.php?start=2')->isEmpty())
        ->toBeFalse();
    expect(new Navbar(urlLast: '/index.php?start=4')->isEmpty())
        ->toBeFalse();
    expect(new Navbar(pages: [
        1 => '/index.php',
    ])->isEmpty())->toBeFalse();
    expect(new Navbar(nbPage: 3)->isEmpty())
        ->toBeFalse();
});

test('isEmpty is false for a real multi-page bar', function (): void {
    expect(Navbar::fromLegacyArray([
        'CURRENT_PAGE' => 1,
        'URL_NEXT' => '/index.php?start=2',
        'URL_LAST' => '/index.php?start=4',
        'pages' => [
            1 => '/index.php',
            3 => '/index.php?start=4',
        ],
        'NB_PAGE' => 3,
    ])->isEmpty())
        ->toBeFalse();
});

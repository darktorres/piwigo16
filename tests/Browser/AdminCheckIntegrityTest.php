<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

// check_integrity.ts had no coverage in any suite, and could not get any from
// the shared route table: IntroSubController renders CheckIntegrityView only
// when `$c13yResult !== null` -- that is, only when the integrity check
// actually finds an anomaly. The fixture gallery is clean, so `/admin.php`
// renders no panel and never loads the bundle (the admin-dashboard fixture
// contains zero occurrences of it). Covering it therefore means seeding a
// real anomaly.
//
// C13yInternal::c13yUser() is the cheapest true one: it compares the
// configured guest/webmaster users against their expected status, so flipping
// the guest user's status to `normal` produces "Main \"guest\" user status is
// incorrect" through the real check, with no stubbing. `guest_id` is not set
// in config here, so CurrentConfig::$guestId's own default of 2 applies --
// user 2, `guest`.

it('renders the integrity panel and loads its bundle when a real anomaly exists', function (): void {
    $db = H::connect();
    $before = H::dbFetchAssoc($db, 'SELECT status FROM user_infos WHERE user_id = 2');
    $original = is_string($before['status'] ?? null) ? $before['status'] : 'guest';

    try {
        H::dbQuery($db, "UPDATE user_infos SET status = 'normal' WHERE user_id = 2");

        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php');

        // The panel itself, and the bundle that drives it -- the whole point
        // of the test is that neither appears without an anomaly.
        $page->assertPresent('#c13y');
        $page->assertPresent('#checkAllLink');

        $loadsBundle = $page->script(
            "Array.from(document.querySelectorAll('script[src]')).some(s => s.src.includes('checkIntegrity'))"
        );

        expect($loadsBundle)
            ->toBeTrue();

        $page->assertNoJavaScriptErrors();
    } finally {
        H::dbQuery(
            $db,
            "UPDATE user_infos SET status = '" . H::dbEscape($db, $original) . "' WHERE user_id = 2"
        );
        H::dbClose($db);
        H::markSharedSessionDirty();
    }
});

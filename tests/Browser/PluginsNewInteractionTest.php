<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use PHPUnit\Framework\Assert;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/plugins_new.ts -- 0% prior
 * JS coverage (PluginsNewPageRendererTest.php's own docblock confirms
 * `getServerExtensions()` always fails in this offline environment, so
 * `$plugins` is always `[]` and `.pluginBox` never renders through a live
 * page load here -- everything gated on real plugin data (rating stars
 * per box, author/tags collection, sort/filter, `.buttonInstall`) is
 * therefore untestable live in this environment; not fixed or worked
 * around, since it's the same documented PEM-reachability limitation
 * every other test of this page already accepts).
 *
 * What IS unconditionally rendered regardless of PEM reachability is the
 * sort/filter chrome itself, so this file covers: the advanced-filter
 * panel's open/close toggle, the beta-test-plugin checkbox's URL+reload
 * flow, and the advanced filter's own always-present rating-star/
 * certification/revision-date widgets' initial render (each set once by
 * this file's own converted `updateRatingFilterLabel(0)`/
 * `updateCertificationFilterLabel(minCertification)`/
 * `updateRevisionFilterLabel(0)` calls at load).
 *
 * `.sortElements()` (jquery.sort, P49-B group 1), `.selectize()` (group 6),
 * `.slider()` (jQuery-UI, group 4), `.tipTip()` (group 2) and
 * `pwg_jconfirm_follow_href` (jquery-confirm, group 5) stay jQuery.
 */
it('toggles the advanced-filter panel open and closed', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=plugins&tab=new');

    $isOpen = static fn (Webpage|PendingAwaitablePage|AwaitableWebpage $page): bool => H::scriptBool(
        $page,
        "document.querySelector('.advanced-filter').classList.contains('advanced-filter-open')"
    );

    expect($isOpen($page))
        ->toBeFalse();

    $page->click('.advanced-filter-btn');

    expect($isOpen($page))
        ->toBeTrue();

    $page->click('.advanced-filter span.icon-cancel');

    expect($isOpen($page))
        ->toBeFalse();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'plugins_new advanced-filter toggle');
});

it('renders the advanced filter\'s rating/certification/revision widgets at their zero-state on load', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=plugins&tab=new');

    $ratingIcons = H::scriptJson(
        $page,
        "JSON.stringify(Array.from(document.querySelectorAll('.advanced-filter-rating .rating-star-container span')).map((el) => el.className))"
    );

    expect($ratingIcons)
        ->toHaveCount(5);
    foreach ($ratingIcons as $className) {
        if (! is_string($className)) {
            throw new RuntimeException('expected a className string, got: ' . var_export($className, true));
        }
        expect($className)
            ->toBe('icon-star-empty');
    }

    $certification = H::scriptString(
        $page,
        "document.querySelector('.advanced-filter-certification .certification').getAttribute('data-certification')"
    );
    // betaTestPlugins is false for a real admin with no `beta-test` query
    // param, so minCertification is 0 (not -1).
    expect($certification)
        ->toBe('0');

    $revisionDate = H::scriptString(
        $page,
        "document.querySelector('.revision-date').textContent"
    );
    expect($revisionDate)
        ->toBe('since the beginning');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'plugins_new advanced-filter zero-state');
});

it('reloads with the beta-test query param set after toggling the switch', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=plugins&tab=new');

    // `.switch input` is visually hidden (opacity:0, 0x0) -- the toggle's
    // real click target is its `<label class="switch">` wrapper, same
    // native label-forwards-to-checkbox pattern as common.ts's own
    // font-checkbox inputs.
    H::rawWebpage($page)->click('label.switch');

    // The click's own handler calls window.location.reload() directly (a
    // real browser navigation, not one this harness's click() awaits), so
    // poll for the reload to land rather than asserting immediately.
    $url = H::scriptString($page, <<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                if (window.location.search.includes('beta-test=true')) {
                    return resolve(window.location.href);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('page never reloaded with beta-test=true'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);

    expect($url)
        ->toContain('beta-test=true');

    H::assertNoServerErrors($page, 'plugins_new beta-test-plugin switch');
});

it('renders a real plugin\'s half-star rating without crashing on an unquoted attribute selector', function (): void {
    // Confirmed live: getServerExtensions() genuinely reaches PEM in this
    // environment (same surprise as updates_ext.ts's page) and returns at
    // least one real available plugin -- this test's own setup found one
    // with a 4.5 rating, which is exactly the value that crashed
    // displayStars() before its attribute-selector values were quoted
    // (span[data-star=4], unquoted, is invalid native CSS; Sizzle
    // tolerated it, querySelectorAll doesn't -- see displayStars()'s own
    // comment). If no real plugin is available when this runs, the
    // assertion is skipped rather than failing on an environment this
    // test doesn't control.
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=plugins&tab=new');

    $rawState = $page->script(<<<'JS'
        (() => {
            const box = document.querySelector('.pluginRating');
            if (box === null) return null;
            const rating = parseFloat(box.getAttribute('data-rating'));
            const stars = Array.from(box.querySelectorAll('.rating-star-container span')).map((el) => ({
                emptyRemoved: !el.classList.contains('icon-star-empty'),
                iconClass: el.querySelector('i').className,
            }));
            return JSON.stringify({ rating, stars });
        })()
        JS);

    if ($rawState === null) {
        Assert::markTestSkipped('No real plugin with a rating was available from PEM in this environment.');
    }

    if (! is_string($rawState)) {
        throw new RuntimeException('expected null or a JSON string, got: ' . var_export($rawState, true));
    }

    $decoded = json_decode($rawState, true);
    if (! is_array($decoded) || ! is_numeric($decoded['rating'] ?? null) || ! is_array($decoded['stars'] ?? null)) {
        throw new RuntimeException('unexpected state shape: ' . var_export($decoded, true));
    }

    $rating = (float) $decoded['rating'];
    $stars = $decoded['stars'];
    $fullStars = (int) floor($rating);
    $hasHalf = $rating - $fullStars >= 0.5;

    for ($i = 0; $i < 5; $i++) {
        $star = $stars[$i] ?? null;
        if (! is_array($star) || ! is_bool($star['emptyRemoved'] ?? null) || ! is_string($star['iconClass'] ?? null)) {
            throw new RuntimeException("unexpected star shape at index {$i}: " . var_export($star, true));
        }

        if ($i < $fullStars) {
            expect($star['emptyRemoved'])->toBeTrue("star {$i} should be full");
            expect($star['iconClass'])->toBe('icon-star');
        } elseif ($i === $fullStars && $hasHalf) {
            expect($star['iconClass'])->toBe('icon-star-half');
        } else {
            expect($star['iconClass'])->toBe('');
        }
    }

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'plugins_new real plugin rating stars');
});

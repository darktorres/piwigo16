<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

// End-to-end cover for the incompatible-plugins panel, which was dead from
// P40 until P48 and had no test of any kind (docs/PLAN.md). The whole
// feature hangs off one jQuery.ajax call in
// themes/admin/default/js/plugins_installated.ts: it GETs admin.php with
// `dataType: "json"`, and everything downstream -- the .warning marker, the
// `incompatible` row class, the confirm-before-activate guard on .activate
// -- lives in that call's own success handler.
//
// That is exactly why it could die silently. The request used
// `page=plugins_installed`, upstream Piwigo's slug; this fork consolidated
// the per-tab slugs into `page=plugins` + `tab` (CoreTabs.php's own
// 'plugins' case). An unrecognised slug does not 404 -- it returns 200 with
// the default admin page's HTML -- so `dataType: "json"` failed to parse on
// every single view, the success handler never ran, and no error surfaced
// anywhere. Nothing in the suite noticed, because the fixture database has
// no incompatible plugin, so the panel renders identically whether the
// handler runs or not: golden-html and VR both passed throughout.
//
// PluginsInstalledPageRendererTest already exercises the endpoint, but only
// ever against an empty result and without the `tab` segment
// (`page=plugins&incompatible_plugins=1`, asserting `body === '[]'`), which
// cannot distinguish "the lookup ran and found nothing" from "the lookup
// never ran". These three add the parts that can: the exact URL the JS
// builds, a non-empty result reached through the real PemCatalog path, the
// retired slug's actual behaviour, and the DOM the success handler produces.
//
// Why a filesystem fixture is enough to make a plugin "incompatible":
// PemCatalog::getIncompatibleExtensions() keeps a plugin only when its
// installed version is absent from the PEM revisions that are compatible
// with this Piwigo version. getVersionsToCheck() returns [AppInfo::VERSION]
// -- branch '17' -- and no entry in the local PEM manifest
// (PIWIGO_ALT_PLUGINS_PEM_URL, a real static manifest.json served over
// http, see .env.test) declares `piwigo_compat` '17': v17 is a deliberate
// clean break from the external catalog (project_version_17_breaks_extensions,
// verified against the manifest -- 405 extensions, zero of them v17). So
// the compatible-revision set is empty for every extension, and any scanned
// plugin carrying a PEM extension id is incompatible. No manifest fixture,
// no stubbed HTTP, no network.

const INCOMPATIBLE_FIXTURE_PLUGIN_ID = 'zz_incompatible_fixture';

/**
 * ExtensionScanner::scanPlugin() needs a real directory holding a
 * `plugin.json` it can json_decode. `homepage` is not decoration: it is the
 * only source of the PEM extension id (extractExtensionId() looks for
 * `extension_view.php?eid=` and requires the tail to be numeric), and
 * getIncompatibleExtensions() skips any scan row whose `extension` is null,
 * so without it the plugin is simply never considered.
 *
 * The id must also stay outside ExtensionType::Plugin->defaultIds() --
 * bundled plugins are exempt from the check by design.
 */
function incompatible_fixture_plugin_create(): string
{
    $dir = dirname(__DIR__, 2) . '/plugins/' . INCOMPATIBLE_FIXTURE_PLUGIN_ID;
    if (! is_dir($dir) && ! mkdir($dir, 0o777, true) && ! is_dir($dir)) {
        throw new RuntimeException('could not create the fixture plugin directory: ' . $dir);
    }

    $manifest = [
        'name' => 'Incompatible Fixture Plugin',
        'version' => '1.0.0',
        // eid 879 is a real extension in the local manifest. Any numeric
        // eid would do -- nothing is v17-compatible -- but a real one keeps
        // this honest if the manifest ever does gain a v17 revision: the
        // test would then start failing rather than passing for the wrong
        // reason against an id the catalog has never heard of.
        'homepage' => 'https://piwigo.org/ext/extension_view.php?eid=879',
        'description' => 'Browser-test fixture for the incompatible-plugins panel.',
        'author' => 'piwigo-tests',
    ];

    file_put_contents($dir . '/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    return $dir;
}

function incompatible_fixture_plugin_remove(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $files = glob($dir . '/*');
    foreach ($files === false ? [] : $files as $file) {
        unlink($file);
    }

    rmdir($dir);
}

/**
 * The panel's markers arrive on an ajax round trip that navigateOk() does
 * not wait for, and assertPresent() is one-shot -- so poll, the same shape
 * as BrowserTestHelpers::waitUntilHidden().
 */
function incompatible_fixture_wait_for_marker(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $id): void
{
    $timeoutMs = 5000;
    $page->script(<<<JS
    new Promise((resolve, reject) => {
        const deadline = Date.now() + {$timeoutMs};
        const check = () => {
            const row = document.getElementById('{$id}');
            if (row !== null && row.classList.contains('incompatible')) {
                return resolve(true);
            }
            if (Date.now() > deadline) {
                return reject(new Error('Timed out waiting for #{$id} to be marked incompatible'));
            }
            setTimeout(check, 100);
        };
        check();
    })
    JS);
}

it('serves the incompatible-plugins list as JSON from the fork\'s own slug', function (): void {
    // loginAsAdmin(), not asAdmin(): getIncompatibleExtensions() memoises
    // its result in $_SESSION['incompatible_plugins'] for 300 seconds, and
    // asAdmin() reuses one session for the whole suite run -- an earlier
    // test that loaded admin.php?page=plugins would have cached an empty
    // list from before this fixture existed. loginAsAdmin() mints a brand
    // new session per call, so the lookup genuinely runs.
    // BrowserTestHelpers::$sharedSessionKnownClean's own docblock names
    // this exact key as one of the shared-session hazards.
    $dir = incompatible_fixture_plugin_create();

    try {
        $page = H::loginAsAdmin($this);

        $response = H::rawGet($page, '/admin.php?page=plugins&tab=installed&incompatible_plugins=true');

        expect($response['status'])
            ->toBe(200);

        // The regression assertion. The broken slug also answered 200 --
        // with an HTML document -- so status alone proves nothing; that the
        // body actually parses as JSON is the whole point.
        $decoded = json_decode($response['body'], true);

        expect($decoded)
            ->toBeArray()
            ->and($response['body'])
            ->not->toStartWith('<')
            ->and($decoded)
            ->toContain(INCOMPATIBLE_FIXTURE_PLUGIN_ID);
    } finally {
        incompatible_fixture_plugin_remove($dir);
    }
});

it('returns the default admin page, not JSON, for upstream Piwigo\'s retired slug', function (): void {
    // Pins the trap itself rather than just the fix. `page=plugins_installed`
    // is what the request used until P48; if someone restores that slug the
    // test above goes red, and this one explains why by showing what the
    // unrecognised slug actually does -- answer 200 with HTML, which is
    // precisely why `dataType: "json"` failed silently instead of loudly.
    $page = H::loginAsAdmin($this);

    $response = H::rawGet($page, '/admin.php?page=plugins_installed&incompatible_plugins=true');

    expect($response['status'])
        ->toBe(200)
        ->and(json_decode($response['body'], true))
        ->toBeNull();
});

it('asks before activating an incompatible plugin, and reverts the switch when refused', function (): void {
    // The guard this covers was dead code until now. It used to bind to
    // `#<id> .activate` -- upstream Piwigo's <a class="activate"> link,
    // which this fork replaced with the toggle switch. No element of that
    // class exists in any template, so the .each() matched nothing: the
    // warning marker rendered and activation went straight through with no
    // confirmation at all. It now hangs off the switch handler instead.
    $dir = incompatible_fixture_plugin_create();

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=plugins');

        $id = INCOMPATIBLE_FIXTURE_PLUGIN_ID;
        incompatible_fixture_wait_for_marker($page, $id);

        // The change handler is bound only when is_webmaster is truthy
        // (plugins_installated.ts's own `if (isWebmaster != 0)`), so assert
        // that first: otherwise the switch would simply do nothing and the
        // "no activation happened" assertions below would pass for entirely
        // the wrong reason.
        // page-data is shaped {data, strings} -- the exposed values live
        // under .data, not at the top level.
        expect($page->script('JSON.parse(document.getElementById("page-data").textContent).data.is_webmaster'))
            ->toBeTruthy();

        $page->click('#' . $id . ' label.switch');

        // jquery-confirm renders into .jconfirm, carrying incompatible_msg
        // as its title.
        $page->assertPresent('.jconfirm');
        H::assertSeeSettled($page, 'does not seem to be compatible');

        // Refusing must both leave the plugin inactive and put the switch
        // back: `change` has already flipped it by the time the dialog
        // opens. jConfirm_confirm_with_content_options sets
        // backgroundDismiss, so the revert hangs off onClose rather than
        // the cancel button's own action -- clicking cancel exercises the
        // same path a backdrop dismissal takes.
        $page->click('.jconfirm button.btn-default');

        $reverted = $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 5000;
            const check = () => {
                const box = document.querySelector('#{$id} label.switch input');
                if (box !== null && box.checked === false) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('switch was never reverted'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);

        expect($reverted)
            ->not->toBeNull();

        $page->assertPresent('#' . $id . '.plugin-inactive');
        $page->assertNotPresent('#' . $id . '.plugin-active');

        $page->assertNoJavaScriptErrors();
    } finally {
        incompatible_fixture_plugin_remove($dir);
    }
});

it('marks an incompatible plugin in the DOM once the panel\'s ajax call lands', function (): void {
    $dir = incompatible_fixture_plugin_create();

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=plugins');

        $id = INCOMPATIBLE_FIXTURE_PLUGIN_ID;

        incompatible_fixture_wait_for_marker($page, $id);

        // What the success handler is responsible for: the row class, and
        // the warning marker prepended into .pluginName. A <span>, not an
        // <a>: the marker's tag is the handler's own show_details branch,
        // and loginAsAdmin()'s brand-new session leaves show_details at its
        // false default.
        $page->assertPresent('#' . $id . '.incompatible');
        $page->assertPresent('#' . $id . ' .pluginName span.warning');

        // The marker carries no `title` by the time it is observable, even
        // though the handler prepends one. Verified against the real DOM,
        // not assumed: the element settles as
        // `<span class="warning"></span>`, sole attribute `class`. The
        // handler's own trailing jQuery(".warning").tipTip() call consumes
        // the attribute -- tipTip moves it into its own tooltip and strips
        // it so the browser's native tooltip cannot double up. Asserting
        // its absence pins that the tipTip call still runs: drop it and the
        // title survives, and this goes red.
        $page->assertNotPresent('#' . $id . ' .pluginName .warning[title]');

        $page->assertNoJavaScriptErrors();
    } finally {
        incompatible_fixture_plugin_remove($dir);
    }
});

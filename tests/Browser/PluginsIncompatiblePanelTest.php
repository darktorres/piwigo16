<?php

declare(strict_types=1);

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

it('marks an incompatible plugin in the DOM once the panel\'s ajax call lands', function (): void {
    $dir = incompatible_fixture_plugin_create();

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=plugins');

        $id = INCOMPATIBLE_FIXTURE_PLUGIN_ID;

        // navigateOk() waits for the document, not for this panel's own
        // ajax round trip, so the markers do not exist yet at this point --
        // assertPresent() is one-shot and would race it. Poll instead, the
        // same shape as waitUntilHidden().
        $timeoutMs = 5000;
        $js = <<<JS
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
        JS;
        $page->script($js);

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

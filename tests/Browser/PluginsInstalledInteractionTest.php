<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/plugins_installated.ts --
 * PluginsInstalledPageRendererTest.php covers the page-render/session
 * branches, and PluginsIncompatiblePanelTest.php already covers the
 * incompatible-plugin confirm-before-activate path end to end (including
 * the switch revert on cancel). This file covers what neither does: a
 * plain, *compatible* plugin's activate/deactivate success flow (no
 * confirm dialog, the AddPluginSuccess/DeactivatePluginSuccess markers),
 * and the search-input filtering.
 *
 * Stays jQuery: `$.confirm()`/`$.alert()` (jquery-confirm, P49-B group 5)
 * and `jQuery(".warning").tipTip(...)` (tipTip, P49-B group 2) -- only
 * the DOM work around them converted.
 *
 * `PluginsIncompatiblePanelTest.php`'s own bare-plugin.json fixture is
 * enough to be *visible* in the listing, but not enough to actually
 * *activate*: `ExtensionLifecycle::performPluginAction()` requires a
 * validated manifest (a real, autoloadable class implementing
 * `ExtensionInterface`) and answers 422 "has no validated manifest"
 * without one -- confirmed live, not assumed. This uses
 * `PluginsInstalledPageRendererTest.php`'s own
 * `pluginsInstalledWriteHookedFixturePlugin()`-shaped technique instead
 * (a real PSR-4 class with inert `activate()`/`deactivate()` methods),
 * self-contained rather than reused across files. No `homepage` field,
 * so `PemCatalog::getIncompatibleExtensions()` skips it (null
 * `extension`) and it renders as a normal, compatible plugin.
 */
const PLUGINS_INTERACTION_FIXTURE_ID = 'zz_plugins_interaction_fixture';

function pluginsInteractionFixtureCreate(): string
{
    $dir = dirname(__DIR__, 2) . '/plugins/' . PLUGINS_INTERACTION_FIXTURE_ID;
    if (! is_dir($dir . '/src') && ! mkdir($dir . '/src', 0o777, true) && ! is_dir($dir . '/src')) {
        throw new RuntimeException('could not create the fixture plugin directory: ' . $dir);
    }

    $namespace = 'PiwigoTestFixture\\PluginsInteraction' . bin2hex(random_bytes(6));

    $manifest = [
        'id' => PLUGINS_INTERACTION_FIXTURE_ID,
        'name' => 'Plugins Interaction Fixture Plugin',
        'version' => '1.0.0',
        'description' => 'Browser-test fixture for plugins_installated.ts (P49-A).',
        'author' => 'piwigo-tests',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        'main' => $namespace . '\\Plugin',
        'autoload' => [
            'psr-4' => [
                $namespace . '\\' => 'src/',
            ],
        ],
    ];

    file_put_contents($dir . '/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($dir . '/src/Plugin.php', <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Piwigo\\PluginConfig\\ExtensionContext;
        use Piwigo\\PluginConfig\\ExtensionInterface;

        final class Plugin implements ExtensionInterface
        {
            public function boot(ExtensionContext \$context): void {}
            public function install(): void {}
            public function activate(): void {}
            public function deactivate(): void {}
            public function uninstall(): void {}
            public function update(string \$oldVersion, string \$newVersion): void {}

            public function subscribedEvents(): array
            {
                return [];
            }
        }

        PHP);

    return $dir;
}

function pluginsInteractionFixtureRemove(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    if (is_file($dir . '/src/Plugin.php')) {
        unlink($dir . '/src/Plugin.php');
    }
    if (is_dir($dir . '/src')) {
        rmdir($dir . '/src');
    }
    if (is_file($dir . '/plugin.json')) {
        unlink($dir . '/plugin.json');
    }

    rmdir($dir);
}

function pluginsInteractionWaitForRow(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $id): void
{
    $page->script(<<<JS
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 8000;
            const check = () => {
                if (document.getElementById('{$id}') !== null) return resolve(true);
                if (Date.now() > deadline) return reject(new Error('plugin row never rendered'));
                setTimeout(check, 100);
            };
            check();
        })
        JS);
}

it('activates then deactivates a compatible plugin, showing the matching success marker each time', function (): void {
    $dir = pluginsInteractionFixtureCreate();
    $id = PLUGINS_INTERACTION_FIXTURE_ID;

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=plugins&tab=installed');
        pluginsInteractionWaitForRow($page, $id);

        $page->assertPresent('#' . $id . '.plugin-inactive');

        $page->click('#' . $id . ' label.switch');

        // The row's own plugin-active/plugin-inactive class flips
        // synchronously, before the ajax call that actually performs the
        // action even starts (applyActivation() updates the row eagerly;
        // activatePlugin()'s own success handler, which shows
        // .AddPluginSuccess, only runs once that ajax call resolves) --
        // wait on the success marker itself, the real signal the round
        // trip actually completed, not the row's own immediate class.
        $page->script(<<<JS
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 5000;
                const check = () => {
                    const marker = document.querySelector('#{$id} .AddPluginSuccess');
                    if (marker !== null && getComputedStyle(marker).display !== 'none') return resolve(true);
                    if (Date.now() > deadline) return reject(new Error('plugin activation never succeeded'));
                    setTimeout(check, 100);
                };
                check();
            })
            JS);

        $page->assertPresent('#' . $id . '.plugin-active');
        expect($page->script(
            "document.querySelector('#{$id} .switch input').checked",
        ))
            ->toBeTrue();

        $page->click('#' . $id . ' label.switch');

        $page->script(<<<JS
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 5000;
                const check = () => {
                    const marker = document.querySelector('#{$id} .DeactivatePluginSuccess');
                    if (marker !== null && getComputedStyle(marker).display !== 'none') return resolve(true);
                    if (Date.now() > deadline) return reject(new Error('plugin deactivation never succeeded'));
                    setTimeout(check, 100);
                };
                check();
            })
            JS);

        $page->assertPresent('#' . $id . '.plugin-inactive');

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'plugins_installated activate/deactivate');
    } finally {
        pluginsInteractionFixtureRemove($dir);
    }
});

it('filters the plugin list via the search box', function (): void {
    // Not exercised here: the seeActive/seeInactive radios themselves.
    // actualizeFilter() hides whichever of those two options has a zero
    // count (there is no reason to offer an "Active" filter when nothing
    // is active), and this file's own single fixture plugin can only ever
    // be in one state at a time -- showing both options at once needs a
    // second, differently-stated plugin, more fixture setup than this
    // file's own search-focused scope warrants. The switch/filter *label*
    // visibility rule itself has no jQuery behind it worth converting
    // (plain CSS classes), so the coverage gap here is small.
    $dir = pluginsInteractionFixtureCreate();
    $id = PLUGINS_INTERACTION_FIXTURE_ID;

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=plugins&tab=installed');
        pluginsInteractionWaitForRow($page, $id);

        $page->assertPresent('#' . $id);
        expect($page->script("getComputedStyle(document.getElementById('{$id}')).display"))
            ->not->toBe('none');

        // A search term matching neither its name nor description hides it.
        $page->fill('.pluginFilter input.search-input', 'zzz-does-not-match-anything');
        expect($page->script("getComputedStyle(document.getElementById('{$id}')).display"))
            ->toBe('none');
        expect($page->script("document.querySelector('.nbPluginsSearch').textContent"))
            ->not->toBe('');

        // A term matching its own name brings it back.
        $page->fill('.pluginFilter input.search-input', 'Plugins Interaction Fixture');
        expect($page->script("getComputedStyle(document.getElementById('{$id}')).display"))
            ->not->toBe('none');

        // Clearing the search brings back the plain unfiltered view too.
        $page->fill('.pluginFilter input.search-input', '');
        expect($page->script("getComputedStyle(document.getElementById('{$id}')).display"))
            ->not->toBe('none');

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'plugins_installated search filter');
    } finally {
        pluginsInteractionFixtureRemove($dir);
    }
});

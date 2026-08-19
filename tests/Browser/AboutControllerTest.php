<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\AboutController (about.php) -- a proof-of-concept
 * controller. Guest-accessible, no POST handling, no redirects: the only
 * real branches are the theme-specific "THEME_ABOUT" assignment (gated on
 * Lang::load() returning something other than false) and the credits body
 * itself (Lang::load('about.html', ...), which falls back to the real
 * en_UK/about.html file in this fixture since no_fallback isn't set and
 * en_US itself ships no about.html).
 */
it('renders the real About credits content for a guest visitor', function (): void {
    $page = H::gotoOk($this, '/about.php');

    // ABOUT_MESSAGE comes straight from language/en_UK/about.html (the
    // configured AppInfo::DEFAULT_LANGUAGE fallback) -- assert on its real,
    // specific text rather than just "the page rendered".
    $page->assertSee('This photo gallery is based on Piwigo.');
    $page->assertSeeLink('Visit Piwigo website');

    $page->assertNoJavaScriptErrors();
});

it('sets the About page title and body id', function (): void {
    $page = H::gotoOk($this, '/about.php');

    // Lang::t('About Piwigo') is passed straight into PageHeaderRenderer's
    // <title> (header.latte renders "{$PAGE_TITLE} | {$GALLERY_TITLE}" --
    // assert the page-specific part only, not the configurable gallery
    // title suffix); $this->pageState->setBodyId('theAboutPage') is
    // rendered onto <body id="...">.
    $page->assertTitleContains('About Piwigo');
    $page->assertPresent('body#theAboutPage');
});

/**
 * Piwigo\Menu\MenubarRenderer::render()'s own "external links" (mbLinks)
 * block -- rendered on every page via MenubarRenderer, exercised here
 * through about.php (the simplest real caller) since the block itself has
 * no dedicated test elsewhere. Covers the plain-string-label shape
 * (`is_array($url_data)` false -> rebuilt as `['label' => ...]`, which
 * also defaults `new_window` to true), the array shape with an explicit
 * `new_window => false`, and Menu\Event\CheckMenuLinkVisibility's own
 * both-directions gating (SEC-49: a real plugin handler setting
 * `visible = false`, and a `visibility_link_id` with no subscriber at
 * all defaulting to visible).
 */
/**
 * Closes the "THEME_ABOUT" assignment (~line 73): gated on
 * Lang::load('about.html', CurrentConfig::themesPath() . $user_theme .
 * '/', ...) returning something other than false -- no bundled theme
 * ships its own language/<lang>/about.html (same absence this file's own
 * top docblock already notes for the credits fallback), so this writes
 * one directly under the
 * live, Apache-shared themes/default/ root for the duration of this one
 * test (same throwaway-fixture-under-a-live-root technique
 * PluginsInstalledPageRendererTest.php's own docblock establishes for
 * plugins/, scoped here to a NEW file+directories under an EXISTING
 * theme rather than a whole new theme directory, so no other test's own
 * theme enumeration is affected). H::setGuestTheme() pins the guest
 * user's own theme to 'default' (deterministic regardless of test-order
 * pollution from other files that also call it), matching about.php's
 * own guest-accessible, no-login-required nature.
 */
it('assigns THEME_ABOUT when the active theme ships its own language-specific about.html', function (): void {
    H::setGuestTheme('default');

    $dir = dirname(__DIR__, 2) . '/themes/default/language/en_UK';
    $languageDirExisted = is_dir(dirname($dir));
    if (! is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
    $file = $dir . '/about.html';
    file_put_contents($file, '<p>CT theme-specific about content ' . uniqid() . '</p>');

    try {
        $page = H::gotoOk($this, '/about.php');

        $page->assertSee('CT theme-specific about content');
        // Still renders the credits body too -- THEME_ABOUT is additive,
        // not a replacement (about.latte's own `{if isset($THEME_ABOUT)}`
        // block sits below `{$ABOUT_MESSAGE}`).
        $page->assertSee('This photo gallery is based on Piwigo.');
        $page->assertNoJavaScriptErrors();
    } finally {
        @unlink($file);
        if (is_dir($dir)) {
            @rmdir($dir);
        }
        if (! $languageDirExisted && is_dir(dirname($dir))) {
            @rmdir(dirname($dir));
        }
        H::setGuestTheme('default');
    }
});

/**
 * SEC-49's own real fixture plugin: registers a
 * Menu\Event\CheckMenuLinkVisibility handler in a real, throwaway
 * plugin.json + src/Plugin.php pair under the live plugins/ root -- the
 * same technique PluginsInstalledPageRendererTest.php's own
 * pluginsInstalledWriteHookedFixturePlugin() already established (a
 * Browser test drives a real, separate Apache-served process, so a
 * bare EventDispatcherTestFactory::get()->addTypedHandler() in this
 * test process wouldn't reach it). Hides any link whose
 * $event->linkId matches the id below, leaving every other link alone
 * (the default $visible = true).
 */
function aboutControllerTestVisibilityLinkId(): string
{
    return 'ct-about-hidden-link';
}

function aboutControllerTestPluginsPath(): string
{
    return dirname(__DIR__, 2) . '/plugins/';
}

function aboutControllerTestWriteFixturePlugin(string $pluginId): void
{
    $dir = aboutControllerTestPluginsPath() . $pluginId;
    mkdir($dir . '/src', 0o777, true);

    $namespace = 'PiwigoTestFixture\\Ext' . bin2hex(random_bytes(6));
    $linkId = aboutControllerTestVisibilityLinkId();

    file_put_contents($dir . '/plugin.json', json_encode([
        'id' => $pluginId,
        'name' => $pluginId,
        'version' => '1.0.0',
        'description' => 'Test-only fixture plugin (tests/Browser/AboutControllerTest.php).',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        'main' => $namespace . '\\Plugin',
        'autoload' => [
            'psr-4' => [
                $namespace . '\\' => 'src/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($dir . '/src/Plugin.php', <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Piwigo\\Menu\\Event\\CheckMenuLinkVisibility;
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
                return [
                    CheckMenuLinkVisibility::class => \$this->onCheckVisibility(...),
                ];
            }

            private function onCheckVisibility(CheckMenuLinkVisibility \$event): CheckMenuLinkVisibility
            {
                if (\$event->linkId === '{$linkId}') {
                    \$event->visible = false;
                }

                return \$event;
            }
        }

        PHP);
}

function aboutControllerTestRemoveFixturePlugin(string $pluginId): void
{
    $dir = aboutControllerTestPluginsPath() . $pluginId;
    if (is_file($dir . '/plugin.json')) {
        unlink($dir . '/plugin.json');
    }
    if (is_file($dir . '/src/Plugin.php')) {
        unlink($dir . '/src/Plugin.php');
    }
    if (is_dir($dir . '/src')) {
        rmdir($dir . '/src');
    }
    if (is_dir($dir)) {
        rmdir($dir);
    }
}

it('renders the mbLinks menu block for configured links, honoring CheckMenuLinkVisibility and new_window', function (): void {
    $snapshot = H::snapshotConfig(['links']);
    $pluginId = 'ct-about-visibility-' . uniqid();
    aboutControllerTestWriteFixturePlugin($pluginId);
    $db = H::connect();
    H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", H::dbEscape($db, $pluginId)));

    try {
        $links = json_encode([
            'https://example.test/plain' => 'Plain String Link',
            'https://example.test/no-popup' => [
                'label' => 'No Popup Link',
                'new_window' => false,
            ],
            'https://example.test/hidden' => [
                'label' => 'Should Not Appear',
                'visibility_link_id' => aboutControllerTestVisibilityLinkId(),
            ],
        ]);
        if ($links === false) {
            throw new RuntimeException('json_encode failed for the links config value');
        }
        H::setConfigValue('links', $links);

        $page = H::gotoOk($this, '/about.php');

        $page->assertSeeLink('Plain String Link');
        $page->assertSeeLink('No Popup Link');
        $page->assertDontSee('Should Not Appear');
        $page->assertPresent('a.external[href="https://example.test/plain"][data-window-name]');
        $page->assertPresent('a.external[href="https://example.test/no-popup"]:not([data-window-name])');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
        H::dbQuery($db, sprintf("DELETE FROM plugins WHERE id = '%s'", H::dbEscape($db, $pluginId)));
        H::dbClose($db);
        aboutControllerTestRemoveFixturePlugin($pluginId);
    }
});

it('a link with a visibility_link_id but no subscriber stays visible by default', function (): void {
    $snapshot = H::snapshotConfig(['links']);

    try {
        $links = json_encode([
            'https://example.test/unsubscribed' => [
                'label' => 'No Subscriber Link',
                'visibility_link_id' => 'ct-about-no-subscriber-anywhere',
            ],
        ]);
        if ($links === false) {
            throw new RuntimeException('json_encode failed for the links config value');
        }
        H::setConfigValue('links', $links);

        $page = H::gotoOk($this, '/about.php');

        $page->assertSeeLink('No Subscriber Link');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});

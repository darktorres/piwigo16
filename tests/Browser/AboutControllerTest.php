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
    // <title> (header.tpl renders "{$PAGE_TITLE} | {$GALLERY_TITLE}" --
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
 * `new_window => false`, and eval_visible's true/false gating (the eval()
 * call is a real, preserved-as-is legacy plugin contract).
 */
/**
 * Closes the "THEME_ABOUT" assignment (~line 73): gated on
 * Lang::load('about.html', CurrentConfig::themesPath() . $user_theme .
 * '/', ...) returning something other than false -- no bundled theme
 * ships its own language/<lang>/about.html (confirmed via a real
 * filesystem search, same absence this file's own top docblock already
 * notes for the credits fallback), so this writes one directly under the
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
        // not a replacement (about.tpl's own `{if isset($THEME_ABOUT)}`
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

it('renders the mbLinks menu block for configured links, honoring eval_visible and new_window', function (): void {
    $snapshot = H::snapshotConfig(['links']);

    try {
        $links = json_encode([
            'https://example.test/plain' => 'Plain String Link',
            'https://example.test/no-popup' => [
                'label' => 'No Popup Link',
                'new_window' => false,
            ],
            'https://example.test/hidden' => [
                'label' => 'Should Not Appear',
                'eval_visible' => 'return false;',
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
        $page->assertPresent('a.external[href="https://example.test/plain"][onclick]');
        $page->assertPresent('a.external[href="https://example.test/no-popup"]:not([onclick])');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});

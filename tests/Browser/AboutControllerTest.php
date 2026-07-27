<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\AboutController (about.php) -- P22's own proof-of-concept
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
    // title suffix); PageState::current()->setBodyId('theAboutPage') is
    // rendered onto <body id="...">.
    $page->assertTitleContains('About Piwigo');
    $page->assertPresent('body#theAboutPage');
});

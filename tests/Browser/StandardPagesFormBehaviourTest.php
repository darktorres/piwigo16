<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

// standard_pages is not the guest theme by default -- these pages render
// under whatever theme the guest user has, and this file's JS only loads
// with standard_pages. GoldenHtmlSnapshotTest reaches its own
// standard-pages-* fixtures the same way, by pointing guest user 2 at the
// `golden_html_test` theme for the duration and putting it back afterwards.
//
// The theme lives in user_infos, so leaving it behind would render every
// later guest page under it: restored in afterEach so an assertion failure
// cannot strand it, and the shared session is marked dirty because it
// caches the resolved theme.
beforeEach(function (): void {
    // `allow_user_registration` is shared, global config across the whole
    // Browser suite: a concurrent file leaving it `false` makes
    // /register.php answer with the "User registration closed" page and
    // none of the form exists. Reset to the fixture's documented default
    // before *and* after, the pattern RegisterControllerTest and
    // CatOptionsPageRendererTest already use for contended shared state --
    // a snapshot/restore round trip would just preserve whatever another
    // process happened to leave behind.
    //
    // Missing this passed for as long as these tests were only ever run
    // filtered; the first full-suite run failed all five.
    H::setConfigValue('allow_user_registration', 'true');

    $this->previousGuestTheme = H::userTheme(2) ?? 'default';
    H::setUserTheme(2, 'golden_html_test');
    H::markSharedSessionDirty();
});

afterEach(function (): void {
    H::setConfigValue('allow_user_registration', 'true');
    H::setUserTheme(2, $this->previousGuestTheme);
    H::markSharedSessionDirty();
});

it('mirrors the password-match check before the form is submitted', function (): void {
    $page = H::gotoOk($this, '/register.php');

    // Nothing is wrong yet.
    $page->assertMissing('#password_conf ~ .error-message');

    $page->fill('#password', 'correct-horse');
    $page->fill('#password_conf', 'battery-staple');
    // The check is bound to blur *and* keyup, both in one registration.
    $page->script(
        "document.getElementById('password_conf').dispatchEvent(new Event('blur'))"
    );

    /** @var string $mismatch */
    $mismatch = $page->script(<<<'JS'
    (() => {
        const column = document.getElementById('password_conf').closest('.column-flex');
        const message = column.querySelector('.error-message');

        return getComputedStyle(message).display === 'none' ? '' : message.textContent.trim();
    })()
    JS);

    expect($mismatch)
        ->toContain('The passwords do not match');

    // Correcting it clears the message again.
    $page->fill('#password_conf', 'correct-horse');
    $page->script(
        "document.getElementById('password_conf').dispatchEvent(new Event('blur'))"
    );

    /** @var bool $cleared */
    $cleared = $page->script(<<<'JS'
    (() => {
        const column = document.getElementById('password_conf').closest('.column-flex');

        return getComputedStyle(column.querySelector('.error-message')).display === 'none';
    })()
    JS);

    expect($cleared)
        ->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('mirrors the email format check on blur', function (): void {
    $page = H::gotoOk($this, '/register.php');

    $page->fill('#mail_address', 'not-an-email');
    $page->script(
        "document.getElementById('mail_address').dispatchEvent(new Event('blur'))"
    );

    /** @var string $message */
    $message = $page->script(<<<'JS'
    (() => {
        const column = document.getElementById('mail_address').closest('.column-flex');
        const el = column.querySelector('.error-message');

        return getComputedStyle(el).display === 'none' ? '' : el.textContent.trim();
    })()
    JS);

    expect($message)
        ->toContain('xxx@yyy.eee');

    $page->fill('#mail_address', 'someone@example.com');
    $page->script(
        "document.getElementById('mail_address').dispatchEvent(new Event('blur'))"
    );

    /** @var bool $cleared */
    $cleared = $page->script(<<<'JS'
    (() => {
        const column = document.getElementById('mail_address').closest('.column-flex');

        return getComputedStyle(column.querySelector('.error-message')).display === 'none';
    })()
    JS);

    expect($cleared)
        ->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('cancels a submit that leaves a data-required field empty', function (): void {
    $page = H::gotoOk($this, '/register.php');

    // #login carries data-required="true". That attribute is the string
    // "true" in the DOM and the boolean `true` through jQuery's coercion,
    // and the check compares against the boolean -- reading it as a raw
    // string would make every field optional.
    /** @var array{shown: int, url: string} $before */
    $before = $page->script(<<<'JS'
    (() => ({
        shown: Array.from(document.querySelectorAll('.column-flex .error-message'))
            .filter(el => getComputedStyle(el).display !== 'none').length,
        url: location.href,
    }))()
    JS);

    expect($before['shown'])->toBe(0);

    $page->script("document.querySelector('form[name=register_form]').requestSubmit()");

    /** @var array{shown: int, url: string} $after */
    $after = $page->script(<<<'JS'
    (() => ({
        shown: Array.from(document.querySelectorAll('.column-flex .error-message'))
            .filter(el => getComputedStyle(el).display !== 'none').length,
        url: location.href,
    }))()
    JS);

    // The submit was cancelled, so the page never navigated, and every
    // required field that is empty is now flagged.
    expect($after['url'])->toBe($before['url']);
    expect($after['shown'])->toBeGreaterThan(0);

    $page->assertNoJavaScriptErrors();
});

it('reveals and re-hides a password field', function (): void {
    $page = H::gotoOk($this, '/register.php');

    /** @var string $initial */
    $initial = $page->script("document.getElementById('password').type");
    expect($initial)
        ->toBe('password');

    // `.siblings("input")` -- the toggle's own parent's other children.
    $page->click('#password ~ .togglePassword');

    /** @var array{type: string, colour: string} $revealed */
    $revealed = $page->script(<<<'JS'
    (() => {
        const toggle = document.querySelector('#password ~ .togglePassword');

        return { type: document.getElementById('password').type, colour: toggle.style.color };
    })()
    JS);

    expect($revealed['type'])->toBe('text');
    expect($revealed['colour'])->not->toBe('');

    $page->click('#password ~ .togglePassword');

    /** @var string $rehidden */
    $rehidden = $page->script("document.getElementById('password').type");
    expect($rehidden)
        ->toBe('password');

    // The confirmation field has its own toggle and must be untouched by
    // the first one -- siblings, not "the next input on the page".
    /** @var string $conf */
    $conf = $page->script("document.getElementById('password_conf').type");
    expect($conf)
        ->toBe('password');

    $page->assertNoJavaScriptErrors();
});

it('switches between light and dark mode and remembers the choice', function (): void {
    $page = H::gotoOk($this, '/register.php');

    /** @var array{classes: string, logo: string} $before */
    $before = $page->script(<<<'JS'
    (() => ({
        classes: document.getElementById('mode').className,
        logo: document.getElementById('piwigo-logo').getAttribute('src'),
    }))()
    JS);

    expect($before['classes'])->toContain('light');

    $page->click('#toggle_mode_light');

    /** @var array{classes: string, logo: string, cookie: string, lightShown: bool, darkShown: bool} $after */
    $after = $page->script(<<<'JS'
    (() => ({
        classes: document.getElementById('mode').className,
        logo: document.getElementById('piwigo-logo').getAttribute('src'),
        cookie: document.cookie,
        lightShown: getComputedStyle(document.getElementById('toggle_mode_light')).display !== 'none',
        darkShown: getComputedStyle(document.getElementById('toggle_mode_dark')).display !== 'none',
    }))()
    JS);

    // The class swaps rather than accumulating, the logo follows its own
    // data-logo-dark, and exactly one of the two toggles is on screen.
    expect($after['classes'])->toContain('dark');
    expect($after['classes'])->not->toContain('light');
    expect($after['logo'])->not->toBe($before['logo']);
    expect($after['lightShown'])->toBeFalse();
    expect($after['darkShown'])->toBeTrue();
    expect($after['cookie'])->toContain('mode=dark');

    $page->assertNoJavaScriptErrors();
});

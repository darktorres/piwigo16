<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

it('loads the gallery home page without errors', function (): void {
    $page = H::gotoOk($this, '/index.php');
    $page->assertNoJavaScriptErrors();
});

it('renders the identification (login) form', function (): void {
    $page = H::gotoOk($this, '/identification.php');
    $page->assertPresent('input.login[name="username"]');
    $page->assertPresent('form[name="login_form"] input[name="password"]');
    $page->assertPresent('form[name="login_form"] input[name="login"]');
    $page->assertNoJavaScriptErrors();
});

it('sizes the menubar tag cloud by how many photos each tag has', function (): void {
    // `.tagLevel1` through `.tagLevel5` are font sizes (90% to 150% in
    // theme.css) -- the whole point of a tag *cloud*. MenubarRenderer
    // never called TagService::addLevelToTags(), and getAvailableTags()
    // returns no `level`, so every tag rendered `class="tagLevel "` and
    // they were all the same size. A golden fixture pins whatever the
    // current output happens to be but cannot say a level should be
    // there at all, which is why this names it.
    //
    // The fixture's three tags are nature (3 photos), travel (1) and
    // family (1), so the set genuinely spans more than one level.
    $response = H::rawGet(H::gotoOk($this, '/index.php'), '/index.php');
    expect($response['status'])
        ->toBe(200);

    $start = strpos($response['body'], 'id="menuTagCloud"');
    expect($start)
        ->not->toBeFalse();
    $cloud = substr($response['body'], (int) $start, 900);

    preg_match_all('/class="tagLevel(\d*)"/', $cloud, $matches);
    expect($matches[1])
        ->not->toBeEmpty();
    // Every tag carries a real level...
    expect(array_filter($matches[1], static fn (string $level): bool => $level === ''))
        ->toBe([]);
    // ...and they are not all the same one.
    expect(count(array_unique($matches[1])))
        ->toBeGreaterThan(1);
});

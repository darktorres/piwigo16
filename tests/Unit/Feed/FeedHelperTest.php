<?php

declare(strict_types=1);

use Piwigo\Feed\FeedHelper;

test('datetimeToTs converts a datetime string to a Unix timestamp', function (): void {
    $helper = new FeedHelper();

    expect($helper->datetimeToTs('2005-07-14 23:01:37'))->toBe(strtotime('2005-07-14 23:01:37'));
});

test('datetimeToTs returns false for an unparseable string', function (): void {
    $helper = new FeedHelper();

    expect($helper->datetimeToTs('not a date'))->toBeFalse();
});

test('ts8601 matches PHP\'s own ISO 8601 date() format', function (): void {
    $helper = new FeedHelper();
    $ts = 1_100_000_000;

    expect($helper->ts8601($ts))->toBe(date('Y-m-d\\TH:i:sP', $ts));
});

test('generateRss2Feed escapes the channel title and link', function (): void {
    $helper = new FeedHelper();

    $feed = $helper->generateRss2Feed(
        ['title' => 'My <Gallery>', 'link' => 'https://example.test/?a=1&b=2', 'encoding' => 'utf-8'],
        [],
    );

    expect($feed)->toContain('<title>My &lt;Gallery&gt;</title>')
        ->and($feed)->toContain('<link>https://example.test/?a=1&amp;b=2</link>')
        ->and($feed)->toContain('encoding="utf-8"');
});

test('generateRss2Feed strips tags from the item title but escapes the description', function (): void {
    $helper = new FeedHelper();

    $feed = $helper->generateRss2Feed(
        ['title' => 't', 'link' => 'l', 'encoding' => 'utf-8'],
        [[
            'title' => '<b>Bold</b> title',
            'link' => 'https://example.test/item',
            'description' => '<p>hello</p>',
            'html' => false,
            'author' => '',
            'guid' => '',
        ]],
    );

    expect($feed)->toContain('<title>Bold title</title>')
        ->and($feed)->toContain('<description>&lt;p&gt;hello&lt;/p&gt;</description>');
});

test('generateRss2Feed wraps the description in CDATA when html is true', function (): void {
    $helper = new FeedHelper();

    $feed = $helper->generateRss2Feed(
        ['title' => 't', 'link' => 'l', 'encoding' => 'utf-8'],
        [[
            'title' => 'title',
            'link' => 'https://example.test/item',
            'description' => '<p>hello</p>',
            'html' => true,
            'author' => '',
            'guid' => '',
        ]],
    );

    expect($feed)->toContain('<description><![CDATA[<p>hello</p>]]></description>');
});

test('generateRss2Feed falls back to the link for guid when guid is empty', function (): void {
    $helper = new FeedHelper();

    $feed = $helper->generateRss2Feed(
        ['title' => 't', 'link' => 'l', 'encoding' => 'utf-8'],
        [[
            'title' => 'title',
            'link' => 'https://example.test/item',
            'description' => 'd',
            'html' => false,
            'author' => '',
            'guid' => '',
        ]],
    );

    expect($feed)->toContain('<guid isPermaLink="false">https://example.test/item</guid>');
});

test('generateRss2Feed omits author and pubDate when empty', function (): void {
    $helper = new FeedHelper();

    $feed = $helper->generateRss2Feed(
        ['title' => 't', 'link' => 'l', 'encoding' => 'utf-8'],
        [[
            'title' => 'title',
            'link' => 'https://example.test/item',
            'description' => 'd',
            'html' => false,
            'author' => '',
            'date' => '',
            'guid' => 'g',
        ]],
    );

    expect($feed)->not->toContain('<author>')
        ->and($feed)->not->toContain('<pubDate>');
});

test('generateRss2Feed includes author and pubDate when set', function (): void {
    $helper = new FeedHelper();

    $feed = $helper->generateRss2Feed(
        ['title' => 't', 'link' => 'l', 'encoding' => 'utf-8'],
        [[
            'title' => 'title',
            'link' => 'https://example.test/item',
            'description' => 'd',
            'html' => false,
            'author' => 'Jane',
            'date' => '2020-01-01T00:00:00+00:00',
            'guid' => 'g',
        ]],
    );

    expect($feed)->toContain('<author>Jane</author>')
        ->and($feed)->toContain('<pubDate>');
});

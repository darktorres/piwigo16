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

test('generateRss2Feed produces the exact well-formed RSS document, byte for byte', function (): void {
    // Kills nearly every Concat*/ConcatEqualToEqual/UnwrapHtmlspecialchars
    // mutation on lines 55/72/73/74/77/80/82: the existing toContain()
    // tests only check for SUBSTRING presence, which many structural
    // corruptions (a `.=` reset to `=` dropping everything built so
    // far, a swapped concat order, a dropped htmlspecialchars() call)
    // don't actually break -- an exact full-string comparison does.
    // Every escaped field below carries a literal '&' so an unwrapped
    // htmlspecialchars() call is visible in the output. lastBuildDate is
    // real wall-clock time, verified separately below, then normalized
    // out before the exact comparison (also kills line 61's 6 Concat*
    // mutations: the regex below only matches the real, well-formed
    // `<lastBuildDate>DATE</lastBuildDate>` shape).
    $helper = new FeedHelper();

    $feed = $helper->generateRss2Feed(
        ['title' => 'My Gallery & Co', 'link' => 'https://example.test/?a=1&b=2', 'encoding' => 'utf-8'],
        [[
            'title' => '<b>Bold</b> & Title',
            'link' => 'https://example.test/item?x=1&y=2',
            'description' => 'plain & text',
            'html' => false,
            'author' => 'Jane & Bob',
            'date' => '2020-06-15T10:30:00+00:00',
            'guid' => 'guid-value & special',
        ]],
    );

    $matched = preg_match('/<lastBuildDate>([^<]+)<\/lastBuildDate>/', $feed, $matches);
    expect($matched)->toBe(1);
    assert(isset($matches[1]));

    $lastBuildDate = \DateTimeImmutable::createFromFormat(\DATE_RFC2822, $matches[1]);
    expect($lastBuildDate)->not->toBeFalse();
    assert($lastBuildDate instanceof \DateTimeImmutable);
    expect(abs($lastBuildDate->getTimestamp() - time()))->toBeLessThan(5);

    $normalized = preg_replace('/<lastBuildDate>[^<]*<\/lastBuildDate>/', '<lastBuildDate>DATE</lastBuildDate>', $feed);

    $pubDate = new \DateTimeImmutable('2020-06-15T10:30:00+00:00')->format(\DATE_RFC2822);
    $expected = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
        . "<rss version=\"2.0\">\n"
        . "  <channel>\n"
        . "    <title>My Gallery &amp; Co</title>\n"
        . "    <link>https://example.test/?a=1&amp;b=2</link>\n"
        . "    <description></description>\n"
        . "    <lastBuildDate>DATE</lastBuildDate>\n"
        . "    <item>\n"
        . "      <title>Bold &amp; Title</title>\n"
        . "      <link>https://example.test/item?x=1&amp;y=2</link>\n"
        . "      <description>plain &amp; text</description>\n"
        . "      <author>Jane &amp; Bob</author>\n"
        . '      <pubDate>' . $pubDate . "</pubDate>\n"
        . "      <guid isPermaLink=\"false\">guid-value &amp; special</guid>\n"
        . "    </item>\n"
        . "  </channel>\n"
        . "</rss>\n";

    expect($normalized)->toBe($expected);
});

test('generateRss2Feed treats missing channel keys the same as empty strings', function (): void {
    // Kills lines 51/52/53's EmptyStringToNotEmpty on the channel
    // encoding/title/link `?? ''` fallbacks. The existing tests always
    // pass these keys explicitly, so the coalesce fallback itself (which
    // only engages for a genuinely MISSING key, not a present-but-empty
    // one) was never exercised.
    $helper = new FeedHelper();

    $feed = $helper->generateRss2Feed([], []);

    expect($feed)->toContain('encoding=""?>')
        ->and($feed)->toContain("    <title></title>\n")
        ->and($feed)->toContain("    <link></link>\n");
});

test('generateRss2Feed treats missing item keys the same as their defaults', function (): void {
    // Kills lines 64/65/66/67's EmptyStringToNotEmpty and line 70's
    // FalseToTrue (item html default) -- same "coalesce fallback never
    // exercised by an explicitly-empty-but-present key" gap as the
    // channel-level test above, for every per-item field default.
    $helper = new FeedHelper();

    $feed = $helper->generateRss2Feed(
        ['title' => 't', 'link' => 'l', 'encoding' => 'utf-8'],
        [[]],
    );

    expect($feed)->toContain("      <title></title>\n")
        ->and($feed)->toContain("      <link></link>\n")
        ->and($feed)->toContain("      <description></description>\n")
        ->and($feed)->not->toContain('<author>')
        ->and($feed)->not->toContain('<pubDate>')
        ->and($feed)->not->toContain('CDATA')
        ->and($feed)->toContain('<guid isPermaLink="false"></guid>');
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

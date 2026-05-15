<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Feed;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Piwigo\Feed\FeedItem;
use Piwigo\Feed\PiwigoFeedCreator;

final class PiwigoFeedCreatorTest extends TestCase
{
    public function test_emits_valid_rss20_root(): void
    {
        $rss = new PiwigoFeedCreator();
        $rss->title = 'Gallery';
        $rss->link  = 'https://example.test/';
        $xml        = $rss->toRss20Xml();

        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        self::assertStringContainsString('<rss version="2.0">', $xml);
        self::assertStringContainsString('<channel>', $xml);
        self::assertStringContainsString('<title>Gallery</title>', $xml);
        self::assertStringContainsString('<link>https://example.test/</link>', $xml);
        // RSS 2.0 requires <description>; falls back to title.
        self::assertStringContainsString('<description>Gallery</description>', $xml);
    }

    public function test_emits_each_item_with_cdata_when_html_syndicated(): void
    {
        $rss = new PiwigoFeedCreator();
        $rss->title = 'g';
        $rss->link = 'https://example.test/';

        $item = new FeedItem();
        $item->title = 'photo';
        $item->link  = 'https://example.test/p/1';
        $item->description = '<p>contains <a href="x">html</a></p>';
        $item->descriptionHtmlSyndicated = true;
        $item->date = new DateTimeImmutable('2026-05-15 12:00:00+00:00');
        $item->author = 'admin';
        $item->guid = 'photo-1';
        $rss->addItem($item);

        $xml = $rss->toRss20Xml();

        self::assertStringContainsString('<![CDATA[<p>contains <a href="x">html</a></p>]]>', $xml);
        self::assertStringContainsString('<title>photo</title>', $xml);
        self::assertStringContainsString('<link>https://example.test/p/1</link>', $xml);
        self::assertStringContainsString('<author>admin</author>', $xml);
        self::assertStringContainsString('<guid>photo-1</guid>', $xml);
    }

    public function test_item_description_is_xml_escaped_when_not_html_syndicated(): void
    {
        $rss = new PiwigoFeedCreator();
        $rss->title = 'g';
        $rss->link = 'https://example.test/';

        $item = new FeedItem();
        $item->title = 't';
        $item->description = 'plain text & ampersand';
        $item->descriptionHtmlSyndicated = false;
        $rss->addItem($item);

        $xml = $rss->toRss20Xml();

        self::assertStringContainsString('<description>plain text &amp; ampersand</description>', $xml);
        self::assertStringNotContainsString('CDATA', $xml);
    }

    public function test_item_date_emitted_as_rfc822(): void
    {
        $rss = new PiwigoFeedCreator();
        $rss->title = 'g';
        $rss->link  = 'https://example.test/';

        $item = new FeedItem();
        $item->title = 't';
        $item->description = 'd';
        $item->date = new DateTimeImmutable('2026-05-15 12:00:00+00:00');
        $rss->addItem($item);

        $xml = $rss->toRss20Xml();
        self::assertStringContainsString('<pubDate>Fri, 15 May 2026 12:00:00 +0000</pubDate>', $xml);
    }

    public function test_item_title_strips_html_and_truncates_at_100_chars(): void
    {
        $rss = new PiwigoFeedCreator();
        $rss->title = 'g';
        $rss->link  = 'https://example.test/';

        $item = new FeedItem();
        $item->title = '<b>' . str_repeat('A', 150) . '</b>';
        $rss->addItem($item);

        $xml = $rss->toRss20Xml();
        // strip_tags removes <b>, then truncate at 100 (ends with ...)
        self::assertStringContainsString('<title>' . str_repeat('A', 97) . '...</title>', $xml);
    }

    public function test_item_with_no_date_omits_pubdate_element(): void
    {
        $rss = new PiwigoFeedCreator();
        $rss->title = 'g';
        $rss->link  = 'https://example.test/';

        $item = new FeedItem();
        $item->title = 't';
        $item->description = 'd';
        // date stays null
        $rss->addItem($item);

        $xml = $rss->toRss20Xml();
        self::assertStringNotContainsString('<pubDate>', $xml);
    }

    public function test_multiple_items_emitted_in_insertion_order(): void
    {
        $rss = new PiwigoFeedCreator();
        $rss->title = 'g';
        $rss->link  = 'https://example.test/';

        foreach (['first', 'second', 'third'] as $title) {
            $item = new FeedItem();
            $item->title = $title;
            $item->description = '';
            $rss->addItem($item);
        }

        $xml = $rss->toRss20Xml();
        $posFirst  = strpos($xml, '<title>first</title>');
        $posSecond = strpos($xml, '<title>second</title>');
        $posThird  = strpos($xml, '<title>third</title>');
        self::assertNotFalse($posFirst);
        self::assertNotFalse($posSecond);
        self::assertNotFalse($posThird);
        self::assertLessThan($posSecond, $posFirst);
        self::assertLessThan($posThird, $posSecond);
    }

    public function test_xml_is_well_formed(): void
    {
        $rss = new PiwigoFeedCreator();
        $rss->title = 'Gallery & friends';
        $rss->link  = 'https://example.test/?q=1&r=2';

        $item = new FeedItem();
        $item->title = 'Some <unsafe> title';
        $item->description = 'desc with <em>html</em>';
        $item->descriptionHtmlSyndicated = true;
        $rss->addItem($item);

        $xml = $rss->toRss20Xml();

        // Parse via SimpleXML — throws on malformed XML.
        $parsed = simplexml_load_string($xml);
        self::assertNotFalse($parsed);
        self::assertSame('Gallery & friends', (string) $parsed->channel->title);
        self::assertSame('https://example.test/?q=1&r=2', (string) $parsed->channel->link);
    }
}

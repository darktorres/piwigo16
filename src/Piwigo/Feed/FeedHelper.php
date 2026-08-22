<?php

declare(strict_types=1);

namespace Piwigo\Feed;

use DateTimeImmutable;
use Piwigo\Feed\Projection\FeedChannel;
use Piwigo\Feed\Projection\FeedItem;

/**
 * Pure date-conversion and RSS 2.0 rendering helpers for feed.php. Injects
 * nothing -- no cross-domain calls at all, matching Url\UrlService/
 * Html\HtmlService's "injects nothing" shape for services with no service
 * dependency of their own.
 */
final class FeedHelper
{
    /**
     * Creates a Unix timestamp (number of seconds since 1970-01-01 00:00:00
     * GMT) from a MySQL/PostgreSQL datetime format (2005-07-14 23:01:37).
     */
    public function datetimeToTs(string $datetime): int|false
    {
        return strtotime($datetime);
    }

    /**
     * Creates an ISO 8601 format date (2003-01-20T18:05:41+04:00) from a
     * Unix timestamp (number of seconds since 1970-01-01 00:00:00 GMT).
     *
     * Function copied from the Dotclear project http://dotclear.net
     */
    public function ts8601(int $ts): string
    {
        $tz = date('O', $ts);
        $tz = substr($tz, 0, -2) . ':' . substr($tz, -2);

        return date('Y-m-d\\TH:i:s', $ts) . $tz;
    }

    /**
     * Builds a well-formed RSS 2.0 feed from channel metadata and items.
     * Covers exactly feed.php's own usage (no image/language/copyright/
     * enclosure/etc. -- feed.php never sets any of those), not a
     * general-purpose feed library.
     *
     * @param list<FeedItem> $items
     */
    public function generateRss2Feed(FeedChannel $channel, array $items): string
    {
        $channel_encoding = $channel->encoding;
        $channel_title = htmlspecialchars($channel->title);
        $channel_link = htmlspecialchars($channel->link);
        $last_build_date = new DateTimeImmutable()
            ->format(\DATE_RFC2822);

        $feed = <<<XML
        <?xml version="1.0" encoding="{$channel_encoding}"?>
        <rss version="2.0">
          <channel>
            <title>{$channel_title}</title>
            <link>{$channel_link}</link>
            <description></description>
            <lastBuildDate>{$last_build_date}</lastBuildDate>
        XML . "\n";

        foreach ($items as $item) {
            $item_title = htmlspecialchars(strip_tags($item->title));
            $item_link_raw = $item->link;
            $item_link = htmlspecialchars($item_link_raw);
            $item_description_raw = $item->description;
            $item_author = $item->author;
            $item_date = $item->date;
            $item_guid_raw = $item->guid;
            $item_html = $item->html;
            $item_description = $item_html
                ? '<![CDATA[' . $item_description_raw . ']]>'
                : htmlspecialchars($item_description_raw);
            $item_guid = htmlspecialchars($item_guid_raw !== '' ? $item_guid_raw : $item_link_raw);

            $feed .= <<<XML
                <item>
                  <title>{$item_title}</title>
                  <link>{$item_link}</link>
                  <description>{$item_description}</description>
            XML . "\n";
            if ($item_author !== '') {
                $feed .= '      <author>' . htmlspecialchars($item_author) . "</author>\n";
            }
            if ($item_date !== '') {
                $feed .= '      <pubDate>' . new DateTimeImmutable($item_date)->format(\DATE_RFC2822) . "</pubDate>\n";
            }
            $feed .= <<<XML
                  <guid isPermaLink="false">{$item_guid}</guid>
                </item>
            XML . "\n";
        }

        $feed .= <<<'XML'
          </channel>
        </rss>
        XML . "\n";

        return $feed;
    }
}

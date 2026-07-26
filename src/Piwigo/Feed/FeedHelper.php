<?php

declare(strict_types=1);

namespace Piwigo\Feed;

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
     * @param array{title?: string, link?: string, encoding?: string} $channel
     * @param list<array{title?: string, link?: string, description?: string, html?: bool, date?: string, author?: string, guid?: string}> $items
     *        html wraps description in CDATA instead of escaping it; date is
     *        an ISO 8601 string
     */
    public function generateRss2Feed(array $channel, array $items): string
    {
        $channel_encoding = $channel['encoding'] ?? '';
        $channel_title = $channel['title'] ?? '';
        $channel_link = $channel['link'] ?? '';

        $feed = '<?xml version="1.0" encoding="' . $channel_encoding . '"?>' . "\n";
        $feed .= "<rss version=\"2.0\">\n";
        $feed .= "  <channel>\n";
        $feed .= '    <title>' . htmlspecialchars($channel_title) . "</title>\n";
        $feed .= '    <link>' . htmlspecialchars($channel_link) . "</link>\n";
        $feed .= "    <description></description>\n";
        $feed .= '    <lastBuildDate>' . new \DateTimeImmutable()->format(\DATE_RFC2822) . "</lastBuildDate>\n";

        foreach ($items as $item) {
            $item_title = $item['title'] ?? '';
            $item_link = $item['link'] ?? '';
            $item_description = $item['description'] ?? '';
            $item_author = $item['author'] ?? '';
            $item_date = $item['date'] ?? '';
            $item_guid = $item['guid'] ?? '';
            $item_html = $item['html'] ?? false;

            $feed .= "    <item>\n";
            $feed .= '      <title>' . htmlspecialchars(strip_tags($item_title)) . "</title>\n";
            $feed .= '      <link>' . htmlspecialchars($item_link) . "</link>\n";
            $feed .= '      <description>' . ($item_html ? '<![CDATA[' . $item_description . ']]>' : htmlspecialchars($item_description)) . "</description>\n";
            if ($item_author !== '') {
                $feed .= '      <author>' . htmlspecialchars($item_author) . "</author>\n";
            }
            if ($item_date !== '') {
                $feed .= '      <pubDate>' . new \DateTimeImmutable($item_date)->format(\DATE_RFC2822) . "</pubDate>\n";
            }
            $feed .= '      <guid isPermaLink="false">' . htmlspecialchars($item_guid !== '' ? $item_guid : $item_link) . "</guid>\n";
            $feed .= "    </item>\n";
        }

        $feed .= "  </channel>\n";
        $feed .= "</rss>\n";

        return $feed;
    }
}

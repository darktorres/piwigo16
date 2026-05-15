<?php

declare(strict_types=1);

namespace Piwigo\Feed;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;

/**
 * RSS 2.0 feed generator. Replaces the openpsa/universalfeedcreator vendor
 * lib retired in Phase 2g. Property-bag API matches the call-site shape used
 * by `FeedController` (set channel fields, append `FeedItem`s, render). XML
 * is built via `DOMDocument` so item descriptions land inside proper CDATA
 * sections and channel/item fields get the standard XML-escape treatment.
 */
final class PiwigoFeedCreator
{
    public string $encoding = 'UTF-8';
    public string $title = '';
    public string $link = '';

    /** @var list<FeedItem> */
    private array $items = [];

    public function addItem(FeedItem $item): void
    {
        $this->items[] = $item;
    }

    public function toRss20Xml(): string
    {
        $doc                     = new DOMDocument('1.0', $this->encoding);
        $doc->formatOutput       = true;
        $doc->preserveWhiteSpace = false;

        $rss = $doc->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $doc->appendChild($rss);

        $channel = $doc->createElement('channel');
        $rss->appendChild($channel);

        // RSS 2.0 requires <title>, <link>, <description>. Channel description
        // wasn't set by the legacy caller; fall back to title to stay valid.
        self::appendText($doc, $channel, 'title', self::truncate($this->title, 100));
        self::appendText($doc, $channel, 'link', $this->link);
        self::appendText($doc, $channel, 'description', self::truncate($this->title, 500));
        self::appendText($doc, $channel, 'lastBuildDate', new DateTimeImmutable()->format(\DATE_RSS));
        self::appendText($doc, $channel, 'generator', 'Piwigo');

        foreach ($this->items as $item) {
            $channel->appendChild(self::buildItemElement($doc, $item));
        }

        return $doc->saveXML() ?: '';
    }

    private static function buildItemElement(DOMDocument $doc, FeedItem $item): DOMElement
    {
        $element = $doc->createElement('item');

        // Title gets HTML stripped + truncated to 100 chars — same shape RSS
        // 2.0 readers expect, and what the legacy lib did.
        self::appendText($doc, $element, 'title', self::truncate(strip_tags($item->title), 100));
        self::appendText($doc, $element, 'link', $item->link);

        $description = $doc->createElement('description');
        if ($item->descriptionHtmlSyndicated) {
            $description->appendChild($doc->createCDATASection($item->description));
        } else {
            $description->appendChild($doc->createTextNode($item->description));
        }
        $element->appendChild($description);

        if ($item->author !== '') {
            self::appendText($doc, $element, 'author', $item->author);
        }
        if ($item->date instanceof DateTimeImmutable) {
            self::appendText($doc, $element, 'pubDate', $item->date->format(\DATE_RSS));
        }
        if ($item->guid !== '') {
            self::appendText($doc, $element, 'guid', $item->guid);
        }

        return $element;
    }

    private static function appendText(DOMDocument $doc, DOMElement $parent, string $name, string $value): void
    {
        $parent->appendChild($doc->createElement($name))->appendChild($doc->createTextNode($value));
    }

    private static function truncate(string $value, int $limit): string
    {
        return strlen($value) > $limit ? substr($value, 0, $limit - 3) . '...' : $value;
    }
}

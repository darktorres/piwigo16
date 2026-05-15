<?php

declare(strict_types=1);

namespace Piwigo\Feed;

use DateTimeImmutable;

/**
 * One entry in an RSS 2.0 feed. Property-bag matching the call-site shape in
 * `FeedController`. Replaces the global `\FeedItem` class from the retired
 * `openpsa/universalfeedcreator` vendor lib.
 */
final class FeedItem
{
    public string $title = '';
    public string $link = '';
    public string $description = '';

    /**
     * When true, `$description` is treated as raw HTML and wrapped in a
     * CDATA section instead of XML-escaped. Mirrors the legacy lib's
     * `descriptionHtmlSyndicated` flag.
     */
    public bool $descriptionHtmlSyndicated = false;

    public ?DateTimeImmutable $date = null;
    public string $author = '';
    public string $guid = '';
}

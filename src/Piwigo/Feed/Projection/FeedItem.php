<?php

declare(strict_types=1);

namespace Piwigo\Feed\Projection;

/**
 * {@see \Piwigo\Feed\FeedHelper::generateRss2Feed()}'s own per-item
 * shape. `$html` wraps `$description` in CDATA instead of escaping it;
 * `$date` is an ISO 8601 string; `$author`/`$date`/`$guid` left empty
 * (the default) omit their respective `<author>`/`<pubDate>` elements
 * or fall back to `$link` for `<guid>`.
 */
final readonly class FeedItem
{
    public function __construct(
        public string $title = '',
        public string $link = '',
        public string $description = '',
        public bool $html = false,
        public string $date = '',
        public string $author = '',
        public string $guid = '',
    ) {}
}

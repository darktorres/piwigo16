<?php

declare(strict_types=1);

namespace Piwigo\Feed\Projection;

/**
 * {@see \Piwigo\Feed\FeedHelper::generateRss2Feed()}'s own channel
 * metadata -- covers exactly `Controller\FeedController`'s usage (no
 * image/language/copyright/etc.), not a general-purpose feed shape.
 */
final readonly class FeedChannel
{
    public function __construct(
        public string $title = '',
        public string $link = '',
        public string $encoding = '',
    ) {}
}

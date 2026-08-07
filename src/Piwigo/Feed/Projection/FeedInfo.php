<?php

declare(strict_types=1);

namespace Piwigo\Feed\Projection;

use DateTimeImmutable;

/**
 * {@see \Piwigo\Feed\FeedRepository::findById()}'s own row shape --
 * {@see \Piwigo\Controller\FeedController}'s real (and only) consumer.
 */
final readonly class FeedInfo
{
    public function __construct(
        public int $userId,
        public ?DateTimeImmutable $lastCheck,
    ) {}
}

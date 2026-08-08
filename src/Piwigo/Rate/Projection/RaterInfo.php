<?php

declare(strict_types=1);

namespace Piwigo\Rate\Projection;

/**
 * {@see \Piwigo\Rate\RateRepository::findUsersWithStatusByIdUsername()}'s
 * own row shape -- admin "Rating by user" report,
 * {@see \Piwigo\Admin\RatingUserPageRenderer}'s real (and only) consumer.
 */
final readonly class RaterInfo
{
    public function __construct(
        public int $id,
        public string $name,
        public string $status,
    ) {}
}

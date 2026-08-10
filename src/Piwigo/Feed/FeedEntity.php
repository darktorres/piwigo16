<?php

declare(strict_types=1);

namespace Piwigo\Feed;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `user_feed` table. `id` is the feed identifier string
 * (application-assigned, not auto-generated).
 */
#[ORM\Entity(repositoryClass: FeedRepository::class)]
#[ORM\Table(name: 'user_feed')]
final class FeedEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 50)]
        public string $id,
        #[ORM\Column(name: 'user_id', type: 'user_id')]
        public UserId $userId,
        #[ORM\Column(name: 'last_check', type: 'datetime_immutable', nullable: true)]
        public ?DateTimeImmutable $lastCheck = null,
    ) {}
}

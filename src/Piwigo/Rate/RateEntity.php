<?php

declare(strict_types=1);

namespace Piwigo\Rate;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `rate` table (`piwigo_rate` once Piwigo\Db\TablePrefixListener
 * applies db_prefix at metadata-load time) -- composite PK (element_id,
 * user_id, anonymous_id). `date` stays plain ?string, not
 * \DateTimeImmutable, matching Rate\Projection\Rate's own decision.
 */
#[ORM\Entity(repositoryClass: RateRepository::class)]
#[ORM\Table(name: 'rate')]
final class RateEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'element_id', type: 'image_id')]
        public ImageId $elementId,
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: 'user_id')]
        public UserId $userId,
        #[ORM\Id]
        #[ORM\Column(name: 'anonymous_id', type: 'string', length: 45)]
        public string $anonymousId,
        #[ORM\Column(type: 'integer')]
        public int $rate,
        #[ORM\Column(type: 'string', length: 10, nullable: true)]
        public ?string $date,
    ) {}
}

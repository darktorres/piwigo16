<?php

declare(strict_types=1);

namespace Piwigo\Site;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `sites` table.
 */
#[ORM\Entity(repositoryClass: SiteRepository::class)]
#[ORM\Table(name: 'sites')]
final class SiteEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(name: 'galleries_url', type: 'string', length: 255)]
        public string $galleriesUrl,
    ) {}
}

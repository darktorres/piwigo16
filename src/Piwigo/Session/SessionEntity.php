<?php

declare(strict_types=1);

namespace Piwigo\Session;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `sessions` table (`piwigo_sessions` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time).
 * Real shape: id varchar(50) PK (the composite session id, already
 * IP-hash-prefixed by SessionService before it ever reaches this layer),
 * data mediumtext NOT NULL, expiration datetime nullable. `expiration`
 * maps as a real `datetime_immutable` -- the pre-ORM SessionRepository
 * already bound it as `Types::DATETIME_IMMUTABLE`, not a raw string, so
 * this preserves existing behavior rather than widening or narrowing it.
 */
#[ORM\Entity(repositoryClass: SessionRepository::class)]
#[ORM\Table(name: 'sessions')]
final class SessionEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 50)]
        public string $id,
        #[ORM\Column(type: 'text')]
        public string $data,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $expiration = null,
    ) {}
}

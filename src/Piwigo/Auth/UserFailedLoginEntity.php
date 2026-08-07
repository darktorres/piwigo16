<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `user_failed_logins` table (`piwigo_user_failed_logins` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time).
 * Genuinely append-only (surrogate auto-increment PK, unlike every other
 * table this migration touches) -- one row per failed login attempt
 * through AuthService::pwgLogin(), the single real choke point for both
 * the HTML login form and the WS pwg.session.login method. Backs a
 * dual-scope (per-user AND per-IP) lockout -- see the table's own two
 * indexes (idx_user_failed_logins_user_time, idx_user_failed_logins_ip_time).
 */
#[ORM\Entity(repositoryClass: UserFailedLoginRepository::class)]
#[ORM\Table(name: 'user_failed_logins')]
final class UserFailedLoginEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(name: 'user_id', type: 'user_id', nullable: true)]
        public ?UserId $userId,
        #[ORM\Column(type: 'string', length: 45)]
        public string $ip,
        #[ORM\Column(name: 'attempted_at', type: 'string', length: 19)]
        public string $attemptedAt,
    ) {}
}

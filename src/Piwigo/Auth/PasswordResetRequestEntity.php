<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `password_reset_requests` table. [P44-L] Same shape as
 * {@see UserFailedLoginEntity} (see its own docblock for the append-only/
 * dual-index rationale, shared verbatim here) -- one row per
 * `Controller\PasswordController::processVerificationCode()` call that
 * actually issues a fresh code, backing a dual-scope (per-account AND
 * per-IP) rate limit on how often a reset code can be requested.
 *
 * `ip` is `NOT NULL` with no default, not nullable -- same
 * `ip_address_graceful` reasoning as `UserFailedLoginEntity::$ip`'s own
 * docblock: `PasswordController` can genuinely reach `recordRequest()`
 * with an empty-string IP when `REMOTE_ADDR` is unavailable.
 */
#[ORM\Entity(repositoryClass: PasswordResetRequestRepository::class)]
#[ORM\Table(name: 'password_reset_requests')]
final class PasswordResetRequestEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(name: 'user_id', type: 'user_id', nullable: true)]
        public ?UserId $userId,
        #[ORM\Column(type: 'ip_address_graceful', length: 45)]
        public ?IpAddress $ip,
        #[ORM\Column(name: 'requested_at', type: 'string', length: 19)]
        public string $requestedAt,
    ) {}
}

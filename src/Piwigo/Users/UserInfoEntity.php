<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `user_infos` table (`piwigo_user_infos` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time).
 * `user_id` is the PK, application-assigned (the `users` row's own id --
 * never auto-generated here). `status` stays a plain string, not
 * \Piwigo\Users\UserStatus -- matches {@see \Piwigo\Users\Projection\UserInfo}'s
 * own already-documented layering (the typed enum wraps this at the
 * \Piwigo\Users\User level, not at the persistence row itself).
 * `registration_date`/`activation_key_expire`/`last_visit` stay plain
 * ?string, not \DateTimeImmutable -- every real consumer today expects the
 * raw DB DATETIME string form, same reasoning as UserInfo::fromRow()'s own
 * decision. `preferences` maps as native Doctrine `json` (the column
 * really is JSON, unlike Config\ConfigEntry's still-text `value` column) --
 * no round-trip-fidelity requirement forces a raw-string exception here,
 * unlike Audit\AuditLogEntity's hash-chain columns.
 *
 * The `users` table itself (login/password/email) was previously
 * deliberately left unmapped, reasoning that \Piwigo\Config\
 * CurrentConfig::userFields() gave real multi-auth integrations a way to
 * remap its column names at runtime, which Doctrine's compile-time column
 * attributes can't represent. Re-audited (Item 14 Sub-phase C4):
 * CurrentConfig::setUserFields() has zero real callers anywhere in
 * `src/`, so that remapping was never actually exercised in this
 * rewrite -- reversed, now mapped as {@see UserEntity}.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'user_infos')]
final class UserInfoEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: 'user_id')]
        public UserId $userId,
        #[ORM\Column(name: 'nb_image_page', type: 'smallint')]
        public int $nbImagePage,
        #[ORM\Column(type: 'string', length: 20)]
        public string $status,
        #[ORM\Column(type: 'string', length: 50)]
        public string $language,
        #[ORM\Column(type: 'boolean')]
        public bool $expand,
        #[ORM\Column(name: 'show_nb_comments', type: 'boolean')]
        public bool $showNbComments,
        #[ORM\Column(name: 'show_nb_hits', type: 'boolean')]
        public bool $showNbHits,
        #[ORM\Column(name: 'recent_period', type: 'smallint')]
        public int $recentPeriod,
        #[ORM\Column(type: 'string', length: 255)]
        public string $theme,
        #[ORM\Column(name: 'registration_date', type: 'string', length: 19, nullable: true)]
        public ?string $registrationDate,
        #[ORM\Column(name: 'enabled_high', type: 'boolean')]
        public bool $enabledHigh,
        #[ORM\Column(type: 'smallint')]
        public int $level,
        #[ORM\Column(name: 'activation_key', type: 'string', length: 255, nullable: true)]
        public ?string $activationKey,
        #[ORM\Column(name: 'activation_key_expire', type: 'string', length: 19, nullable: true)]
        public ?string $activationKeyExpire,
        #[ORM\Column(name: 'last_visit', type: 'string', length: 19, nullable: true)]
        public ?string $lastVisit,
        #[ORM\Column(name: 'last_visit_from_history', type: 'boolean')]
        public bool $lastVisitFromHistory,
        #[ORM\Column(type: 'string', length: 19)]
        public string $lastmodified,
        /**
         * @var array<string, mixed>|null
         */
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $preferences,
    ) {}
}

<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `user_infos` table (`piwigo_user_infos` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time).
 * `user_id` is the PK, application-assigned (the `users` row's own id --
 * never auto-generated here).
 *
 * Phase 5 Item 21: `status` is now `UserStatus` (native Doctrine
 * `enumType` column), not a plain string as originally mapped here --
 * reversing this class's own prior documented layering choice
 * (`status` staying a plain string at the persistence row, with the
 * typed enum applied only at the \Piwigo\Users\User domain-object
 * level). That original choice predated `enumType` ever being
 * evaluated in this codebase at all (confirmed via a repo-wide grep at
 * decision time: `enumType` was used nowhere), not an informed
 * rejection of it. Safe to retype: `piwigo_user_infos.status` is a
 * DB-level `enum('webmaster','admin','normal','generic','guest')`
 * matching `UserStatus`'s 5 cases exactly, so Doctrine's
 * throw-on-mismatch hydration can never actually throw for a row read
 * back from this column -- the previous `UserStatus::tryFrom($status)
 * ?? UserStatus::Guest` graceful-fallback pattern (still used at the
 * \Piwigo\Users\User::fromUserArray() domain level, and in this
 * entity's own `UserRepository::buildUserInfoEntity()`, which builds
 * from a caller-supplied bag, not a DB-guaranteed row) was already
 * dead code for this specific column.
 *
 * Real gotcha found live: `enumType`-mapped fields aren't just an
 * object-hydration ({@see \Doctrine\ORM\EntityManager::find()}) concern
 * -- Doctrine's `AbstractHydrator::buildEnum()` applies to *any* scalar
 * DQL select of the field, including `getArrayResult()`/plain
 * `getResult()` rows (e.g. `->select('ui.status AS status')`), not just
 * full-entity selects. Every real call site across
 * `Piwigo\Users\UserRepository`, `Piwigo\Auth\AuthRepository`,
 * `Piwigo\Rate\RateRepository`, and the `AuthUser`/`AuthKeyDetails`/
 * `MailRecipient`/`UserMailNotification` row-shape projections that read
 * `ui.status` via array/scalar hydration was audited and updated to
 * unwrap `->value` right after fetch, preserving every one of those
 * methods' pre-existing plain-string return contract -- confirmed live
 * (via a throwaway scratch Integration test, since PHPStan's loose
 * `array<string, mixed>` return types on those methods can't see this
 * ripple) that WHERE/SET DQL parameter binding, unlike scalar SELECT,
 * is unaffected either way -- both a raw string and a UserStatus
 * instance bind correctly against this column, so no caller
 * constructing a `setParameter('status', ...)` needed to change.
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
        #[ORM\Column(type: 'string', length: 20, enumType: UserStatus::class)]
        public UserStatus $status,
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

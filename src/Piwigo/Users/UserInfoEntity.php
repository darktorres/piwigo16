<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\ORM\Mapping as ORM;
use Override;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Db\HasLastModified;

/**
 * Maps the `user_infos` table. `user_id` is the PK, application-assigned (the
 * `users` row's own id -- never auto-generated here).
 *
 * `status` is `UserStatus` (native Doctrine `enumType` column), not a
 * plain string. `user_infos.status` is a DB-level
 * `enum('webmaster','admin','normal','generic','guest')` matching
 * `UserStatus`'s 5 cases exactly, so Doctrine's throw-on-mismatch
 * hydration can never actually throw for a row read back from this
 * column. The `UserStatus::tryFrom($status) ?? UserStatus::Guest`
 * graceful-fallback pattern is still used at the
 * \Piwigo\Users\User::fromUserArray() domain level, and in this
 * entity's own `UserRepository::buildUserInfoEntity()`, which builds
 * from a caller-supplied bag, not a DB-guaranteed row.
 *
 * `enumType`-mapped fields aren't just an object-hydration
 * ({@see \Doctrine\ORM\EntityManager::find()}) concern -- Doctrine's
 * `AbstractHydrator::buildEnum()` applies to *any* scalar DQL select of
 * the field, including `getArrayResult()`/plain `getResult()` rows
 * (e.g. `->select('ui.status AS status')`), not just full-entity
 * selects. Every real call site across `Piwigo\Users\UserRepository`,
 * `Piwigo\Auth\AuthRepository`, `Piwigo\Rate\RateRepository`, and the
 * `AuthUser`/`AuthKeyDetails`/`MailRecipient`/`UserMailNotification`
 * row-shape projections that read `ui.status` via array/scalar
 * hydration unwrap `->value` right after fetch, preserving each
 * method's plain-string return contract. WHERE/SET DQL parameter
 * binding, unlike scalar SELECT, is unaffected either way -- both a
 * raw string and a UserStatus instance bind correctly against this
 * column, so no caller constructing a `setParameter('status', ...)`
 * needs to account for it.
 * `registration_date`/`activation_key_expire`/`last_visit` are
 * `SqlDateTime`-typed -- every real write path
 * (`UserRepository::buildUserInfoEntity()`, `AuthRepository::
 * setActivationKey()`/`clearActivationKey()`) traces to an
 * `Env::now()`-derived or caller-supplied-and-validated value.
 * `lastmodified` is `SqlDateTime`-typed too -- `NOT NULL DEFAULT
 * CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` means the DB server
 * always populates a real, well-formed timestamp.
 * `Users\Projection\UserInfo` keeps its own plain-string convention for
 * all 4. `preferences` maps as native Doctrine `json` (the column
 * really is JSON, unlike Config\ConfigEntry's still-text `value` column) --
 * no round-trip-fidelity requirement forces a raw-string exception here,
 * unlike Audit\AuditLogEntity's hash-chain columns.
 *
 * The `users` table (login/password/email) is mapped separately, see
 * {@see UserEntity}.
 *
 * `language` is `LangCode`-typed -- every real write path
 * (AuthRepository::updateLanguage(), UserService::checkAndSaveUserInfos(),
 * ProfileFormHandler/ProfileController's own cookie-sync writes,
 * InstallWizard's admin-account creation) validates against
 * LangService::getLanguages() (real installed `language/ll_RR`
 * directories) before persisting, so the hydration boundary is safe to
 * type strictly.
 *
 * `theme` is `ThemeId`-typed, the same `theme_id` custom Doctrine Type
 * already used for `ThemeEntity::$id` (this column's own FK target,
 * {@see \Piwigo\Core\ThemeRepository}) -- every real write path
 * (`ProfileFormHandler`, `UserService::checkAndSaveUserInfos()`)
 * validates against `ThemeCatalog::getPwgThemes()` (real installed
 * themes) before persisting, matching `language`'s own precedent above.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_infos')]
final class UserInfoEntity implements HasLastModified
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: 'user_id')]
        public UserId $userId,
        #[ORM\Column(name: 'nb_image_page', type: 'smallint')]
        public int $nbImagePage,
        #[ORM\Column(type: 'string', length: 20, enumType: UserStatus::class)]
        public UserStatus $status,
        #[ORM\Column(type: 'lang_code', length: 50)]
        public LangCode $language,
        #[ORM\Column(type: 'boolean')]
        public bool $expand,
        #[ORM\Column(name: 'show_nb_comments', type: 'boolean')]
        public bool $showNbComments,
        #[ORM\Column(name: 'show_nb_hits', type: 'boolean')]
        public bool $showNbHits,
        #[ORM\Column(name: 'recent_period', type: 'smallint')]
        public int $recentPeriod,
        #[ORM\Column(type: 'theme_id', length: 255)]
        public ThemeId $theme,
        #[ORM\Column(name: 'registration_date', type: 'sql_datetime', length: 19, nullable: true)]
        public ?SqlDateTime $registrationDate,
        #[ORM\Column(name: 'enabled_high', type: 'boolean')]
        public bool $enabledHigh,
        #[ORM\Column(type: 'smallint')]
        public int $level,
        #[ORM\Column(name: 'activation_key', type: 'string', length: 255, nullable: true)]
        public ?string $activationKey,
        #[ORM\Column(name: 'activation_key_expire', type: 'sql_datetime', length: 19, nullable: true)]
        public ?SqlDateTime $activationKeyExpire,
        #[ORM\Column(name: 'last_visit', type: 'sql_datetime', length: 19, nullable: true)]
        public ?SqlDateTime $lastVisit,
        #[ORM\Column(name: 'last_visit_from_history', type: 'boolean')]
        public bool $lastVisitFromHistory,
        #[ORM\Column(type: 'sql_datetime', length: 19)]
        public SqlDateTime $lastmodified,
        /**
         * @var array<string, mixed>|null
         */
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $preferences,
    ) {}

    #[Override]
    public function touchLastModified(SqlDateTime $now): void
    {
        $this->lastmodified = $now;
    }
}

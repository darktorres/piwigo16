<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\AuthKeyId;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `user_auth_keys` table. No `repositoryClass` -- this table has no
 * single natural owner: {@see AuthRepository} owns its `auth_key`-type rows
 * (persistent login), {@see ApiKeyRepository} owns its `api_key`-type rows
 * (personal API keys), same physical table, different lifecycle/owner (both
 * classes' own docblocks already documented this before either converted).
 * Both query this entity directly via DQL through their own EntityManager,
 * matching Group\UserGroupEntity/GroupAccessEntity's own no-owner precedent.
 * `created_on`/`expired_on` are genuine NOT NULL columns (unlike every other
 * datetime-shaped column here) and are `SqlDateTime`- typed -- both real
 * construction sites (AuthRepository::
 * insertAuthKey()/ApiKeyRepository::insert()) trace to an Env::now()-derived
 * value. Every other datetime column stays plain ?string, not
 * \DateTimeImmutable, matching Auth\Projection\ApiKey's own already-documented
 * decision.
 *
 * `authKeyId` is `AuthKeyId`-typed -- its own primary key, also referenced
 * by `history.auth_key_id`
 * ({@see \Piwigo\History\HistoryEntity::$authKeyId}). Both
 * `Auth\Projection\ApiKey`/`Auth\Projection\AuthKeyDetails` narrow
 * `getArrayResult()`/`getOneOrNullResult(HYDRATE_ARRAY)` rows via
 * `instanceof AuthKeyId`, same Gotcha #1 shape their own `userId` handling
 * already established.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_auth_keys')]
final class UserAuthKeyEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'auth_key_id', type: 'auth_key_id')]
    public ?AuthKeyId $authKeyId = null;

    public function __construct(
        #[ORM\Column(name: 'auth_key', type: 'string', length: 255)]
        public string $authKey,
        #[ORM\Column(name: 'apikey_secret', type: 'string', length: 255, nullable: true)]
        public ?string $apikeySecret,
        #[ORM\Column(name: 'user_id', type: 'user_id')]
        public UserId $userId,
        #[ORM\Column(name: 'created_on', type: 'sql_datetime', length: 19)]
        public SqlDateTime $createdOn,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $duration,
        #[ORM\Column(name: 'expired_on', type: 'sql_datetime', length: 19)]
        public SqlDateTime $expiredOn,
        #[ORM\Column(name: 'apikey_name', type: 'string', length: 100, nullable: true)]
        public ?string $apikeyName,
        #[ORM\Column(name: 'key_type', type: 'string', length: 40, nullable: true)]
        public ?string $keyType,
        #[ORM\Column(name: 'revoked_on', type: 'string', length: 19, nullable: true)]
        public ?string $revokedOn = null,
        #[ORM\Column(name: 'last_used_on', type: 'string', length: 19, nullable: true)]
        public ?string $lastUsedOn = null,
        #[ORM\Column(name: 'last_notified_on', type: 'string', length: 19, nullable: true)]
        public ?string $lastNotifiedOn = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `users` table (`piwigo_users` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time)
 * -- core login accounts: id/username/password/mail_address, exactly the
 * real `CREATE TABLE users` in `install/piwigo_structure-mysql.sql`.
 *
 * SQL-modernization audit, Item 14 Sub-phase C4: previously deliberately
 * left unmapped ({@see UserInfoEntity}'s own docblock, which this
 * replaces) on the premise that `Piwigo\Config\CurrentConfig::userFields()`
 * gave real multi-auth integrations a way to remap these column names at
 * runtime -- Doctrine's compile-time column attributes can't represent
 * that. Re-audited: `CurrentConfig::setUserFields()` has zero real
 * callers anywhere in `src/` (confirmed via a full-repo grep), so
 * `userFields()` always returns the hardcoded defaults this entity now
 * encodes directly. No `repositoryClass` -- queried directly via
 * `$this->getEntityManager()->createQueryBuilder()->from(UserEntity::class,
 * ...)` from whichever repository needs it (UserRepository itself,
 * CommentRepository, ActivityRepository, ...), same "shared entity, no
 * single owning repository" shape {@see \Piwigo\Image\ImageCategoryEntity}/
 * {@see \Piwigo\Group\GroupAccessEntity} already established.
 */
#[ORM\Entity]
#[ORM\Table(name: 'users')]
final class UserEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'user_id')]
    public ?UserId $id = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 100)]
        public string $username,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        public ?string $password,
        #[ORM\Column(name: 'mail_address', type: 'string', length: 255, nullable: true)]
        public ?string $mailAddress,
    ) {}
}

<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;

/**
 * Maps the `users` table (`piwigo_users` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time)
 * -- core login accounts: id/username/password/mail_address, exactly the
 * real `CREATE TABLE users` in `install/piwigo_structure-mysql.sql`.
 *
 * `Piwigo\Config\CurrentConfig::userFields()` exists for remapping these
 * column names at runtime for multi-auth integrations, but
 * `CurrentConfig::setUserFields()` has zero real callers anywhere in
 * `src/`, so `userFields()` always returns the hardcoded defaults this
 * entity encodes directly. No `repositoryClass` -- queried directly via
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
        #[ORM\Column(type: 'username', length: 100)]
        public Username $username,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        public ?string $password,
        #[ORM\Column(name: 'mail_address', type: 'email', length: 255, nullable: true)]
        public ?Email $mailAddress,
    ) {}
}

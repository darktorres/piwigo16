<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `user_access` table (`piwigo_user_access` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time) --
 * composite PK (user_id, cat_id), no per-row payload. No `repositoryClass`,
 * same no-single-owner shape as Group\GroupAccessEntity/UserGroupEntity:
 * CategoryRepository writes it (permission grants tied to a category being
 * created/deleted/moved), Permission\PermissionRepository both reads and
 * writes/deletes it from the permission-management side (owns no table of
 * its own), both via DQL through their own EntityManager.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_access')]
final class UserAccessEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: 'integer')]
        public int $userId,
        #[ORM\Id]
        #[ORM\Column(name: 'cat_id', type: 'integer')]
        public int $catId,
    ) {}
}

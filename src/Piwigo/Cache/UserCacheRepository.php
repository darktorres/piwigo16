<?php

declare(strict_types=1);

namespace Piwigo\Cache;

use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for `UserCacheInvalidator`'s own 3 bulk writes --
 * separate from `Piwigo\Users\UserRepository` since this class is
 * L1Infrastructure (reachable from every layer, see
 * `UserCacheInvalidator`'s own docblock) while `Users` is L2aCoreDomain.
 */
final class UserCacheRepository extends AbstractRepository
{
    public function truncateUserCacheCategories(): void
    {
        $this->conn->executeStatement('TRUNCATE TABLE ' . Tables::userCacheCategories());
    }

    public function truncateUserCache(): void
    {
        $this->conn->executeStatement('TRUNCATE TABLE ' . Tables::userCache());
    }

    public function markNeedUpdate(): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::userCache())
            ->set('need_update', '1')
            ->executeStatement();
    }

    public function clearNbAvailableTags(): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::userCache())
            ->set('nb_available_tags', 'NULL')
            ->executeStatement();
    }
}

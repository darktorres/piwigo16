<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the password domain's one write: rehashing a
 * legacy phpass hash forward to bcrypt on successful verify.
 */
final class PasswordRepository extends AbstractRepository
{
    /**
     * Uses the literal 'password'/'id' column names -- `users`'s columns
     * are fixed ({@see \Piwigo\Users\UserEntity}), matching the original
     * pwg_password_verify()'s own \Piwigo\Db\MysqliDb::singleUpdate() call exactly.
     */
    public function updatePasswordHash(int $userId, string $newHash): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::users())
            ->set('password', ':password')
            ->where('id = :id')
            ->setParameter('password', $newHash)
            ->setParameter('id', $userId)
            ->executeStatement();
    }
}

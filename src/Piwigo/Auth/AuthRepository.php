<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the login/logout domain's two reads/writes:
 * username+password lookup for the auto-login key, and the
 * language-cookie-sync write inside logUser().
 */
final class AuthRepository extends AbstractRepository
{
    /**
     * @return array{username: string, password: string}|null
     */
    public function findUsernameAndPassword(
        int|string $userId,
        string $idColumn,
        string $usernameColumn,
        string $passwordColumn
    ): ?array {
        $row = $this->conn->createQueryBuilder()
            ->select($usernameColumn . ' AS username', $passwordColumn . ' AS password')
            ->from(Tables::users())
            ->where($idColumn . ' = :id')
            ->setParameter('id', $userId)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return [
            'username' => is_string($row['username']) ? $row['username'] : '',
            'password' => is_string($row['password']) ? $row['password'] : '',
        ];
    }

    public function updateLanguage(int|string $userId, string $language): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::userInfos())
            ->set('language', ':language')
            ->where('user_id = :userId')
            ->setParameter('language', $language)
            ->setParameter('userId', $userId)
            ->executeStatement();
    }
}

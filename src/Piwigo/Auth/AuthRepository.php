<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the login/logout domain: username+password lookup
 * for the auto-login key, the language-cookie-sync write inside logUser(),
 * username-or-email lookup, and the auth_key ("remember me"/persistent
 * login) lifecycle -- distinct from Piwigo\Auth\ApiKeyRepository, which
 * covers the same physical `user_auth_keys` table's `api_key`-type rows
 * (personal API keys), a different lifecycle/concern.
 *
 * Every `NOW()`/`ADDDATE(NOW(), ...)` computation the original raw SQL did
 * in MySQL is done in PHP against \Piwigo\Core\Env::now() instead --
 * matches Piwigo\Session\SessionRepository::write()/
 * Piwigo\Comment\CommentRepository::countRecentComments()'s own established
 * reasoning: SQL's NOW() is invisible to Env::now()'s PIWIGO_TEST_NOW
 * freeze, so a fixture-driven test comparing "now" against a fixture-dated
 * column would silently use two different clocks.
 */
final class AuthRepository extends AbstractRepository
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

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

    /**
     * Finds a user by username first, falling back to email -- matches the
     * original's own two-query try-username-then-email order (needed
     * because $usernameColumn/$emailColumn are runtime-configurable via
     * \Piwigo\Config\Config::userFields(), not always literally 'username'/'email').
     *
     * @return array{id: string, username: string, email: string, password: string, status: string}|null
     */
    public function findByUsernameOrEmail(
        string $usernameOrEmail,
        string $idColumn,
        string $usernameColumn,
        string $emailColumn,
        string $passwordColumn
    ): ?array {
        $qb = $this->conn->createQueryBuilder()
            ->select(
                'u.' . $idColumn . ' AS id',
                'u.' . $usernameColumn . ' AS username',
                'u.' . $emailColumn . ' AS email',
                'u.' . $passwordColumn . ' AS password',
                'i.status AS status',
            )
            ->from(Tables::users(), 'u')
            ->leftJoin('u', Tables::userInfos(), 'i', 'u.' . $idColumn . ' = i.user_id')
            ->setParameter('value', $usernameOrEmail);

        $row = (clone $qb)->where('u.' . $usernameColumn . ' = :value')
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            $row = $qb->where('u.' . $emailColumn . ' = :value')
                ->executeQuery()
                ->fetchAssociative();
        }

        if ($row === false) {
            return null;
        }

        return [
            'id' => is_scalar($row['id']) ? (string) $row['id'] : '',
            'username' => is_string($row['username']) ? $row['username'] : '',
            'email' => is_string($row['email']) ? $row['email'] : '',
            'password' => is_string($row['password']) ? $row['password'] : '',
            // the user may not exist in user_infos, so default to 'normal'
            // -- matches the original's `$user['status'] ??= 'normal';`
            'status' => is_string($row['status']) ? $row['status'] : 'normal',
        ];
    }

    /**
     * A single auth_key row (JOINed with its owning user's username/email),
     * or null when no such key exists. Every value that was a SQL-computed
     * pseudo-column in the original (NOW(), DATEDIFF, SUBDATE) is left to
     * the caller to compute from \Piwigo\Core\Env::now() instead.
     *
     * @return array{
     *   auth_key_id: string, user_id: string, auth_key: string,
     *   expired_on: string, revoked_on: ?string, last_used_on: ?string,
     *   last_notified_on: ?string, apikey_secret: ?string, status: string,
     *   username: string, email: string,
     * }|null
     */
    public function findAuthKeyDetails(string $authKey, string $idColumn, string $usernameColumn, string $emailColumn): ?array
    {
        $row = $this->conn->createQueryBuilder()
            ->select(
                'uak.auth_key_id',
                'uak.user_id',
                'uak.auth_key',
                'uak.expired_on',
                'uak.revoked_on',
                'uak.last_used_on',
                'uak.last_notified_on',
                'uak.apikey_secret',
                'ui.status',
                'u.' . $usernameColumn . ' AS username',
                'u.' . $emailColumn . ' AS email',
            )
            ->from(Tables::userAuthKeys(), 'uak')
            ->join('uak', Tables::userInfos(), 'ui', 'uak.user_id = ui.user_id')
            ->join('uak', Tables::users(), 'u', 'u.' . $idColumn . ' = ui.user_id')
            ->where('uak.auth_key = :authKey')
            ->setParameter('authKey', $authKey)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return [
            'auth_key_id' => is_scalar($row['auth_key_id']) ? (string) $row['auth_key_id'] : '',
            'user_id' => is_scalar($row['user_id']) ? (string) $row['user_id'] : '',
            'auth_key' => is_scalar($row['auth_key']) ? (string) $row['auth_key'] : '',
            'expired_on' => is_scalar($row['expired_on']) ? (string) $row['expired_on'] : '',
            'revoked_on' => is_scalar($row['revoked_on']) ? (string) $row['revoked_on'] : null,
            'last_used_on' => is_scalar($row['last_used_on']) ? (string) $row['last_used_on'] : null,
            'last_notified_on' => is_scalar($row['last_notified_on']) ? (string) $row['last_notified_on'] : null,
            'apikey_secret' => is_scalar($row['apikey_secret']) ? (string) $row['apikey_secret'] : null,
            'status' => is_string($row['status']) ? $row['status'] : '',
            'username' => is_string($row['username']) ? $row['username'] : '',
            'email' => is_string($row['email']) ? $row['email'] : '',
        ];
    }

    public function touchAuthKeyLastUsed(int|string $userId, string $authKey, \DateTimeInterface $lastUsedOn): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::userAuthKeys())
            ->set('last_used_on', ':lastUsedOn')
            ->where('user_id = :userId')
            ->andWhere('auth_key = :authKey')
            ->setParameter('lastUsedOn', $lastUsedOn->format(self::DATETIME_FORMAT))
            ->setParameter('userId', $userId)
            ->setParameter('authKey', $authKey)
            ->executeStatement();
    }

    public function findUserStatus(int $userId): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('status')
            ->from(Tables::userInfos())
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : null;
    }

    public function authKeyCandidateExists(string $candidate): bool
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::userAuthKeys())
            ->where('auth_key = :candidate')
            ->setParameter('candidate', $candidate)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * @param array{
     *   auth_key: string, user_id: int, created_on: string, duration: int,
     *   expired_on: string, key_type: string,
     * } $key
     */
    public function insertAuthKey(array $key): string
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::userAuthKeys())
            ->values([
                'auth_key' => ':authKey',
                'user_id' => ':userId',
                'created_on' => ':createdOn',
                'duration' => ':duration',
                'expired_on' => ':expiredOn',
                'key_type' => ':keyType',
            ])
            ->setParameter('authKey', $key['auth_key'])
            ->setParameter('userId', $key['user_id'])
            ->setParameter('createdOn', $key['created_on'])
            ->setParameter('duration', $key['duration'])
            ->setParameter('expiredOn', $key['expired_on'])
            ->setParameter('keyType', $key['key_type'])
            ->executeStatement();

        return (string) $this->conn->lastInsertId();
    }

    public function deactivateAuthKeys(int $userId, \DateTimeInterface $now): void
    {
        $nowStr = $now->format(self::DATETIME_FORMAT);

        $this->conn->createQueryBuilder()
            ->update(Tables::userAuthKeys())
            ->set('expired_on', ':now')
            ->where('user_id = :userId')
            ->andWhere('expired_on > :nowCompare')
            ->andWhere("key_type = 'auth_key'")
            ->setParameter('now', $nowStr)
            ->setParameter('userId', $userId)
            ->setParameter('nowCompare', $nowStr)
            ->executeStatement();
    }

    public function clearActivationKey(int $userId): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::userInfos())
            ->set('activation_key', 'NULL')
            ->set('activation_key_expire', 'NULL')
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->executeStatement();
    }

    public function setActivationKey(int $userId, string $hash, \DateTimeInterface $expire): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::userInfos())
            ->set('activation_key', ':hash')
            ->set('activation_key_expire', ':expire')
            ->where('user_id = :userId')
            ->setParameter('hash', $hash)
            ->setParameter('expire', $expire->format(self::DATETIME_FORMAT))
            ->setParameter('userId', $userId)
            ->executeStatement();
    }

    /**
     * Most recent 'date time' visit string from the history table, or null
     * when the user has no history rows. The original looped over every
     * row returned by its query, but the query itself was already
     * `ORDER BY id DESC LIMIT 1` -- so at most one iteration ever ran; a
     * single-row fetch is behaviorally identical.
     */
    public function findLastVisitFromHistory(int $userId): ?string
    {
        $row = $this->conn->createQueryBuilder()
            ->select('date', 'time')
            ->from(Tables::history())
            ->where('user_id = :userId')
            ->orderBy('id', 'DESC')
            ->setMaxResults(1)
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $date = is_scalar($row['date']) ? (string) $row['date'] : '';
        $time = is_scalar($row['time']) ? (string) $row['time'] : '';

        return $date . ' ' . $time;
    }

    /**
     * `lastmodified = lastmodified` is a deliberate self-assignment in the
     * original -- not a no-op leftover, it's how the original avoids
     * bumping an ON UPDATE CURRENT_TIMESTAMP-style column while still
     * writing the other two columns; a raw SQL fragment (not a bound
     * parameter) is required to reference the column itself rather than a
     * value. `last_visit_from_history` is a real tinyint column now (User
     * domain Stage 1a) -- the literal unquoted `1` below is the numeric
     * equivalent of the old `'true'` string literal, not a bound value,
     * same reasoning as `lastmodified`.
     */
    public function saveLastVisitFromHistory(int $userId, ?string $lastVisit): void
    {
        $qb = $this->conn->createQueryBuilder()
            ->update(Tables::userInfos())
            ->set('last_visit_from_history', '1')
            ->set('lastmodified', 'lastmodified')
            ->where('user_id = :userId')
            ->setParameter('userId', $userId);

        if ($lastVisit === null) {
            $qb->set('last_visit', 'NULL');
        } else {
            $qb->set('last_visit', ':lastVisit')
                ->setParameter('lastVisit', $lastVisit);
        }

        $qb->executeStatement();
    }

    public function countLoginActivity(int $userId): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::activity())
            ->where("action = 'login'")
            ->andWhere('performed_by = :userId')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }
}

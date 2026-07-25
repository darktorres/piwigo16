<?php

declare(strict_types=1);

namespace Piwigo\Mail;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;
use Piwigo\Mail\Projection\MailRecipient;

/**
 * Persistence layer for mailing-recipient discovery: MailService's own
 * `mailAdmins()`/`mailGroup()` queries against `users`/`user_infos`/
 * `user_group`. `$idColumn`/`$usernameColumn`/`$emailColumn` are the
 * runtime-configurable column names from \Piwigo\Config\CurrentConfig::userFields()
 * (resolved by the caller, matching Piwigo\Auth\AuthRepository's own
 * pattern for the same reason).
 */
final class MailRecipientRepository extends AbstractRepository
{
    /**
     * @param  list<string>  $userStatuses
     * @return list<MailRecipient>
     */
    public function findAdminsAndWebmasters(
        string $idColumn,
        string $usernameColumn,
        string $emailColumn,
        array $userStatuses,
        ?int $groupId,
        ?int $excludeUserId
    ): array {
        $qb = $this->conn->createQueryBuilder()
            ->select(
                'i.user_id',
                'u.' . $usernameColumn . ' AS name',
                'u.' . $emailColumn . ' AS email',
            )
            ->from(Tables::users(), 'u')
            ->join('u', Tables::userInfos(), 'i', 'i.user_id = u.' . $idColumn)
            ->where('i.status IN (:statuses)')
            ->andWhere('u.' . $emailColumn . ' IS NOT NULL')
            ->orderBy('name')
            ->setParameter('statuses', $userStatuses, ArrayParameterType::STRING);

        if ($groupId !== null) {
            $qb->join('i', Tables::userGroup(), 'ug', 'ug.user_id = i.user_id')
                ->andWhere('ug.group_id = :groupId')
                ->setParameter('groupId', $groupId);
        }

        if ($excludeUserId !== null) {
            $qb->andWhere('i.user_id <> :excludeUserId')
                ->setParameter('excludeUserId', $excludeUserId);
        }

        $rows = $qb->executeQuery()
            ->fetchAllAssociative();

        return array_map(MailRecipient::fromRow(...), $rows);
    }

    /**
     * @return list<string>
     */
    public function findDistinctLanguagesInGroup(
        string $idColumn,
        string $emailColumn,
        int $groupId,
        ?string $languageFilter
    ): array {
        $qb = $this->conn->createQueryBuilder()
            ->select('DISTINCT ui.language')
            ->from(Tables::userGroup(), 'ug')
            ->join('ug', Tables::users(), 'u', $idColumn . ' = ug.user_id')
            ->join('ug', Tables::userInfos(), 'ui', 'ui.user_id = ug.user_id')
            ->where('ug.group_id = :groupId')
            ->andWhere('u.' . $emailColumn . ' <> \'\'')
            ->setParameter('groupId', $groupId);

        if ($languageFilter !== null && $languageFilter !== '') {
            $qb->andWhere('ui.language = :language')
                ->setParameter('language', $languageFilter);
        }

        $rows = $qb->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_filter($rows, static fn ($language): bool => is_string($language) && $language !== ''));
    }

    /**
     * @return list<MailRecipient>
     */
    public function findByGroupAndLanguage(
        string $idColumn,
        string $usernameColumn,
        string $emailColumn,
        int $groupId,
        string $language
    ): array {
        $rows = $this->conn->createQueryBuilder()
            ->select(
                'ui.user_id',
                'ui.status',
                'u.' . $usernameColumn . ' AS name',
                'u.' . $emailColumn . ' AS email',
            )
            ->from(Tables::userGroup(), 'ug')
            ->join('ug', Tables::users(), 'u', $idColumn . ' = ug.user_id')
            ->join('ug', Tables::userInfos(), 'ui', 'ui.user_id = ug.user_id')
            ->where('ug.group_id = :groupId')
            ->andWhere('u.' . $emailColumn . ' <> \'\'')
            ->andWhere('ui.language = :language')
            ->setParameter('groupId', $groupId)
            ->setParameter('language', $language)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(MailRecipient::fromRow(...), $rows);
    }
}

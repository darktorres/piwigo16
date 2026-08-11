<?php

declare(strict_types=1);

namespace Piwigo\Mail;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Override;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Group\UserGroupEntity;
use Piwigo\Mail\Projection\MailRecipient;
use Piwigo\Users\UserEntity;
use Piwigo\Users\UserInfoEntity;

/**
 * Persistence layer for mailing-recipient discovery: MailService's own
 * `mailAdmins()`/`mailGroup()` queries against `users`/`user_infos`/
 * `user_group`.
 *
 * No `$idColumn`/`$usernameColumn`/`$emailColumn` multi-auth column-name
 * params -- `users` is mapped ({@see \Piwigo\Users\UserEntity}), always
 * `id`/`username`/`mail_address`.
 *
 * None of `UserEntity`/
 * `UserInfoEntity`/`UserGroupEntity` declares a formal ORM association to
 * either of the others, so every join here uses `Join::WITH` (same
 * established pattern as `TagRepository`/`CategoryRepository`/etc.). `Mail`
 * is `L3Presentation`; `Users`/`Group` are both `L2aCoreDomain` -- a
 * downward dependency, not a `deleteSiteRow`-class layer violation.
 */
final readonly class MailRecipientRepository implements MailRecipientRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * @param  list<string>  $userStatuses
     * @return list<MailRecipient>
     */
    #[Override]
    public function findAdminsAndWebmasters(
        array $userStatuses,
        ?int $groupId,
        ?int $excludeUserId
    ): array {
        $qb = $this->em->createQueryBuilder()
            ->select('i.userId AS user_id', 'u.username AS name', 'u.mailAddress AS email')
            ->from(UserEntity::class, 'u')
            ->innerJoin(UserInfoEntity::class, 'i', Join::WITH, 'i.userId = u.id')
            ->where('i.status IN (:statuses)')
            ->andWhere('u.mailAddress IS NOT NULL')
            ->orderBy('name')
            ->setParameter('statuses', $userStatuses);

        if ($groupId !== null) {
            $qb->innerJoin(UserGroupEntity::class, 'ug', Join::WITH, 'ug.userId = i.userId')
                ->andWhere('ug.groupId = :groupId')
                ->setParameter('groupId', GroupId::from($groupId));
        }

        if ($excludeUserId !== null) {
            $qb->andWhere('i.userId <> :excludeUserId')
                ->setParameter('excludeUserId', UserId::from($excludeUserId));
        }

        $rows = $qb->getQuery()
            ->getArrayResult();

        return $this->rowsToRecipients($rows, includeStatus: false);
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function findDistinctLanguagesInGroup(
        int $groupId,
        ?string $languageFilter
    ): array {
        $qb = $this->em->createQueryBuilder()
            ->select('DISTINCT ui.language')
            ->from(UserGroupEntity::class, 'ug')
            ->innerJoin(UserEntity::class, 'u', Join::WITH, 'u.id = ug.userId')
            ->innerJoin(UserInfoEntity::class, 'ui', Join::WITH, 'ui.userId = ug.userId')
            ->where('ug.groupId = :groupId')
            ->andWhere("u.mailAddress <> ''")
            ->setParameter('groupId', GroupId::from($groupId));

        if ($languageFilter !== null && $languageFilter !== '') {
            $languageFilterVo = LangCode::tryFrom($languageFilter);
            if (! $languageFilterVo instanceof LangCode) {
                // A malformed filter value can never match a real row --
                // every real ui.language value is a valid LangCode.
                return [];
            }
            $qb->andWhere('ui.language = :language')
                ->setParameter('language', $languageFilterVo);
        }

        $rows = $qb->getQuery()
            ->getSingleColumnResult();

        return array_values(array_filter($rows, static fn ($language): bool => is_string($language) && $language !== ''));
    }

    /**
     * @return list<MailRecipient>
     */
    #[Override]
    public function findByGroupAndLanguage(
        int $groupId,
        string $language
    ): array {
        $rows = $this->em->createQueryBuilder()
            ->select('ui.userId AS user_id', 'ui.status', 'u.username AS name', 'u.mailAddress AS email')
            ->from(UserGroupEntity::class, 'ug')
            ->innerJoin(UserEntity::class, 'u', Join::WITH, 'u.id = ug.userId')
            ->innerJoin(UserInfoEntity::class, 'ui', Join::WITH, 'ui.userId = ug.userId')
            ->where('ug.groupId = :groupId')
            ->andWhere("u.mailAddress <> ''")
            ->andWhere('ui.language = :language')
            ->setParameter('groupId', GroupId::from($groupId))
            ->setParameter('language', LangCode::from($language))
            ->getQuery()
            ->getArrayResult();

        return $this->rowsToRecipients($rows, includeStatus: true);
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<MailRecipient>
     */
    private function rowsToRecipients(array $rows, bool $includeStatus): array
    {
        $recipients = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $userId = $row['user_id'] ?? null;
            $name = $row['name'] ?? null;
            $email = $row['email'] ?? null;
            $recipients[] = MailRecipient::fromRow([
                'user_id' => $userId instanceof UserId ? $userId->value : $userId,
                'name' => $name instanceof Username ? $name->value : $name,
                'email' => $email instanceof Email ? $email->value : $email,
                'status' => $includeStatus ? ($row['status'] ?? null) : null,
            ]);
        }

        return $recipients;
    }
}

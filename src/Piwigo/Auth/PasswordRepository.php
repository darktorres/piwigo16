<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Users\UserEntity;

/**
 * Persistence layer for the password domain's one write: rehashing a
 * legacy phpass hash forward to bcrypt on successful verify.
 *
 * Item 15 audit: converted to real DQL against {@see UserEntity} --
 * `UserEntity` deliberately has no `repositoryClass` of its own (its own
 * docblock: queried directly by whichever repository needs it -- `users`
 * is a shared entity with no single owner, same shape as
 * {@see \Piwigo\Image\ImageCategoryEntity}), so this class takes
 * `EntityManagerInterface` directly and targets `UserEntity` by class, the
 * same pattern `UserRepository` itself and every other cross-domain
 * consumer of `UserEntity` already uses. `Auth`/`Users` are both
 * `L2aCoreDomain` (same layer), so this is a same-layer dependency, not a
 * cross-layer one.
 */
final readonly class PasswordRepository
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * Uses the literal `password`/`id` column names -- `users`'s columns
     * are fixed ({@see UserEntity}), matching the original
     * pwg_password_verify()'s own \Piwigo\Db\MysqliDb::singleUpdate() call exactly.
     */
    public function updatePasswordHash(int $userId, string $newHash): void
    {
        $this->em->createQueryBuilder()
            ->update(UserEntity::class, 'u')
            ->set('u.password', ':password')
            ->where('u.id = :id')
            ->setParameter('password', $newHash)
            ->setParameter('id', UserId::from($userId))
            ->getQuery()
            ->execute();
        $this->em->clear();
    }
}

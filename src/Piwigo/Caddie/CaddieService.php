<?php

declare(strict_types=1);

namespace Piwigo\Caddie;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Db\TypedRepository;
use Piwigo\Users\CurrentUser;

/**
 * Caller-facing wrapper around CaddieRepository::addElements() that
 * resolves the current user id. Kept out of CaddieRepository itself,
 * which is a pure DB-access class with no `global $user` reads.
 */
final class CaddieService
{
    /**
     * fill the current user caddie with given elements, if not already in caddie
     *
     * @param array<int, int> $elementsId
     */
    public static function fillCurrentUserCaddie(array $elementsId, CurrentUser $currentUser, EntityManagerInterface $entityManager): void
    {
        $userId = $currentUser->get()
            ->id->value;

        TypedRepository::narrow($entityManager->getRepository(CaddieEntity::class), CaddieRepository::class)
            ->addElements($userId, $elementsId);
    }
}

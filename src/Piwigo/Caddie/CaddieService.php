<?php

declare(strict_types=1);

namespace Piwigo\Caddie;

use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
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
    public static function fillCurrentUserCaddie(array $elementsId, CurrentUser $currentUser): void
    {
        $userId = $currentUser->get()
            ->id->value;

        EntityManagerFactory::build(DbConnection::build())->getRepository(CaddieEntity::class)
            ->addElements($userId, $elementsId);
    }
}

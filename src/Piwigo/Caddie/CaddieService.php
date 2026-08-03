<?php

declare(strict_types=1);

namespace Piwigo\Caddie;

use Piwigo\Db\DbConnection;

/**
 * P23 batch 8d: relocated from include/functions.inc.php's fill_caddie(),
 * unchanged logic -- a real caller-facing wrapper around
 * CaddieRepository::addElements() resolving the current user id, kept out
 * of CaddieRepository itself (a pure DB-access class, no `global $user`
 * reads elsewhere in it).
 */
final class CaddieService
{
    /**
     * fill the current user caddie with given elements, if not already in caddie
     *
     * @param array<int, int> $elementsId
     */
    public static function fillCurrentUserCaddie(array $elementsId): void
    {
        $userId = \Piwigo\Users\CurrentUser::current()->get()->id->value;

        \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(CaddieEntity::class)
            ->addElements($userId, $elementsId);
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Auth;

/**
 * Seam {@see AuthRepository}'s own constructor type-hints against --
 * `history` (one row per public page view) is owned by
 * {@see \Piwigo\History\HistoryRepository} (`History`, `L2bExtendedDomain`),
 * and `Auth` (`L2aCoreDomain`) can't depend on it directly (`deptrac.yaml`
 * only allows downward dependencies). Implemented by `HistoryRepository`
 * itself, wired at the composition root (config/container.php), same
 * `Mail\MailRecipientRepositoryInterface`-style seam already established
 * in this codebase.
 */
interface LastVisitLookupInterface
{
    /**
     * Most recent 'date time' visit string for $userId from the history
     * table, or null when they have no history rows.
     */
    public function findLastVisit(int $userId): ?string;
}

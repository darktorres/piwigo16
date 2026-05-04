<?php

declare(strict_types=1);

namespace Piwigo\History;

use Piwigo\Db\AbstractRepository;

/** Persistence layer for the history domain. */
final class HistoryRepository extends AbstractRepository
{
    /**
     * Sum of nb_pages for all history_summary rows with month IS NULL
     * (annual roll-ups), giving total site page views.
     */
    public function sumPageViews(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('SUM(nb_pages)')
            ->from($this->table('history_summary'))
            ->where('month IS NULL')
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }
}

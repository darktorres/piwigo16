<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Doctrine\DBAL\Connection;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;

/**
 * Former install/db/150-database.php (P23 sub-batch 8g-3).
 */
final class Patch150 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '150';
    }

    #[\Override]
    public function description(): string
    {
        return 'add history_id_from+history_id_to in history_summary table';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        // we use PREFIX_TABLE, in case Piwigo uses an external user table
        $conn->executeStatement('
ALTER TABLE `' . Tables::historySummary() . '`
  ADD COLUMN `history_id_from` int(10) unsigned default NULL,
  ADD COLUMN `history_id_to` int(10) unsigned default NULL
;');

        $query = '
SELECT
    *
  FROM ' . Tables::history() . '
  WHERE summarized = :summarized
  ORDER BY id DESC
  LIMIT 1
;';
        // note : much faster than searching MAX(ID), ie on my big sample 14 seconds Vs 2 seconds
        $history_lines = $conn->fetchAllAssociative($query, [
            'summarized' => 'true',
        ]);
        if (count($history_lines) > 0) {
            $last_summarized = $history_lines[0];

            $summarized_date = is_scalar($last_summarized['date']) ? (string) $last_summarized['date'] : '';
            $summarized_time = is_scalar($last_summarized['time']) ? (string) $last_summarized['time'] : '';
            [$year, $month, $day] = explode('-', $summarized_date);
            [$hour] = explode(':', $summarized_time);

            new BatchWriter($conn)
                ->singleUpdate(
                    Tables::historySummary(),
                    [
                        'history_id_to' => $last_summarized['id'],
                    ],
                    [
                        'year' => $year,
                        'month' => $month,
                        'day' => $day,
                        'hour' => $hour,
                    ]
                );

            // in case this script would update no summary line, it would mean the
            // summary has been purged and will be rebuild from scratch, based on the
            // content of history table
        }

        // for now, we keep column history.summarized even if Piwigo 2.9 no longer
        // uses it. We will remove it in a future version. First we need to have
        // "less" lines in history table. This will be possible with the automatic
        // purge implemented in Piwigo 2.9.

        echo "\n" . $this->description() . "\n";
    }
}

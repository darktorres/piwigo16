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
 * Former install/db/98-database.php (P23 sub-batch 8g-1). The original
 * raw SQL wrote a bareword `false` (a MySQL boolean literal = 0) into
 * `config.value`, a TEXT column -- unlike every sibling patch, which
 * quotes `'false'`/`'true'` as real strings. MySQL would have coerced
 * that bareword to the stored string '0' (not 'false'). Bound here as
 * the literal string '0' to preserve that exact stored value rather than
 * relying on a bound PHP `false`'s driver-specific coercion --
 * `comments_update_validation` is confirmed unread anywhere in
 * `src/Piwigo/`/`themes/` today, so this is a faithfulness choice, not
 * a live behavior concern.
 */
final class Patch98 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '98';
    }

    #[\Override]
    public function description(): string
    {
        return 'add the config parameter comments_update_validation';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $batchWriter = new BatchWriter($conn);
        $batchWriter->singleInsert(Tables::config(), [
            'param' => 'comments_update_validation',
            'value' => '0',
            'comment' => 'administrators validate users updated comments before becoming visible',
        ]);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}

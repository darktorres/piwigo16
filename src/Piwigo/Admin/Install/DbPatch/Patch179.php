<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Db\MysqliDb;
use Piwigo\Db\Tables;

/**
 * Former install/db/179-database.php (P23 sub-batch 8g-3).
 */
final class Patch179 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '179';
    }

    #[\Override]
    public function description(): string
    {
        return 'Modification to the user_auth_key table to add last_notified_on';
    }

    #[\Override]
    public function apply(): void
    {
        // For API KEY, add a column last_notified_on, to know when the last email (for the moment)
        // notifying of an upcoming expiration date was sent.
        MysqliDb::query(
            'ALTER TABLE `' . Tables::userAuthKeys() . '`
  ADD COLUMN `last_notified_on` datetime DEFAULT NULL
;'
        );

        echo "\n" . $this->description() . "\n";
    }
}

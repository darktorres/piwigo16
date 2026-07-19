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
use Piwigo\Db\Tables;

/**
 * Former install/db/176-database.php (P23 sub-batch 8g-3).
 */
final class Patch176 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '176';
    }

    #[\Override]
    public function description(): string
    {
        return 'Modification to the user_auth_key table to match the api keys';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        // we are modifying the "auth_key" table structure to support the new API key system.
        // the existing structure was too limited for our needs, this update ensures better
        // flexibility and security for managing API access tokens in the future.
        $conn->executeStatement(
            'ALTER TABLE `' . Tables::userAuthKeys() . '`
  ADD COLUMN `apikey_secret` VARCHAR(255) DEFAULT NULL AFTER auth_key,
  ADD COLUMN `apikey_name` VARCHAR(100) DEFAULT NULL,
  ADD COLUMN `key_type` VARCHAR(40) DEFAULT NULL,
  ADD COLUMN `revoked_on`  datetime DEFAULT NULL,
  ADD COLUMN `last_used_on` datetime DEFAULT NULL
;'
        );

        // For rows that already exist in the table, we add a key_type
        $conn->executeStatement(
            'UPDATE `' . Tables::userAuthKeys() . '`
  SET `key_type` = \'auth_key\'
  WHERE `key_type` IS NULL
;'
        );

        echo "\n" . $this->description() . "\n";
    }
}

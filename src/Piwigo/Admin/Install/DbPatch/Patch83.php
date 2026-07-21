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
 * Former install/db/83-database.php (P23 sub-batch 8g-1). user_fields/
 * guest_id are read via LegacyFileConf::read() -- Config::userFields()/
 * Config::guestId() don't see a site's local/config/config.inc.php
 * override on this path (same reasoning as the rest of this file family).
 */
final class Patch83 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '83';
    }

    #[\Override]
    public function description(): string
    {
        return 'Update column save author_id with value.';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $localConf = LegacyFileConf::read();
        $userFields = is_array($localConf['user_fields'] ?? null) ? $localConf['user_fields'] : [
            'id' => 'id',
            'username' => 'username',
        ];
        $userIdField = is_scalar($userFields['id'] ?? null) ? $userFields['id'] : 'id';
        $usernameField = is_scalar($userFields['username'] ?? null) ? $userFields['username'] : 'username';
        $guestId = is_scalar($localConf['guest_id'] ?? null) ? $localConf['guest_id'] : 2;

        $query = '
UPDATE
  ' . Tables::comments() . ' AS c ,
  ' . Tables::users() . ' AS u,
  ' . Tables::userInfos() . ' AS i
SET c.author_id = u.' . $userIdField . '
WHERE
    c.author_id is null
AND c.author = u.' . $usernameField . '
AND u.' . $userIdField . ' = i.user_id
AND i.registration_date <= c.date
;';
        $conn->executeStatement($query);

        $query = '
UPDATE ' . Tables::comments() . ' AS c
SET c.author_id = :guestId
WHERE c.author_id is null
;';
        $conn->executeStatement($query, [
            'guestId' => $guestId,
        ]);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}

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
 * Former install/db/83-database.php (P23 sub-batch 8g-1). Reads
 * $conf['user_fields']/$conf['guest_id'] from the true global, exactly as
 * the original did in the runner's include scope.
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
    public function apply(): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $query = '
UPDATE
  ' . Tables::comments() . ' AS c ,
  ' . Tables::users() . ' AS u,
  ' . Tables::userInfos() . ' AS i
SET c.author_id = u.' . $conf['user_fields']['id'] . '
WHERE
    c.author_id is null
AND c.author = u.' . $conf['user_fields']['username'] . '
AND u.' . $conf['user_fields']['id'] . ' = i.user_id
AND i.registration_date <= c.date
;';
        MysqliDb::query($query);

        $query = '
UPDATE ' . Tables::comments() . ' AS c
SET c.author_id = ' . $conf['guest_id'] . '
WHERE c.author_id is null
;';
        MysqliDb::query($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}

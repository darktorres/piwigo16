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
 * Former install/db/81-database.php (P23 sub-batch 8g-1). This one's echo
 * printed the bare description without the usual '"..." ended' wrapper --
 * preserved.
 */
final class Patch81 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '81';
    }

    #[\Override]
    public function description(): string
    {
        return 'add new email features :
users can modify/delete their owns comments';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = '
INSERT INTO ' . Tables::config() . ' (param,value,comment)
  VALUES (\'user_can_delete_comment\',\'false\',
    \'administrators can allow user delete their own comments\'),
  (\'user_can_edit_comment\',\'false\',
    \'administrators can allow user edit their own comments\'),
  (\'email_admin_on_comment_edition\',\'false\',
    \'Send an email to the administrators when a comment is modified\'),
  (\'email_admin_on_comment_deletion\',\'false\',
    \'Send an email to the administrators when a comment is deleted\')
;';
        $conn->executeStatement($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}

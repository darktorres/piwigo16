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
use Piwigo\Config\ConfigDb;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Db\Tables;

/**
 * Former install/db/94-database.php (P23 sub-batch 8g-1). The
 * $conf_orig swap operates on the true global (declared below), and the
 * bare load_conf_from_db() call became ConfigDb::loadConfFromDb() --
 * identical semantics, both mutate global $conf. The long-dropped
 * 'waiting' table has no Tables:: accessor; its name is built from
 * $prefixeTable exactly as the original's PREFIX_TABLE concatenation did.
 */
final class Patch94 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '94';
    }

    #[\Override]
    public function description(): string
    {
        return 'remove user upload as core feature and save config for Community plugin';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        /**
         * @var array<string, mixed>
         */
        global $conf;
        /**
         * @var string
         */
        global $prefixeTable;

        $user_upload_conf = [];

        // upload_user_access
        $conf_orig = $conf;
        ConfigDb::loadConfFromDb();
        $user_upload_conf['upload_user_access'] = $conf['upload_user_access'];
        $conf = $conf_orig;

        // unvalidated photos submitted by users
        $query = '
SELECT *
  FROM ' . $prefixeTable . 'waiting
;';
        $user_upload_conf['waiting_rows'] = $conn->fetchAllAssociative($query);

        // uploadable categories
        $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE uploadable = \'true\'
;';
        $user_upload_conf['uploadable_categories'] = $conn->fetchFirstColumn($query);

        // save configuration for a future use by the Community plugin
        $backup_filepath = PHPWG_ROOT_PATH . $conf['data_location'] . 'plugins/core_user_upload_to_community.php';
        $save_conf = true;
        if (is_dir(dirname($backup_filepath))) {
            if (! is_writable(dirname($backup_filepath))) {
                $save_conf = false;
            }
        } elseif (! is_writable(PHPWG_ROOT_PATH . $conf['data_location'])) {
            $save_conf = false;
        }

        if ($save_conf) {
            FilesystemHelper::mkgetdir(dirname($backup_filepath));

            file_put_contents(
                $backup_filepath,
                '<?php $user_upload_conf = \'' . serialize($user_upload_conf) . '\'; ?>'
            );
        }

        //
        // remove all what is related to user upload in the database
        //

        // categories.uploadable
        $conn->executeStatement('ALTER TABLE ' . Tables::categories() . ' DROP COLUMN uploadable;');

        // waiting
        $conn->executeStatement('DROP TABLE ' . $prefixeTable . 'waiting;');

        // config parameter settings : upload_user_access, upload_link_everytime
        $query = '
DELETE FROM ' . Tables::config() . '
  WHERE param IN (\'upload_user_access\', \'upload_link_everytime\', \'email_admin_on_picture_uploaded\')
;';
        $conn->executeStatement($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}

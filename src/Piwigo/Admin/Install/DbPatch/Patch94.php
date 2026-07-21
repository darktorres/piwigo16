<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Db\Tables;

/**
 * Former install/db/94-database.php (P23 sub-batch 8g-1). data_location is
 * read via LegacyFileConf::read() -- Config::dataLocation() doesn't see a
 * site's local/config/config.inc.php override on this path (see
 * InstallWizard's own constructor docblock). The former $conf_orig
 * capture-then-restore around load_conf_from_db() (Legacy Coupling
 * Retirement Phase 8, 8d) is gone: ConfigService::loadConfFromDb() never
 * touches $conf at all (writes Config::$data only), so there is nothing
 * left to restore -- upload_user_access is read straight off Config::
 * right after the load instead.
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
        $user_upload_conf = [];

        // upload_user_access
        $configService = \Piwigo\Config\CurrentConfigService::get();
        $configService->loadConfFromDb();
        $user_upload_conf['upload_user_access'] = $configService->confGetParam('upload_user_access');

        // unvalidated photos submitted by users
        $query = '
SELECT *
  FROM ' . Config::dbPrefix() . 'waiting
;';
        $user_upload_conf['waiting_rows'] = $conn->fetchAllAssociative($query);

        // uploadable categories
        $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE uploadable = :uploadable
;';
        $user_upload_conf['uploadable_categories'] = $conn->fetchFirstColumn($query, [
            'uploadable' => 'true',
        ]);

        // save configuration for a future use by the Community plugin
        $localConf = LegacyFileConf::read();
        $data_location = is_string($localConf['data_location'] ?? null) ? $localConf['data_location'] : '_data/';
        $backup_filepath = PHPWG_ROOT_PATH . $data_location . 'plugins/core_user_upload_to_community.php';
        $save_conf = true;
        if (is_dir(dirname($backup_filepath))) {
            if (! is_writable(dirname($backup_filepath))) {
                $save_conf = false;
            }
        } elseif (! is_writable(PHPWG_ROOT_PATH . $data_location)) {
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
        $conn->executeStatement('DROP TABLE ' . Config::dbPrefix() . 'waiting;');

        // config parameter settings : upload_user_access, upload_link_everytime
        $query = '
DELETE FROM ' . Tables::config() . '
  WHERE param IN (:params)
;';
        $conn->executeStatement($query, [
            'params' => ['upload_user_access', 'upload_link_everytime', 'email_admin_on_picture_uploaded'],
        ], [
            'params' => ArrayParameterType::STRING,
        ]);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}

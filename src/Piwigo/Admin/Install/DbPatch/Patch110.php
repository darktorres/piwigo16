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
use Piwigo\Core\CurrentPaths;
use Piwigo\Db\Tables;

/**
 * Former install/db/110-database.php (P23 sub-batch 8g-2). Error lines are
 * both pushed onto PageState (safe even when this patch runs outside an
 * HTTP request -- PageState::current() always returns a usable instance)
 * and echoed, as before. local_dir_site is read via LegacyFileConf::read()
 * -- there's no Config:: equivalent at all (include/config_default.inc.php
 * never sets this key, same reasoning as
 * UserListPageRenderer::webmasterIdIsLocal()).
 */
final class Patch110 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '110';
    }

    #[\Override]
    public function description(): string
    {
        return 'Move "gallery_url" parameter from config table to local configuration file';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $localConf = LegacyFileConf::read();

        $query = '
SELECT
    value
  FROM ' . Tables::config() . '
  WHERE param = :param
;';
        $row = $conn->fetchNumeric($query, [
            'param' => 'gallery_url',
        ]);
        $gallery_url = $row !== false && is_scalar($row[0]) ? (string) $row[0] : '';

        if (! empty($gallery_url)) {
            $paths = CurrentPaths::get();
            // let's try to write it in the local configuration file
            $local_conf = $paths->local . 'config/config.inc.php';
            if (isset($localConf['local_dir_site'])) {
                $local_conf = $paths->siteLocal . 'config/config.inc.php';
            }

            $conf_line = '$conf[\'gallery_url\'] = \'' . $gallery_url . '\';';

            if (! is_file($local_conf)) {
                $config_file_contents_new = "<?php\n" . $conf_line . "\n?>";
            } else {
                // we have to update the local conf
                $config_file_contents = @file_get_contents($local_conf);
                if ($config_file_contents === false) {
                    $error = 'Cannot load ' . $local_conf . ', add by hand: ' . $conf_line;

                    \Piwigo\Core\PageState::current()->addError($error);
                    echo $error;
                } else {
                    $php_end_tag = strrpos($config_file_contents, '?>');
                    if ($php_end_tag === false) {
                        // the file is empty
                        $config_file_contents_new = "<?php\n" . $conf_line . "\n?>";
                    } else {
                        $config_file_contents_new =
                          substr($config_file_contents, 0, $php_end_tag) . "\n"
                          . $conf_line . "\n"
                          . substr($config_file_contents, $php_end_tag)
                        ;
                    }
                }
            }

            if (isset($config_file_contents_new)) {
                if (! @file_put_contents($local_conf, $config_file_contents_new)) {
                    $error = 'Cannot write into local configuration file ' . $local_conf . ', add by hand: ' . $conf_line;

                    \Piwigo\Core\PageState::current()->addError($error);
                    echo $error;
                }
            }
        }

        $query = '
DELETE
  FROM ' . Tables::config() . '
  WHERE param = :param
;';
        $conn->executeStatement($query, [
            'param' => 'gallery_url',
        ]);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}

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
 * Former install/db/108-database.php (P23 sub-batch 8g-2), ported
 * verbatim including two historical warts kept deliberately: the
 * var_dump() debug leftover (its output went into the upgrade progress
 * stream on real 2010-era upgrades, so dropping it would change output)
 * and the null $replacement args to preg_replace/str_ireplace (PHP 8
 * deprecation-warns but behaves as '').
 */
final class Patch108 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '108';
    }

    #[\Override]
    public function description(): string
    {
        return 'add order parameters to bdd #2';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $order_regex = '#^(( *)(id|file|name|date_available|date_creation|hit|average_rate|comment|author|filesize|width|height|high_filesize|high_width|high_height|rank) (ASC|DESC),{1}){1,}$#';

        // local file is writable
        if (is_writable($local_file = PHPWG_ROOT_PATH . 'local/config/config.inc.php')) {
            $order_by = str_ireplace(
                ['order by ', 'asc', 'desc'],
                [null, 'ASC', 'DESC'],
                trim((string) $conf['order_by_inside_category'])
            );

            // for a simple patern
            if (preg_match($order_regex, $order_by . ',')) {
                $order_by = 'ORDER BY ' . $order_by;
                // update database
                $query = '
    UPDATE ' . Tables::config() . '
      SET value = \'' . preg_replace('# rank (ASC|DESC)(,?)#', '', $order_by) . '\'
      WHERE param = \'order_by\'
    ;';
                $conn->executeStatement($query);
                $query = '
    UPDATE ' . Tables::config() . '
      SET value = \'' . $order_by . '\'
      WHERE param = \'order_by_inside_category\'
    ';
                $conn->executeStatement($query);

                // update local file (delete lines)
                $local_config = file($local_file);
                $new_local_config = [];
                foreach ($local_config as $line) {
                    if (! str_contains($line, 'order_by')) {
                        $new_local_config[] = $line;
                    }
                }
                var_dump($new_local_config);
                file_put_contents(
                    $local_file,
                    implode('', $new_local_config)
                );
            }
            // for a complex patern
            else {
                // update database with default param
                $query = '
    UPDATE ' . Tables::config() . '
      SET value = \'ORDER BY date_available DESC, file ASC, id ASC\'
      WHERE param = \'order_by\'
    ;';
                $conn->executeStatement($query);
                $query = '
    UPDATE ' . Tables::config() . '
      SET value = \'ORDER BY date_available DESC, file ASC, id ASC\'
      WHERE param = \'order_by_inside_category\'
    ';
                $conn->executeStatement($query);

                // update local file (rename lines)
                $local_config = file_get_contents($local_file);
                $new_local_config = str_replace(
                    ["['order_by']", "['order_by_inside_category']"],
                    ["['order_by_custom']", "['order_by_inside_category_custom']"],
                    $local_config
                );
                file_put_contents($local_file, $new_local_config);
            }
        }
        // local file is locked
        else {
            // update database with default param
            $query = '
  UPDATE ' . Tables::config() . '
    SET value = \'ORDER BY date_available DESC, file ASC, id ASC\'
    WHERE param = \'order_by\'
  ;';
            $conn->executeStatement($query);
            $query = '
  UPDATE ' . Tables::config() . '
    SET value = \'ORDER BY date_available DESC, file ASC, id ASC\'
    WHERE param = \'order_by_inside_category\'
  ';
            $conn->executeStatement($query);
        }

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}

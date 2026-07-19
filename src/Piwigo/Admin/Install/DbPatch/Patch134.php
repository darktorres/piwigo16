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

/**
 * Former install/db/134-database.php (P23 sub-batch 8g-2).
 */
final class Patch134 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '134';
    }

    #[\Override]
    public function description(): string
    {
        return 'replace dblayer to "mysqli" if available.';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $config_file = PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'config/database.inc.php';

        if (extension_loaded('mysqli') and $conf['dblayer'] == 'mysql' and is_writable($config_file)) {
            $file_content = file_get_contents($config_file);
            $file_content = preg_replace(
                '#\$conf\[\'dblayer\'\]( *)=( *)\'mysql\';#',
                '$conf[\'dblayer\']$1=$2\'mysqli\';',
                $file_content
            );
            file_put_contents($config_file, $file_content);
        }

        echo "\n" . $this->description() . "\n";
    }
}

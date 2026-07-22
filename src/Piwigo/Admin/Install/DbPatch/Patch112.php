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
use Piwigo\Core\CurrentPaths;
use Piwigo\Db\Tables;

/**
 * Former install/db/112-database.php (P23 sub-batch 8g-2).
 */
final class Patch112 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '112';
    }

    #[\Override]
    public function description(): string
    {
        return 'Change combined dir';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        // Add column
        $query = 'DELETE FROM ' . Tables::config() . '
  WHERE param IN (:params) ';

        $conn->executeStatement($query, [
            'params' => ['local_data_dir_checked', 'combined_dir_checked'],
        ], [
            'params' => ArrayParameterType::STRING,
        ]);

        $dir = CurrentPaths::get()->local . 'combined/';
        if (is_dir($dir)) {
            $cssFiles = glob($dir . '*.css');
            if ($cssFiles !== false) {
                foreach ($cssFiles as $file) {
                    @unlink($file);
                }
            }
            $jsFiles = glob($dir . '*.js');
            if ($jsFiles !== false) {
                foreach ($jsFiles as $file) {
                    @unlink($file);
                }
            }
            @unlink($dir . 'index.htm');
            @rmdir($dir);
        }

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}

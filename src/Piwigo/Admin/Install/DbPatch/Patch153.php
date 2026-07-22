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
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;

/**
 * Former install/db/153-database.php (P23 sub-batch 8g-3). The local
 * value_display_fromto() helper became a private method; its local
 * `$conf = []` deliberately shadows the global so the include of
 * local/config/config.inc.php is read without touching real state --
 * identical inside a method.
 */
final class Patch153 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '153';
    }

    #[\Override]
    public function description(): string
    {
        return 'Show date period of an album';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $value = $this->valueDisplayFromto();

        $batchWriter = new BatchWriter($conn);
        $batchWriter->singleInsert(Tables::config(), [
            'param' => 'display_fromto',
            'value' => $value,
            'comment' => $this->description(),
        ]);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }

    /**
     * Former local value_display_fromto() function.
     */
    private function valueDisplayFromto(): string
    {
        $file = CurrentPaths::get()->local . 'config/config.inc.php';
        if (file_exists($file)) {
            $conf = [];
            include $file;
            if (isset($conf['display_fromto']) and $conf['display_fromto']) {
                return 'true';
            }
        }

        return 'false';
    }
}

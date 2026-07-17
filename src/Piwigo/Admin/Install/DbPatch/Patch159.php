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
 * Former install/db/159-database.php (P23 sub-batch 8g-3).
 */
final class Patch159 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '159';
    }

    #[\Override]
    public function description(): string
    {
        return 'add index on images.path';
    }

    #[\Override]
    public function apply(): void
    {
        $query = '
ALTER TABLE ' . Tables::images() . '
  ADD INDEX `images_i7` (`path`)
;';
        MysqliDb::query($query);

        echo "\n" . $this->description() . "\n";
    }
}

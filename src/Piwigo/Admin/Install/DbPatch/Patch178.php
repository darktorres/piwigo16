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
 * Former install/db/178-database.php (P23 sub-batch 8g-3). Effectively a
 * no-op in the original too -- its one conf_update_param() call was
 * already commented out ("let the $conf['filters_views'] be written in
 * config table when the admin will change settings in administration").
 */
final class Patch178 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '178';
    }

    #[\Override]
    public function description(): string
    {
        return 'add config parameters to the gallery filters';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        // let the $conf['filters_views'] be written in config table when the admin will change settings in administration.
        //
        // conf_update_param('filters_views', $conf['default_filters_views']);

        echo "\n" . $this->description() . "\n";
    }
}

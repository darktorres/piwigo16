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
use Piwigo\Db\BatchWriter;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;

/**
 * Former install/db/104-database.php (P23 sub-batch 8g-2). The bare
 * load_conf_from_db()/boolean_to_string() calls became
 * ConfigDb::loadConfFromDb(conn: $conn)/MysqliDb::booleanToString(),
 * later retargeted onto CurrentConfigService::get() (Legacy Coupling
 * Retirement Phase 8, 8d). The "is this param already in the config
 * table" check moved off `isset($conf[$param_name])` onto a direct row
 * fetch: Config::$data is pre-populated with every SCHEMA default at
 * boot, so Config::has() can't tell "no DB row" from "SCHEMA default,
 * no DB row" the way the old bare-$conf dual-write could -- this patch's
 * whole point is inserting a DB row for params an old install never got,
 * so the row's real existence is what has to be tested.
 */
final class Patch104 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '104';
    }

    #[\Override]
    public function description(): string
    {
        return 'Add upload form parameters in database';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        \Piwigo\Config\CurrentConfigService::get()->loadConfFromDb();

        $existingParams = $conn->fetchFirstColumn('SELECT param FROM ' . Tables::config() . ';');

        $upload_form_config = [
            'websize_resize' => true,
            'websize_maxwidth' => 800,
            'websize_maxheight' => 600,
            'websize_quality' => 95,
            'thumb_maxwidth' => 128,
            'thumb_maxheight' => 96,
            'thumb_quality' => 95,
            'thumb_crop' => false,
            'thumb_follow_orientation' => true,
            'hd_keep' => true,
            'hd_resize' => false,
            'hd_maxwidth' => 2000,
            'hd_maxheight' => 2000,
            'hd_quality' => 95,
        ];

        $inserts = [];

        foreach ($upload_form_config as $param_shortname => $param) {
            $param_name = 'upload_form_' . $param_shortname;

            if (! in_array($param_name, $existingParams, true)) {
                array_push(
                    $inserts,
                    [
                        'param' => $param_name,
                        'value' => SqlDialect::booleanToString($param),
                    ]
                );
            }
        }

        if (count($inserts) > 0) {
            new BatchWriter($conn)
                ->massInsert(
                    Tables::config(),
                    array_keys($inserts[0]),
                    $inserts
                );
        }

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}

<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Config\ConfigDb;
use Piwigo\Db\MysqliDb;
use Piwigo\Db\Tables;

/**
 * Former install/db/104-database.php (P23 sub-batch 8g-2). The bare
 * load_conf_from_db()/boolean_to_string() calls became
 * ConfigDb::loadConfFromDb()/MysqliDb::booleanToString().
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
    public function apply(): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        ConfigDb::loadConfFromDb();

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

            if (! isset($conf[$param_name])) {
                $conf[$param_name] = $param;

                array_push(
                    $inserts,
                    [
                        'param' => $param_name,
                        'value' => MysqliDb::booleanToString($param),
                    ]
                );
            }
        }

        if (count($inserts) > 0) {
            MysqliDb::massInserts(
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

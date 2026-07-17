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
use Piwigo\Core\ArrayHelper;

/**
 * Former install/db/181-database.php (P23 sub-batch 8g-3) -- the last
 * upstream one-shot patch. The bare safe_unserialize() became
 * ArrayHelper::safeUnserialize() (real fix for the broken-at-HEAD
 * coupling, same as Patch177).
 */
final class Patch181 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '181';
    }

    #[\Override]
    public function description(): string
    {
        return 'add "expert mode" in filters_views for gallery search';
    }

    #[\Override]
    public function apply(): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        ConfigDb::loadConfFromDb();

        // if the filters_views is not already registered in the config table, no need
        // to update it because it will be initialized with all filters
        if (isset($conf['filters_views'])) {
            $conf['filters_views'] = ArrayHelper::safeUnserialize($conf['filters_views']);

            if (! isset($conf['filters_views']['expert'])) {
                $conf['filters_views']['expert'] = $conf['default_filters_views']['expert'];
                ConfigDb::confUpdateParam('filters_views', $conf['filters_views']);
            }
        }

        echo "\n" . $this->description() . "\n";
    }
}

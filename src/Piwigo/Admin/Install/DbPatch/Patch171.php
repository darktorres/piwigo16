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
 * Former install/db/171-database.php (P23 sub-batch 8g-3).
 */
final class Patch171 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '171';
    }

    #[\Override]
    public function description(): string
    {
        return 'convert file configuration setting webmaster_id to database';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        // If the webmaster_id has been modified, it must be present in
        // local/config/config.inc.php, so read it from there directly --
        // CurrentConfig::webmasterId() doesn't see a site's
        // local/config/config.inc.php override on this path (same
        // reasoning as the rest of this file family).
        $localConf = LegacyFileConf::read();
        $webmasterId = $localConf['webmaster_id'] ?? 1;
        \Piwigo\Config\CurrentConfigService::get()->confUpdateParam('webmaster_id', is_scalar($webmasterId) ? $webmasterId : 1);

        echo "\n" . $this->description() . "\n";
    }
}

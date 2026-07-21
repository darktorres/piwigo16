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
        /** @var array<string, mixed> $conf */
        global $conf;

        // If the webmaster_id has been modified, it must be present in local/config/config.inc.php
        // so we retrieve it and insert it into the database.
        $webmasterId = $conf['webmaster_id'] ?? 1;
        \Piwigo\Config\CurrentConfigService::get()->confUpdateParam('webmaster_id', is_scalar($webmasterId) ? $webmasterId : 1);

        echo "\n" . $this->description() . "\n";
    }
}

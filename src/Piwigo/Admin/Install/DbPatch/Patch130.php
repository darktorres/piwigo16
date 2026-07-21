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
use Piwigo\Db\Tables;

/**
 * Former install/db/130-database.php (P23 sub-batch 8g-2).
 */
final class Patch130 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '130';
    }

    #[\Override]
    public function description(): string
    {
        return 'add "email" field in comments table';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = 'ALTER TABLE `' . Tables::comments() . '` ADD `email` varchar(255) default NULL;';
        $conn->executeStatement($query);

        $configService = \Piwigo\Config\CurrentConfigService::get();
        $configService->confUpdateParam('comments_author_mandatory', 'false');
        $configService->confUpdateParam('comments_email_mandatory', 'false');

        echo "\n" . $this->description() . "\n";
    }
}

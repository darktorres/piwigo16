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
 * Former install/db/180-database.php (P23 sub-batch 8g-3). A deliberate
 * no-op: upstream emptied the body after deciding against the related-tags
 * configuration, but the id must stay in the ledger sequence for
 * galleries that already applied it. The original had no echo either.
 */
final class Patch180 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '180';
    }

    #[\Override]
    public function description(): string
    {
        return 'add config parameters to display by default or not "related tags" [Aborted] ';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        // The contents of the database upgrade have been emptied
        // due to the fact that we decicded to not use this configuration
        // in the end for the related tags display
        // We need to keep this database ugrapde to avoid errors
        // for those that have already passed the "180"th upgrade.
        // This database upgrade now does nothing.
    }
}

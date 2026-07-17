<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

/**
 * Former install/db/121-database.php (P23 sub-batch 8g-2). A no-op in the
 * original too -- the htaccess hotlink work was cancelled upstream (see
 * plugin "Hotlink Compatibility"); only the ledger row and echo remain.
 * Reference: piwigo.org/doc/doku.php?id=user_documentation:htaccess_and_hotlink_in_2.4
 */
final class Patch121 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '121';
    }

    #[\Override]
    public function description(): string
    {
        return 'add/append htaccess for hotlinks (cancelled, see plugin "Hotlink Compatibility")';
    }

    #[\Override]
    public function apply(): void
    {
        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}

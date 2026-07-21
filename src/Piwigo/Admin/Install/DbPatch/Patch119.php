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
use Piwigo\Image\DerivativeCacheService;

/**
 * Former install/db/119-database.php (P23 sub-batch 8g-2). The bare
 * clear_derivative_cache() call became
 * DerivativeCacheService::clearDerivativeCache() (same target its
 * frozen-script delegate forwarded to).
 */
final class Patch119 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '119';
    }

    #[\Override]
    public function description(): string
    {
        return 'Reset derivative configuration to include XXS and XS sizes.';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        new DerivativeCacheService()
            ->clearDerivativeCache();

        $derivative_conf_file = PHPWG_ROOT_PATH . $conf['data_location'] . 'derivatives.dat';
        if (is_file($derivative_conf_file)) {
            unlink($derivative_conf_file);
        }

        \Piwigo\Config\CurrentConfigService::get()->confUpdateParam('derivatives', '');

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}

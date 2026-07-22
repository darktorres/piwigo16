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
use Piwigo\Core\CurrentPaths;
use Piwigo\Image\DerivativeCacheService;

/**
 * Former install/db/119-database.php (P23 sub-batch 8g-2). The bare
 * clear_derivative_cache() call became
 * DerivativeCacheService::clearDerivativeCache() (same target its
 * frozen-script delegate forwarded to). data_location is read via
 * LegacyFileConf::read() -- Config::dataLocation() doesn't see a site's
 * local/config/config.inc.php override on this path (see InstallWizard's
 * own constructor docblock).
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
        new DerivativeCacheService()
            ->clearDerivativeCache();

        $localConf = LegacyFileConf::read();
        $data_location = is_string($localConf['data_location'] ?? null) ? $localConf['data_location'] : '_data/';

        $derivative_conf_file = CurrentPaths::get()->root . $data_location . 'derivatives.dat';
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

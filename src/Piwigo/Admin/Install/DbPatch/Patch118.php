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
use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Html\HtmlService;
use Piwigo\Url\UrlService;

/**
 * Former install/db/118-database.php (P23 sub-batch 8g-2).
 */
final class Patch118 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '118';
    }

    #[\Override]
    public function description(): string
    {
        return 'Automatically activate mobile theme.';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $urlService = new UrlService(new HtmlService());
        $fsEntry = new ExtensionScanner()
            ->scan(ExtensionType::Theme, $urlService)['smartpocket'] ?? null;
        new ExtensionLifecycle(
            new ExtensionRepository($conn),
            new PemCatalog(new ZipExtractor()),
            $urlService,
            CurrentConfigService::get(),
        )->performAction(ExtensionType::Theme, 'activate', 'smartpocket', $fsEntry);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}

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
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Url\UrlService;

/**
 * Former install/db/90-database.php (P23 sub-batch 8g-1). The bare
 * DB_CHARSET/PWG_CHARSET constant reads became UpgradeCharset accessors
 * (shell constant first, Patch65's mid-run value otherwise), same as
 * Patch85.
 */
final class Patch90 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '90';
    }

    #[\Override]
    public function description(): string
    {
        return 'Add a table to manage languages.';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = '
CREATE TABLE ' . Tables::languages() . " (
  `id` varchar(64) NOT NULL default '',
  `version` varchar(64) NOT NULL default '0',
  `name` varchar(64) default NULL,
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM";

        if (UpgradeCharset::dbCharset() === 'utf8') {
            $query .= ' DEFAULT CHARACTER SET utf8';
        }

        $conn->executeStatement($query);

        // Fill table

        $urlService = new UrlService(new HtmlService());
        $lifecycle = new ExtensionLifecycle(
            new ExtensionRepository($conn),
            new PemCatalog(new ZipExtractor()),
            $urlService,
            CurrentConfigService::get(),
        );
        $fs_languages = new ExtensionScanner()
            ->scan(ExtensionType::Language, $urlService, UpgradeCharset::pwgCharset());

        foreach ($fs_languages as $language_code => $fs_language) {
            $lifecycle->performAction(ExtensionType::Language, 'activate', $language_code, $fs_language);
        }

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}

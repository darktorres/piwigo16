<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\Projection\LanguageScanRow;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Bootstrap\CoreDomainAccessor;
use Piwigo\Bootstrap\ExtendedDomainAccessor;
use Piwigo\Bootstrap\InstallBootstrap;
use Piwigo\Bootstrap\PresentationAccessor;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\CurrentUser;

/**
 * Seeds base install data against the already-migrated schema --
 * extracted out of InstallWizard::performInstall()'s own former step-2
 * block: config.sql, config params, language activation, core theme/plugin
 * activation, the sites row, and webmaster/guest user creation. The
 * install sentinel stamp write stays on InstallWizard itself: it's gated
 * on InstallWizard's own cumulative $errors (including a possible earlier
 * .env-write failure), cross-cutting state this class has no reason to
 * know about.
 */
final class InstallDataSeeder
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Lang $lang,
        private readonly CurrentConfigService $currentConfigService,
        private readonly CurrentConfig $currentConfig,
        private readonly CurrentUser $currentUser,
        private readonly EventDispatcher $eventDispatcher,
        private readonly InstallServiceFactory $installServiceFactory,
    ) {}

    public function seed(Connection $conn, string $language, ?LanguageScanRow $languageScanRow, string $adminName, string $adminPass1, string $adminMail): void
    {
        // We fill the tables with basic informations
        InstallService::executeSqlfile(
            $conn,
            $this->paths->root . 'install/config.sql',
        );

        $configService = $this->currentConfigService->get();
        $configService->confUpdateParam('secret_key', sha1(random_bytes(1000)));
        $configService->confUpdateParam('gallery_title', $this->lang->t('Just another Piwigo gallery'));

        $configService->confUpdateParam(
            'page_banner',
            '<h1>%gallery_title%</h1>' . "\n\n<p>" . $this->lang->t('Welcome to my photo gallery') . '</p>'
        );

        // fill languages table, only activate the current language
        // Deliberately a fresh DbConnection::build(), not the outer $conn
        // (still needed as InstallWizard's own $this->conn, unshadowed, by
        // BatchWriter/PasswordRepository/userService() calls further down
        // this same method) -- matches this call's own pre-existing "fresh
        // connection" shape, just extended to the new repository too.
        $urlService = PresentationAccessor::urlService();
        $languageActivationConn = DbConnection::build();
        new ExtensionLifecycle(
            $this->lang,
            new ExtensionRepository(EntityManagerFactory::build($languageActivationConn)),
            new PemCatalog(new ZipExtractor(), InstallBootstrap::currentLogger(), $this->paths, $this->currentConfig),
            $urlService,
            $configService,
            ExtendedDomainAccessor::activityService(),
            CoreDomainAccessor::userService(),
            PresentationAccessor::htmlService(),
            $this->currentConfig,
            $this->paths,
            $this->currentUser,
            $this->eventDispatcher,
            PresentationAccessor::pluginRegistry(),
            PresentationAccessor::themeRegistry(),
            EntityManagerFactory::build($languageActivationConn),
        )->performAction(ExtensionType::Language, 'activate', $language, $languageScanRow);

        // fill CurrentConfig::$data from the freshly-seeded config table
        $configService->loadConfFromDb();

        InstallService::activateCoreThemes($this->lang, $this->currentUser, $this->currentConfigService, $this->currentConfig, $this->paths, $this->eventDispatcher);
        InstallService::activateCorePlugins($this->paths, $this->currentUser, $this->currentConfig);

        $insert = [
            'id' => 1,
            'galleries_url' => $this->paths->root . 'galleries/',
        ];
        new BatchWriter($conn)
            ->massInsert('sites', array_keys($insert), [$insert]);
        new DbInfo($conn)
            ->resyncIdentitySequence('sites');

        // webmaster admin user
        $inserts = [
            [
                'id' => 1, // must be the same value as webmaster_id in config.sql
                'username' => $adminName,
                'password' => $this->installServiceFactory->passwordService($conn)
                    ->hash($adminPass1),
                'mail_address' => $adminMail,
            ],
            [
                'id' => 2,
                'username' => 'guest',
            ],
        ];
        new BatchWriter($conn)
            ->massInsert('users', array_keys($inserts[0]), $inserts);
        new DbInfo($conn)
            ->resyncIdentitySequence('users');

        $this->installServiceFactory->userService($conn)
            ->createUserInfos([UserId::from(1), UserId::from(2)], LangCode::from($language));
    }
}

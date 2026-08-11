<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\PhotosAddApplicationsPageRenderer;
use Piwigo\Admin\PhotosAddDirectPageRenderer;
use Piwigo\Admin\PhotosAddFtpPageRenderer;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\WsContext;
use Piwigo\Db\DbCredentials;
use Piwigo\Image\ImageService;
use Piwigo\Metadata\MetadataService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Backs the "photos_add" admin page's tab dispatch. The "direct",
 * "applications", and "ftp" tabs are pure template/form display, rendered
 * respectively by PhotosAddDirectPageRenderer, PhotosAddApplicationsPageRenderer,
 * and PhotosAddFtpPageRenderer; the actual upload pipeline lives in
 * UploadService.
 *
 * admin.php gates every page behind check_status(AccessLevel::Administrator)
 * before dispatch, so this controller does not duplicate that check.
 */
final readonly class PhotosAddSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private CurrentLogger $currentLogger,
        private StorageRegistry $storageRegistry,
        private EventDispatcher $eventDispatcher,
        private CurrentTemplate $currentTemplate,
        private ConfigService $configService,
        private EntityManagerInterface $entityManager,
        private PhotosAddDirectPageRenderer $photosAddDirectPageRenderer,
        private ActivityService $activityService,
        private MetadataService $metadataService,
        private ImageService $imageService,
        private CurrentConfig $currentConfig,
        private WsContext $wsContext,
        private CurrentUser $currentUser,
        private Paths $paths,
        private DbCredentials $dbCredentials,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = $this->currentTemplate->get();

        // getUploadFormConfig()'s return value is unused here -- see this
        // class's own docblock: the upload pipeline lives in UploadService,
        // not this sub-controller.
        new UploadService($this->lang, $this->currentLogger, $this->storageRegistry, $this->eventDispatcher, $this->configService, $this->entityManager, $this->activityService, $this->metadataService, $this->imageService, $this->currentConfig, $this->wsContext, $this->currentUser, $this->paths, $this->dbCredentials)
            ->getUploadFormConfig();

        // admin.php's own shared check_input_parameter('section', ...,
        // '/^[a-z]+[a-z_\/-]*(\.php)?$/i') already runs before dispatch and
        // blocks any '.' other than one trailing ".php" -- real path
        // traversal is already unreachable. This sub-controller never
        // splices $_GET['section'] straight into an include path; the
        // tighter 3-value allowlist below is defense-in-depth, not a fix
        // for an actively-exploitable hole.
        $section = $request->getQueryParams()['section'] ?? null;
        $tab = is_string($section) ? $section : 'direct';

        // backward compatibility
        if ($tab === 'ploader') {
            $tab = 'applications';
        }

        if (! in_array($tab, ['direct', 'applications', 'ftp'], true)) {
            $tab = 'direct';
        }

        $tabsheet = new Tabsheet();
        $tabsheet->setId('photos_add');
        $tabsheet->select($tab, $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        $template->setFilenames([
            'photos_add' => 'photos_add_' . $tab . '.tpl',
        ]);

        if ($tab === 'direct') {
            $this->photosAddDirectPageRenderer
                ->render();
        } elseif ($tab === 'applications') {
            new PhotosAddApplicationsPageRenderer()
                ->render($this->lang, $this->currentTemplate);
        } else {
            new PhotosAddFtpPageRenderer()
                ->render($this->lang, $this->currentTemplate);
        }
    }
}

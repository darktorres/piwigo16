<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\PhotosAddApplicationsPageRenderer;
use Piwigo\Admin\PhotosAddDirectPageRenderer;
use Piwigo\Admin\PhotosAddFtpPageRenderer;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Template\Template;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/photos_add.php's own tab-dispatch shell (page slug
 * "photos_add"). The 3 tab bodies are typed renderers:
 * PhotosAddDirectPageRenderer ("direct", P23 batch 6e, folding in
 * photos_add_direct_prepare.inc.php's form-prep body too) /
 * PhotosAddApplicationsPageRenderer / PhotosAddFtpPageRenderer -- pure
 * template/form display, no security-sensitive logic; the real upload
 * pipeline they call into (add_uploaded_file() et al) is fully migrated
 * to UploadService (see that class + its own SEC-16/SEC-21 fixes).
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch, so the
 * original photos_add.php's own (redundant) check_status() call is
 * dropped here.
 */
final class PhotosAddSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly \Piwigo\Core\CurrentLogger $currentLogger,
        private readonly StorageRegistry $storageRegistry,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly \Piwigo\Config\ConfigService $configService,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = $this->currentTemplate->get();

        // upload form config is loaded here to match the original page's
        // own behavior (validated/used by the tab templates), even though
        // this sub-controller doesn't read it directly itself.
        new UploadService($this->currentLogger, $this->storageRegistry, $this->eventDispatcher, $this->configService, $this->entityManager)
            ->getUploadFormConfig();

        // admin.php's own shared check_input_parameter('section', ...,
        // '/^[a-z]+[a-z_\/-]*(\.php)?$/i') already runs before dispatch and
        // blocks any '.' other than one trailing ".php" -- real path
        // traversal is already unreachable. This sub-controller still
        // never spliced $_GET['section'] straight into the include path
        // (unlike the original photos_add.php, which did): a tighter
        // 3-value allowlist is real defense-in-depth, not a fix for an
        // actively-exploitable hole.
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
        $tabsheet->set_id('photos_add');
        $tabsheet->select($tab);
        $tabsheet->assign($this->currentTemplate);

        $template->set_filenames([
            'photos_add' => 'photos_add_' . $tab . '.tpl',
        ]);

        if ($tab === 'direct') {
            \Piwigo\Bootstrap\AdminAccessor::photosAddDirectPageRenderer()
                ->render();
        } elseif ($tab === 'applications') {
            new PhotosAddApplicationsPageRenderer()
                ->render($this->currentTemplate);
        } else {
            new PhotosAddFtpPageRenderer()
                ->render($this->currentTemplate);
        }
    }
}

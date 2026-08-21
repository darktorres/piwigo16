<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\PhotosAddApplicationsPageRenderer;
use Piwigo\Admin\PhotosAddDirectPageRenderer;
use Piwigo\Admin\PhotosAddFtpPageRenderer;
use Piwigo\Admin\Tabsheet;
use Piwigo\Bootstrap\AdminAccessor;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\Lang;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
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
        private EventDispatcher $eventDispatcher,
        private CurrentTemplate $currentTemplate,
        private PhotosAddDirectPageRenderer $photosAddDirectPageRenderer,
        private Renderer $renderer,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): AdminPageResult
    {
        // getUploadFormConfig()'s return value is unused here -- see this
        // class's own docblock: the upload pipeline lives in UploadService,
        // not this sub-controller.
        AdminAccessor::uploadService()
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

        if (! in_array($tab, ['direct', 'applications', 'ftp'], true)) {
            $tab = 'direct';
        }

        $tabsheet = new Tabsheet();
        $tabsheet->setId('photos_add');
        $tabsheet->select($tab, $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate, $this->renderer);

        if ($tab === 'direct') {
            return $this->photosAddDirectPageRenderer
                ->render();
        }
        if ($tab === 'applications') {
            return new PhotosAddApplicationsPageRenderer()
                ->render($this->lang, $this->currentTemplate, $this->renderer);
        }

        return new PhotosAddFtpPageRenderer()
            ->render($this->lang, $this->currentTemplate, $this->renderer);
    }
}

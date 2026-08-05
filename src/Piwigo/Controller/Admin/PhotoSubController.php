<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\PictureCoiPageRenderer;
use Piwigo\Admin\PictureFormatsPageRenderer;
use Piwigo\Admin\PictureModifyPageRenderer;
use Piwigo\Admin\Tabsheet;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Request\PhotoDispatchRequest;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/photo.php's own tab-dispatch shell (page slug "photo").
 * Its 3 tab bodies are all typed renderers: PictureModifyPageRenderer
 * ("properties", P23 batch 6d), PictureCoiPageRenderer/
 * PictureFormatsPageRenderer ("coi"/"formats", already built in the earlier
 * Config batch and additionally directly reachable as their own top-level
 * `?page=` slugs). Same "fold the shell into the sub-controller, keep tab
 * bodies as their own classes" shape as AlbumSubController.
 *
 * The legacy admin/photo.php had a dynamic `include admin/photo_' .
 * $page['tab'] . '.php'` fallback for any tab outside the known 3 -- no
 * such file has ever existed on disk, and admin.php's own shared
 * check_input_parameter('tab', ..., '/^[a-zA-Z\d_-]+$/') already blocks
 * real path traversal on the 'tab' param before dispatch even reaches this
 * class, the same non-issue AlbumSubController's own docblock documents for
 * the identical pattern in admin/album.php. This class's own KNOWN_TABS
 * allowlist (with a safe 'properties' fallback) is real defense-in-depth,
 * not a fix for an actively-exploitable hole -- it also drops the dynamic
 * `include` entirely, so tab dispatch no longer depends on filesystem state.
 */
final class PhotoSubController implements AdminSubControllerInterface
{
    private const array KNOWN_TABS = ['properties', 'coi', 'formats'];

    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly ImageStdParams $imageStdParams,
        private readonly CurrentTemplate $currentTemplate,
        private readonly PictureModifyPageRenderer $pictureModifyPageRenderer,
        private readonly PictureCoiPageRenderer $pictureCoiPageRenderer,
        private readonly ImageService $imageService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
        private readonly InputValidator $inputValidator,
        private readonly EventDispatcher $eventDispatcher,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        // Phase 2 global-residual sweep: $page is a local scratch array
        // for this method's own body only (no longer `global $page;`),
        // same shape as Section\SectionPopulator::populate()'s own
        // equivalent fix (Track A5.2e).
        /** @var array<string, mixed> $page */
        $page = [];
        $template = $this->currentTemplate->get();

        $this->accessControl->checkStatus(AccessLevel::Administrator);

        $photoDispatch = PhotoDispatchRequest::fromGlobals(self::KNOWN_TABS, $this->inputValidator);
        $get_image_id = $photoDispatch->imageId;

        $adminPhotoBaseUrl = $this->urlService->getRootUrl() . 'admin.php?page=photo-' . $get_image_id;
        $this->coreTabs->setContext(new CoreTabsContext(adminPhotoBaseUrl: $adminPhotoBaseUrl));

        // retrieving direct information about picture
        $imageConn = DbConnection::build();
        $page['image'] = $this->imageService
            ->getImageInfos($get_image_id, $this->htmlRenderer, true);

        $tab = $photoDispatch->tab;

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('photo');
        $tabsheet->select($tab, $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        $template->assign(
            [
                'ADMIN_PAGE_TITLE' => $this->lang->t('Edit photo') . ' <span class="image-id">#' . $get_image_id . '</span>',
            ]
        );

        if ($tab === 'properties') {
            $this->pictureModifyPageRenderer
                ->render($adminPhotoBaseUrl);
        } elseif ($tab === 'coi') {
            $this->pictureCoiPageRenderer
                ->render();
        } elseif ($this->currentConfig->isFormatsEnabled()) {
            new PictureFormatsPageRenderer()
                ->render($this->lang, $this->accessControl, $this->urlService, $this->imageStdParams, $this->currentTemplate, $this->htmlRenderer, $this->inputValidator);
        }
    }
}

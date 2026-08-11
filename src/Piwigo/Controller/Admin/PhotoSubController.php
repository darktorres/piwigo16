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
use Piwigo\Controller\Admin\Projection\PhotoSubControllerPageContext;
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
 * Backs the "photo" admin page's tab dispatch. The three tabs are rendered
 * by PictureModifyPageRenderer ("properties"), PictureCoiPageRenderer
 * ("coi"), and PictureFormatsPageRenderer ("formats") -- the latter two are
 * also directly reachable as their own top-level `?page=` slugs.
 *
 * Tab dispatch is restricted to the KNOWN_TABS allowlist with a safe
 * "properties" fallback; there is no dynamic `include` based on user input.
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
        $tabsheet->setId('photo');
        $tabsheet->select($tab, $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        $template->assignContext(new PhotoSubControllerPageContext(
            adminPageTitle: $this->lang->t('Edit photo') . ' <span class="image-id">#' . $get_image_id . '</span>',
        ));

        if ($tab === 'properties') {
            $this->pictureModifyPageRenderer
                ->render($adminPhotoBaseUrl);
        } elseif ($tab === 'coi') {
            $this->pictureCoiPageRenderer
                ->render();
        } elseif ($this->currentConfig->isFormatsEnabled) {
            new PictureFormatsPageRenderer()
                ->render($this->lang, $this->accessControl, $this->urlService, $this->imageStdParams, $this->currentTemplate, $this->htmlRenderer, $this->inputValidator, $this->currentConfig);
        }
    }
}

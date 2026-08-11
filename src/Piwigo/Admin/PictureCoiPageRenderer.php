<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Projection\PictureCoiPageContext;
use Piwigo\Admin\Request\PictureCoiRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeUrlCodec;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Validation\InputValidator;

/**
 * Ported from admin/picture_coi.php (page slug "picture_coi").
 */
final class PictureCoiPageRenderer
{
    public function __construct(
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly ImageStdParams $imageStdParams,
        private readonly CurrentTemplate $currentTemplate,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
        private readonly InputValidator $inputValidator,
        private readonly Paths $paths,
    ) {}

    public function render(): void
    {
        $template = $this->currentTemplate->get();

        $htmlRenderer = $this->htmlRenderer;
        $conn = DbConnection::build();

        $this->accessControl->checkStatus(AccessLevel::Administrator);

        $pictureCoiRequest = PictureCoiRequest::fromGlobals($this->inputValidator);
        $image_id = $pictureCoiRequest->imageId;
        if ($image_id === null) {
            $htmlRenderer->pageNotFound($this->redirectService, 'Requested photo does not exist');
        }

        if ($pictureCoiRequest->isSubmitted) {
            EntityManagerFactory::build($conn)->getRepository(ImageEntity::class)
                ->updateCoi($image_id, $pictureCoiRequest->coi);
        }

        $image = EntityManagerFactory::build($conn)->getRepository(ImageEntity::class)
            ->findById($image_id);
        if ($image === null) {
            $htmlRenderer->pageNotFound($this->redirectService, 'Requested photo does not exist');
        }
        $row = $image->toArray();

        if ($pictureCoiRequest->isSubmitted) {
            $derivative_infos = [
                'path' => $row['path'],
            ];
            if (isset($row['representative_ext']) && $row['representative_ext'] !== '' && $row['representative_ext'] !== '0') {
                $derivative_infos['representative_ext'] = $row['representative_ext'];
            }

            foreach ($this->imageStdParams->getDefinedTypeMap() as $params) {
                if ($params->sizing->max_crop !== 0.0) {
                    new DerivativeCacheService($this->currentConfig, $this->paths)
                        ->deleteElementDerivatives($derivative_infos, $params->type);
                }
            }
            new DerivativeCacheService($this->currentConfig, $this->paths)
                ->deleteElementDerivatives($derivative_infos, ImageStdParams::CUSTOM);
            $uid = '&b=' . time();
            $this->currentConfig->questionMarkInUrls = true;
            $this->currentConfig->phpExtensionInUrls = true;
            if ($this->currentConfig->derivativeUrlStyle === 1) {
                $this->currentConfig->derivativeUrlStyle = 0; // auto
            }
        } else {
            $uid = '';
        }

        $title = $htmlRenderer->renderElementName($row);
        $alt = $row['file'];
        $imgUrl = DerivativeImage::url(ImageStdParams::LARGE, $row);

        $coi_raw = $row['coi'];
        $row_coi = is_string($coi_raw) ? $coi_raw : '';
        $coi = null;
        if ($row_coi !== '' && $row_coi !== '0') {
            $coi = [
                'l' => DerivativeUrlCodec::charToFraction($row_coi[0]),
                't' => DerivativeUrlCodec::charToFraction($row_coi[1]),
                'r' => DerivativeUrlCodec::charToFraction($row_coi[2]),
                'b' => DerivativeUrlCodec::charToFraction($row_coi[3]),
            ];
        }

        $cropped_derivatives = [];
        foreach ($this->imageStdParams->getDefinedTypeMap() as $params) {
            if ($params->sizing->max_crop !== 0.0) {
                $derivative = new DerivativeImage($params, new SrcImage($row), $this->currentConfig);
                $cropped_derivatives[] = [
                    'U_IMG' => $derivative->getUrl() . $uid,
                    'HTM_SIZE' => $derivative->getSizeHtm(),
                ];
            }
        }

        $template->assignContext(new PictureCoiPageContext(
            title: $title,
            alt: $alt,
            imgUrl: $imgUrl,
            coi: $coi,
            croppedDerivatives: $cropped_derivatives,
        ));
        $template->set_filename('picture_coi', 'picture_coi.tpl');

        $template->assign_var_from_handle('ADMIN_CONTENT', 'picture_coi');
    }
}

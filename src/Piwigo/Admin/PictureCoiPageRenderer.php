<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Projection\PictureCoiView;
use Piwigo\Admin\Request\PictureCoiRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeUrlCodec;
use Piwigo\Image\DerivativeUrlStyleOverride;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Template\Renderer;
use Piwigo\Validation\InputValidator;

/**
 * Ported from admin/picture_coi.php (page slug "picture_coi").
 */
final readonly class PictureCoiPageRenderer
{
    public function __construct(
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private ImageStdParams $imageStdParams,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private InputValidator $inputValidator,
        private Paths $paths,
        private EntityManagerInterface $entityManager,
        private Renderer $renderer,
    ) {}

    public function render(): AdminPageResult
    {
        $htmlRenderer = $this->htmlRenderer;

        $this->accessControl->checkStatus(AccessLevel::Administrator);

        $pictureCoiRequest = PictureCoiRequest::fromGlobals($this->inputValidator);
        $image_id = $pictureCoiRequest->imageId;
        if (! $image_id instanceof ImageId) {
            $htmlRenderer->pageNotFound($this->redirectService, 'Requested photo does not exist');
        }

        if ($pictureCoiRequest->isSubmitted) {
            $this->entityManager->getRepository(ImageEntity::class)
                ->updateCoi($image_id, $pictureCoiRequest->coi);
        }

        $image = $this->entityManager->getRepository(ImageEntity::class)
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
            // Passed explicitly to DerivativeImage below instead of mutated
            // onto the shared CurrentConfig instance -- that would
            // otherwise leak into every other consumer for the rest of
            // this request (and across requests under worker mode).
            $urlStyleOverride = new DerivativeUrlStyleOverride(
                questionMarkInUrls: true,
                phpExtensionInUrls: true,
                derivativeUrlStyle: $this->currentConfig->derivativeUrlStyle === 1 ? 0 : null, // auto
            );
        } else {
            $uid = '';
            $urlStyleOverride = null;
        }

        $alt = $row['file'];
        $imgUrl = DerivativeImage::url(ImageStdParams::LARGE, $row, $urlStyleOverride);

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
                $derivative = new DerivativeImage($params, new SrcImage($row), $this->currentConfig, $urlStyleOverride);
                $cropped_derivatives[] = [
                    'U_IMG' => $derivative->getUrl() . $uid,
                    'HTM_SIZE' => $derivative->getSizeHtm(),
                ];
            }
        }

        $adminContent = $this->renderer->render(new PictureCoiView(
            alt: $alt,
            imgUrl: $imgUrl,
            coi: $coi,
            croppedDerivatives: $cropped_derivatives,
        ));

        return new AdminPageResult(content: $adminContent);
    }
}

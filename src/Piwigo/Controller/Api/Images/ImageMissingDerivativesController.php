<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

use Override;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Api\ImageFilterQueryInput;
use Piwigo\Core\OperationError;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeUrlStyleOverride;
use Piwigo\Image\ImageFilterCriteriaBuilder;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\MissingDerivativesCriteria;
use Piwigo\Image\SrcImage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/images/actions/missing-derivatives` --
 * `pwg.getMissingDerivatives`'s real replacement, admin only. Returns a
 * page of URLs for not-yet-generated derivative thumbnails (visiting
 * each URL triggers on-the-fly generation); `nextPage` is present
 * when more results remain for the caller to
 * request with a follow-up call.
 */
final readonly class ImageMissingDerivativesController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private ImageService $imageService,
        private CurrentConfig $currentConfig,
        private ImageStdParams $imageStdParams,
        private ImageFilterCriteriaBuilder $imageFilterCriteriaBuilder,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $body = JsonBody::decode($request);
        $input = ImageMissingDerivativesInput::fromArray($body);

        $requestedTypes = $input->types;
        if ($requestedTypes === []) {
            $types = array_keys($this->imageStdParams->getDefinedTypeMap());
        } else {
            $types = array_intersect(array_keys($this->imageStdParams->getDefinedTypeMap()), $requestedTypes);
            if ($types === []) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid types.');
            }
        }

        $ids = $input->ids;
        $maxUrls = $input->maxUrls;
        $prevPage = $input->prevPage;

        $nextIdAndCount = $this->imageService->getNextIdAndCount();
        $maxId = $nextIdAndCount->nextId;
        $imageCount = $nextIdAndCount->count;

        if ($imageCount === 0) {
            return ResponseFactory::json([
                'urls' => [],
            ]);
        }

        $startId = $prevPage ?? 0;
        if ($startId <= 0) {
            $startId = $maxId;
        }

        $uid = '&b=' . time();

        $urlStyleOverride = new DerivativeUrlStyleOverride(
            questionMarkInUrls: true,
            phpExtensionInUrls: true,
            derivativeUrlStyle: 2, // script
        );

        $qlimit = (int) min(5000, ceil(max($imageCount / 500, $maxUrls / count($types))));

        $filterCriteria = $this->imageFilterCriteriaBuilder->stdImageSqlFilterCriteria(ImageFilterQueryInput::fromQueryParams($body));
        if ($filterCriteria instanceof OperationError) {
            return ResponseFactory::problem('Unprocessable Entity', 422, $filterCriteria->message());
        }

        $criteria = new MissingDerivativesCriteria(
            filterCriteria: $filterCriteria,
            ids: $ids,
        );

        $urls = [];
        do {
            $rows = $this->imageService->getForMissingDerivatives($criteria, $startId, $qlimit);
            $isLast = count($rows) < $qlimit;

            foreach ($rows as $imageRow) {
                $startId = $imageRow->id;
                $srcImage = new SrcImage($imageRow->toArray());
                if ($srcImage->isMimetype()) {
                    continue;
                }

                foreach ($types as $type) {
                    $derivative = new DerivativeImage($type, $srcImage, $this->currentConfig, $urlStyleOverride);
                    if ($type !== $derivative->getType()) {
                        continue;
                    }
                    if (@filemtime($derivative->getPath()) === false) {
                        $urls[] = $derivative->getUrl() . $uid;
                    }
                }

                if (count($urls) >= $maxUrls && ! $isLast) {
                    break;
                }
            }
            if ($isLast) {
                $startId = 0;
            }
        } while (count($urls) < $maxUrls && $startId !== 0);

        $response = [
            'urls' => $urls,
        ];
        if ($startId !== 0) {
            $response['nextPage'] = $startId;
        }

        return ResponseFactory::json($response);
    }
}

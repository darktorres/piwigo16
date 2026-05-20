<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg;

use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\SrcImage;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsHelper;
use Piwigo\Ws\WsParamException;

/**
 * `pwg.getMissingDerivatives` — paginate through images and emit
 * derivative URLs that don't have an on-disk file yet, capped by
 * `max_urls`. The next-page cursor is the last-seen image id.
 */
final readonly class GetMissingDerivativesHandler implements WsAction
{
    public function __construct(
        private ImageRepository $imageRepository,
        private WsHelper $wsHelper,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>|GetMissingDerivativesResult|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array|GetMissingDerivativesResult
    {
        try {
            $input = GetMissingDerivativesParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(WsError::InvalidParam->value, $e->getMessage());
        }
        $maxIdAndCount = $this->imageRepository->findMaxIdAndCount();
        $maxId = $maxIdAndCount->nextId;
        $imageCount = $maxIdAndCount->total;
        if ($imageCount === 0) {
            return [];
        }
        $startId = $input->prevPageCursor;
        if ($startId <= 0) {
            $startId = $maxId;
        }
        $uid = '&b=' . time();
        Config::override('derivative_url_style', 2);
        $qlimit = min(5000, (int) ceil(max($imageCount / 500, $input->maxUrls / count($input->types))));
        /** @var array<string> $whereClauses */
        $whereClauses = $this->wsHelper->imageSqlFilter($params, '');
        if ($input->imageIds !== []) {
            $whereClauses[] = 'id IN (' . implode(',', $input->imageIds) . ')';
        }
        $urls = [];
        do {
            $rows   = $this->imageRepository->findDerivativeCandidatesBeforeId($startId, array_values($whereClauses), $qlimit);
            $isLast = count($rows) < $qlimit;
            foreach ($rows as $row) {
                $startId  = $row['id'];
                $srcImage = new SrcImage($row);
                if ($srcImage->isMimetype()) {
                    continue;
                }
                foreach ($input->types as $type) {
                    $derivative = new DerivativeImage($type, $srcImage);
                    if ($type !== $derivative->getType()) {
                        continue;
                    }
                    if (Filesystem::tryFileMtime($derivative->getPath()) === false) {
                        $url    = $derivative->getUrl();
                        $urls[] = (is_string($url) ? $url : '') . $uid;
                    }
                }
                if (count($urls) >= $input->maxUrls && !$isLast) {
                    break;
                }
            }
            if ($isLast) {
                $startId = 0;
            }
        } while (count($urls) < $input->maxUrls && $startId);
        return new GetMissingDerivativesResult(
            urls:           $urls,
            nextPageCursor: $startId > 0 ? $startId : null,
        );
    }
}

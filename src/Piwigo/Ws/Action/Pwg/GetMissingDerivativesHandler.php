<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg;

use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsHelper;

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
     * @return array<string, mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        if (empty($params['types'])) {
            $types = array_keys(ImageStdParams::getDefinedTypeMap());
        } else {
            $typesRaw = is_array($params['types']) ? $params['types'] : [];
            $types = array_intersect(array_keys(ImageStdParams::getDefinedTypeMap()), array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $typesRaw));
            if (count($types) === 0) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid types');
            }
        }
        $maxUrls = is_numeric($params['max_urls']) ? (int) $params['max_urls'] : 0;
        [$maxId, $imageCount] = $this->imageRepository->findMaxIdAndCount();
        if ($imageCount === 0) {
            return [];
        }
        $startId = is_numeric($params['prev_page']) ? (int) $params['prev_page'] : 0;
        if ($startId <= 0) {
            $startId = $maxId;
        }
        $uid = '&b=' . time();
        Config::override('derivative_url_style', 2);
        $qlimit = min(5000, (int) ceil(max($imageCount / 500, $maxUrls / count($types))));
        /** @var array<string> $whereClauses */
        $whereClauses = $this->wsHelper->imageSqlFilter($params, '');
        if (!empty($params['ids'])) {
            $idsArr         = is_array($params['ids']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['ids']) : [];
            $whereClauses[] = 'id IN (' . implode(',', $idsArr) . ')';
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
                foreach ($types as $type) {
                    $derivative = new DerivativeImage($type, $srcImage);
                    if ($type !== $derivative->getType()) {
                        continue;
                    }
                    if (Filesystem::tryFileMtime($derivative->getPath()) === false) {
                        $url    = $derivative->getUrl();
                        $urls[] = (is_string($url) ? $url : '') . $uid;
                    }
                }
                if (count($urls) >= $maxUrls && !$isLast) {
                    break;
                }
            }
            if ($isLast) {
                $startId = 0;
            }
        } while (count($urls) < $maxUrls && $startId);
        $ret = [];
        if ($startId) {
            $ret['next_page'] = $startId;
        }
        $ret['urls'] = $urls;
        return $ret;
    }
}

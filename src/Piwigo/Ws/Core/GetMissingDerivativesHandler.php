<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Core;

use Override;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\WsError;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\MissingDerivativesCriteria;
use Piwigo\Image\SrcImage;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.getMissingDerivatives` -- admin only. Returns a list of missing derivatives (not generated yet).
 */
final readonly class GetMissingDerivativesHandler implements WsAction
{
    public function __construct(
        private ImageService $imageService,
        private CurrentConfig $currentConfig,
        private ImageStdParams $imageStdParams,
        private WsHelper $wsHelper,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{next_page?: int, urls?: string[]}
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = GetMissingDerivativesParams::fromArray($params);

        if ($input->types === []) {
            $types = array_keys($this->imageStdParams->getDefinedTypeMap());
        } else {
            $types = array_intersect(array_keys($this->imageStdParams->getDefinedTypeMap()), $input->types);
            if (count($types) === 0) {
                return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid types');
            }
        }

        $max_urls = $input->maxUrls;
        $next_id_and_count = $this->imageService->getNextIdAndCount();
        $max_id = $next_id_and_count->nextId;
        $image_count = $next_id_and_count->count;

        if ($image_count === 0) {
            return [];
        }

        $start_id = $input->prevPage ?? 0;
        if ($start_id <= 0) {
            $start_id = $max_id;
        }

        $uid = '&b=' . time();

        $this->currentConfig->questionMarkInUrls = true;
        $this->currentConfig->phpExtensionInUrls = true;
        $this->currentConfig->derivativeUrlStyle = 2; // script

        $qlimit = (int) min(5000, ceil(max($image_count / 500, $max_urls / count($types))));

        // MethodDefinition's own registration for this method merges
        // WsDefaultMethods::sharedImageFilterParams() into its param
        // list, so Server::invoke()'s generic validation guarantees this
        // exact shape before __invoke() ever runs -- WsAction::__invoke()'s
        // own $params type can't express that (every handler shares the
        // same loose array<mixed> contract), so it's asserted locally at
        // this one call site instead.
        /** @var array{f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null, ...} */
        $filterParams = $params;

        $filterCriteria = $this->wsHelper->stdImageSqlFilterCriteria($filterParams);
        if ($filterCriteria instanceof WsErrorResponse) {
            return $filterCriteria;
        }

        $criteria = new MissingDerivativesCriteria(
            filterCriteria: $filterCriteria,
            ids: $input->ids,
        );

        $urls = [];
        do {
            $rows = $this->imageService->getForMissingDerivatives($criteria, $start_id, $qlimit);
            $is_last = count($rows) < $qlimit;

            foreach ($rows as $image_row) {
                $start_id = $image_row->id;
                $src_image = new SrcImage($image_row->toArray());
                if ($src_image->isMimetype()) {
                    continue;
                }

                foreach ($types as $type) {
                    $derivative = new DerivativeImage($type, $src_image, $this->currentConfig);
                    if ($type !== $derivative->getType()) {
                        continue;
                    }
                    if (@filemtime($derivative->getPath()) === false) {
                        $urls[] = $derivative->getUrl() . $uid;
                    }
                }

                if (count($urls) >= $max_urls and ! $is_last) {
                    break;
                }
            }
            if ($is_last) {
                $start_id = 0;
            }
        } while (count($urls) < $max_urls and (bool) $start_id);

        $ret = [];
        if ((bool) $start_id) {
            $ret['next_page'] = $start_id;
        }
        $ret['urls'] = $urls;
        return $ret;
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg;

use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;
use Piwigo\Image\DerivativeEncoding;
use Piwigo\Image\ImageStdParams;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Psr\Cache\CacheItemPoolInterface;

/**
 * `pwg.getCacheSize` — recursive on-disk size of the cache, broken
 * down by derivative-size + a `templates_c` total. Caches the result
 * under `piwigo.cache_sizes` for subsequent reads.
 */
final readonly class GetCacheSizeHandler implements WsAction
{
    public function __construct(
        private ImageAdminService $imageAdminService,
        private CacheItemPoolInterface $pool,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>
     */
    public function __invoke(array $params, PwgServer $server): array
    {
        $infos = [];
        $pathCache = Config::dataLocation();
        $infos['cache_size'] = Filesystem::directorySizeBytes($pathCache);
        $pathMsizes = Config::dataLocation() . 'i';
        $msizes     = $this->imageAdminService->getCacheSizeDerivatives($pathMsizes);
        $infos['msizes'] = array_fill_keys(array_keys(ImageStdParams::getDefinedTypeMap()), 0.0);
        $infos['msizes']['custom'] = 0.0;
        $all = 0.0;
        foreach (array_keys($infos['msizes']) as $sizeType) {
            $infos['msizes'][$sizeType] += (float) ($msizes[DerivativeEncoding::derivativeToUrl($sizeType)] ?? 0);
            $all += $infos['msizes'][$sizeType];
        }
        $infos['msizes']['all'] = $all;
        $pathTemplateC = Config::dataLocation() . 'templates_c';
        $infos['tsizes']         = Filesystem::directorySizeBytes($pathTemplateC);
        $infos['last_date_calc'] = date('Y-m-d H:i:s');
        $output = [];
        foreach ($infos as $name => $value) {
            $output[] = ['name' => $name, 'value' => $value];
        }
        $item = $this->pool->getItem('piwigo.cache_sizes');
        $item->set($output);
        $this->pool->save($item);

        return ['infos' => new PwgNamedArray($output, 'item')];
    }
}

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
use Piwigo\Admin\Maintenance\CacheSizeCalculator;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\WsAction;

/**
 * `pwg.getCacheSize` -- admin only. Calculates and returns the size of
 * the cache. The real computation (and the `cache_sizes` config
 * persistence real admin pages read back) lives in
 * `CacheSizeCalculator`, shared with `Command\MaintenanceCacheSizeCommand`
 * -- this handler only reshapes the result into WS's own wire format.
 *
 * @since 12
 */
final readonly class GetCacheSizeHandler implements WsAction
{
    public function __construct(
        private CacheSizeCalculator $cacheSizeCalculator,
    ) {}

    /**
     * @param array<mixed> $params this method is registered with a null
     *   signature (zero registered params) -- $params is the raw, entirely
     *   unvalidated request array, but the body doesn't read it.
     * @return array{infos: NamedArray}
     */
    #[Override]
    public function __invoke(array $params): array
    {
        $result = $this->cacheSizeCalculator->calculate();

        $infos = [
            'cache_size' => $result['cacheSize'],
            'msizes' => $result['msizes'],
            'tsizes' => $result['templatesSize'],
            'last_date_calc' => $result['lastDateCalc'],
        ];

        // $output matches NamedArray::$content's own by-design generic
        // array<int, mixed> contract (a name/value pair list encoded
        // generically for XML/REST) -- $infos itself is genuinely
        // heterogeneous (int/array/string/null per key).
        /** @var array<int, mixed> $output */
        $output = [];
        foreach ($infos as $name => $value) {
            $output[] = [
                'name' => $name,
                'value' => $value,
            ];
        }

        return [
            'infos' => new NamedArray($output, 'item'),
        ];
    }
}

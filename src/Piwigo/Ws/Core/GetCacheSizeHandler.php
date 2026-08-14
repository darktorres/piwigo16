<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Core;

use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Paths;
use Piwigo\Image\DerivativeUrlCodec;
use Piwigo\Image\ImageStdParams;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;

/**
 * `pwg.getCacheSize` -- admin only. Calculates and returns the size of
 * the cache.
 *
 * @since 12
 */
final readonly class GetCacheSizeHandler implements WsAction
{
    public function __construct(
        private CurrentConfig $currentConfig,
        private Paths $paths,
        private ImageStdParams $imageStdParams,
        private ConfigService $configService,
    ) {}

    /**
     * @param array<mixed> $params this method is registered with a null
     *   signature (zero registered params) -- $params is the raw, entirely
     *   unvalidated request array, but the body doesn't read it.
     * @return array{infos: NamedArray}
     */
    public function __invoke(array $params, Server $server): array
    {
        $data_location = $this->currentConfig->dataLocation;

        // $data_location ('_data/') is a path relative to the install root,
        // not to the PHP process's CWD -- request-time CWD is public/ (the
        // webroot), not the install root. Compose it against
        // $this->paths->root, like every other call site of dataLocation()
        // in this codebase (PersistentFileCache, FeedController,
        // RequestBootstrap, Template, IntroSubController, MailService,
        // CoreUpdateService), per Paths' own class-level contract
        // ("Config-driven directories ... compose against data/root at the
        // call site").
        $root = $this->paths->root;

        // Cache size
        $path_cache = $root . $data_location;
        $infos = [];
        $infos['cache_size'] = null;
        if (function_exists('exec')) {
            $return_array_cache = [];
            @exec('du -sk ' . $path_cache, $return_array_cache);
            if (
                isset($return_array_cache[0]) && $return_array_cache[0] !== '' && $return_array_cache[0] !== '0'
                and (bool) preg_match('/^(\d+)\s/', $return_array_cache[0], $matches_cache)
            ) {
                $infos['cache_size'] = (int) $matches_cache[1] * 1024;
            }
        }

        // Multiples sizes size
        $path_msizes = $root . $data_location . 'i';
        $msizes = FilesystemHelper::getCacheSizeDerivatives($path_msizes);

        $infos['msizes'] = array_fill_keys(array_keys($this->imageStdParams->getDefinedTypeMap()), 0);
        $infos['msizes']['custom'] = 0;
        $all = 0;

        foreach (array_keys($infos['msizes']) as $size_type) {
            $current_size = $infos['msizes'][$size_type];

            // getCacheSizeDerivatives()'s array<string, int> return type
            // doesn't capture that it's a sparse map -- it only contains keys
            // for derivative sizes that actually have files on disk (see its
            // real implementation, admin/include/functions.php), so a given
            // $size_type is genuinely, verifiably absent at runtime when no
            // such file exists. treatPhpDocTypesAsCertain makes PHPStan
            // (wrongly) prove this offset always exists and is always int;
            // @ suppresses the resulting real undefined-key warning, and the
            // two guards below are the actual runtime safety net, not dead
            // code. Tried telling PHPStan the real int|null result via an
            // explicit @var instead of ignoring -- rejected by PHPStan's own
            // varTag.type check (a @var can only narrow the type it already
            // infers, never widen it beyond what treatPhpDocTypesAsCertain
            // already committed to), confirming this can't be told, only
            // suppressed.
            $added_size = @$msizes[DerivativeUrlCodec::derivativeToUrl($size_type)];
            // @phpstan-ignore function.alreadyNarrowedType
            $added_size = is_int($added_size) ? $added_size : 0;

            $infos['msizes'][$size_type] = $current_size + $added_size;
            $all += $infos['msizes'][$size_type];
        }
        $infos['msizes']['all'] = $all;

        // Compiled templates size
        $path_template_c = $root . $data_location . 'templates_c';
        $infos['tsizes'] = null;
        if (function_exists('exec')) {
            $return_array_template_c = [];
            @exec('du -sk ' . $path_template_c, $return_array_template_c);
            if (
                isset($return_array_template_c[0]) && $return_array_template_c[0] !== '' && $return_array_template_c[0] !== '0'
                and (bool) preg_match('/^(\d+)\s/', $return_array_template_c[0], $matches_template_c)
            ) {
                $infos['tsizes'] = (int) $matches_template_c[1] * 1024;
            }
        }

        $infos['last_date_calc'] = date('Y-m-d H:i:s');

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

        $this->configService->confUpdateParam('cache_sizes', $output, true);

        return [
            'infos' => new NamedArray($output, 'item'),
        ];
    }
}

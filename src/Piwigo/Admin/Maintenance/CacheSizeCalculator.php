<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance;

use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Paths;
use Piwigo\Image\DerivativeUrlCodec;
use Piwigo\Image\ImageStdParams;

/**
 * The `du -sk` computation over the cache/derivative/
 * compiled-template directories, shared by `Controller\Api\
 * CacheSizeController` and `Command\MaintenanceCacheSizeCommand`, same
 * discipline `Admin\Extensions\PluginListBuilder` uses for its own
 * shared consumers. Persists the same
 * `cache_sizes` config value both callers' predecessors already wrote
 * (real admin pages read it back via `CurrentConfig::cacheSizes`/
 * `CacheSizesSnapshot`) so running this from either surface keeps those
 * pages in sync.
 */
final readonly class CacheSizeCalculator
{
    public function __construct(
        private CurrentConfig $currentConfig,
        private Paths $paths,
        private ImageStdParams $imageStdParams,
        private ConfigService $configService,
    ) {}

    /**
     * @return array{cacheSize: ?int, msizes: array<string, int>, templatesSize: ?int, lastDateCalc: string}
     */
    public function calculate(): array
    {
        $dataLocation = $this->currentConfig->dataLocation;
        $root = $this->paths->root;

        $cacheSize = null;
        if (function_exists('exec')) {
            $pathCache = $root . $dataLocation;
            $returnArrayCache = [];
            // [SEC-16] $pathCache is composed from CurrentConfig::dataLocation,
            // an admin-settable config value -- escape it before it reaches
            // the shell, same as ImageBackend's own escapeshellarg() sites.
            @exec('du -sk ' . escapeshellarg($pathCache), $returnArrayCache);
            if (
                isset($returnArrayCache[0]) && $returnArrayCache[0] !== '' && $returnArrayCache[0] !== '0'
                and (bool) preg_match('/^(\d+)\s/', $returnArrayCache[0], $matchesCache)
            ) {
                $cacheSize = (int) $matchesCache[1] * 1024;
            }
        }

        $pathMsizes = $root . $dataLocation . 'i';
        $msizesOnDisk = FilesystemHelper::getCacheSizeDerivatives($pathMsizes);

        $msizes = array_fill_keys(array_keys($this->imageStdParams->getDefinedTypeMap()), 0);
        $msizes['custom'] = 0;
        $all = 0;

        foreach (array_keys($msizes) as $sizeType) {
            // getCacheSizeDerivatives()'s array<string, int> return type
            // doesn't capture that it's a sparse map -- it only contains keys
            // for derivative sizes that actually have files on disk (see its
            // real implementation), so a given $sizeType is genuinely,
            // verifiably absent at runtime when no such file exists.
            // treatPhpDocTypesAsCertain makes PHPStan (wrongly) prove this
            // offset always exists and is always int; @ suppresses the
            // resulting real undefined-key warning, and the is_int() guard
            // below is the actual runtime safety net, not dead code.
            $addedSize = @$msizesOnDisk[DerivativeUrlCodec::derivativeToUrl($sizeType)];
            // @phpstan-ignore function.alreadyNarrowedType
            $addedSize = is_int($addedSize) ? $addedSize : 0;

            $msizes[$sizeType] += $addedSize;
            $all += $msizes[$sizeType];
        }
        $msizes['all'] = $all;

        $templatesSize = null;
        if (function_exists('exec')) {
            $pathTemplateC = $root . $dataLocation . 'templates_c';
            $returnArrayTemplateC = [];
            // [SEC-16] see the escapeshellarg() note on $pathCache above.
            @exec('du -sk ' . escapeshellarg($pathTemplateC), $returnArrayTemplateC);
            if (
                isset($returnArrayTemplateC[0]) && $returnArrayTemplateC[0] !== '' && $returnArrayTemplateC[0] !== '0'
                and (bool) preg_match('/^(\d+)\s/', $returnArrayTemplateC[0], $matchesTemplateC)
            ) {
                $templatesSize = (int) $matchesTemplateC[1] * 1024;
            }
        }

        $lastDateCalc = date('Y-m-d H:i:s');

        $this->persist($cacheSize, $msizes, $templatesSize, $lastDateCalc);

        return [
            'cacheSize' => $cacheSize,
            'msizes' => $msizes,
            'templatesSize' => $templatesSize,
            'lastDateCalc' => $lastDateCalc,
        ];
    }

    /**
     * @param array<string, int> $msizes
     */
    private function persist(?int $cacheSize, array $msizes, ?int $templatesSize, string $lastDateCalc): void
    {
        $infos = [
            'cache_size' => $cacheSize,
            'msizes' => $msizes,
            'tsizes' => $templatesSize,
            'last_date_calc' => $lastDateCalc,
        ];

        $rows = [];
        foreach ($infos as $name => $value) {
            $rows[] = [
                'name' => $name,
                'value' => $value,
            ];
        }

        $this->configService->confUpdateParam('cache_sizes', $rows, true);
    }
}

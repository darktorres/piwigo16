<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * A decoded cache-size snapshot, written by {@see
 * \Piwigo\Admin\Maintenance\CacheSizeCalculator} -- persisted as a {name,
 * value} pair list, looked up here by name rather than position.
 * `msizes` (per-derivative-size-type bytes, keyed the same way {@see
 * \Piwigo\Admin\Maintenance\CacheSizeCalculator::calculate()} builds it,
 * including its own `'all'` aggregate key) and `tsizes` (compiled-
 * template cache bytes) are both real fields the admin Maintenance pages
 * (`maintenance_actions.latte`/`maintenance_env.latte`) display.
 */
final readonly class CacheSizesSnapshot
{
    /**
     * @param array<string, int> $msizes
     */
    public function __construct(
        public ?int $cacheSize,
        public array $msizes,
        public ?int $tsizes,
        public string $lastDateCalc,
    ) {}

    /**
     * @param array<mixed> $value
     */
    public static function fromArray(array $value): self
    {
        $byName = [];
        foreach ($value as $row) {
            $name = is_array($row) ? ($row['name'] ?? null) : null;
            if (is_string($name)) {
                $byName[$name] = $row['value'] ?? null;
            }
        }

        $cacheSize = $byName['cache_size'] ?? null;
        $msizesRaw = $byName['msizes'] ?? null;
        $tsizes = $byName['tsizes'] ?? null;
        $lastDateCalc = $byName['last_date_calc'] ?? null;

        $msizes = [];
        if (is_array($msizesRaw)) {
            foreach ($msizesRaw as $sizeType => $bytes) {
                if (is_string($sizeType) && is_int($bytes)) {
                    $msizes[$sizeType] = $bytes;
                }
            }
        }

        return new self(
            cacheSize: is_int($cacheSize) ? $cacheSize : null,
            msizes: $msizes,
            tsizes: is_int($tsizes) ? $tsizes : null,
            lastDateCalc: is_string($lastDateCalc) ? $lastDateCalc : '',
        );
    }
}

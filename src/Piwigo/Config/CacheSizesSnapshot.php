<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * A decoded pwg.getCacheSize snapshot (see Ws\PwgCore::getCacheSize()) --
 * persisted as a {name, value} pair list (NamedArray's own wire
 * shape), looked up here by name rather than position. The real
 * producer also emits `msizes`/`tsizes` rows, but no consumer in this
 * codebase reads either one back out, so only the 2 fields real callers
 * use are kept here.
 */
final readonly class CacheSizesSnapshot
{
    public function __construct(
        public ?int $cacheSize,
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
        $lastDateCalc = $byName['last_date_calc'] ?? null;

        return new self(
            cacheSize: is_int($cacheSize) ? $cacheSize : null,
            lastDateCalc: is_string($lastDateCalc) ? $lastDateCalc : '',
        );
    }
}

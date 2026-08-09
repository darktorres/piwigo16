<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * A decoded pwg.getCacheSize snapshot (see Ws\PwgCore::getCacheSize()) --
 * 4 fixed, distinctly-typed rows persisted as a {name, value} pair list
 * (PwgNamedArray's own wire shape), looked up here by name rather than
 * position.
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
        $tsizes = $byName['tsizes'] ?? null;
        $lastDateCalc = $byName['last_date_calc'] ?? null;

        return new self(
            cacheSize: is_int($cacheSize) ? $cacheSize : null,
            msizes: self::sanitizeMsizes($byName['msizes'] ?? null),
            tsizes: is_int($tsizes) ? $tsizes : null,
            lastDateCalc: is_string($lastDateCalc) ? $lastDateCalc : '',
        );
    }

    /**
     * @return array<string, int>
     */
    private static function sanitizeMsizes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $key => $val) {
            if (is_string($key) && is_int($val)) {
                $result[$key] = $val;
            }
        }

        return $result;
    }
}

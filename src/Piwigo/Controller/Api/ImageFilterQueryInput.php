<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api;

/**
 * Shared `f_*` images-table range-filter query-param parser, feeding
 * `Image\ImageFilterCriteriaBuilder::stdImageSqlFilterCriteria()`
 * directly (that builder's own date validation is reused unchanged, not
 * duplicated here). `GET /api/v1/tags/images`, `GET /api/v1/categories/
 * images`, and `GET /api/v1/images/search` all share this one parser.
 */
final class ImageFilterQueryInput
{
    /**
     * @param array<array-key, mixed> $query
     * @return array{f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null}
     */
    public static function fromQueryParams(array $query): array
    {
        return [
            'f_min_rate' => self::float($query['fMinRate'] ?? null),
            'f_max_rate' => self::float($query['fMaxRate'] ?? null),
            'f_min_hit' => self::int($query['fMinHit'] ?? null),
            'f_max_hit' => self::int($query['fMaxHit'] ?? null),
            'f_min_ratio' => self::float($query['fMinRatio'] ?? null),
            'f_max_ratio' => self::float($query['fMaxRatio'] ?? null),
            'f_max_level' => self::int($query['fMaxLevel'] ?? null),
            'f_min_date_available' => self::string($query['fMinDateAvailable'] ?? null),
            'f_max_date_available' => self::string($query['fMaxDateAvailable'] ?? null),
            'f_min_date_created' => self::string($query['fMinDateCreated'] ?? null),
            'f_max_date_created' => self::string($query['fMaxDateCreated'] ?? null),
        ];
    }

    private static function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

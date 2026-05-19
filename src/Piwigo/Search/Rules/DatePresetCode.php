<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

/**
 * Saved-search date-filter preset code. Persisted as the literal
 * string in the search-rule JSON. The 'custom' code signals that
 * the caller will read a separate `custom` list of date-codes
 * (year/month/day strings, parsed by DatePostedFilter / DateCreatedFilter).
 *
 * Date-posted accepts: 24h / 7d / 30d / 3m / 6m / custom.
 * Date-created accepts: 7d / 30d / 3m / 6m / 12m / custom.
 * The shared enum carries the union; per-filter callers map the
 * non-applicable cases to their own clauses.
 */
enum DatePresetCode: string
{
    case Last24Hours = '24h';
    case Last7Days   = '7d';
    case Last30Days  = '30d';
    case Last3Months = '3m';
    case Last6Months = '6m';
    case Last12Months = '12m';
    case Custom      = 'custom';

    public static function tryParse(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}

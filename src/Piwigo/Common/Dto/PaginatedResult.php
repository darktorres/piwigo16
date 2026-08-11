<?php

declare(strict_types=1);

namespace Piwigo\Common\Dto;

/**
 * Uniform shape for paginated repository queries: a page of rows plus
 * the optional total record count (null when SQL_CALC_FOUND_ROWS was skipped).
 *
 * Covariant: `$rows` is the only place `T` appears, always as an output
 * (a readonly property, never consumed by a method after construction),
 * so a `PaginatedResult<Sub>` is safely usable wherever a
 * `PaginatedResult<Super>` is expected.
 *
 * @template-covariant T
 */
final readonly class PaginatedResult
{
    /**
     * @param list<T> $rows
     */
    public function __construct(
        public array $rows,
        public ?int $total
    ) {}
}

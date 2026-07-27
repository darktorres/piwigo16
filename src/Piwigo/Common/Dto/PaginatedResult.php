<?php

declare(strict_types=1);

namespace Piwigo\Common\Dto;

/**
 * Uniform shape for paginated repository queries: a page of rows plus
 * the optional total record count (null when SQL_CALC_FOUND_ROWS was skipped).
 *
 * @template T
 */
final readonly class PaginatedResult
{
    /**
     * @var list<T>
     */
    public array $rows;

    public ?int $total;

    /**
     * @param list<T> $rows
     */
    public function __construct(array $rows, ?int $total)
    {
        $this->rows = $rows;
        $this->total = $total;
    }
}

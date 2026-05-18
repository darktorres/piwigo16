<?php

declare(strict_types=1);

namespace Piwigo\Common\Enum;

/**
 * SQL sort direction. Stored uppercase to match the form used in
 * `ORDER BY … ASC | DESC` clauses across the codebase.
 */
enum SortOrder: string
{
    case Asc  = 'ASC';
    case Desc = 'DESC';
}

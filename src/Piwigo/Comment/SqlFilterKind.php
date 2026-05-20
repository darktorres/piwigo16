<?php

declare(strict_types=1);

namespace Piwigo\Comment;

/** The 5 filter dimensions the comments getList endpoint supports. */
enum SqlFilterKind: string
{
    case Author  = 'author';
    case Image   = 'image';
    case MinDate = 'min_date';
    case MaxDate = 'max_date';
    case Search  = 'search';
}

<?php

declare(strict_types=1);

namespace Piwigo\History;

/**
 * Backed enum for the origin `history.image_type` column
 * (`enum('picture','high','other')`) -- the string case values are the
 * exact DB-stored values. Unlike its sibling `history.section` (kept a
 * plain string -- see `HistoryRepository::getSectionEnumOptions()`'s own
 * docblock), this column has no plugin-widening mechanism anywhere in
 * this codebase (confirmed via grep at retyping time): a real, closed,
 * core-defined set, safe for the same `enumType` treatment as
 * {@see \Piwigo\Category\CategoryStatus}/{@see \Piwigo\Users\UserStatus}.
 */
enum HistoryImageType: string
{
    case Picture = 'picture';
    case High = 'high';
    case Other = 'other';
}

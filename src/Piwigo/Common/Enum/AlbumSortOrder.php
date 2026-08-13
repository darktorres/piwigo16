<?php

declare(strict_types=1);

namespace Piwigo\Common\Enum;

/**
 * The 8 legal `order` values AlbumsPageRenderer.php's own auto-order form
 * accepts -- a compound field+direction domain, unlike SortOrder's bare
 * direction. Values match its `$sort_orders` array exactly (the exact
 * string this enum's own `explode(' ', ...)` consumer splits back apart
 * into field + direction).
 */
enum AlbumSortOrder: string
{
    case NameAsc = 'name ASC';
    case NameDesc = 'name DESC';
    case DateCreationAsc = 'date_creation ASC';
    case DateCreationDesc = 'date_creation DESC';
    case DateAvailableAsc = 'date_available ASC';
    case DateAvailableDesc = 'date_available DESC';
    case NaturalOrderAsc = 'natural_order ASC';
    case NaturalOrderDesc = 'natural_order DESC';
}

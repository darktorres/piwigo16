<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

/**
 * One `{field, dir}` sort clause -- the shape {@see PhotoSortOrder} and
 * {@see \Piwigo\Sort\UserSortField::parseOrderClause()} each build a list
 * of, previously as a bare `array{field: TField, dir: TDir}` in both
 * (and a third time, unparsed, as `Users\UserRepository::findList()`'s
 * own `$orderClauses` parameter type). `TField`/`TDir` differ per use --
 * `PhotoSortField`/`Common\Enum\SortOrder` for the photo vocabulary,
 * `Sort\UserSortField`/plain `'ASC'|'DESC'` string for the user one --
 * so this stays a plain 2-slot pair rather than fixing either type.
 *
 * Covariant, same reasoning as {@see \Piwigo\Common\Dto\PaginatedResult}:
 * both properties are readonly output only, never consumed as a method
 * parameter after construction.
 *
 * @template-covariant TField
 * @template-covariant TDir
 */
final readonly class SortEntry
{
    /**
     * @param TField $field
     * @param TDir $dir
     */
    public function __construct(
        public mixed $field,
        public mixed $dir,
    ) {}
}

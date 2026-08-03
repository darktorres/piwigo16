<?php

declare(strict_types=1);

namespace Piwigo\Permission;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;

/**
 * SQL-modernization audit, Item 14 Sub-phase C1: typed replacement for
 * {@see PermissionService::getSqlConditionFandFAsCondition()}'s own
 * `array<string,string> $conditionFields` (condition-type-name =>
 * column-name) + raw `SqlCondition` return -- confirmed by reading that
 * method's own full body, exactly 4 condition types, each independently
 * optional (null = no restriction on that dimension):
 *
 * - `forbidden_categories` -> $forbiddenCategoryIds (NOT IN)
 * - `visible_categories` -> $visibleCategoryIds (IN)
 * - `visible_images` -> $visibleImageIds (IN) -- the old switch fell
 *   through from this case into `forbidden_images` with no `break`, so
 *   both applied together whenever a caller requested `visible_images`;
 *   expressed here as two independent fields instead, since a caller can
 *   now just apply both directly.
 * - `forbidden_images` -> two ORTHOGONAL fields, not one: the old code
 *   picked between them at runtime via a `$fieldName === 'id' ||
 *   $fieldName === 'i.id'` string-match, which was really encoding "does
 *   this caller have the images table itself in scope" (then: a
 *   `level <= x` check against ITS OWN `level` column) vs. "does this
 *   caller only have a foreign-key `image_id` column on some other table
 *   in scope" (then: an `image_access_list` IN/NOT IN check against that
 *   column instead) -- never both on the same query. Computed here as
 *   $maxLevel (images-table's own level check) and $imageAccessIds/
 *   $imageAccessIsAllowlist (the foreign-key-column check) so the caller
 *   picks whichever one actually matches the entity it has in scope,
 *   instead of encoding that choice into a magic field-name string.
 */
final readonly class PermissionCriteria
{
    /**
     * @param list<int>|null $forbiddenCategoryIds
     * @param list<int>|null $visibleCategoryIds
     * @param list<int>|null $visibleImageIds
     * @param list<int>|null $imageAccessIds combined with
     *   $imageAccessIsAllowlist to know IN vs NOT IN
     */
    public function __construct(
        public ?array $forbiddenCategoryIds,
        public ?array $visibleCategoryIds,
        public ?array $visibleImageIds,
        public ?int $maxLevel,
        public ?array $imageAccessIds,
        public ?bool $imageAccessIsAllowlist,
    ) {}

    /**
     * Raw-SQL fragment builders, one per dimension, for the DBAL-based
     * consumers of this criteria (plain `Doctrine\DBAL\Query\QueryBuilder`,
     * not DQL) -- same shape as
     * {@see \Piwigo\Image\ImageFilterCriteria::toSqlCondition()} (Sub-phase
     * C3). Each takes the real column/alias.path the consumer's own query
     * has in scope for that dimension (e.g. `'ic.category_id'`, plain
     * `'id'`) and returns an empty (`isEmpty()`) SqlCondition when the
     * dimension doesn't apply -- SqlCondition::combine() already drops
     * empty fragments, so callers can combine unconditionally. Every bound
     * placeholder is prefixed `perm` so a caller combining several of
     * these in one query never collides on a name.
     */
    public function forbiddenCategoriesCondition(string $column): SqlCondition
    {
        if ($this->forbiddenCategoryIds === null) {
            return new SqlCondition('');
        }

        return new SqlCondition(
            $column . ' NOT IN (:permForbiddenCategoryIds)',
            [
                'permForbiddenCategoryIds' => $this->forbiddenCategoryIds,
            ],
            [
                'permForbiddenCategoryIds' => ArrayParameterType::INTEGER,
            ],
        );
    }

    public function visibleCategoriesCondition(string $column): SqlCondition
    {
        if ($this->visibleCategoryIds === null) {
            return new SqlCondition('');
        }

        return new SqlCondition(
            $column . ' IN (:permVisibleCategoryIds)',
            [
                'permVisibleCategoryIds' => $this->visibleCategoryIds,
            ],
            [
                'permVisibleCategoryIds' => ArrayParameterType::INTEGER,
            ],
        );
    }

    public function visibleImagesCondition(string $column): SqlCondition
    {
        if ($this->visibleImageIds === null) {
            return new SqlCondition('');
        }

        return new SqlCondition(
            $column . ' IN (:permVisibleImageIds)',
            [
                'permVisibleImageIds' => $this->visibleImageIds,
            ],
            [
                'permVisibleImageIds' => ArrayParameterType::INTEGER,
            ],
        );
    }

    public function maxLevelCondition(string $column): SqlCondition
    {
        if ($this->maxLevel === null) {
            return new SqlCondition('');
        }

        return new SqlCondition(
            $column . ' <= :permMaxLevel',
            [
                'permMaxLevel' => $this->maxLevel,
            ],
            [
                'permMaxLevel' => ParameterType::INTEGER,
            ],
        );
    }

    public function imageAccessCondition(string $column): SqlCondition
    {
        if ($this->imageAccessIds === null) {
            return new SqlCondition('');
        }

        return new SqlCondition(
            $column . ($this->imageAccessIsAllowlist === true ? ' IN (:permImageAccessIds)' : ' NOT IN (:permImageAccessIds)'),
            [
                'permImageAccessIds' => $this->imageAccessIds,
            ],
            [
                'permImageAccessIds' => ArrayParameterType::INTEGER,
            ],
        );
    }
}

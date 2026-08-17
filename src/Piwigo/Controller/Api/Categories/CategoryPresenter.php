<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

use Piwigo\Category\Projection\Category;

/**
 * Shared `Category` -> REST JSON row shape, camelCased -- used by
 * `CategoryUpdateController`'s own read-back after `pwg.categories.setInfo`'s
 * real replacement mutates a row.
 */
final class CategoryPresenter
{
    /**
     * @return array{id: int, name: string, parentId: ?int, comment: ?string, status: string, visible: bool, commentable: bool, uppercats: string, globalRank: ?string, imageOrder: ?string, lastmodified: string}
     */
    public static function toArray(Category $category): array
    {
        return [
            'id' => $category->id->value,
            'name' => $category->name,
            'parentId' => $category->idUppercat,
            'comment' => $category->comment,
            'status' => $category->status,
            'visible' => $category->visible,
            'commentable' => $category->commentable,
            'uppercats' => $category->uppercats,
            'globalRank' => $category->globalRank,
            'imageOrder' => $category->imageOrder,
            'lastmodified' => $category->lastmodified,
        ];
    }
}

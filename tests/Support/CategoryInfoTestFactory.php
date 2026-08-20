<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Piwigo\Category\Projection\CategoryIdNamePermalink;
use Piwigo\Category\Projection\CategoryInfo;

/**
 * CategoryInfo has no optional constructor params (every field is a real,
 * always-known column) -- this factory exists purely to cut ceremony in
 * tests that only care about 1-2 of its 17 fields.
 */
final class CategoryInfoTestFactory
{
    /**
     * @param list<CategoryIdNamePermalink> $upperNames
     */
    public static function build(
        int $id = 1,
        string $name = 'Category',
        ?int $idUppercat = null,
        ?string $comment = null,
        ?string $dir = null,
        ?int $rank = null,
        string $status = 'public',
        ?int $siteId = 1,
        bool $visible = true,
        ?int $representativePictureId = null,
        string $uppercats = '1',
        bool $commentable = true,
        ?string $globalRank = null,
        ?string $imageOrder = null,
        ?string $permalink = null,
        string $lastmodified = '2026-01-01 00:00:00',
        array $upperNames = [],
    ): CategoryInfo {
        return new CategoryInfo(
            id: $id,
            name: $name,
            idUppercat: $idUppercat,
            comment: $comment,
            dir: $dir,
            rank: $rank,
            status: $status,
            siteId: $siteId,
            visible: $visible,
            representativePictureId: $representativePictureId,
            uppercats: $uppercats,
            commentable: $commentable,
            globalRank: $globalRank,
            imageOrder: $imageOrder,
            permalink: $permalink,
            lastmodified: $lastmodified,
            upperNames: $upperNames,
        );
    }
}

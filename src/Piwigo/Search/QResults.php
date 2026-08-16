<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Search;

/**
 * Structure of results being filled from different tables
 */
final class QResults
{
    /**
     * Rows get a 'name' key overwritten by EventDispatcher::dispatch()
     * (RenderTagName) before being stored here, so the row can't be a clean
     * Tag Projection -- the mixed leaf is real, not just unnarrowed.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $all_tags = [];

    /**
     * @var array<int, array<int, int>>
     */
    public array $tag_ids = [];

    /**
     * Populated from SearchRepository::findIdsByClause() calls (id lists,
     * always real int -- SearchRepository casts every fetched id
     * explicitly, matching this project's DBAL/mysqli driver, which
     * returns native int for numeric columns unlike the legacy
     * \Piwigo\Db\MysqliDb::query2Array() layer's all-string rows).
     *
     * @var array<int, list<int>>
     */
    public array $tag_iids = [];

    /**
     * Same EventDispatcher::dispatch()-poisons-'name' rationale as
     * $all_tags above.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $all_cats = [];

    /**
     * @var array<int, array<int, int>>
     */
    public array $cat_ids = [];

    /**
     * @var array<int, list<int>>
     */
    public array $cat_iids = [];

    /**
     * @var array<int, list<int>>
     */
    public array $images_iids = [];

    /**
     * array_unique(array_merge(...)) of images_iids/cat_iids/tag_iids — same
     * element type as those three, but array_unique() doesn't renumber keys
     * so this is a plain (possibly sparse) array, not a list.
     *
     * @var array<int, array<int, int>>
     */
    public array $iids = [];
}

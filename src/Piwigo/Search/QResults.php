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
class QResults
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $all_tags = [];

    /**
     * @var array<int, array<int, int>>
     */
    public array $tag_ids = [];

    /**
     * Populated exclusively from query2array($query, null, ...) calls (id lists).
     *
     * @var array<int, list<string|null>>
     */
    public array $tag_iids = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $all_cats = [];

    /**
     * @var array<int, array<int, int>>
     */
    public array $cat_ids = [];

    /**
     * Populated exclusively from query2array($query, null, ...) calls (id lists).
     *
     * @var array<int, list<string|null>>
     */
    public array $cat_iids = [];

    /**
     * Populated exclusively from query2array($query, null, ...) calls (id lists).
     *
     * @var array<int, list<string|null>>
     */
    public array $images_iids = [];

    /**
     * array_unique(array_merge(...)) of images_iids/cat_iids/tag_iids — same
     * element type as those three, but array_unique() doesn't renumber keys
     * so this is a plain (possibly sparse) array, not a list.
     *
     * @var array<int, array<int, string|null>>
     */
    public array $iids = [];
}

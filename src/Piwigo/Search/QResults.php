<?php

declare(strict_types=1);

namespace Piwigo\Search;

/**
  Structure of results being filled from different tables
*/
final class QResults
{
    /** @var array<mixed> */
    public array $all_tags = [];
    /** @var array<int, int[]> */
    public array $tag_ids = [];
    /** @var array<int, int[]> */
    public array $tag_iids = [];
    /** @var array<mixed> */
    public array $all_cats = [];
    /** @var array<int, int[]> */
    public array $cat_ids = [];
    /** @var array<int, int[]> */
    public array $cat_iids = [];
    /** @var array<int, int[]> */
    public array $images_iids = [];
    /** @var array<int, int[]> */
    public array $iids = [];
}

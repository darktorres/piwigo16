<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * Structure of results being filled from different tables
 */
class QResults
{
    public array $all_tags;

    public array $tag_ids;

    public array $tag_iids;

    public array $all_cats;

    public array $cat_ids;

    public array $cat_iids;

    public array $images_iids;

    public array $iids;
}

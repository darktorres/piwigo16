<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Filter\FilterService;

/**
 * Updates data of categories with filtered values
 * @param array<int, array<string, mixed>> $cats
 */
function update_cats_with_filtered_data(array &$cats): void
{
    new FilterService()
        ->updateCatsWithFilteredData($cats);
}

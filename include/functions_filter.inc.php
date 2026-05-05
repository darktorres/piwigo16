<?php

declare(strict_types=1);
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+
/**
 * @package functions\filter
 */
/**
 * Updates data of categories with filtered values
 */
/** @param array<array<string, mixed>> $cats */
function update_cats_with_filtered_data(array &$cats): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Filter\FilterService::class)->updateCategoriesWithFilteredData($cats);
}

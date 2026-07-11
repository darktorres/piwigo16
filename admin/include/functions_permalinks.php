<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Db\DbConnection;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Permalink\PermalinkService;

/**
 * returns a category id that corresponds to the given permalink (or null)
 * @param string $permalink
 */
function get_cat_id_from_permalink($permalink): ?string
{
    $id = new PermalinkRepository(DbConnection::build())
        ->findCategoryIdByPermalink($permalink);

    return $id !== null ? (string) $id : null;
}

/**
 * returns a category id that has used before this permalink (or null)
 * @param string $permalink
 */
function get_cat_id_from_old_permalink($permalink): ?string
{
    $id = new PermalinkRepository(DbConnection::build())
        ->findOldCategoryId($permalink);

    return $id !== null ? (string) $id : null;
}

/**
 * deletes the permalink associated with a category
 * returns true on success
 * @param int $cat_id the target category id
 * @param bool $save if true, the current category-permalink association
 * is saved in the old permalinks table in case external links hit it
 */
function delete_cat_permalink($cat_id, $save): bool
{
    return new PermalinkService(new PermalinkRepository(DbConnection::build()))
        ->deleteCatPermalink($cat_id, $save);
}

/**
 * sets a new permalink for a category
 * returns true on success
 * @param int $cat_id the target category id
 * @param string $permalink the new permalink
 * @param bool $save if true, the current category-permalink association
 * is saved in the old permalinks table in case external links hit it
 */
function set_cat_permalink($cat_id, $permalink, $save): bool
{
    return new PermalinkService(new PermalinkRepository(DbConnection::build()))
        ->setCatPermalink($cat_id, $permalink, $save);
}

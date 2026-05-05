<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Core\PageState;
use Piwigo\Cache\RequestCache;
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+
/** returns a category id that corresponds to the given permalink (or null)
 */
function get_cat_id_from_permalink(string $permalink): ?int
{
    return ServiceLocator::get(PermalinkRepository::class)
        ->findCategoryIdByPermalink($permalink);
}

/** returns a category id that has used before this permalink (or null)
 */
function get_cat_id_from_old_permalink(string $permalink): ?int
{
    return ServiceLocator::get(PermalinkRepository::class)
        ->findOldCategoryId($permalink);
}


/** deletes the permalink associated with a category
 * returns true on success
 *  string  the target category id
 * @param boolean $save if true, the current category-permalink association
 * is saved in the old permalinks table in case external links hit it
 */
function delete_cat_permalink(string $cat_id, $save): bool
{
    $permalinkRepo = ServiceLocator::get(PermalinkRepository::class);
    $permalink = $permalinkRepo->findPermalinkByCategoryId((int) $cat_id);

    if (!isset($permalink)) {// no permalink; nothing to do
        return true;
    }
    if ($save) {
        $old_cat_id = get_cat_id_from_old_permalink((string)$permalink);
        if (isset($old_cat_id) and $old_cat_id != $cat_id) {
            PageState::current()->addError(
                sprintf(
                    l10n('Permalink %s has been previously used by album %s. Delete from the permalink history first'),
                    $permalink,
                    $old_cat_id
                )
            );
            return false;
        }
    }

    $permalinkRepo->clearCategoryPermalink((int) $cat_id);

    RequestCache::clearNs('cat_names');
    if ($save) {
        if (isset($old_cat_id)) {
            $permalinkRepo->markOldPermalinkDeleted((int) $cat_id, $permalink);
        } else {
            $permalinkRepo->insertOldPermalinkDeleted($permalink, (int) $cat_id);
        }
    }
    return true;
}

/** sets a new permalink for a category
 * returns true on success
 *  string  the target category id
 * @param string $permalink the new permalink
 * @param boolean $save if true, the current category-permalink association
 * is saved in the old permalinks table in case external links hit it
 */
function set_cat_permalink(string $cat_id, string $permalink, $save): bool
{
    $sanitized_permalink = preg_replace('#[^a-zA-Z0-9_/-]#', '', (string) $permalink);
    $sanitized_permalink = trim((string) $sanitized_permalink, '/');
    $sanitized_permalink = str_replace('//', '/', $sanitized_permalink);
    if ($sanitized_permalink != $permalink
        or preg_match('#^(\d)+(-.*)?$#', (string) $permalink)) {
        PageState::current()->addError('{'.$permalink.'} '.l10n('The permalink name must be composed of a-z, A-Z, 0-9, "-", "_" or "/". It must not be numeric or start with number followed by "-"'));
        return false;
    }

    // check if the new permalink is actively used
    $existing_cat_id = get_cat_id_from_permalink($permalink);
    if (isset($existing_cat_id)) {
        if ($existing_cat_id == $cat_id) {// no change required
            return true;
        } else {
            PageState::current()->addError(
                sprintf(
                    l10n('Permalink %s is already used by album %s'),
                    $permalink,
                    $existing_cat_id
                )
            );
            return false;
        }
    }

    // check if the new permalink was historically used
    $old_cat_id = get_cat_id_from_old_permalink($permalink);
    if (isset($old_cat_id) and $old_cat_id != $cat_id) {
        PageState::current()->addError(
            sprintf(
                l10n('Permalink %s has been previously used by album %s. Delete from the permalink history first'),
                $permalink,
                $old_cat_id
            )
        );
        return false;
    }

    if (!delete_cat_permalink($cat_id, $save)) {
        return false;
    }

    $permalinkRepo = ServiceLocator::get(PermalinkRepository::class);

    if (isset($old_cat_id)) {// the new permalink must not be active and old at the same time
        assert($old_cat_id == $cat_id);
        $permalinkRepo->deleteOldPermalink((int) $old_cat_id, $permalink);
    }

    $permalinkRepo->setCategoryPermalink((int) $cat_id, $permalink);

    RequestCache::clearNs('cat_names');

    return true;
}

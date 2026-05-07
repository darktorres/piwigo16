<?php

declare(strict_types=1);

namespace Piwigo\Permalink;

use Piwigo\Cache\RequestCache;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;

final class PermalinkService
{
    public function deleteCatPermalink(string $catId, bool $save): bool
    {
        $repo      = ServiceLocator::get(PermalinkRepository::class);
        $permalink = $repo->findPermalinkByCategoryId((int) $catId);

        if (!isset($permalink)) {
            return true;
        }
        if ($save) {
            $oldCatId = $repo->findOldCategoryId((string) $permalink);
            if (isset($oldCatId) && $oldCatId != $catId) {
                PageState::current()->addError(
                    sprintf(
                        Lang::t('Permalink %s has been previously used by album %s. Delete from the permalink history first'),
                        $permalink,
                        $oldCatId
                    )
                );
                return false;
            }
        }

        $repo->clearCategoryPermalink((int) $catId);
        RequestCache::clearNs('cat_names');

        if ($save) {
            $oldCatId2 = $repo->findOldCategoryId((string) $permalink);
            if (isset($oldCatId2)) {
                $repo->markOldPermalinkDeleted((int) $catId, $permalink);
            } else {
                $repo->insertOldPermalinkDeleted($permalink, (int) $catId);
            }
        }
        return true;
    }

    public function setCatPermalink(string $catId, string $permalink, bool $save): bool
    {
        $sanitized = preg_replace('#[^a-zA-Z0-9_/-]#', '', $permalink);
        $sanitized = trim((string) $sanitized, '/');
        $sanitized = str_replace('//', '/', $sanitized);
        if ($sanitized !== $permalink || preg_match('#^(\d)+(-.*)?$#', $permalink)) {
            PageState::current()->addError('{' . $permalink . '} ' . Lang::t('The permalink name must be composed of a-z, A-Z, 0-9, "-", "_" or "/". It must not be numeric or start with number followed by "-"'));
            return false;
        }

        $repo = ServiceLocator::get(PermalinkRepository::class);

        $existingCatId = $repo->findCategoryIdByPermalink($permalink);
        if (isset($existingCatId)) {
            if ($existingCatId == $catId) {
                return true;
            }
            PageState::current()->addError(
                sprintf(Lang::t('Permalink %s is already used by album %s'), $permalink, $existingCatId)
            );
            return false;
        }

        $oldCatId = $repo->findOldCategoryId($permalink);
        if (isset($oldCatId) && $oldCatId != $catId) {
            PageState::current()->addError(
                sprintf(
                    Lang::t('Permalink %s has been previously used by album %s. Delete from the permalink history first'),
                    $permalink,
                    $oldCatId
                )
            );
            return false;
        }

        if (!$this->deleteCatPermalink($catId, $save)) {
            return false;
        }

        if (isset($oldCatId)) {
            $repo->deleteOldPermalink((int) $oldCatId, $permalink);
        }

        $repo->setCategoryPermalink((int) $catId, $permalink);
        RequestCache::clearNs('cat_names');
        return true;
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Permalink;

use Piwigo\Core\Lang;

/**
 * Category-permalink business logic: validation, uniqueness against both
 * the live and historical (old_permalinks) permalink sets, cache
 * invalidation.
 *
 * Constructor-injects PermalinkRepository directly -- no ServiceLocator
 * (doesn't exist in this repo; DI is plain constructor injection via the
 * PHP-DI container throughout, established since P8), unlike the reference
 * implementation's much-later, controller-era PermalinkService built
 * during its own "sweep admin/include/ free function call sites" pass
 * (ServiceLocator::get(PermalinkRepository::class), PageState/RequestCache
 * dependencies) -- that shape belongs to P21/P22 (controller migration),
 * not this phase's scope.
 */
final readonly class PermalinkService
{
    public function __construct(
        private PermalinkRepository $repo,
    ) {}

    /**
     * Deletes the permalink associated with a category. Returns true on
     * success.
     *
     * @param bool $save if true, the current category-permalink association
     * is saved in the old permalinks table in case external links hit it
     */
    public function deleteCatPermalink(int $catId, bool $save): bool
    {
        $permalink = $this->repo->findPermalinkByCategoryId($catId);
        if ($permalink === null) { // no permalink; nothing to do
            return true;
        }

        $oldCatId = null;
        if ($save) {
            $oldCatId = $this->repo->findOldCategoryId($permalink);
            if ($oldCatId !== null && $oldCatId !== $catId) {
                \Piwigo\Core\PageState::current()->addError(sprintf(
                    Lang::t('Permalink %s has been previously used by album %s. Delete from the permalink history first'),
                    $permalink,
                    $oldCatId,
                ));

                return false;
            }
        }

        $this->repo->clearCategoryPermalink($catId);
        \Piwigo\Core\ProcessCache::forget('cat_names'); // force regeneration

        if ($save) {
            if ($oldCatId !== null) {
                $this->repo->markOldPermalinkDeleted($catId, $permalink);
            } else {
                $this->repo->insertOldPermalinkDeleted($catId, $permalink);
            }
        }

        return true;
    }

    /**
     * Sets a new permalink for a category. Returns true on success.
     *
     * @param bool $save if true, the current category-permalink association
     * is saved in the old permalinks table in case external links hit it
     */
    public function setCatPermalink(int $catId, string $permalink, bool $save): bool
    {
        $sanitized_permalink = preg_replace('#[^a-zA-Z0-9_/-]#', '', $permalink);
        $sanitized_permalink = trim((string) $sanitized_permalink, '/');
        $sanitized_permalink = str_replace('//', '/', $sanitized_permalink);
        if ($sanitized_permalink !== $permalink
            or (bool) preg_match('#^(\d)+(-.*)?$#', $permalink)) {
            \Piwigo\Core\PageState::current()->addError('{' . $permalink . '} ' . Lang::t('The permalink name must be composed of a-z, A-Z, 0-9, "-", "_" or "/". It must not be numeric or start with number followed by "-"'));

            return false;
        }

        // check if the new permalink is actively used
        $existingCatId = $this->repo->findCategoryIdByPermalink($permalink);
        if ($existingCatId !== null) {
            if ($existingCatId === $catId) { // no change required
                return true;
            }
            \Piwigo\Core\PageState::current()->addError(sprintf(
                Lang::t('Permalink %s is already used by album %s'),
                $permalink,
                $existingCatId,
            ));

            return false;
        }

        // check if the new permalink was historically used
        $oldCatId = $this->repo->findOldCategoryId($permalink);
        if ($oldCatId !== null && $oldCatId !== $catId) {
            \Piwigo\Core\PageState::current()->addError(sprintf(
                Lang::t('Permalink %s has been previously used by album %s. Delete from the permalink history first'),
                $permalink,
                $oldCatId,
            ));

            return false;
        }

        if (! $this->deleteCatPermalink($catId, $save)) {
            return false;
        }

        if ($oldCatId !== null) { // the new permalink must not be active and old at the same time
            $this->repo->deleteOldPermalink($oldCatId, $permalink);
        }

        $this->repo->setCategoryPermalink($catId, $permalink);
        \Piwigo\Core\ProcessCache::forget('cat_names'); // force regeneration

        return true;
    }

    /**
     * Permanently deletes an old-permalink history row by its permalink
     * value. Returns true on success, appending a PageState error entry
     * and returning false if nothing matched.
     */
    public function deleteOldPermalinkByValue(string $permalink): bool
    {
        if (! $this->repo->deleteOldPermalinkByValue($permalink)) {
            \Piwigo\Core\PageState::current()->addError(Lang::t('Cannot delete the old permalink !'));

            return false;
        }

        return true;
    }
}

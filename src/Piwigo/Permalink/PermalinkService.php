<?php

declare(strict_types=1);

namespace Piwigo\Permalink;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\ProcessCache;

/**
 * Category-permalink business logic: validation, uniqueness against both
 * the live and retired (old_permalinks) permalink sets, cache
 * invalidation.
 */
final readonly class PermalinkService
{
    public function __construct(
        private Lang $lang,
        private PermalinkRepository $repo,
        private ProcessCache $processCache,
        private PageState $pageState,
    ) {}

    /**
     * Deletes the permalink associated with a category. Returns true on
     * success.
     *
     * clearCategoryPermalink() and (when $save) markOldPermalinkDeleted()/
     * insertOldPermalinkDeleted() are two writes for one logical "retire
     * this permalink" operation -- wrapped in one transaction via
     * $entityManager so a failure between them can't leave a category with
     * its live permalink cleared but no old-permalink history row
     * recorded for it.
     *
     * @param bool $save if true, the current category-permalink association
     * is saved in the old permalinks table in case external links hit it
     */
    public function deleteCatPermalink(int $catId, bool $save, EntityManagerInterface $entityManager): bool
    {
        $permalink = $this->repo->findPermalinkByCategoryId($catId);
        if ($permalink === null) { // no permalink; nothing to do
            return true;
        }

        $oldCatId = null;
        if ($save) {
            $oldCatId = $this->repo->findOldCategoryId($permalink);
            if ($oldCatId !== null && $oldCatId !== $catId) {
                $this->pageState->addError(sprintf(
                    $this->lang->t('Permalink %s has been previously used by album %s. Delete from the permalink history first'),
                    $permalink,
                    $oldCatId,
                ));

                return false;
            }
        }

        $entityManager->getConnection()->transactional(function () use ($catId, $save, $oldCatId, $permalink): void {
            $this->repo->clearCategoryPermalink($catId);

            if ($save) {
                if ($oldCatId !== null) {
                    $this->repo->markOldPermalinkDeleted($catId, $permalink);
                } else {
                    $this->repo->insertOldPermalinkDeleted($catId, $permalink);
                }
            }
        });
        $this->processCache->forget('cat_names'); // force regeneration

        return true;
    }

    /**
     * Sets a new permalink for a category. Returns true on success.
     *
     * deleteOldPermalink() and setCategoryPermalink() below are two writes
     * for the "assign this permalink" half of the operation -- wrapped in
     * one transaction via $entityManager so a failure between them can't
     * leave a stale old-permalink history row blocking the very permalink
     * this call was supposed to free up.
     *
     * @param bool $save if true, the current category-permalink association
     * is saved in the old permalinks table in case external links hit it
     */
    public function setCatPermalink(int $catId, string $permalink, bool $save, EntityManagerInterface $entityManager): bool
    {
        $sanitized_permalink = preg_replace('#[^a-zA-Z0-9_/-]#', '', $permalink);
        $sanitized_permalink = trim((string) $sanitized_permalink, '/');
        $sanitized_permalink = str_replace('//', '/', $sanitized_permalink);
        if ($sanitized_permalink !== $permalink
            or (bool) preg_match('#^(\d)+(-.*)?$#', $permalink)) {
            $this->pageState->addError('{' . $permalink . '} ' . $this->lang->t('The permalink name must be composed of a-z, A-Z, 0-9, "-", "_" or "/". It must not be numeric or start with number followed by "-"'));

            return false;
        }

        // check if the new permalink is actively used
        $existingCatId = $this->repo->findCategoryIdByPermalink($permalink);
        if ($existingCatId !== null) {
            if ($existingCatId === $catId) { // no change required
                return true;
            }
            $this->pageState->addError(sprintf(
                $this->lang->t('Permalink %s is already used by album %s'),
                $permalink,
                $existingCatId,
            ));

            return false;
        }

        // check if the new permalink is recorded in the old-permalinks history
        $oldCatId = $this->repo->findOldCategoryId($permalink);
        if ($oldCatId !== null && $oldCatId !== $catId) {
            $this->pageState->addError(sprintf(
                $this->lang->t('Permalink %s has been previously used by album %s. Delete from the permalink history first'),
                $permalink,
                $oldCatId,
            ));

            return false;
        }

        if (! $this->deleteCatPermalink($catId, $save, $entityManager)) {
            return false;
        }

        $entityManager->getConnection()->transactional(function () use ($catId, $permalink, $oldCatId): void {
            if ($oldCatId !== null) { // the new permalink must not be active and old at the same time
                $this->repo->deleteOldPermalink($oldCatId, $permalink);
            }

            $this->repo->setCategoryPermalink($catId, $permalink);
        });
        $this->processCache->forget('cat_names'); // force regeneration

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
            $this->pageState->addError($this->lang->t('Cannot delete the old permalink !'));

            return false;
        }

        return true;
    }
}

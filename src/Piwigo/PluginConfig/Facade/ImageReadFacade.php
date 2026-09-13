<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig\Facade;

use Piwigo\Caddie\CaddieRepository;
use Piwigo\Category\CategoryRepository;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\Projection\Image;

/**
 * Narrow, purpose-built read facade handed out by `ExtensionContext::
 * images()` -- never the existing whole `CategoryService`/`ImageService`
 * directly (most of those methods take internal collaborators as
 * parameters or are unrestricted mutations, not a safe surface to hand a
 * plugin whole), and never raw SQL (a real plugin's own code comment
 * admits using raw SQL specifically "to bypass permission checks").
 *
 * Every method here is grounded in a real caller, traced from
 * `../piwigo16-plugins/AdminTools_16.3.0/include/events.inc.php`:
 * `isInCaddie()` (its own raw `SELECT element_id FROM caddie WHERE
 * element_id = ...` query), `getAddedBy()` (its own raw `SELECT added_by
 * FROM images WHERE id = ...` query, used to gate a "photo owner" quick-
 * edit button), and `getRepresentativePictureId()` (its own
 * `$page['category']['representative_picture_id']` comparison, used to
 * show/hide a "set as representative" toggle).
 */
final readonly class ImageReadFacade
{
    public function __construct(
        private CaddieRepository $caddieRepository,
        private ImageRepository $imageRepository,
        private CategoryRepository $categoryRepository,
    ) {}

    public function isInCaddie(int $userId, int $imageId): bool
    {
        return in_array($imageId, $this->caddieRepository->findElementIdsForUser($userId), true);
    }

    public function getAddedBy(int $imageId): ?int
    {
        $imageIdVo = ImageId::tryFrom($imageId);
        if (! $imageIdVo instanceof ImageId) {
            return null;
        }

        return $this->imageRepository->findById($imageIdVo)?->addedBy;
    }

    public function getRepresentativePictureId(int $categoryId): ?int
    {
        $categoryIdVo = CategoryId::tryFrom($categoryId);
        if (! $categoryIdVo instanceof CategoryId) {
            return null;
        }

        return $this->categoryRepository->findById($categoryIdVo)?->representativePictureId;
    }

    /**
     * `ImageRepository::findByIds()` already does the real fetch but
     * returns an `int`-keyed map in DB-result order, not a `list` in the
     * caller's own requested order -- grounded in
     * `bootstrap_darkroom_16.d/include/themecontroller.php`'s own
     * `getAllThumbnailsInCategory()` (P61), which needs its carousel's
     * thumbnails in the same order as the id list it was given. Any id
     * not found is silently dropped, matching this facade's own other
     * methods' "never found -- null/absent, never an error" convention.
     *
     * @param list<int|string> $ids
     * @return list<Image>
     */
    public function findByIdsOrdered(array $ids): array
    {
        $byId = $this->imageRepository->findByIds($ids);

        $ordered = [];
        foreach ($ids as $id) {
            $image = $byId[(int) $id] ?? null;
            if ($image instanceof Image) {
                $ordered[] = $image;
            }
        }

        return $ordered;
    }

    /**
     * Paginated raw-id descending scan -- `$cursor: null` means "start
     * from the highest id". Grounded in a real caller: gdThumb's own
     * legacy `admin.php`'s `getMissingDerivative` handler (`SELECT *
     * FROM IMAGES_TABLE WHERE id < start_id ORDER BY id DESC LIMIT
     * $qlimit`, `docs/plugin-porting/gdthumb-port-analysis.md`) -- a
     * generic core equivalent exists
     * ({@see \Piwigo\Controller\Api\Images\ImageMissingDerivativesController})
     * but only scans the site's own admin-configured *defined*
     * derivative types, never an arbitrary plugin-chosen custom size, so
     * it can't cover this case.
     *
     * @return list<int>
     */
    public function findIdsBefore(?int $cursor, int $limit): array
    {
        $cursorVo = $cursor !== null ? ImageId::tryFrom($cursor) : null;

        return $this->imageRepository->findIdsBefore($cursorVo, $limit);
    }
}

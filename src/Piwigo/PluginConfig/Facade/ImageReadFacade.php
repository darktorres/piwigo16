<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig\Facade;

use Piwigo\Caddie\CaddieRepository;
use Piwigo\Category\CategoryRepository;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Image\ImageRepository;

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
        return $this->categoryRepository->findById($categoryId)?->representativePictureId;
    }
}

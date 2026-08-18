<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig\Facade;

use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\ImageService;
use Piwigo\Tag\TagService;

/**
 * Narrow, purpose-built write facade handed out by `ExtensionContext::
 * imagesWrite()` -- same discipline as `ImageReadFacade`'s own docblock:
 * never `ImageService`/`TagService`/raw SQL directly, and never a
 * manifest-gated capability either (considered and rejected -- see this
 * fork's own plan history for P29.6's write-facade design: write access
 * is no more baked-in-authorized than a core admin controller calling
 * `ImageService` directly already is, so gating access to the facade
 * itself wouldn't address the real risk).
 *
 * Every method here is grounded in a real caller, traced from
 * `../piwigo16-plugins/AdminTools_16.3.0/include/events.inc.php`'s own
 * `admintools_save_picture()`: `single_update()` on `IMAGES_TABLE`
 * (name/author/comment/date_creation, plus `level` when the acting user
 * is an admin), `set_tags()`, and `delete_elements(..., true)`.
 */
final readonly class ImageWriteFacade
{
    public function __construct(
        private ImageService $imageService,
        private TagService $tagService,
        private UrlServiceInterface $urlService,
    ) {}

    public function updateDescriptiveFields(int $imageId, ?string $name = null, ?string $author = null, ?string $comment = null, ?string $dateCreation = null): void
    {
        $this->imageService->updateDescriptiveFields(ImageId::from($imageId), $name, $author, $comment, $dateCreation);
    }

    public function updateLevel(int $imageId, int $level): void
    {
        $this->imageService->updateLevelForImages([$imageId], $level);
    }

    /**
     * @param string|list<string> $rawTags
     */
    public function setTags(int $imageId, string|array $rawTags, bool $allowCreate = true): void
    {
        $this->tagService->setTags($this->tagService->getTagIds($rawTags, $allowCreate), $imageId);
    }

    /**
     * `$physicalDeletion` defaults to `true`, not `ImageService::
     * deleteElements()`'s own conservative `false` default --
     * AdminTools' real call (`delete_elements(array($page['image_id']),
     * true)`) explicitly wants the files removed from disk, not just
     * the DB rows. Silently inheriting the service's own default here
     * would have been a real behavior change for the plugin this facade
     * exists to port.
     */
    public function delete(int $imageId, bool $physicalDeletion = true): void
    {
        $this->imageService->deleteElements([$imageId], $this->urlService, $physicalDeletion);
    }
}

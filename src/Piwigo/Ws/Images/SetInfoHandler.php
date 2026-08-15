<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ValidationPattern;
use Piwigo\Core\WsError;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Image\Projection\Image;
use Piwigo\Tag\TagService;
use Piwigo\Ws\Request\TagListRequest;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.images.setInfo` -- admin only. Sets details of an image.
 *
 * Every field except `image_id`/`single_value_mode`/`multiple_value_mode`
 * is registered with a null default (always present, never absent) --
 * this method mutates its own `$params` copy in place (`strip_tags()`ing
 * a value before re-reading the same key as `$update[$key]`), the same
 * "whole raw array is what's consumed" shape as
 * `Images\FilteredSearchCreateHandler`, so this reads/mutates a local
 * `@var`-narrowed copy directly rather than a dedicated Params DTO.
 */
final readonly class SetInfoHandler implements WsAction
{
    public function __construct(
        private CurrentConfig $currentConfig,
        private ImageRepository $imageRepository,
        private ImageService $imageService,
        private ActivityService $activityService,
        private TagService $tagService,
        private ImageCategoryRelationsHelper $imageCategoryRelationsHelper,
        private EntityManagerInterface $entityManager,
        private WsHelper $wsHelper,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params, Server $server): ?WsErrorResponse
    {
        // MethodDefinition's own registration for this method guarantees
        // this exact shape before __invoke() ever runs -- WsAction::
        // __invoke()'s own $params type can't express that (every handler
        // shares the same loose array<mixed> contract), so it's asserted
        // locally at this one call site instead.
        /** @var array{image_id: int, file: string|null, name: string|null, author: string|null, date_creation: string|null, comment: string|null, categories: string|null, tag_ids: string|null, level: int|null, single_value_mode: string, multiple_value_mode: string, pwg_token?: string, ...} */
        $params = $params;

        $csrfError = $this->wsHelper->checkSecurityToken(
            is_string($params['pwg_token'] ?? null) ? $params['pwg_token'] : null,
        );
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $imageId = ImageId::tryFrom($params['image_id']);
        $imageRow = $imageId instanceof ImageId ? $this->imageRepository->findById($imageId) : null;

        if (! $imageRow instanceof Image) {
            return new WsErrorResponse(404, 'image_id not found');
        }
        // Unboxed here rather than kept as the typed object -- this method
        // reads $image_row[$key] for a dynamically-iterated column name
        // list below, not a fixed set of named properties.
        $image_row = $imageRow->toArray();

        // database registration
        $update = [];

        $info_columns = [
            'name',
            'author',
            'comment',
            'level',
            'date_creation',
        ];

        foreach ($info_columns as $key) {
            if (isset($params[$key])) {
                if (! $this->currentConfig->allowHtmlDescriptions) {
                    $params[$key] = strip_tags((string) $params[$key], '<b><strong><em><i>');
                }

                if ($params['single_value_mode'] === 'fill_if_empty') {
                    // $image_row[$key] is int|null|string for every key in
                    // $info_columns (Image::toArray()) -- false/0.0/[] can
                    // never actually occur, so they're dropped from the
                    // haystack rather than kept as unreachable dead entries.
                    if (in_array($image_row[$key], [null, 0, '0', ''], true)) {
                        $update[$key] = $params[$key];
                    }
                } elseif ($params['single_value_mode'] === 'replace') {
                    $update[$key] = $params[$key];
                } else {
                    return new WsErrorResponse(
                        500,
                        '[ws_images_setInfo]'
          . ' invalid parameter single_value_mode "' . $params['single_value_mode'] . '"'
          . ', possible values are {fill_if_empty, replace}.'
                    );
                }
            }
        }

        if (isset($params['file'])) {
            if (($image_row['storage_category_id'] ?? 0) !== 0) {
                return new WsErrorResponse(
                    500,
                    '[ws_images_setInfo] updating "file" is forbidden on photos added by synchronization'
                );
            }

            // prevent XSS, remove HTML tags
            $update['file'] = strip_tags($params['file']);
            if ($update['file'] === '' || $update['file'] === '0') {
                unset($update['file']);
            }
        }

        if (count(array_keys($update)) > 0) {
            $this->imageService->updateFields($imageId, $update);
            $this->entityManager->clear();
            $this->activityService->record('photo', $params['image_id'], 'edit');
        }

        if (isset($params['categories'])) {
            $this->imageCategoryRelationsHelper->addImageCategoryRelations(
                $imageId,
                $params['categories'],
                ($params['multiple_value_mode'] === 'replace' ? true : false)
            );
        }

        // and now, let's create tag associations
        $tagService = $this->tagService;

        if (isset($params['tag_ids'])) {
            $tag_ids = [];

            foreach (explode(',', $params['tag_ids']) as $candidate) {
                $candidate = trim($candidate);

                if ((bool) preg_match(ValidationPattern::ID, $candidate)) {
                    $tag_ids[] = TagId::from((int) $candidate);
                }
            }

            if ($params['multiple_value_mode'] === 'replace') {
                $tagService->setTags(
                    $tag_ids,
                    $params['image_id']
                );
            } elseif ($params['multiple_value_mode'] === 'append') {
                $tagService->addTags(
                    $tag_ids,
                    [$params['image_id']]
                );
            } else {
                return new WsErrorResponse(
                    500,
                    '[ws_images_setInfo]'
        . ' invalid parameter multiple_value_mode "' . $params['multiple_value_mode'] . '"'
        . ', possible values are {replace, append}.'
                );
            }
        }

        // Temporary use of the batch manager's unit mode,
        // not to be used by an external application,
        // as this code bellow will be deleted when a tag selector is added.
        $tagListRequest = TagListRequest::fromGlobals();
        if ($tagListRequest->present) {
            if (isset($params['tag_ids'])) {
                return new WsErrorResponse(WsError::INVALID_PARAM, 'Do not use tag_list and tag_ids at the same time.');
            }

            // TagService::getTagIds()/tagIdFromTagName() go through
            // TagRepository's parameterized DBAL queries, so no manual
            // escaping is needed here.
            $cleaned_tag_list = [];
            foreach ($tagListRequest->items as $tag_candidate) {
                $cleaned_tag_list[] = strip_tags(is_string($tag_candidate) ? $tag_candidate : '');
            }

            $tag_list = $tagService->getTagIds($cleaned_tag_list);
            $tagService->setTags($tag_list, $params['image_id']);
        }

        PermissionCacheInvalidator::invalidate();

        return null;
    }
}

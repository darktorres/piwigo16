<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Config\Config;
use Piwigo\Core\ValidationPattern;
use Piwigo\Csrf\CsrfService;
use Piwigo\Image\ImageRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.images.setInfo` — update image properties; single/multiple_value_mode control fill vs replace. */
final readonly class SetInfoHandler implements WsAction
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private CategoryAdminService $categoryAdminService,
        private CsrfService $csrfService,
        private ImageRepository $imageRepository,
        private TagAdminService $tagAdminService,
        private UserAdminService $userAdminService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        if (isset($params['pwg_token']) && $this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $setImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $image      = $this->imageRepository->findById($setImageId);
        if ($image === null) {
            return new PwgError(404, 'image_id not found');
        }
        $existingValues = [
            'name'          => $image->name,
            'author'        => $image->author,
            'comment'       => $image->comment,
            'level'         => $image->level,
            'date_creation' => $image->dateCreation?->value,
        ];
        $update            = [];
        $singleValueMode   = is_string($params['single_value_mode'] ?? null) ? $params['single_value_mode'] : '';
        $multipleValueMode = is_string($params['multiple_value_mode'] ?? null) ? $params['multiple_value_mode'] : '';
        foreach (['name', 'author', 'comment', 'level', 'date_creation'] as $key) {
            if (isset($params[$key])) {
                if (!Config::allowHtmlDescriptions() || !isset($params['pwg_token'])) {
                    $params[$key] = strip_tags(is_scalar($params[$key]) ? (string) $params[$key] : '', '<b><strong><em><i>');
                }
                if ($singleValueMode === 'fill_if_empty') {
                    $existing = $existingValues[$key] ?? null;
                    if ($existing === null || $existing === '' || $existing === 0) {
                        $update[$key] = $params[$key];
                    }
                } elseif ($singleValueMode === 'replace') {
                    $update[$key] = $params[$key];
                } else {
                    return new PwgError(500, '[ws_images_setInfo] invalid parameter single_value_mode "' . $singleValueMode . '", possible values are {fill_if_empty, replace}.');
                }
            }
        }
        if (isset($params['file'])) {
            if ($image->storageCategoryId !== null) {
                return new PwgError(500, '[ws_images_setInfo] updating "file" is forbidden on photos added by synchronization');
            }
            $update['file'] = strip_tags(is_string($params['file']) ? $params['file'] : '');
            if (empty($update['file'])) {
                unset($update['file']);
            }
        }
        if (count($update) > 0) {
            $this->imageRepository->updateById($setImageId, $update);
            $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, $setImageId, 'edit'));
        }
        if (isset($params['categories'])) {
            $this->categoryAdminService->addImageCategoryRelations($setImageId, is_string($params['categories']) ? $params['categories'] : '', $multipleValueMode === 'replace');
        }
        if (isset($params['tag_ids'])) {
            $tagIds = [];
            foreach (explode(',', is_string($params['tag_ids']) ? $params['tag_ids'] : '') as $candidate) {
                $candidate = trim($candidate);
                if (preg_match(ValidationPattern::ID, $candidate)) {
                    $tagIds[] = $candidate;
                }
            }
            if ($multipleValueMode === 'replace') {
                $this->tagAdminService->setTags($tagIds, $setImageId);
            } elseif ($multipleValueMode === 'append') {
                $this->tagAdminService->addTags($tagIds, [$setImageId]);
            } else {
                return new PwgError(500, '[ws_images_setInfo] invalid parameter multiple_value_mode "' . $multipleValueMode . '", possible values are {replace, append}.');
            }
        }
        if (isset($_REQUEST['tag_list'])) {
            if (isset($params['tag_ids'])) {
                return new PwgError(WsError::InvalidParam->value, 'Do not use tag_list and tag_ids at the same time.');
            }
            $requestTagList = is_array($_REQUEST['tag_list']) ? $_REQUEST['tag_list'] : [];
            foreach ($requestTagList as $idx => $tagCandidate) {
                $requestTagList[$idx] = strip_tags(stripslashes(is_string($tagCandidate) ? $tagCandidate : ''));
            }
            $tagList = $this->tagAdminService->getTagIds($requestTagList);
            $this->tagAdminService->setTags($tagList, $setImageId);
        }
        $this->userAdminService->invalidateUserCache();
        return null;
    }
}

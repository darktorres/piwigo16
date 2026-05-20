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
        $input = SetInfoParams::fromArray($params);
        if ($input->pwgToken !== null && $this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        $setImageId = $input->imageId;
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
        $singleValueMode   = $input->singleValueMode;
        $multipleValueMode = $input->multipleValueMode;
        $allowHtml         = Config::allowHtmlDescriptions() && $input->pwgToken !== null;
        $incoming = [
            'name'          => $input->name,
            'author'        => $input->author,
            'comment'       => $input->comment,
            'level'         => $input->level,
            'date_creation' => $input->dateCreation,
        ];
        foreach ($incoming as $key => $val) {
            if ($val === null) {
                continue;
            }
            $sanitized = $val;
            if (!$allowHtml) {
                $sanitized = strip_tags(is_scalar($val) ? (string) $val : '', '<b><strong><em><i>');
            }
            if ($singleValueMode === 'fill_if_empty') {
                $existing = $existingValues[$key] ?? null;
                if ($existing === null || $existing === '' || $existing === 0) {
                    $update[$key] = $sanitized;
                }
            } elseif ($singleValueMode === 'replace') {
                $update[$key] = $sanitized;
            } else {
                return new PwgError(500, '[ws_images_setInfo] invalid parameter single_value_mode "' . $singleValueMode . '", possible values are {fill_if_empty, replace}.');
            }
        }
        if ($input->file !== null) {
            if ($image->storageCategoryId !== null) {
                return new PwgError(500, '[ws_images_setInfo] updating "file" is forbidden on photos added by synchronization');
            }
            $update['file'] = strip_tags($input->file);
            if (empty($update['file'])) {
                unset($update['file']);
            }
        }
        if (count($update) > 0) {
            $this->imageRepository->updateById($setImageId, $update);
            $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, $setImageId, 'edit'));
        }
        if ($input->categories !== null) {
            $this->categoryAdminService->addImageCategoryRelations($setImageId, $input->categories, $multipleValueMode === 'replace');
        }
        if ($input->tagIds !== null) {
            $tagIds = [];
            foreach (explode(',', $input->tagIds) as $candidate) {
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
            if ($input->tagIds !== null) {
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

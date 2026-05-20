<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Activity\ActivityAction;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Activity\Details\GenericDetails;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\Enum\Privacy;
use Piwigo\Config\Config;
use Piwigo\Csrf\CsrfService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.categories.setInfo` — update an album's name/comment/status/visibility/commentable. */
final readonly class SetInfoHandler implements WsAction
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private CategoryAdminService $categoryAdminService,
        private CategoryRepository $categoryRepository,
        private CategoryService $categoryService,
        private CsrfService $csrfService,
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
        $categoryId = $input->categoryId;
        $category   = $this->categoryRepository->findCategoryById($categoryId);
        if ($category === null) {
            return new PwgError(404, 'category_id not found');
        }
        if ($input->status !== null) {
            $statusEnum = Privacy::tryFrom($input->status);
            if ($statusEnum === null) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid status, only public/private');
            }
            if ($statusEnum !== $category->status) {
                $this->categoryAdminService->setCatStatus([$categoryId], $statusEnum);
            }
        }
        $update = ['id' => $categoryId];
        foreach (['visible' => $input->visibleRaw, 'commentable' => $input->commentableRaw] as $paramName => $paramVal) {
            if ($paramVal !== null && !preg_match('/^(true|false)$/i', $paramVal)) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid param ' . $paramName . ' : ' . $paramVal);
            }
        }
        $paramVisibleBool = $input->visibleRaw !== null ? strtolower($input->visibleRaw) === 'true' : null;
        if ($input->visibleRaw !== null && $paramVisibleBool !== null && $paramVisibleBool !== $category->visible) {
            $this->categoryAdminService->setCatVisible([$categoryId], $input->visibleRaw);
        }
        $performUpdate = false;
        $allowHtml     = Config::allowHtmlDescriptions() && $input->pwgToken !== null;
        foreach (['name' => $input->name, 'comment' => $input->comment, 'commentable' => $input->commentableRaw] as $key => $val) {
            if ($val !== null) {
                $performUpdate = true;
                $update[$key]  = $allowHtml ? $val : strip_tags($val);
            }
        }
        if ($input->commentableRaw !== null && $input->applyCommentableToSubalbums) {
            $subcats = $this->categoryService->getSubcatIds([$categoryId]);
            if (count($subcats) > 0) {
                $this->categoryRepository->setCommentable(array_map(intval(...), $subcats), $input->commentableRaw === 'true');
            }
        }
        if ($performUpdate) {
            $updateFields = $update;
            unset($updateFields['id']);
            $this->categoryRepository->updateById($categoryId, $updateFields);
        }
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $categoryId, ActivityAction::Edit, new GenericDetails(['fields' => implode(',', array_keys($update))])));
        return null;
    }
}

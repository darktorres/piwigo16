<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
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
        if (isset($params['pwg_token']) && $this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $categoryId = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        $category   = $this->categoryRepository->findCategoryById($categoryId);
        if ($category === null) {
            return new PwgError(404, 'category_id not found');
        }
        if (!empty($params['status'])) {
            if (!in_array($params['status'], ['private', 'public'])) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid status, only public/private');
            }
            if ($params['status'] !== $category->status->value) {
                $this->categoryAdminService->setCatStatus([$categoryId], is_string($params['status']) ? $params['status'] : '');
            }
        }
        $update = ['id' => $categoryId];
        foreach (['visible', 'commentable'] as $paramName) {
            $paramValStr = is_scalar($params[$paramName] ?? null) ? (string) $params[$paramName] : '';
            if (isset($params[$paramName]) && !preg_match('/^(true|false)$/i', $paramValStr)) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid param ' . $paramName . ' : ' . $paramValStr);
            }
        }
        $paramVisible = $params['visible'] ?? null;
        $paramVisibleBool = is_bool($paramVisible) ? $paramVisible : (is_string($paramVisible) ? strtolower($paramVisible) === 'true' : null);
        if (!empty($params['visible']) && $paramVisibleBool !== null && $paramVisibleBool !== $category->visible) {
            $this->categoryAdminService->setCatVisible([$categoryId], is_string($params['visible']) ? $params['visible'] : (is_bool($params['visible']) ? $params['visible'] : false));
        }
        $infoColumns   = ['name', 'comment', 'commentable'];
        $performUpdate = false;
        foreach ($infoColumns as $key) {
            if (isset($params[$key])) {
                $performUpdate = true;
                $keyValStr     = is_scalar($params[$key]) ? (string) $params[$key] : '';
                $update[$key]  = (!Config::allowHtmlDescriptions() || !isset($params['pwg_token'])) ? strip_tags($keyValStr) : $keyValStr;
            }
        }
        if (isset($params['commentable']) && isset($params['apply_commentable_to_subalbums']) && $params['apply_commentable_to_subalbums']) {
            $subcats = $this->categoryService->getSubcatIds([$categoryId]);
            if (count($subcats) > 0) {
                $commentableVal = is_string($params['commentable']) ? $params['commentable'] : 'false';
                $this->categoryRepository->setCommentable(array_map(intval(...), $subcats), $commentableVal === 'true');
            }
        }
        if ($performUpdate) {
            $updateFields = $update;
            unset($updateFields['id']);
            $this->categoryRepository->updateById($categoryId, $updateFields);
        }
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $categoryId, 'edit', ['fields' => implode(',', array_keys($update))]));
        return null;
    }
}

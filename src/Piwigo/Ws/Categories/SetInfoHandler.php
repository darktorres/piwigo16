<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Categories;

use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Projection\Category;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\WsError;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.categories.setInfo` -- admin only. Changes properties of an album.
 */
final readonly class SetInfoHandler implements WsAction
{
    public function __construct(
        private CategoryService $categoryService,
        private CategoryRepository $categoryRepository,
        private ActivityService $activityService,
        private CurrentConfig $currentConfig,
        private WsHelper $wsHelper,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params, Server $server): ?WsErrorResponse
    {
        $input = SetInfoParams::fromArray($params);

        $csrfError = $this->wsHelper->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        // does the category really exist?
        $category = $this->categoryRepository->findById($input->categoryId);
        if (! $category instanceof Category) {
            return new WsErrorResponse(404, 'category_id not found');
        }

        $categoryService = $this->categoryService;

        if (! in_array($input->status, [null, ''], true)) {
            if (! in_array($input->status, ['private', 'public'], true)) {
                return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid status, only public/private');
            }

            if ($input->status !== $category->status) {
                $categoryService->setCatStatus([$input->categoryId], $input->status);
            }
        }

        $update = [
            'id' => $input->categoryId,
        ];

        foreach ([
            'visible' => $input->visible,
            'commentable' => $input->commentable,
        ] as $param_name => $value) {
            if ($value !== null and ! (bool) preg_match('/^(true|false)$/i', $value)) {
                return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid param ' . $param_name . ' : ' . $value);
            }
        }

        if ($input->visible !== null
            and filter_var($input->visible, FILTER_VALIDATE_BOOLEAN) !== $category->visible) {
            $categoryService->setCatVisible([$input->categoryId], $input->visible);
        }

        // 'commentable' is handled separately below (same setCatX() shape
        // as 'visible' above, needing bool coercion for the tinyint
        // column), not through this generic strip_tags loop.
        $info_values = [
            'name' => $input->name,
            'comment' => $input->comment,
        ];

        $perform_update = false;
        foreach ($info_values as $key => $value) {
            if ($value !== null) {
                $perform_update = true;
                $update[$key] = $this->currentConfig->allowHtmlDescriptions ? $value : strip_tags($value);
            }
        }

        if ($input->commentable !== null && $input->applyCommentableToSubalbums !== null && (bool) $input->applyCommentableToSubalbums) {
            $subcats = $categoryService->getSubcatIds([$input->categoryId]);
            if (count($subcats) > 0) {
                $categoryService->setCatCommentable($subcats, $input->commentable);
            }
        } elseif ($input->commentable !== null
            and filter_var($input->commentable, FILTER_VALIDATE_BOOLEAN) !== $category->commentable) {
            $categoryService->setCatCommentable([$input->categoryId], $input->commentable);
        }

        if ($perform_update) {
            $updateFields = $update;
            unset($updateFields['id']);
            $categoryService->updateFields(CategoryId::from($input->categoryId), $updateFields);
        }

        $this->activityService->record('album', $input->categoryId, 'edit', [
            'fields' => implode(',', array_keys($update)),
        ]);

        return null;
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Category\CategoryRepository;
use Piwigo\Comment\CommentService;
use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Users\PermissionService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.images.addComment` — post a comment on an image. */
final readonly class AddCommentHandler implements WsAction
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private CommentService $commentService,
        private PermissionService $permissionService,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        if (!Config::activateComments()) {
            return new PwgError(403, 'Comments are disabled');
        }
        $input    = AddCommentParams::fromArray($params);
        $pImageId = $input->imageId;
        $perm = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'id', 'visible_categories' => 'id', 'visible_images' => 'image_id'], ' AND');
        if (!$this->categoryRepository->isImageInVisibleCommentableCategory($pImageId, $perm->where, $perm->params, $perm->types)) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid image_id');
        }
        $comm = ['author' => $input->author, 'content' => $input->content, 'image_id' => $pImageId];
        $infos         = [];
        $commentAction = $this->commentService->insertUserComment($comm, $input->key, $infos);
        switch ($commentAction) {
            case 'reject':
                $infos[] = Lang::t('Your comment has NOT been registered because it did not pass the validation rules');
                return new PwgError(403, implode('; ', $infos));
            case 'validate':
            case 'moderate':
                return ['comment' => new PwgNamedStruct(['id' => $comm['id'], 'validation' => $commentAction === 'validate'])];
            default:
                return new PwgError(500, 'Unknown comment action ' . $commentAction);
        }
    }
}

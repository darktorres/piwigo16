<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Piwigo\Comment\CommentService;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\WsError;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionService;
use Piwigo\Ws\NamedStruct;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.images.addComment` -- adds a comment to an image.
 */
final readonly class AddCommentHandler implements WsAction
{
    public function __construct(
        private CurrentConfig $currentConfig,
        private PermissionService $permissionService,
        private ImageService $imageService,
        private CommentService $commentService,
        private Lang $lang,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{comment: NamedStruct}
     */
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = AddCommentParams::fromArray($params);

        if (! $this->currentConfig->activateComments) {
            return new WsErrorResponse(403, 'Comments are disabled');
        }

        $permissionCriteria = $this->permissionService->getPermissionCriteria();

        if (! $this->imageService->isImageCommentableWithCondition(ImageId::from($input->imageId), $permissionCriteria)) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid image_id');
        }

        $comm = [
            'author' => trim($input->author),
            'content' => trim($input->content),
            'image_id' => $input->imageId,
        ];

        $infos = [];
        $comment_action = $this->commentService
            ->insertComment($comm, $input->key, $infos);

        switch ($comment_action) {
            case 'reject':
                $infos[] = $this->lang->t('Your comment has NOT been registered because it did not pass the validation rules');
                return new WsErrorResponse(403, implode('; ', $infos));

            case 'validate':
            case 'moderate':
                $ret = [
                    'id' => $comm['id'] ?? 0,
                    'validation' => $comment_action === 'validate',
                ];
                return [
                    'comment' => new NamedStruct($ret),
                ];

            default:
                return new WsErrorResponse(500, 'Unknown comment action ' . $comment_action);
        }
    }
}

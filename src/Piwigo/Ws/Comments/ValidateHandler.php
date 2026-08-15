<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Comments;

use Override;
use Piwigo\Comment\CommentService;
use Piwigo\Core\Lang;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.userComments.validate` -- admin bulk-validate user comments.
 */
final readonly class ValidateHandler implements WsAction
{
    public function __construct(
        private CommentService $commentService,
        private Lang $lang,
        private WsHelper $wsHelper,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|string
    {
        $input = ValidateParams::fromArray($params);

        $csrfError = $this->wsHelper->checkSecurityToken($input->pwgToken, message: $this->lang->t('Invalid security token'));
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $this->commentService->validateComment($input->commentIds);
        return 'Comment successfully validated';
    }
}

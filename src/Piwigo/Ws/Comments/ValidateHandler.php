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
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Csrf\CsrfService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.userComments.validate` -- admin bulk-validate user comments.
 */
final readonly class ValidateHandler implements WsAction
{
    public function __construct(
        private CommentService $commentService,
        private Lang $lang,
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|string
    {
        $input = ValidateParams::fromArray($params);

        if (new CsrfService($this->currentConfig)->getToken() !== $input->pwgToken) {
            return new WsErrorResponse(403, $this->lang->t('Invalid security token'));
        }

        $this->commentService->validateComment($input->commentIds);
        return 'Comment successfully validated';
    }
}

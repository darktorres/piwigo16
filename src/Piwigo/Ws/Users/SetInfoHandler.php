<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Users;

use LogicException;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\PageState;
use Piwigo\Core\WsError;
use Piwigo\Csrf\CsrfService;
use Piwigo\Users\UserService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.users.setInfo` -- admin only. Updates a user. Leave a field blank to keep the current value.
 */
final readonly class SetInfoHandler implements WsAction
{
    public function __construct(
        private UserService $userService,
        private CurrentConfig $currentConfig,
        private PageState $pageState,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array<int|string, mixed> WsErrorResponse, or the result of
     *   the pwg.users.getList invocation
     */
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = SetInfoParams::fromArray($params);

        if (new CsrfService($this->currentConfig)->getToken() !== $input->pwgToken) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        $updated_users = $this->userService->checkAndSaveUserInfos($params, $this->pageState);

        if (isset($updated_users['error'])) {
            // UserService::checkAndSaveUserInfos() is declared to return plain
            // `array`; its error branches always
            // populate error.code (int) and error.message (string), but that
            // shape isn't statically expressed, so narrow defensively here
            // rather than trust the mixed offsets.
            $error = $updated_users['error'];
            $error_code = is_array($error) && is_int($error['code'] ?? null) ? $error['code'] : WsError::INVALID_PARAM;
            $error_message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : 'Invalid parameters';
            return new WsErrorResponse($error_code, $error_message);
        }

        $updated_infos = is_array($updated_users['infos'] ?? null) ? $updated_users['infos'] : [];

        return $this->narrowGetListResult($server->invoke('pwg.users.getList', [
            'user_id' => $updated_users['user_id'],
            'display' => 'basics,' . implode(',', array_keys($updated_infos)),
        ]));
    }

    /**
     * $server->invoke() is a genuine string-keyed dynamic dispatcher (see
     * Server's own class docblock) -- its declared return type is
     * `mixed` by design. This narrows it to the real shape this specific
     * sub-invocation (always 'pwg.users.getList', which itself really
     * does return WsErrorResponse|array<int|string, mixed>) is known to
     * return, the same "resolve, narrow, or throw" idiom already used
     * throughout this codebase for other statically-unknowable-but-
     * really-fixed-shape values.
     *
     * @return WsErrorResponse|array<int|string, mixed>
     */
    private function narrowGetListResult(mixed $result): WsErrorResponse|array
    {
        if (! $result instanceof WsErrorResponse && ! is_array($result)) {
            throw new LogicException('pwg.users.getList returned an unexpected type');
        }

        return $result;
    }
}

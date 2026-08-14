<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Users;

use Piwigo\Core\WsError;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.users.preferences.set` -- set a preferences parameter for the current user.
 */
final readonly class PreferencesSetHandler implements WsAction
{
    public function __construct(
        private PreferencesService $preferencesService,
        private CurrentUser $currentUser,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array<string, mixed> matches
     *   Users\User::$preferences' own by-design arbitrary per-user
     *   key-value shape (User.php's own $preferences docblock)
     */
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = PreferencesSetParams::fromArray($params);

        if (! (bool) preg_match('/^[a-zA-Z0-9_-]+$/', $input->param)) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid param name #' . $input->param . '#');
        }

        $value = stripslashes($input->value ?? '');
        $decoded_value = $input->isJson ? json_decode($value, true) : $value;

        $this->preferencesService->updateParam($input->param, $decoded_value);

        return $this->currentUser->get()
            ->preferences;
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.users.preferencesSet` — set a JSON-encodable preference param for the current user. */
final readonly class PreferencesSetHandler implements WsAction
{
    public function __construct(
        private PreferencesService $preferencesService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        $prefParam = is_string($params['param'] ?? null) ? $params['param'] : '';
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $prefParam)) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid param name #' . $prefParam . '#');
        }
        $value = stripslashes(is_string($params['value'] ?? null) ? $params['value'] : '');
        if ($params['is_json']) {
            $value = json_decode($value, true);
        }
        $this->preferencesService->userprefsUpdateParam($prefParam, $value);
        return CurrentUser::get()->rawAttributes['preferences'] ?? null;
    }
}

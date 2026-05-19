<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsParamException;

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
        try {
            $input = PreferencesSetParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(WsError::InvalidParam->value, $e->getMessage());
        }
        $value = stripslashes($input->value);
        $this->preferencesService->userprefsUpdateParam(
            $input->param,
            $input->isJson ? json_decode($value, true) : $value,
        );
        return CurrentUser::get()->rawAttributes['preferences'] ?? null;
    }
}

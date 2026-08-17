<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Session;

use Override;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PUT /api/v1/session/preferences/{param}` --
 * `pwg.users.preferences.set`'s real replacement. No auth gate at all,
 * matching its WS predecessor (no `requiresAuth` on its own
 * registration) -- preferences are stored per session-user, even a
 * guest pseudo-user's own. `{param}` is route-constrained to
 * `[a-zA-Z0-9_-]+`, so an invalid name 404s at the routing layer
 * instead of a generic error response.
 */
final readonly class PreferenceSetController implements ControllerInterface
{
    public function __construct(
        private PreferencesService $preferencesService,
        private CurrentUser $currentUser,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $routeArgs = $request->getAttribute('route_args');
        $param = is_array($routeArgs) && is_string($routeArgs['param'] ?? null) ? $routeArgs['param'] : '';

        $input = PreferenceSetInput::fromArray(JsonBody::decode($request));
        $value = $input->value ?? '';
        $decodedValue = $input->isJson ? json_decode($value, true) : $value;

        $this->preferencesService->updateParam($param, $decodedValue);

        return ResponseFactory::json([
            'preferences' => $this->currentUser->get()
                ->preferences,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Users;

use Override;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\PageState;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Users\UserInfoUpdateFailureReason;
use Piwigo\Users\UserInfoUpdateInput;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PATCH /api/v1/users/{id}` -- `pwg.users.setInfo`'s real replacement,
 * admin + CSRF, one user per call. Set `groupIds` to `[-1]` to dissociate
 * the user from every group, a sentinel passed through unchanged to
 * `UserService::checkAndSaveUserInfos()`.
 */
final readonly class UserUpdateController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private UserService $userService,
        private UserRowFetcher $userRowFetcher,
        private PageState $pageState,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $csrfDenied = $this->csrfGuard->check($request);
        if ($csrfDenied instanceof ResponseInterface) {
            return $csrfDenied;
        }

        $routeArgs = $request->getAttribute('route_args');
        $rawId = is_array($routeArgs) ? ($routeArgs['id'] ?? null) : null;
        $userId = is_string($rawId) ? (int) $rawId : 0;

        $input = UserUpdateInput::fromArray(JsonBody::decode($request));

        $result = $this->userService->checkAndSaveUserInfos(new UserInfoUpdateInput(
            userIds: [$userId],
            username: $input->username,
            password: $input->password,
            email: $input->email,
            status: $input->status,
            level: $input->level,
            language: $input->language,
            theme: $input->theme,
            groupIds: $input->groupIds,
            nbImagePage: $input->nbImagePage,
            recentPeriod: $input->recentPeriod,
            expand: $input->expand,
            showNbComments: $input->showNbComments,
            showNbHits: $input->showNbHits,
            enabledHigh: $input->enabledHigh,
        ), $this->pageState);

        if ($result->isFailure) {
            assert($result->failureReason instanceof UserInfoUpdateFailureReason);
            $status = match ($result->failureReason) {
                UserInfoUpdateFailureReason::Forbidden => 403,
                UserInfoUpdateFailureReason::InvalidInput => 422,
            };

            return ResponseFactory::problem($status === 403 ? 'Forbidden' : 'Unprocessable Entity', $status, $result->failureMessage);
        }

        $rows = $this->userRowFetcher->byIds([UserId::from($userId)]);

        return ResponseFactory::json($rows[0] ?? [
            'id' => $userId,
        ]);
    }
}

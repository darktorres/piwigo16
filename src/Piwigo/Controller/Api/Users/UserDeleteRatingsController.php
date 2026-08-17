<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Users;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Rate\RateService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/users/{id}/actions/delete-ratings` --
 * `pwg.rates.delete`'s real replacement, admin + CSRF
 * (`requiresAuth: true` on the WS side really means admin_only).
 * `Ws\Rates\DeleteHandler` itself has no CSRF check -- this fresh
 * implementation adds one anyway, matching every other mutating
 * `/api/v1` endpoint.
 */
final readonly class UserDeleteRatingsController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private RateService $rateService,
        private EntityManagerInterface $entityManager,
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

        $input = UserDeleteRatingsInput::fromArray(JsonBody::decode($request));

        $changes = $this->rateService->deleteByOptionalConditions(
            UserId::from($userId),
            $input->anonymousId,
            $input->imageId === null ? null : ImageId::from($input->imageId),
        );
        $this->entityManager->clear();
        if ($changes > 0) {
            $this->rateService->updateRatingScore($this->entityManager);
        }

        return ResponseFactory::json([
            'deletedCount' => $changes,
        ]);
    }
}

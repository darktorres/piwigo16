<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Session;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Caddie\CaddieEntity;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/session/caddie` -- `pwg.caddie.add`'s real replacement,
 * admin + CSRF (the caddie/lightbox is a Batch Manager feature, not a
 * general visitor one). Adds images to the calling admin's own caddie.
 */
final readonly class CaddieAddController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private CurrentUser $currentUser,
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

        $input = CaddieAddInput::fromArray(JsonBody::decode($request));
        $userId = $this->currentUser->get()
            ->id->value;

        $added = $this->entityManager->getRepository(CaddieEntity::class)->addElements($userId, $input->imageIds);

        return ResponseFactory::json([
            'addedCount' => $added,
        ], 201);
    }
}

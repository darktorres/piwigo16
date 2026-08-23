<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Tags;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageService;
use Piwigo\Tag\TagService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /api/v1/tags/{id}` -- `pwg.tags.delete`'s real replacement,
 * admin + CSRF, one tag per call (a REST single-resource delete; no
 * first-party caller needs a batch-by-id-list shape that couldn't just
 * call this once per tag). `{id}` is
 * route-constrained to `\d+`, so an unmatched id 404s at the routing
 * layer before this controller ever runs. `TagService::deleteTags()`
 * records its own activity entry and dispatches `Tag\Event\DeleteTags`
 * itself -- unlike add/rename/duplicate, nothing extra is needed here.
 */
final readonly class TagDeleteController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private TagService $tagService,
        private EntityManagerInterface $entityManager,
        private ImageService $imageService,
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
        $tagId = is_string($rawId) ? (int) $rawId : 0;

        if (! $this->tagService->existsById($tagId)) {
            return ResponseFactory::problem('Not Found', 404, 'This tag does not exist.');
        }

        $this->tagService->deleteTags([TagId::from($tagId)], $this->entityManager, $this->imageService);

        return ResponseFactory::json([
            'id' => $tagId,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Tags;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tag\Event\RenderTagUrl;
use Piwigo\Tag\Projection\ImageTagPair;
use Piwigo\Tag\TagService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/tags/{id}/actions/duplicate` -- `pwg.tags.duplicate`'s
 * real replacement, admin + CSRF. `{id}` is route-constrained to `\d+`,
 * so an unmatched id 404s at the routing layer before this controller
 * ever runs.
 */
final readonly class TagDuplicateController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private TagService $tagService,
        private ActivityService $activityService,
        private EventDispatcher $eventDispatcher,
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
        $sourceTagId = is_string($rawId) ? (int) $rawId : 0;

        if (! $this->tagService->existsById($sourceTagId)) {
            return ResponseFactory::problem('Not Found', 404, 'This tag does not exist.');
        }

        $input = TagDuplicateInput::fromArray(JsonBody::decode($request));
        $copyName = $input->name;

        if ($this->tagService->existsByName($copyName)) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'This name is already taken.');
        }

        $copyUrlNameEvent = $this->eventDispatcher->dispatch(new RenderTagUrl($copyName));
        $copyUrlName = $copyUrlNameEvent->tagName;
        $destinationTagId = $this->tagService->duplicateTag($copyName, $copyUrlName)
            ->value;
        $this->entityManager->clear();

        $this->activityService->record('tag', $destinationTagId, 'add', [
            'action' => 'duplicate',
            'source_tag' => $sourceTagId,
        ]);

        $sourceImageIds = $this->tagService->getImageIdsForTagIds([TagId::from($sourceTagId)]);

        $inserts = [];
        foreach ($sourceImageIds as $imageId) {
            $inserts[] = new ImageTagPair(
                imageId: $imageId,
                tagId: $destinationTagId,
            );
            $this->activityService->record('photo', $imageId, 'edit', [
                'add-tag' => $destinationTagId,
            ]);
        }

        if ($inserts !== []) {
            $this->tagService->copyImageTagAssociations($inserts);
        }

        return ResponseFactory::json([
            'id' => $destinationTagId,
            'name' => $copyName,
            'urlName' => $copyUrlName,
            'count' => count($inserts),
        ], 201);
    }
}

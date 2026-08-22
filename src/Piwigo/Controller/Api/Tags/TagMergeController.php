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
use Piwigo\Tag\Event\MergeTags;
use Piwigo\Tag\Projection\ImageTagPair;
use Piwigo\Tag\TagService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/tags/actions/merge` -- `pwg.tags.merge`'s real
 * replacement, admin + CSRF. Merges one or more source tags into a
 * destination tag, keeping the destination and deleting the sources.
 */
final readonly class TagMergeController implements ControllerInterface
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

        $input = TagMergeInput::fromArray(JsonBody::decode($request));

        $allTags = $input->mergeTagIds;
        $allTags[] = $input->destinationTagId;
        $allTags = array_unique($allTags);
        $mergeTagIds = array_diff($input->mergeTagIds, [$input->destinationTagId]);

        if ($this->tagService->countExistingIds(array_values($allTags)) !== count($allTags)) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'All tags does not exist.');
        }

        $imagesInMergeTags = array_values(array_unique(
            $this->tagService->getImageIdsForTagIds(array_map(TagId::from(...), array_values($mergeTagIds)))
        ));
        $imagesInDestination = $this->tagService->getImageIdsForTagIds([TagId::from($input->destinationTagId)]);
        $imagesToAdd = array_values(array_diff($imagesInMergeTags, $imagesInDestination));

        $inserts = [];
        foreach ($imagesToAdd as $imageId) {
            $inserts[] = new ImageTagPair(
                imageId: $imageId,
                tagId: $input->destinationTagId,
            );
        }
        $this->tagService->copyImageTagAssociations($inserts, ignore: true);

        $this->activityService->record('tag', $input->destinationTagId, 'edit');
        foreach ($imagesToAdd as $imageId) {
            $this->activityService->record('photo', $imageId, 'edit', [
                'tag-add' => $input->destinationTagId,
            ]);
        }

        $this->eventDispatcher->dispatch(new MergeTags(
            TagId::from($input->destinationTagId),
            array_values(array_map(TagId::from(...), $mergeTagIds))
        ));

        $this->tagService->deleteTags(array_values(array_map(TagId::from(...), $mergeTagIds)), $this->entityManager);

        return ResponseFactory::json([
            'destinationTagId' => $input->destinationTagId,
            'deletedTagIds' => $input->mergeTagIds,
            'imagesInMergedTag' => array_merge($imagesInDestination, $imagesToAdd),
        ]);
    }
}

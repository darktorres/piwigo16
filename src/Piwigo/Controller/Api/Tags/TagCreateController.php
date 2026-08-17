<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Tags;

use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Tag\TagService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/tags` -- `pwg.tags.add`'s real replacement, admin +
 * CSRF, one tag per call -- batch creation would need a list-of-names
 * body shape, a known limitation.
 */
final readonly class TagCreateController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private TagService $tagService,
        private ActivityService $activityService,
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

        $input = TagCreateInput::fromArray(JsonBody::decode($request));
        $outcome = $this->tagService->createTag($input->name);

        if ($outcome->error !== null) {
            return ResponseFactory::problem('Unprocessable Entity', 422, $outcome->error);
        }

        // success()'s own contract guarantees info/id are non-null whenever
        // error is null (just checked above).
        $id = $outcome->id ?? 0;
        $this->activityService->record('tag', $id, 'add');

        $newTag = $this->tagService->getById(TagId::from($id));

        return ResponseFactory::json([
            'id' => $id,
            'name' => $newTag->name ?? '',
            'urlName' => $newTag->urlName ?? '',
            'message' => $outcome->info ?? '',
        ], 201);
    }
}

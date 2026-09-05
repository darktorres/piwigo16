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
use Piwigo\Tag\Event\GetTagAltNames;
use Piwigo\Tag\Event\RenderTagName;
use Piwigo\Tag\Event\RenderTagUrl;
use Piwigo\Tag\Projection\Tag;
use Piwigo\Tag\TagService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `PATCH /api/v1/tags/{id}` -- `pwg.tags.rename`'s real replacement,
 * admin + CSRF. `{id}` is route-constrained to `\d+` (RouteDefinitions),
 * so an unmatched id 404s at the routing layer before this controller
 * ever runs.
 */
final readonly class TagRenameController implements ControllerInterface
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
        $tagId = is_string($rawId) ? (int) $rawId : 0;
        $tagIdVo = TagId::tryFrom($tagId);

        if (! $tagIdVo instanceof TagId || ! $this->tagService->existsById($tagIdVo)) {
            return ResponseFactory::problem('Not Found', 404, 'This tag does not exist.');
        }

        $input = TagRenameInput::fromArray(JsonBody::decode($request));
        $tagName = strip_tags($input->name);

        $existingNames = $this->tagService->getOtherNames($tagIdVo);
        if (in_array($tagName, $existingNames, true)) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'This name is already taken.');
        }

        $this->activityService->record('tag', $tagId, 'edit');

        if ($tagName !== '') {
            $urlNameEvent = $this->eventDispatcher->dispatch(new RenderTagUrl($tagName));
            $this->tagService->renameTag($tagIdVo, $tagName, $urlNameEvent->tagName);
        }
        $this->entityManager->clear();

        $renamedTag = $this->tagService->getById($tagIdVo);
        assert($renamedTag instanceof Tag);

        $rawName = $renamedTag->name;
        $nameEvent = $this->eventDispatcher->dispatch(new RenderTagName($rawName, [
            'id' => $renamedTag->id->value,
            'name' => $rawName,
            'url_name' => $renamedTag->urlName,
        ]));
        $altNamesEvent = $this->eventDispatcher->dispatch(new GetTagAltNames([], $rawName));

        return ResponseFactory::json([
            'id' => $renamedTag->id->value,
            'name' => $nameEvent->tagName,
            'urlName' => $renamedTag->urlName,
            'nameRaw' => $rawName,
            'altNames' => array_values($altNamesEvent->value),
        ]);
    }
}

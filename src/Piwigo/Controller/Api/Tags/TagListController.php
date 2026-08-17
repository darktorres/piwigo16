<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Tags;

use Override;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Tag\TagService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/tags` -- `pwg.tags.getAdminList`'s real replacement, admin
 * only (permissions are not taken into account). `pwg.tags.getList`'s own permission-filtered browsing
 * shape (`TagService::getAvailableTags()`, a genuinely different query,
 * not just a field-visibility difference on this one) is a separate,
 * external-only REST concern -- not this endpoint.
 */
final readonly class TagListController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private TagService $tagService,
        private HtmlRenderingInterface $htmlRenderer,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $tags = array_map(
            static fn (array $tag): array => [
                'id' => $tag['id'],
                'name' => $tag['name'],
                'urlName' => $tag['url_name'],
                'nameRaw' => $tag['name_raw'],
                'lastmodified' => $tag['lastmodified'],
            ],
            $this->tagService->getAllTags($this->htmlRenderer)
        );

        return ResponseFactory::json([
            'tags' => $tags,
        ]);
    }
}

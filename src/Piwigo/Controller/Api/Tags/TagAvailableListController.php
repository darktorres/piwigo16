<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Tags;

use Override;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Tag\TagService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/tags/available` -- `pwg.tags.getList`'s real replacement.
 * Public, permission-filtered: `TagService::getAvailableTags()` (not
 * `getAllTags()`, which `TagListController`/`GET /api/v1/tags` uses) --
 * a genuinely different query, tags with no visible image are excluded
 * and a `counter`/`url` pair is added per tag. Kept as its own resource
 * rather than folded into `GET /api/v1/tags` behind a permission check --
 * see that controller's own docblock for why.
 */
final readonly class TagAvailableListController implements ControllerInterface
{
    public function __construct(
        private TagService $tagService,
        private HtmlRenderingInterface $htmlRenderer,
        private UrlServiceInterface $urlService,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $sortByCounter = ($query['sortByCounter'] ?? null) === 'true';

        $tags = $this->tagService->getAvailableTags();
        if ($sortByCounter) {
            usort($tags, static fn (array $a, array $b): int => $b['counter'] <=> $a['counter']);
        } else {
            usort($tags, $this->htmlRenderer->tagAlphaCompare(...));
        }

        $result = array_map(
            fn (array $tag): array => [
                'id' => $tag['id'],
                'name' => $tag['name'],
                'urlName' => $tag['url_name'],
                'nameRaw' => $tag['name_raw'],
                'lastmodified' => $tag['lastmodified'],
                'counter' => $tag['counter'],
                'url' => $this->urlService->makeIndexUrl([
                    'section' => 'tags',
                    'tags' => [$tag],
                ]),
            ],
            $tags
        );

        return ResponseFactory::json([
            'tags' => $result,
        ]);
    }
}

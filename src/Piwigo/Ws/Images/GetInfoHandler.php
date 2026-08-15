<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentService;
use Piwigo\Comment\Projection\CommentSummary;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Event\Picture\RenderElementName;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Html\HtmlService;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Rate\RateService;
use Piwigo\Tag\TagService;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\Encoder\ResponseEncoder;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\NamedStruct;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.images.getInfo` -- returns detailed information for an element.
 */
final readonly class GetInfoHandler implements WsAction
{
    public function __construct(
        private ImageService $imageService,
        private PermissionService $permissionService,
        private WsHelper $wsHelper,
        private EventDispatcher $eventDispatcher,
        private TagService $tagService,
        private HtmlService $htmlService,
        private UrlServiceInterface $urlService,
        private AccessControl $accessControl,
        private RateService $rateService,
        private CommentService $commentService,
        private CurrentConfig $currentConfig,
        private CurrentUser $currentUser,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array<string, mixed>
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = GetInfoParams::fromArray($params);

        $image_row = $this->imageService->getRowWithCondition(
            ImageId::from($input->imageId),
            $this->permissionService->getPermissionCriteria()
        );
        if ($image_row === null) {
            return new WsErrorResponse(404, 'image_id not found');
        }

        // id is the 'images' primary key, guaranteed numeric; captured
        // before array_merge() below widens every value of $image_row to mixed.
        assert(is_numeric($image_row['id']));
        $image_id = (int) $image_row['id'];

        // array_merge() with WsHelper::stdGetUrls()'s mixed-valued return widens
        // PHPStan's tracked shape for every other key of the original
        // fetchAssociative() row -- restate the columns this function reads
        // below (id: 'images' NOT NULL primary key, native int under
        // DBAL; file: NOT NULL; name/comment/rating_score: nullable) plus an
        // open tail for the rest of the row and the page_url/element_url/
        // download_url/derivatives keys WsHelper::stdGetUrls() injects.
        /** @var array{id: int, file: string, name: string|null, comment: string|null, rating_score: string|null, ...} $image_row */
        $image_row = array_merge($image_row, $this->wsHelper->stdGetUrls($image_row, $this->urlService));

        $image_row['name_raw'] = $image_row['name'];
        $nameEvent = $this->eventDispatcher->dispatchChange(new RenderElementName(is_string($image_row['name']) ? $image_row['name'] : '', 'getInfo'));
        $image_row['name'] = strip_tags($nameEvent->elementName);

        $image_row['comment_raw'] = $image_row['comment'];
        $descriptionEvent = $this->eventDispatcher->dispatchChange(new RenderElementDescription(is_string($image_row['comment']) ? $image_row['comment'] : '', 'getInfo'));
        $image_row['comment'] = $descriptionEvent->elementDescription;

        $related_category_rows = $this->imageService->getRelatedCategoriesForImage(
            ImageId::from($image_id),
            $this->permissionService->getPermissionCriteria()
        );

        $is_commentable = false;
        $related_categories = [];
        foreach ($related_category_rows as $row) {
            if ((bool) $row['commentable']) {
                $is_commentable = true;
            }
            unset($row['commentable']);

            $row['url'] = $this->urlService->makeIndexUrl(
                [
                    'category' => $row,
                ]
            );

            $row['page_url'] = $this->urlService->makePictureUrl(
                [
                    'image_id' => $image_row['id'],
                    'image_file' => $image_row['file'],
                    'category' => $row,
                ]
            );

            $row['id'] = is_numeric($row['id']) ? (int) $row['id'] : 0;

            $nameEvent = $this->eventDispatcher->dispatchChange(new RenderCategoryName(is_string($row['name']) ? $row['name'] : '', 'getInfo'));
            $row['name'] = strip_tags($nameEvent->categoryName);

            $related_categories[] = $row;
        }
        usort($related_categories, CategoryService::compareByGlobalRank(...));

        if ($related_categories === [] and ! $this->accessControl->isAdmin()) {
            // photo might be in the lounge? or simply orphan. A standard user should not get
            // info. An admin should still be able to get info.
            return new WsErrorResponse(401, 'Access denied');
        }

        $related_tags = $this->tagService
            ->getCommonTags([$image_id], -1, $this->htmlService);
        foreach ($related_tags as $i => $tag) {
            $tag['url'] = $this->urlService->makeIndexUrl(
                [
                    'tags' => [$tag],
                ]
            );
            $tag['page_url'] = $this->urlService->makePictureUrl(
                [
                    'image_id' => $image_row['id'],
                    'image_file' => $image_row['file'],
                    'tags' => [$tag],
                ]
            );

            unset($tag['counter']);
            $related_tags[$i] = $tag;
        }

        $rating_score_raw = $image_row['rating_score'];
        $rating = [
            'score' => $rating_score_raw,
            'count' => 0,
            'average' => null,
        ];
        if (isset($rating['score'])) {
            $rate_summary = $this->rateService->getRateSummaryForElement(ImageId::from($image_id));

            assert(is_numeric($rating_score_raw));
            $rating['score'] = (float) $rating_score_raw;
            $rating['average'] = $rate_summary->average ?? 0.0;
            $rating['count'] = $rate_summary->count;
        }

        $related_comments = [];

        $only_validated_comments = ! $this->accessControl->isAdmin();
        $commentService = $this->commentService;

        $nb_comments = $commentService->countForImage(ImageId::from($image_id), $only_validated_comments);

        if ($nb_comments > 0 and $input->commentsPerPage > 0) {
            $related_comments = array_map(
                static fn (CommentSummary $summary): array => $summary->toArray(),
                $commentService->getSummariesForImage(
                    ImageId::from($image_id),
                    $only_validated_comments,
                    $input->commentsPerPage,
                    $input->commentsPerPage * $input->commentsPage
                )
            );
        }

        $comment_post_data = null;
        if ($this->currentConfig->activateComments and
            $is_commentable and
            (! $this->accessControl->isAGuest()
              or $this->currentConfig->commentsForall
            )
        ) {
            $username = $this->currentUser->get()
                ->username->value ?? '';
            $comment_post_data['author'] = stripslashes($username);
            $comment_post_data['key'] = new EphemeralKeyService($this->currentConfig)->generate(2, (string) $input->imageId);
        }

        $ret = $image_row;
        foreach (['id', 'width', 'height', 'hit', 'filesize'] as $k) {
            if (isset($ret[$k])) {
                assert(is_numeric($ret[$k]));
                $ret[$k] = (int) $ret[$k];
            }
        }
        foreach (['path', 'storage_category_id'] as $k) {
            unset($ret[$k]);
        }

        $ret['rates'] = [
            ResponseEncoder::ATTRIBUTES_KEY => $rating,
        ];
        $ret['categories'] = new NamedArray(
            $related_categories,
            'category',
            ['id', 'url', 'page_url']
        );
        $ret['tags'] = new NamedArray(
            $related_tags,
            'tag',
            $this->wsHelper->stdGetTagXmlAttributes()
        );
        if (isset($comment_post_data)) {
            $ret['comment_post'] = [
                ResponseEncoder::ATTRIBUTES_KEY => $comment_post_data,
            ];
        }
        $ret['comments_paging'] = new NamedStruct(
            [
                'page' => $input->commentsPage,
                'per_page' => $input->commentsPerPage,
                'count' => count($related_comments),
                'total_count' => $nb_comments,
            ]
        );
        $ret['comments'] = new NamedArray(
            $related_comments,
            'comment',
            ['id', 'date']
        );

        if ($server->responseFormat !== 'rest') {
            return $ret; // for backward compatibility only
        } else {
            return [
                'image' => new NamedStruct($ret, null, ['name', 'comment']),
            ];
        }
    }
}

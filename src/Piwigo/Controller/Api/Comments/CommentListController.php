<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Comments;

use Override;
use Piwigo\Comment\CommentApiCriteria;
use Piwigo\Comment\CommentService;
use Piwigo\Comment\Projection\CommentDateRange;
use Piwigo\Comment\Projection\CommentSummaryCounts;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\DateHelper;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\SqlDialect;
use Piwigo\Html\Event\RenderCommentContent;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\Projection\SrcImageInfo;
use Piwigo\Picture\Event\RenderCommentAuthor;
use Piwigo\PluginConfig\EventDispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/comments` -- `pwg.userComments.getList`'s real
 * replacement, admin only. A composite, multi-query response (summary
 * counts, a comment page, author-count filters, computed paging) --
 * genuinely complex enough that forcing one precise shape risked getting
 * it wrong unverified, so this stays `array<string, mixed>`.
 */
final readonly class CommentListController implements ControllerInterface
{
    private const array ACCEPTED_STATUS = ['all', 'pending', 'validated'];

    private const array ACCEPTED_PER_PAGE = [5, 10, 25, 50];

    public function __construct(
        private AdminGuard $adminGuard,
        private CommentService $commentService,
        private Lang $lang,
        private CurrentConfig $currentConfig,
        private UrlServiceInterface $urlService,
        private EventDispatcher $eventDispatcher,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        if (! $this->currentConfig->activateComments) {
            return ResponseFactory::problem('Forbidden', 403, 'Comments are disabled.');
        }

        $query = $request->getQueryParams();

        $status = is_string($query['status'] ?? null) ? $query['status'] : 'all';
        if (! in_array($status, self::ACCEPTED_STATUS, true)) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'status must be: all, pending or validated.');
        }

        $perPage = isset($query['perPage']) && is_numeric($query['perPage']) ? (int) $query['perPage'] : 10;
        if (! in_array($perPage, self::ACCEPTED_PER_PAGE, true)) {
            return ResponseFactory::problem('Unprocessable Entity', 422, 'perPage must be: 5, 10, 25 or 50.');
        }

        $page = isset($query['page']) && is_numeric($query['page']) ? (int) $query['page'] : 0;
        $search = isset($query['search']) && is_string($query['search']) ? $query['search'] : null;
        $authorId = isset($query['authorId']) && is_numeric($query['authorId']) && (int) $query['authorId'] !== 0 ? (int) $query['authorId'] : null;
        $imageId = isset($query['imageId']) && is_numeric($query['imageId']) && (int) $query['imageId'] !== 0 ? (int) $query['imageId'] : null;

        $minDate = null;
        if (isset($query['minDate']) && is_string($query['minDate']) && $query['minDate'] !== '') {
            $parsedMinDate = date_create($query['minDate']);
            if ($parsedMinDate === false) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid minDate.');
            }
            $minDate = SqlDateTime::from(date_format($parsedMinDate, 'Y-m-d 00:00:00'));
        }

        $maxDate = null;
        if (isset($query['maxDate']) && is_string($query['maxDate']) && $query['maxDate'] !== '') {
            $parsedMaxDate = date_create($query['maxDate']);
            if ($parsedMaxDate === false) {
                return ResponseFactory::problem('Unprocessable Entity', 422, 'Invalid maxDate.');
            }
            $maxDate = SqlDateTime::from(date_format($parsedMaxDate, 'Y-m-d 23:59:59'));
        }

        $criteria = new CommentApiCriteria(
            authorId: $authorId !== null ? UserId::from($authorId) : null,
            imageId: $imageId !== null ? ImageId::from($imageId) : null,
            minDate: $minDate,
            maxDate: $maxDate,
            search: $search,
            status: $status,
        );

        $summary = $this->commentService->getSummaryCounts($criteria);
        if (! $summary instanceof CommentSummaryCounts) {
            return ResponseFactory::problem('Internal Server Error', 500, 'Unable to compute comments summary.');
        }

        $totalComments = match ($status) {
            'pending' => $summary->pending,
            'validated' => $summary->validated,
            default => $summary->allComments,
        };

        $comments = [];
        foreach ($this->commentService->getList($criteria, $perPage * $page, $perPage) as $row) {
            $mediumDerivative = DerivativeImage::getOne(ImageStdParams::MEDIUM, new SrcImageInfo(
                id: is_numeric($row->imageId) ? (int) $row->imageId : 0,
                path: $row->path,
                representativeExt: $row->representativeExt,
            ));
            assert($mediumDerivative instanceof DerivativeImage);

            if (! is_numeric($row->authorId) || (int) $row->authorId === 0 || (int) $row->authorId === $this->currentConfig->guestId) {
                $authorName = $row->author;
            } else {
                $authorName = $row->username ?? $row->author ?? $this->lang->t('guest');
            }

            $commentDate = $row->date ?? false;
            $commentDateAvailable = $row->dateAvailable ?? false;

            $authorEvent = $this->eventDispatcher->dispatch(new RenderCommentAuthor($authorName ?? ''));
            $contentEvent = $this->eventDispatcher->dispatch(new RenderCommentContent($row->content ?? ''));

            $comments[] = [
                'id' => $row->id,
                'adminLink' => $this->urlService->getRootUrl() . 'admin.php?page=photo-' . (string) $row->imageId,
                'mediumUrl' => $mediumDerivative->getUrl(),
                'file' => $row->file,
                'imageDateAvailable' => DateHelper::formatDate($commentDateAvailable, ['day_name', 'day', 'month', 'year', 'time']),
                'author' => $authorEvent->commentAuthor,
                'authorStatus' => is_numeric($row->authorId) && $this->currentConfig->webmasterId === (int) $row->authorId ? 'main_user' : $row->status,
                'date' => DateHelper::formatDate($commentDate, ['day_name', 'day', 'month', 'year', 'time']),
                'content' => $contentEvent->commentContent,
                'contentRaw' => $row->content,
                'isPending' => ! SqlDialect::getBoolean($row->validated),
            ];
        }

        $dates = $this->commentService->getDateRange($criteria);
        if (! $dates instanceof CommentDateRange) {
            return ResponseFactory::problem('Internal Server Error', 500, 'Unable to compute comments date range.');
        }

        $nbAuthorsIn = $this->commentService->getAuthorCounts($criteria);

        return ResponseFactory::json([
            'summary' => [
                'allComments' => $summary->allComments,
                'validated' => $summary->validated,
                'pending' => $summary->pending,
            ],
            'comments' => $comments,
            'filters' => [
                'nbAuthors' => $nbAuthorsIn,
                'startedAt' => $dates->startedAt,
                'endedAt' => $dates->endedAt,
            ],
            'paging' => [
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => max(0.0, ceil((float) $totalComments / (float) $perPage) - 1.0),
            ],
        ]);
    }
}

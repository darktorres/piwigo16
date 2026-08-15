<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Comments;

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
use Piwigo\Event\Template\RenderCommentAuthor;
use Piwigo\Event\Template\RenderCommentContent;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.userComments.getList` -- admin paginated comment moderation view.
 */
final readonly class GetListHandler implements WsAction
{
    public function __construct(
        private CommentService $commentService,
        private Lang $lang,
        private CurrentConfig $currentConfig,
        private UrlServiceInterface $urlService,
        private EventDispatcher $eventDispatcher,
    ) {}

    /**
     * A composite, multi-query response (raw summary/nb_authors aggregate
     * rows, a built-up comment list, computed paging) -- genuinely complex
     * enough that forcing one precise shape risked getting it wrong
     * unverified; left as array<string, mixed>, same as the god-class
     * method this replaces.
     *
     * @param array<mixed> $params
     * @return WsErrorResponse|array<string, mixed>
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        if (! $this->currentConfig->activateComments) {
            return new WsErrorResponse(403, 'Comments are disabled');
        }

        $input = GetListParams::fromArray($params);

        // accepted status values
        $accepted_status = ['all', 'pending', 'validated'];
        if (! in_array($input->status, $accepted_status, true)) {
            return new WsErrorResponse(401, 'Status must be: all, pending or validated');
        }

        // accepted values must match pagination options (5,10,25,50)
        $items_number = [5, 10, 25, 50];
        if (! in_array($input->perPage, $items_number, true)) {
            return new WsErrorResponse(401, 'Per page must be: 5, 10, 25 or 50');
        }

        // authorId/imageId/f_min_date/f_max_date/search/status collapse
        // into one CommentApiCriteria, built once and passed unchanged to
        // all 4 CommentService calls below -- each decides for itself
        // which fields it honors (see CommentApiCriteria's own docblock).
        // authorId/imageId are already positive-int-guaranteed by
        // GetListParams::fromArray() and f_min_date/f_max_date already
        // pass through date_format() (which can't emit SQL
        // metacharacters), so none of these are live injection risks; the
        // search term is bound as a real parameter rather than relying on
        // escaping.
        $authorId = $input->authorId !== null ? UserId::from($input->authorId) : null;
        $imageId = $input->imageId !== null ? ImageId::from($input->imageId) : null;

        $minDate = null;
        if (! in_array($input->fMinDate, [null, ''], true)) {
            $min_date = date_create($input->fMinDate);
            if ($min_date === false) {
                return new WsErrorResponse(401, 'Invalid f_min_date');
            }
            $minDate = SqlDateTime::from(date_format($min_date, 'Y-m-d 00:00:00'));
        }

        $maxDate = null;
        if (! in_array($input->fMaxDate, [null, ''], true)) {
            $max_date = date_create($input->fMaxDate);
            if ($max_date === false) {
                return new WsErrorResponse(401, 'Invalid f_max_date');
            }
            $maxDate = SqlDateTime::from(date_format($max_date, 'Y-m-d 23:59:59'));
        }

        $criteria = new CommentApiCriteria(
            authorId: $authorId,
            imageId: $imageId,
            minDate: $minDate,
            maxDate: $maxDate,
            search: $input->search,
            status: $input->status,
        );

        // summary. validated is a real tinyint(1) column -- numeric
        // literals, not enum('true','false') strings; MySQL's
        // non-numeric-string-to-int coercion would otherwise silently
        // convert 'true' to 0 too, inverting the validated/pending counts
        // (same bug class as Category's own commentable/visible columns).
        $summary = $this->commentService->getSummaryCounts($criteria);
        if (! $summary instanceof CommentSummaryCounts) {
            return new WsErrorResponse(500, 'Unable to compute comments summary');
        }
        $total_comments = $summary->allComments;

        switch ($input->status) {
            case 'pending':
                $total_comments = $summary->pending;
                break;

            case 'validated':
                $total_comments = $summary->validated;
                break;
        }

        // comments
        $list = [];
        foreach ($this->commentService->getListForAdminWs(
            $criteria,
            $input->perPage * $input->page,
            $input->perPage
        ) as $row) {

            $row_image_id = $row['image_id'];

            $medium_derivative = DerivativeImage::getOne(
                ImageStdParams::MEDIUM,
                [
                    'id' => $row_image_id,
                    'path' => $row['path'],
                    'representative_ext' => $row['representative_ext'],
                ]
            );
            // MEDIUM is a standard type, always present in the defined
            // type map — getOne() only returns null for an unknown type.
            assert($medium_derivative instanceof DerivativeImage);
            $medium = $medium_derivative->getUrl();

            $row_author = is_string($row['author']) ? $row['author'] : null;
            if (! is_numeric($row['author_id']) or (int) $row['author_id'] === 0 or (int) $row['author_id'] === $this->currentConfig->guestId) {
                $author_name = $row_author;
            } else {
                $row_username = $row['username'];
                $author_name = stripslashes((is_string($row_username) ? $row_username : null) ?? $row_author ?? $this->lang->t('guest'));
            }

            // date/date_available are both nullable columns on their
            // respective entities (CommentEntity::$date/ImageEntity::
            // $dateAvailable); format_date()'s phpDoc param forbids null,
            // so fall back to false (its "no date" sentinel) when absent.
            $comment_date = is_string($row['date']) ? $row['date'] : false;
            $comment_date_available = is_string($row['date_available']) ? $row['date_available'] : false;

            $authorEvent = $this->eventDispatcher->dispatchChange(new RenderCommentAuthor($author_name ?? ''));

            $contentEvent = $this->eventDispatcher->dispatchChange(new RenderCommentContent(is_string($row['content']) ? $row['content'] : ''));

            $list[] = [
                'id' => $row['id'],
                'admin_link' => $this->urlService
                    ->getRootUrl() . 'admin.php?page=photo-' . (string) $row_image_id,
                'medium_url' => $medium,
                'file' => $row['file'],
                'image_date_available' => DateHelper::formatDate($comment_date_available, ['day_name', 'day', 'month', 'year', 'time']),
                'author' => $authorEvent->commentAuthor,
                'author_status' => is_numeric($row['author_id']) && $this->currentConfig->webmasterId === (int) $row['author_id'] ? 'main_user' : $row['status'],
                'date' => DateHelper::formatDate($comment_date, ['day_name', 'day', 'month', 'year', 'time']),
                'content' => $contentEvent->commentContent,
                'raw_content' => $row['content'],
                'is_pending' => ! SqlDialect::getBoolean($row['validated']),
            ];
        }

        // filters
        $dates = $this->commentService->getDateRange($criteria);
        if (! $dates instanceof CommentDateRange) {
            return new WsErrorResponse(500, 'Unable to compute comments date range');
        }

        // getAuthorCounts() ignores $criteria->authorId internally -- see
        // CommentRepository::findAuthorCounts()'s own docblock.
        $nb_authors_in = $this->commentService->getAuthorCounts($criteria);

        return [
            'summary' => [
                'all_comments' => $summary->allComments,
                'validated' => $summary->validated,
                'pending' => $summary->pending,
            ],
            'comments' => $list,
            'filters' => [
                'nb_authors' => $nb_authors_in,
                'started_at' => $dates->startedAt,
                'ended_at' => $dates->endedAt,
            ],
            'paging' => [
                'page' => $input->page,
                'per_page' => $input->perPage,
                'total_pages' => max(0.0, ceil((float) $total_comments / (float) $input->perPage) - 1.0),
            ],
        ];
    }
}

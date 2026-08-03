<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Comment\CommentApiCriteria;
use Piwigo\Comment\CommentService;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Core\Lang;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\SqlDialect;
use Piwigo\Event\Template\RenderCommentAuthor;
use Piwigo\Event\Template\RenderCommentContent;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;

/**
 * P23 batch 8e-2: relocated from include/ws_functions/pwg.comments.php.
 * `pwg.userComments.*` WS methods (3 registrations, all admin_only) --
 * registered via callable arrays in include/ws_default_methods.inc.php.
 */
final class PwgComments
{
    public function __construct(
        private readonly CommentService $commentService,
        private readonly Lang $lang,
        private readonly \Piwigo\Config\CurrentConfig $currentConfig,
        private readonly \Piwigo\Core\UrlServiceInterface $urlService,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
    ) {}

    /**
     * API method
     * Get comments
     * @since 16
     *
     * @param array{status: string, search: string|null, author_id?: int, image_id?: int, f_min_date: string|null, f_max_date: string|null, page: int, per_page: int, ...} $params
     *   status: non-null string default ('all'), no 'type' flag -- always
     *   present. search/f_min_date/f_max_date: null default, no 'type' flag
     *   -- always present, string|null. author_id/image_id: WsParamFlag::OPTIONAL
     *   with no 'default' key -- may be entirely absent; WsParamType::ID
     *   guarantees a plain int when present. page: non-null int default,
     *   WsParamType::INT|WsParamType::POSITIVE -- always present. per_page: same type
     *   flag, default is $this->currentConfig->commentsPageNbComments() (a real int,
     *   confirmed 10 in config_default.inc.php) -- always present, always
     *   int.
     * A composite, multi-query response (raw summary/nb_authors aggregate
     * rows, a built-up comment list, computed paging) -- genuinely complex
     * enough that forcing one precise shape risked getting it wrong
     * unverified; left as array<string, mixed>.
     * @return PwgError|array<string, mixed>
     */
    public function getList(array $params, PwgServer &$service): PwgError|array
    {
        if (! $this->currentConfig->activateComments()) {
            return new PwgError(403, 'Comments are disabled');
        }

        // accepted status values
        $accepted_status = ['all', 'pending', 'validated'];
        if (! in_array($params['status'], $accepted_status, true)) {
            return new PwgError(401, 'Status must be: all, pending or validated');
        }

        // accepted values must match pagination options (5,10,25,50)
        $items_number = [5, 10, 25, 50];
        if (! in_array($params['per_page'], $items_number, true)) {
            return new PwgError(401, 'Per page must be: 5, 10, 25 or 50');
        }

        // Further SQL-modernization audit, Item 13: author_id/image_id/
        // f_min_date/f_max_date/search/status collapse into one
        // CommentApiCriteria, built once and passed unchanged to all 4
        // CommentService calls below -- each decides for itself which
        // fields it honors (see CommentApiCriteria's own docblock),
        // replacing the former list<SqlCondition> $where_clauses (with a
        // string-keyed 'author_id' entry used purely as a removable
        // marker) this method used to build once and mutate/reuse.
        // author_id/image_id were already WsParamType::ID-guaranteed ints
        // and f_min_date/f_max_date already passed through date_format()
        // (which can't emit SQL metacharacters), so none of these were
        // live injection risks -- the search term used to rely on
        // Connection::quote() ([SEC-18]) for escaping; now a real bound
        // parameter instead, one step further than escaping.
        $authorId = (isset($params['author_id']) and $params['author_id'] !== 0) ? $params['author_id'] : null;
        $imageId = (isset($params['image_id']) and $params['image_id'] !== 0) ? $params['image_id'] : null;

        $minDate = null;
        if (! in_array($params['f_min_date'], [null, ''], true)) {
            $min_date = date_create($params['f_min_date']);
            if ($min_date === false) {
                return new PwgError(401, 'Invalid f_min_date');
            }
            $minDate = date_format($min_date, 'Y-m-d 00:00:00');
        }

        $maxDate = null;
        if (! in_array($params['f_max_date'], [null, ''], true)) {
            $max_date = date_create($params['f_max_date']);
            if ($max_date === false) {
                return new PwgError(401, 'Invalid f_max_date');
            }
            $maxDate = date_format($max_date, 'Y-m-d 23:59:59');
        }

        $criteria = new CommentApiCriteria(
            authorId: $authorId,
            imageId: $imageId,
            minDate: $minDate,
            maxDate: $maxDate,
            search: $params['search'],
            status: $params['status'],
        );

        // summary. validated is a real tinyint(1) column now (Comment
        // domain Stage 1a) -- numeric literals, not the old
        // enum('true','false') strings; MySQL's non-numeric-string-to-int
        // coercion would otherwise silently convert 'true' to 0 too,
        // inverting the validated/pending counts (same bug class
        // Category's own commentable/visible retype found).
        $summary = $this->commentService->getSummaryCounts($criteria);
        if ($summary === null) {
            return new PwgError(500, 'Unable to compute comments summary');
        }
        // count(*)/sum(...) results are typed string|null by the driver; they
        // are always numeric here (count/sum of a non-empty aggregate), but
        // fall back to 0 rather than assume it.
        $total_comments = is_numeric($summary['all_comments']) ? (int) $summary['all_comments'] : 0;

        switch ($params['status']) {
            case 'pending':
                $total_comments = is_numeric($summary['pending']) ? (int) $summary['pending'] : 0;
                break;

            case 'validated':
                $total_comments = is_numeric($summary['validated']) ? (int) $summary['validated'] : 0;
                break;
        }

        // comments
        $list = [];
        foreach ($this->commentService->getListForAdminWs(
            $criteria,
            $params['per_page'] * $params['page'],
            $params['per_page']
        ) as $row) {

            $row_image_id = $row['image_id'];

            $medium_derivative = DerivativeImage::get_one(
                ImageStdParams::MEDIUM,
                [
                    'id' => $row_image_id,
                    'path' => $row['path'],
                    'representative_ext' => $row['representative_ext'],
                ]
            );
            // MEDIUM is a standard type, always present in the defined
            // type map — get_one() only returns null for an unknown type.
            assert($medium_derivative instanceof DerivativeImage);
            $medium = $medium_derivative->get_url();

            $row_author = is_string($row['author']) ? $row['author'] : null;
            if (! is_numeric($row['author_id']) or (int) $row['author_id'] === 0 or (int) $row['author_id'] === $this->currentConfig->guestId()) {
                $author_name = $row_author;
            } else {
                $row_username = $row['username'] ?? null;
                $author_name = stripslashes((is_string($row_username) ? $row_username : null) ?? $row_author ?? $this->lang->t('guest'));
            }

            // date/date_available are NOT NULL DATETIME columns -- always
            // string under both the legacy mysqli driver and DBAL (native
            // int/float casting only applies to INT/DECIMAL/FLOAT columns,
            // never temporal ones); format_date()'s phpDoc param forbids
            // null, so fall back to false (its "no date" sentinel) if that
            // ever isn't the case.
            $comment_date = is_string($row['date']) ? $row['date'] : false;
            $comment_date_available = is_string($row['date_available']) ? $row['date_available'] : false;

            $authorEvent = $this->eventDispatcher->dispatchChange(new RenderCommentAuthor($author_name ?? ''));

            $contentEvent = $this->eventDispatcher->dispatchChange(new RenderCommentContent(is_string($row['content']) ? $row['content'] : ''));

            $list[] = [
                'id' => $row['id'],
                'admin_link' => $this->urlService
                    ->getRootUrl() . 'admin.php?page=photo-' . (is_scalar($row_image_id) ? (string) $row_image_id : ''),
                'medium_url' => $medium,
                'file' => $row['file'],
                'image_date_available' => \Piwigo\Core\DateHelper::formatDate($comment_date_available, ['day_name', 'day', 'month', 'year', 'time']),
                'author' => $authorEvent->commentAuthor,
                'author_status' => is_numeric($row['author_id']) && $this->currentConfig->webmasterId() === (int) $row['author_id'] ? 'main_user' : $row['status'],
                'date' => \Piwigo\Core\DateHelper::formatDate($comment_date, ['day_name', 'day', 'month', 'year', 'time']),
                'content' => $contentEvent->commentContent,
                'raw_content' => $row['content'],
                'is_pending' => ! SqlDialect::getBoolean($row['validated']),
            ];
        }

        // filters
        $dates = $this->commentService->getDateRange($criteria);
        if ($dates === null) {
            return new PwgError(500, 'Unable to compute comments date range');
        }

        // getAuthorCounts() ignores $criteria->authorId internally -- see
        // CommentRepository::findAuthorCounts()'s own docblock.
        $nb_authors_in = $this->commentService->getAuthorCounts($criteria);

        return [
            'summary' => $summary,
            'comments' => $list,
            'filters' => [
                'nb_authors' => $nb_authors_in,
                'started_at' => $dates['started_at'],
                'ended_at' => $dates['ended_at'],
            ],
            'paging' => [
                'page' => $params['page'],
                'per_page' => $params['per_page'],
                'total_pages' => max(0.0, ceil((float) $total_comments / (float) $params['per_page']) - 1.0),
            ],
        ];
    }

    /**
     * API method
     * Delete comments
     * @since 16
     *
     * @param array{comment_id: array<int, int>, pwg_token: string, ...} $params
     *   neither has a 'default' key -- both mandatory, always present;
     *   FORCE_ARRAY always coerces comment_id to a list of positive ints.
     */
    public function delete(array $params, PwgServer &$service): PwgError|string
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, $this->lang->t('Invalid security token'));
        }

        $commentIds = array_values(array_map(CommentId::from(...), array_unique($params['comment_id'])));
        $this->commentService->deleteComment($commentIds);
        return 'Comment successfully deleted';
    }

    /**
     * API method
     * Validate comments
     * @since 16
     *
     * @param array{comment_id: array<int, int>, pwg_token: string, ...} $params
     *   neither has a 'default' key -- both mandatory, always present;
     *   FORCE_ARRAY always coerces comment_id to a list of positive ints.
     */
    public function validate(array $params, PwgServer &$service): PwgError|string
    {
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, $this->lang->t('Invalid security token'));
        }

        $commentIds = array_values(array_map(CommentId::from(...), array_unique($params['comment_id'])));
        $this->commentService->validateComment($commentIds);
        return 'Comment successfully validated';
    }
}

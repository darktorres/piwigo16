<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Comments;

use Doctrine\DBAL\ParameterType;
use Piwigo\Comment\CommentRepository;
use Piwigo\Comment\SqlFilterClause;
use Piwigo\Comment\SqlFilterKind;
use Piwigo\Config\Config;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Db\Tables;
use Piwigo\Event\Template\RenderCommentAuthor;
use Piwigo\Event\Template\RenderCommentContent;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeSize;
use Piwigo\Url\UrlGenerator;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Psr\EventDispatcher\EventDispatcherInterface;

/** `pwg.userComments.getList` — admin paginated comment moderation view. */
final readonly class GetListHandler implements WsAction
{
    public function __construct(
        private CommentRepository $commentRepository,
        private DateService $dateService,
        private UrlGenerator $urlGenerator,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        if (!Config::activateComments()) {
            return new PwgError(403, 'Comments are disabled');
        }
        $input = GetListParams::fromArray($params);
        if (!in_array($input->perPage, [5, 10, 25, 50])) {
            return new PwgError(401, 'Per page must be: 5, 10, 25 or 50');
        }
        /** @var list<SqlFilterClause> $filters */
        $filters = [];
        if ($input->authorId !== null) {
            $filters[] = new SqlFilterClause('author_id = ?', $input->authorId, ParameterType::INTEGER, SqlFilterKind::Author);
        }
        if ($input->imageId !== null) {
            $filters[] = new SqlFilterClause('image_id = ?', $input->imageId, ParameterType::INTEGER, SqlFilterKind::Image);
        }
        if ($input->fMinDate !== null) {
            $dmin = date_create($input->fMinDate);
            if ($dmin !== false) {
                $filters[] = new SqlFilterClause('date >= ?', date_format($dmin, 'Y-m-d 00:00:00'), ParameterType::STRING, SqlFilterKind::MinDate);
            }
        }
        if ($input->fMaxDate !== null) {
            $dmax = date_create($input->fMaxDate);
            if ($dmax !== false) {
                $filters[] = new SqlFilterClause('date <= ?', date_format($dmax, 'Y-m-d 23:59:59'), ParameterType::STRING, SqlFilterKind::MaxDate);
            }
        }
        if ($input->search !== null) {
            $filters = [new SqlFilterClause('content LIKE ?', '%' . $input->search . '%', ParameterType::STRING, SqlFilterKind::Search)];
        }

        $build = static function (array $rows): array {
            /** @var list<SqlFilterClause> $rows */
            $where  = ['1=1'];
            $params = [];
            $types  = [];
            foreach ($rows as $row) {
                $where[]  = $row->sql;
                $params[] = $row->param;
                $types[]  = $row->type;
            }
            return [$where, $params, $types];
        };
        [$whereClauses, $qParams, $qTypes] = $build($filters);

        $summary = $this->commentRepository->findCommentsSummary($whereClauses, $qParams, $qTypes);
        $totalComments = $summary->allComments;
        switch ($input->status) {
            case CommentListFilter::Pending:
                $whereClauses[] = 'validated = 0';
                $totalComments  = $summary->pending;
                break;
            case CommentListFilter::Validated:
                $whereClauses[] = 'validated = 1';
                $totalComments  = $summary->validated;
                break;
            case CommentListFilter::All:
                break;
        }
        $perPage = $input->perPage;
        $pageNum = $input->page;
        $userFields = Config::userFields();
        $rows = $this->commentRepository->findCommentsAdminList(
            $whereClauses,
            $qParams,
            $qTypes,
            Tables::users(),
            $userFields->id,
            $userFields->username,
            $perPage,
            $perPage * $pageNum,
        );
        $list = [];
        foreach ($rows as $row) {
            $imageIdValue       = $row->imageId->value;
            $authorIdValue      = $row->authorId?->value;
            $mediumDerivative   = DerivativeImage::getOne(DerivativeSize::Medium->value, ['id' => $imageIdValue, 'path' => $row->path, 'representative_ext' => $row->representativeExt]);
            $medium             = $mediumDerivative !== null ? $mediumDerivative->getUrl() : null;
            if ($authorIdValue === null || $authorIdValue === Config::guestId()) {
                $authorName = $row->author ?? '';
            } else {
                $authorName = stripslashes($row->username ?? $row->author ?? Lang::t('guest'));
            }
            $authorEvent = new RenderCommentAuthor($authorName);
            $this->dispatcher->dispatch($authorEvent);
            $contentEvent = new RenderCommentContent($row->content ?? '');
            $this->dispatcher->dispatch($contentEvent);
            $list[] = [
                'id'                   => $row->id->value,
                'admin_link'           => $this->urlGenerator->admin('photo-' . $imageIdValue),
                'medium_url'           => $medium,
                'file'                 => $row->file,
                'image_date_available' => $this->dateService->formatDate($row->dateAvailable !== null ? $row->dateAvailable->value : '', ['day_name', 'day', 'month', 'year', 'time']),
                'author'               => $authorEvent->commentAuthor,
                'author_status'        => Config::webmasterId() === $authorIdValue ? 'main_user' : $row->status,
                'date'                 => $this->dateService->formatDate($row->date !== null ? $row->date->value : '', ['day_name', 'day', 'month', 'year', 'time']),
                'content'              => $contentEvent->commentContent,
                'raw_content'          => $row->content,
                'is_pending'           => !$row->validated,
            ];
        }
        $dates = $this->commentRepository->findCommentDateRange($whereClauses, $qParams, $qTypes);

        [$authorsWhere, $authorsParams, $authorsTypes] = $build(array_values(array_filter(
            $filters,
            static fn (SqlFilterClause $f): bool => $f->kind !== SqlFilterKind::Author,
        )));
        $nbAuthorsIn = array_map(
            static fn (\Piwigo\Comment\Projection\AuthorCount $ac): array => $ac->toArray(),
            $this->commentRepository->findCommentAuthorCounts($authorsWhere, $authorsParams, $authorsTypes),
        );

        return ['summary' => ['all_comments' => $summary->allComments, 'validated' => $summary->validated, 'pending' => $summary->pending], 'comments' => $list, 'filters' => ['nb_authors' => $nbAuthorsIn, 'started_at' => $dates->startedAt, 'ended_at' => $dates->endedAt], 'paging' => ['page' => $input->page, 'per_page' => $input->perPage, 'total_pages' => max(0, (int) ceil((float) $totalComments / (float) max(1, $perPage)) - 1)]];
    }
}

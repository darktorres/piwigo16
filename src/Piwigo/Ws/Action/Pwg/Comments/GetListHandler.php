<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Comments;

use Doctrine\DBAL\ParameterType;
use Piwigo\Comment\CommentRepository;
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
        $acceptedStatus = ['all', 'pending', 'validated'];
        if (!in_array($params['status'], $acceptedStatus)) {
            return new PwgError(401, 'Status must be: all, pending or validated');
        }
        $itemsNumber = [5, 10, 25, 50];
        if (!in_array($params['per_page'], $itemsNumber)) {
            return new PwgError(401, 'Per page must be: 5, 10, 25 or 50');
        }
        /** @var list<array{sql: string, param: mixed, type: ParameterType, kind: string}> $filters */
        $filters = [];
        if (!empty($params['author_id'])) {
            $filters[] = ['sql' => 'author_id = ?', 'param' => is_numeric($params['author_id']) ? (int) $params['author_id'] : 0, 'type' => ParameterType::INTEGER, 'kind' => 'author'];
        }
        if (!empty($params['image_id'])) {
            $filters[] = ['sql' => 'image_id = ?', 'param' => is_numeric($params['image_id']) ? (int) $params['image_id'] : 0, 'type' => ParameterType::INTEGER, 'kind' => 'image'];
        }
        if (!empty($params['f_min_date'])) {
            $dmin = date_create(is_string($params['f_min_date']) ? $params['f_min_date'] : '');
            if ($dmin !== false) {
                $filters[] = ['sql' => 'date >= ?', 'param' => date_format($dmin, 'Y-m-d 00:00:00'), 'type' => ParameterType::STRING, 'kind' => 'min_date'];
            }
        }
        if (!empty($params['f_max_date'])) {
            $dmax = date_create(is_string($params['f_max_date']) ? $params['f_max_date'] : '');
            if ($dmax !== false) {
                $filters[] = ['sql' => 'date <= ?', 'param' => date_format($dmax, 'Y-m-d 23:59:59'), 'type' => ParameterType::STRING, 'kind' => 'max_date'];
            }
        }
        if (!empty($params['search'])) {
            $filters = [['sql' => 'content LIKE ?', 'param' => '%' . (is_string($params['search']) ? $params['search'] : '') . '%', 'type' => ParameterType::STRING, 'kind' => 'search']];
        }

        $build = static function (array $rows): array {
            /** @var list<array{sql: string, param: mixed, type: ParameterType, kind: string}> $rows */
            $where = ['1=1'];
            $params = [];
            $types  = [];
            foreach ($rows as $row) {
                $where[]  = $row['sql'];
                $params[] = $row['param'];
                $types[]  = $row['type'];
            }
            return [$where, $params, $types];
        };
        [$whereClauses, $qParams, $qTypes] = $build($filters);

        $summary = $this->commentRepository->findCommentsSummary($whereClauses, $qParams, $qTypes);
        $totalComments = $summary['all_comments'];
        switch ($params['status']) {
            case 'pending':
                $whereClauses[] = 'validated = 0';
                $totalComments  = $summary['pending'];
                break;
            case 'validated':
                $whereClauses[] = 'validated = 1';
                $totalComments  = $summary['validated'];
                break;
        }
        $perPage = is_numeric($params['per_page']) ? (int) $params['per_page'] : 10;
        $pageNum = is_numeric($params['page']) ? (int) $params['page'] : 0;
        $userFields = Config::userFields();
        $rows = $this->commentRepository->findCommentsAdminList(
            $whereClauses,
            $qParams,
            $qTypes,
            Tables::users(),
            $userFields['id'],
            $userFields['username'],
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
            static fn (array $f): bool => $f['kind'] !== 'author',
        )));
        $nbAuthorsIn = array_map(
            static fn (\Piwigo\Comment\Projection\AuthorCount $ac): array => $ac->toArray(),
            $this->commentRepository->findCommentAuthorCounts($authorsWhere, $authorsParams, $authorsTypes),
        );

        return ['summary' => $summary, 'comments' => $list, 'filters' => ['nb_authors' => $nbAuthorsIn, 'started_at' => $dates['started_at'], 'ended_at' => $dates['ended_at']], 'paging' => ['page' => $params['page'], 'per_page' => $params['per_page'], 'total_pages' => max(0, (int) ceil((float) $totalComments / (float) max(1, $perPage)) - 1)]];
    }
}

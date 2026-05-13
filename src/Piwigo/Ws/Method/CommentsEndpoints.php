<?php

declare(strict_types=1);

namespace Piwigo\Ws\Method;

use Doctrine\DBAL\Connection;
use Piwigo\Comment\CommentService;
use Piwigo\Config\Config;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\Util;
use Piwigo\Db\Tables;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeSize;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Url\UrlGenerator;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;

final class CommentsEndpoints
{
    public function __construct(
        private readonly Connection $conn,
        private readonly CommentService $commentService,
        private readonly DateService $dateService,
        private readonly UrlGenerator $urlGenerator,
        private readonly Util $util,
    ) {
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function getList(array $params, PwgServer &$service): PwgError|array
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
        $whereClauses = ['1=1'];
        if (isset($params['author_id']) && !empty($params['author_id'])) {
            $whereClauses['author_id'] = 'author_id = ' . (is_numeric($params['author_id']) ? (int) $params['author_id'] : 0);
        }
        if (isset($params['image_id']) && !empty($params['image_id'])) {
            $whereClauses[] = 'image_id = ' . (is_numeric($params['image_id']) ? (int) $params['image_id'] : 0);
        }
        if (!empty($params['f_min_date'])) {
            $dmin = date_create(is_string($params['f_min_date']) ? $params['f_min_date'] : '');
            if ($dmin !== false) {
                $whereClauses[] = "date >= '" . date_format($dmin, 'Y-m-d 00:00:00') . "'";
            }
        }
        if (!empty($params['f_max_date'])) {
            $dmax = date_create(is_string($params['f_max_date']) ? $params['f_max_date'] : '');
            if ($dmax !== false) {
                $whereClauses[] = "date <= '" . date_format($dmax, 'Y-m-d 23:59:59') . "'";
            }
        }
        if (!empty($params['search'])) {
            $whereClauses   = ['1=1'];
            $whereClauses[] = 'content LIKE ' . $this->conn->quote('%' . (is_string($params['search']) ? $params['search'] : '') . '%');
        }
        $conn = $this->conn;
        $querySum = 'SELECT count(*) as all_comments, sum(validated = \'true\') as validated, sum(validated = \'false\') as pending FROM ' . Tables::comments() . ' WHERE ' . implode(' AND ', $whereClauses) . ';';
        $summaryResult = $conn->executeQuery($querySum)->fetchAssociative();
        $summary  = $summaryResult !== false ? $summaryResult : [];
        $totalComments = $summary['all_comments'] ?? null;
        switch ($params['status']) {
            case 'pending':
                $whereClauses[] = "validated = 'false'";
                $totalComments  = $summary['pending'] ?? null;
                break;
            case 'validated':
                $whereClauses[] = "validated = 'true'";
                $totalComments  = $summary['validated'] ?? null;
                break;
        }
        $perPage = is_numeric($params['per_page']) ? (int) $params['per_page'] : 10;
        $pageNum = is_numeric($params['page']) ? (int) $params['page'] : 0;
        $userFields = Config::userFields();
        $query = 'SELECT c.id, c.image_id, c.date, c.author, c.author_id, ' . $userFields['username'] . ' AS username, ui.status, c.content, i.path, i.representative_ext, i.file, i.date_available, validated, c.anonymous_id FROM ' . Tables::comments() . ' AS c INNER JOIN ' . Tables::images() . ' AS i ON i.id = c.image_id LEFT JOIN ' . Tables::users() . ' AS u ON u.' . $userFields['id'] . ' = c.author_id LEFT JOIN ' . Tables::userInfos() . ' AS ui ON ui.user_id = c.author_id WHERE ' . implode(' AND ', $whereClauses) . ' ORDER BY c.date DESC LIMIT ' . ($perPage * $pageNum) . ', ' . $perPage . ';';
        $list = [];
        foreach ($conn->executeQuery($query)->fetchAllAssociative() as $row) {
            $mediumDerivative = DerivativeImage::getOne(DerivativeSize::Medium->value, ['id' => $row['image_id'], 'path' => $row['path'], 'representative_ext' => $row['representative_ext']]);
            $medium = $mediumDerivative !== null ? $mediumDerivative->getUrl() : null;
            if (empty($row['author_id']) || $row['author_id'] == Config::guestId()) {
                $authorName = $row['author'];
            } else {
                $coalesced  = $row['username'] ?? $row['author'] ?? Lang::t('guest');
                $authorName = stripslashes(is_scalar($coalesced) ? (string) $coalesced : Lang::t('guest'));
            }
            $list[] = ['id' => $row['id'], 'admin_link' => $this->urlGenerator->admin('photo-' . (is_string($row['image_id'] ?? null) ? $row['image_id'] : '')), 'medium_url' => $medium, 'file' => $row['file'], 'image_date_available' => $this->dateService->formatDate(is_string($row['date_available'] ?? null) ? $row['date_available'] : '', ['day_name', 'day', 'month', 'year', 'time']), 'author' => EventDispatcher::dispatch('render_comment_author', $authorName), 'author_status' => Config::webmasterId() == $row['author_id'] ? 'main_user' : $row['status'], 'date' => $this->dateService->formatDate(is_string($row['date'] ?? null) ? $row['date'] : '', ['day_name', 'day', 'month', 'year', 'time']), 'content' => EventDispatcher::dispatch('render_comment_content', $row['content']), 'raw_content' => $row['content'], 'is_pending' => ('false' === $row['validated'])];
        }
        $datesQuery = 'SELECT MIN(date) AS started_at, MAX(date) AS ended_at FROM ' . Tables::comments() . ' WHERE ' . implode(' AND ', $whereClauses) . ';';
        $datesResult = $conn->executeQuery($datesQuery)->fetchAssociative();
        $dates      = $datesResult !== false ? $datesResult : [];
        unset($whereClauses['author_id']);
        $authorsQuery = 'SELECT author, author_id, count(*) as nb_authors FROM ' . Tables::comments() . ' WHERE ' . implode(' AND ', $whereClauses) . ' GROUP BY author_id;';
        $nbAuthorsIn  = $this->conn->executeQuery($authorsQuery)->fetchAllAssociative();
        $totalCount   = is_numeric($totalComments) ? (int) $totalComments : 0;
        return ['summary' => $summary, 'comments' => $list, 'filters' => ['nb_authors' => $nbAuthorsIn, 'started_at' => $dates['started_at'] ?? null, 'ended_at' => $dates['ended_at'] ?? null], 'paging' => ['page' => $params['page'], 'per_page' => $params['per_page'], 'total_pages' => max(0, (int) ceil((float) $totalCount / (float) max(1, $perPage)) - 1)]];
    }

    /** @param array<mixed> $params */
    public function delete(array $params, PwgServer &$service): PwgError|string
    {
        if ($this->util->getPwgToken() !== $params['pwg_token']) {
            return new PwgError(403, Lang::t('Invalid security token'));
        }
        $rawIds    = is_array($params['comment_id']) ? $params['comment_id'] : [];
        $strIds    = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $rawIds);
        $commentIds = array_map(fn (string $v): int => (int) $v, array_unique($strIds));
        $this->commentService->deleteUserComment($commentIds);
        return 'Comment successfully deleted';
    }

    /** @param array<mixed> $params */
    public function validate(array $params, PwgServer &$service): PwgError|string
    {
        if ($this->util->getPwgToken() !== $params['pwg_token']) {
            return new PwgError(403, Lang::t('Invalid security token'));
        }
        $rawIds     = is_array($params['comment_id']) ? $params['comment_id'] : [];
        $strIds     = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $rawIds);
        $commentIds = array_map(fn (string $v): int => (int) $v, array_unique($strIds));
        $this->commentService->validateUserComment($commentIds);
        return 'Comment successfully validated';
    }
}

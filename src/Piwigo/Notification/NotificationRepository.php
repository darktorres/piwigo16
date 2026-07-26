<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for `custom_notification_query()`'s 5 known
 * "what's new" query shapes (`include/functions_notification.inc.php`)
 * plus `get_recent_post_dates()`'s own 3-query cascade (dates, then
 * per-date thumbnails/categories).
 *
 * $restrictSql is always an already-built permission fragment
 * (NotificationService::getSqlWhereRestrictFilter(), itself
 * PermissionService::getSqlConditionFandF()) -- same "hand-written
 * parameterized SQL, permission fragments stay raw string concatenation"
 * split as every other P19 domain; $start/$end (the only genuinely
 * dynamic/external-origin values here) are always bound parameters.
 */
final class NotificationRepository extends AbstractRepository
{
    public function countByType(string $type, ?string $start, ?string $end, string $restrictSql): int
    {
        [$fromWhereSql, $fieldId, $params] = $this->buildFromWhere($type, $start, $end, $restrictSql);

        $count = $this->conn->executeQuery('SELECT COUNT(DISTINCT ' . $fieldId . ') ' . $fromWhereSql, $params)
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return list<int>
     */
    public function findIdsByType(string $type, ?string $start, ?string $end, string $restrictSql): array
    {
        [$fromWhereSql, $fieldId, $params] = $this->buildFromWhere($type, $start, $end, $restrictSql);

        $ids = $this->conn->executeQuery('SELECT DISTINCT ' . $fieldId . ' ' . $fromWhereSql, $params)
            ->fetchFirstColumn();

        return array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));
    }

    /**
     * @return array{0: string, 1: string, 2: list<mixed>}
     */
    private function buildFromWhere(string $type, ?string $start, ?string $end, string $restrictSql): array
    {
        $params = [];

        switch ($type) {
            case 'new_comments':
                $sql = ' FROM ' . Tables::comments() . ' AS c'
                    . ' INNER JOIN ' . Tables::imageCategory() . ' AS ic ON c.image_id = ic.image_id'
                    . ' WHERE 1=1';
                $dateColumn = 'c.validation_date';
                $fieldId = 'c.id';

                break;
            case 'unvalidated_comments':
                $sql = ' FROM ' . Tables::comments() . ' WHERE 1=1';
                $dateColumn = 'date';
                $fieldId = 'id';

                break;
            case 'new_elements':
            case 'updated_categories':
                $sql = ' FROM ' . Tables::images()
                    . ' INNER JOIN ' . Tables::imageCategory() . ' AS ic ON image_id = id'
                    . ' WHERE 1=1';
                $dateColumn = 'date_available';
                $fieldId = $type === 'new_elements' ? 'image_id' : 'category_id';

                break;
            case 'new_users':
                $sql = ' FROM ' . Tables::userInfos() . ' WHERE 1=1';
                $dateColumn = 'registration_date';
                $fieldId = 'user_id';

                break;
            default:
                throw new \InvalidArgumentException('Unknown notification type: ' . $type);
        }

        if ($start !== null && $start !== '') {
            $sql .= ' AND ' . $dateColumn . ' > ?';
            $params[] = $start;
        }

        if ($end !== null && $end !== '') {
            $sql .= ' AND ' . $dateColumn . ' <= ?';
            $params[] = $end;
        }

        if ($type === 'unvalidated_comments') {
            // comments.validated is a real tinyint(1) column (Comment
            // domain Stage 1a) -- a numeric literal, not the old
            // enum('true','false') string; MySQL's non-numeric-string-to-
            // int coercion would otherwise silently convert 'false' to 0
            // too, matching every row instead of filtering to unvalidated
            // ones (same bug class Category's own commentable/visible
            // retype found).
            $sql .= ' AND validated = 0';
        }

        $sql .= ' ' . $restrictSql;

        return [$sql, $fieldId, $params];
    }

    /**
     * @return list<array{date_available: ?string, nb_elements: int, nb_cats: int}>
     */
    public function findRecentPostDates(string $whereSql, int $maxDates): array
    {
        return $this->conn->executeQuery(
            'SELECT date_available, COUNT(DISTINCT id) AS nb_elements, COUNT(DISTINCT category_id) AS nb_cats'
            . ' FROM ' . Tables::images() . ' i INNER JOIN ' . Tables::imageCategory() . ' AS ic ON id = image_id'
            . ' ' . $whereSql
            . ' GROUP BY date_available ORDER BY date_available DESC LIMIT ' . $maxDates
        )->fetchAllAssociative();
    }

    /**
     * `i.*` (a full images row) by design -- fed straight into
     * DerivativeImage::thumb_url(), whose array<string, mixed>|SrcImage
     * signature already documents why the array form stays generic.
     *
     * @return list<array<string, mixed>>
     */
    public function findRecentElementsForDate(string $whereSql, string $dateAvailable, int $maxElements): array
    {
        return $this->conn->executeQuery(
            'SELECT DISTINCT i.* FROM ' . Tables::images() . ' i'
            . ' INNER JOIN ' . Tables::imageCategory() . ' AS ic ON id = image_id'
            . ' ' . $whereSql
            . ' AND date_available = ? ORDER BY RAND() LIMIT ' . $maxElements,
            [$dateAvailable]
        )->fetchAllAssociative();
    }

    /**
     * @return list<array{uppercats: string, img_count: int}>
     */
    public function findRecentCategoriesForDate(string $whereSql, string $dateAvailable, int $maxCats): array
    {
        return $this->conn->executeQuery(
            'SELECT DISTINCT c.uppercats, COUNT(DISTINCT i.id) AS img_count'
            . ' FROM ' . Tables::images() . ' i'
            . ' INNER JOIN ' . Tables::imageCategory() . ' AS ic ON i.id = image_id'
            . ' INNER JOIN ' . Tables::categories() . ' c ON c.id = category_id'
            . ' ' . $whereSql
            . ' AND date_available = ? GROUP BY category_id, c.uppercats ORDER BY img_count DESC LIMIT ' . $maxCats,
            [$dateAvailable]
        )->fetchAllAssociative();
    }
}

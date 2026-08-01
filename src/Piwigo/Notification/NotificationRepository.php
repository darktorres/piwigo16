<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;
use Piwigo\Permission\SqlCondition;

/**
 * Persistence layer for `custom_notification_query()`'s 5 known
 * "what's new" query shapes (`include/functions_notification.inc.php`)
 * plus `get_recent_post_dates()`'s own 3-query cascade (dates, then
 * per-date thumbnails/categories).
 *
 * $restrictCondition is always an already-built permission fragment
 * (NotificationService::getSqlWhereRestrictCondition(), itself
 * PermissionService::getSqlConditionFandFAsCondition()) -- SQL-
 * modernization audit: this and $start/$end (the only other genuinely
 * dynamic/external-origin values here) are now always bound, via
 * QueryBuilder throughout instead of hand-assembled heredoc SQL text.
 */
final class NotificationRepository extends AbstractRepository
{
    public function countByType(string $type, ?string $start, ?string $end, SqlCondition $restrictCondition): int
    {
        [$qb, $fieldId] = $this->buildQuery($type, $start, $end, $restrictCondition);

        $count = $qb->select('COUNT(DISTINCT ' . $fieldId . ')')
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return list<int>
     */
    public function findIdsByType(string $type, ?string $start, ?string $end, SqlCondition $restrictCondition): array
    {
        [$qb, $fieldId] = $this->buildQuery($type, $start, $end, $restrictCondition);

        $ids = $qb->select($fieldId)
            ->distinct()
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));
    }

    /**
     * @return array{0: QueryBuilder, 1: string}
     */
    private function buildQuery(string $type, ?string $start, ?string $end, SqlCondition $restrictCondition): array
    {
        $qb = $this->conn->createQueryBuilder();

        switch ($type) {
            case 'new_comments':
                $qb->from(Tables::comments(), 'c')
                    ->innerJoin('c', Tables::imageCategory(), 'ic', 'c.image_id = ic.image_id');
                $dateColumn = 'c.validation_date';
                $fieldId = 'c.id';

                break;
            case 'unvalidated_comments':
                $qb->from(Tables::comments());
                $dateColumn = 'date';
                $fieldId = 'id';

                break;
            case 'new_elements':
            case 'updated_categories':
                $qb->from(Tables::images(), 'i')
                    ->innerJoin('i', Tables::imageCategory(), 'ic', 'i.id = ic.image_id');
                $dateColumn = 'date_available';
                $fieldId = $type === 'new_elements' ? 'image_id' : 'category_id';

                break;
            case 'new_users':
                $qb->from(Tables::userInfos());
                $dateColumn = 'registration_date';
                $fieldId = 'user_id';

                break;
            default:
                throw new \InvalidArgumentException('Unknown notification type: ' . $type);
        }

        if ($start !== null && $start !== '') {
            $qb->andWhere($dateColumn . ' > :start')
                ->setParameter('start', $start);
        }

        if ($end !== null && $end !== '') {
            $qb->andWhere($dateColumn . ' <= :end')
                ->setParameter('end', $end);
        }

        if ($type === 'unvalidated_comments') {
            // comments.validated is a real tinyint(1) column (Comment
            // domain Stage 1a) -- a numeric literal, not the old
            // enum('true','false') string; MySQL's non-numeric-string-to-
            // int coercion would otherwise silently convert 'false' to 0
            // too, matching every row instead of filtering to unvalidated
            // ones (same bug class Category's own commentable/visible
            // retype found).
            $qb->andWhere('validated = 0');
        }

        self::applyCondition($qb, $restrictCondition);

        return [$qb, $fieldId];
    }

    private static function applyCondition(QueryBuilder $qb, SqlCondition $condition): void
    {
        if ($condition->isEmpty()) {
            return;
        }

        $qb->andWhere($condition->sql);
        foreach ($condition->parameters as $name => $value) {
            $qb->setParameter($name, $value, $condition->types[$name] ?? ParameterType::STRING);
        }
    }

    /**
     * @return list<array{date_available: ?string, nb_elements: int, nb_cats: int}>
     */
    public function findRecentPostDates(SqlCondition $restrictCondition, int $maxDates): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('date_available', 'COUNT(DISTINCT id) AS nb_elements', 'COUNT(DISTINCT category_id) AS nb_cats')
            ->from(Tables::images(), 'i')
            ->innerJoin('i', Tables::imageCategory(), 'ic', 'id = image_id')
            ->groupBy('date_available')
            ->orderBy('date_available', 'DESC')
            ->setMaxResults($maxDates);
        self::applyCondition($qb, $restrictCondition);

        $rows = $qb->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'date_available' => is_string($row['date_available'] ?? null) ? $row['date_available'] : null,
                'nb_elements' => is_numeric($row['nb_elements']) ? (int) $row['nb_elements'] : 0,
                'nb_cats' => is_numeric($row['nb_cats']) ? (int) $row['nb_cats'] : 0,
            ],
            $rows
        );
    }

    /**
     * `i.*` (a full images row) by design -- fed straight into
     * DerivativeImage::thumb_url(), whose array<string, mixed>|SrcImage
     * signature already documents why the array form stays generic.
     *
     * @return list<array<string, mixed>>
     */
    public function findRecentElementsForDate(SqlCondition $restrictCondition, string $dateAvailable, int $maxElements): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('i.*')
            ->distinct()
            ->from(Tables::images(), 'i')
            ->innerJoin('i', Tables::imageCategory(), 'ic', 'id = image_id')
            ->andWhere('date_available = :dateAvailable')
            ->orderBy('RAND()')
            ->setMaxResults($maxElements)
            ->setParameter('dateAvailable', $dateAvailable);
        self::applyCondition($qb, $restrictCondition);

        return $qb->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return list<array{uppercats: string, img_count: int}>
     */
    public function findRecentCategoriesForDate(SqlCondition $restrictCondition, string $dateAvailable, int $maxCats): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('c.uppercats', 'COUNT(DISTINCT i.id) AS img_count')
            ->distinct()
            ->from(Tables::images(), 'i')
            ->innerJoin('i', Tables::imageCategory(), 'ic', 'i.id = image_id')
            ->innerJoin('i', Tables::categories(), 'c', 'c.id = category_id')
            ->andWhere('date_available = :dateAvailable')
            ->groupBy('category_id', 'c.uppercats')
            ->orderBy('img_count', 'DESC')
            ->setMaxResults($maxCats)
            ->setParameter('dateAvailable', $dateAvailable);
        self::applyCondition($qb, $restrictCondition);

        $rows = $qb->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'uppercats' => is_string($row['uppercats']) ? $row['uppercats'] : '',
                'img_count' => is_numeric($row['img_count']) ? (int) $row['img_count'] : 0,
            ],
            $rows
        );
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use Piwigo\Category\CategoryEntity;
use Piwigo\Comment\CommentEntity;
use Piwigo\Common\ValueObject\NumericId;
use Piwigo\Image\ImageCategoryEntity;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\Projection\Image;
use Piwigo\Permission\SqlCondition;
use Piwigo\Users\UserInfoEntity;

/**
 * Persistence layer for `custom_notification_query()`'s 5 known
 * "what's new" query shapes (`include/functions_notification.inc.php`)
 * plus `get_recent_post_dates()`'s own 3-query cascade (dates, then
 * per-date thumbnails/categories).
 *
 * $restrictCondition is always an already-built permission fragment
 * ({@see NotificationService::getCommentsRestrictCondition()}/
 * {@see NotificationService::getElementsRestrictCondition()}, themselves
 * built from {@see \Piwigo\Permission\PermissionCriteria}) -- SQL-
 * modernization audit: this and $start/$end (the only other genuinely
 * dynamic/external-origin values here) are now always bound, via
 * QueryBuilder throughout instead of hand-assembled heredoc SQL text.
 *
 * Further SQL-modernization audit, Item 15G: every method here is now
 * real DQL -- `comments`/`images`/`image_category`/`user_infos` are all
 * mapped, and `PermissionCriteria` needs no API changes, same finding as
 * every other consumer across this campaign. Dropped `extends
 * AbstractRepository` (this class dispatches across 4 different
 * entities depending on $type, so no single `EntityRepository<X>` fits)
 * in favor of a directly-injected `EntityManagerInterface`, same shape
 * as {@see \Piwigo\Telemetry\TelemetryService}.
 */
final class NotificationRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function countByType(string $type, ?string $start, ?string $end, SqlCondition $restrictCondition): int
    {
        [$qb, $fieldId] = $this->buildQuery($type, $start, $end, $restrictCondition);

        $count = $qb->select('COUNT(DISTINCT ' . $fieldId . ')')
            ->getQuery()
            ->getSingleScalarResult();

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
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(self::scalarIdToInt(...), $ids));
    }

    /**
     * Unwraps a `NumericId` VO (`CommentId`/`CategoryId`/`UserId`,
     * depending on $type) or an already-plain int/int-string into a
     * plain int -- `$fieldId`'s real hydrated shape depends on which of
     * `buildQuery()`'s 4 cases produced it, and `NumericId::__toString()`
     * (part of its interface contract, unlike `->value`) is the one thing
     * every case's possible VO type shares.
     */
    private static function scalarIdToInt(mixed $value): int
    {
        if ($value instanceof NumericId) {
            return (int) (string) $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array{0: QueryBuilder, 1: string}
     */
    private function buildQuery(string $type, ?string $start, ?string $end, SqlCondition $restrictCondition): array
    {
        $qb = $this->em->createQueryBuilder();

        switch ($type) {
            case 'new_comments':
                $qb->from(CommentEntity::class, 'c')
                    ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'c.imageId = ic.imageId');
                $dateColumn = 'c.validationDate';
                $fieldId = 'c.id';

                break;
            case 'unvalidated_comments':
                $qb->from(CommentEntity::class, 'c');
                $dateColumn = 'c.date';
                $fieldId = 'c.id';

                break;
            case 'new_elements':
            case 'updated_categories':
                $qb->from(ImageEntity::class, 'i')
                    ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'i.id = ic.imageId');
                $dateColumn = 'i.dateAvailable';
                $fieldId = $type === 'new_elements' ? 'ic.imageId' : 'ic.categoryId';

                break;
            case 'new_users':
                $qb->from(UserInfoEntity::class, 'ui');
                $dateColumn = 'ui.registrationDate';
                $fieldId = 'ui.userId';

                break;
            default:
                throw new InvalidArgumentException('Unknown notification type: ' . $type);
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
            // retype found). Now a real bound bool, DQL-hydrated the
            // same way {@see \Piwigo\Comment\CommentEntity::$validated}
            // already does everywhere else.
            $qb->andWhere('c.validated = :false')
                ->setParameter('false', false, Types::BOOLEAN);
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
        $qb = $this->em->createQueryBuilder()
            ->select('i.dateAvailable', 'COUNT(DISTINCT i.id) AS nb_elements', 'COUNT(DISTINCT ic.categoryId) AS nb_cats')
            ->from(ImageEntity::class, 'i')
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'i.id = ic.imageId')
            ->groupBy('i.dateAvailable')
            ->orderBy('i.dateAvailable', 'DESC')
            ->setMaxResults($maxDates);
        self::applyCondition($qb, $restrictCondition);

        $rows = $qb->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'date_available' => is_string($row['dateAvailable'] ?? null) ? $row['dateAvailable'] : null,
                'nb_elements' => is_numeric($row['nb_elements']) ? (int) $row['nb_elements'] : 0,
                'nb_cats' => is_numeric($row['nb_cats']) ? (int) $row['nb_cats'] : 0,
            ];
        }

        return $result;
    }

    /**
     * `i.*` (a full images row) by design -- fed straight into
     * DerivativeImage::thumb_url(), whose array<string, mixed>|SrcImage
     * signature already documents why the array form stays generic.
     *
     * Further SQL-modernization audit, Item 15G: the *selection* (which
     * image ids match $dateAvailable + $restrictCondition, in random
     * order, capped at $maxElements) now runs as its own DQL query --
     * every table/condition it touches is mapped and `PermissionCriteria`
     * needs no API changes, same finding as every other consumer in this
     * campaign.
     *
     * Item 16H: the final full-row-by-id fetch also converted, via
     * {@see \Piwigo\Image\Projection\Image::fromEntity()}/`toArray()` --
     * a ready-made mapping shim (already existed, no new code needed)
     * translating the DQL-hydrated `ImageEntity` back into the exact
     * raw snake_case row shape {@see \Piwigo\Image\SrcImage}'s own
     * constructor and `DerivativeImage::thumb_url()` already expect, same
     * reasoning {@see \Piwigo\Image\ImageRepository::findRowWithCondition()}'s
     * own exclusion cites for staying array-shaped rather than switching
     * every consumer to the typed projection object outright.
     *
     * @return list<array<string, mixed>>
     */
    public function findRecentElementsForDate(SqlCondition $restrictCondition, string $dateAvailable, int $maxElements): array
    {
        // pgsql support pass: real bug found live -- Postgres requires
        // every ORDER BY expression to appear in the SELECT list for a
        // SELECT DISTINCT query ("for SELECT DISTINCT, ORDER BY
        // expressions must appear in select list"), which RAND()/
        // RANDOM() never does by design; MySQL has no such restriction.
        // GROUP BY has no equivalent rule on either platform and
        // deduplicates i.id identically here (confirmed live) since the
        // join can otherwise produce duplicate rows for an image in
        // multiple categories.
        $qb = $this->em->createQueryBuilder()
            ->select('i.id')
            ->from(ImageEntity::class, 'i')
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'i.id = ic.imageId')
            ->andWhere('i.dateAvailable = :dateAvailable')
            ->groupBy('i.id')
            ->orderBy('RAND()')
            ->setMaxResults($maxElements)
            ->setParameter('dateAvailable', $dateAvailable);
        self::applyCondition($qb, $restrictCondition);

        $ids = array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $qb->getQuery()
                ->getSingleColumnResult()
        ));

        if ($ids === []) {
            return [];
        }

        $entities = $this->em->createQueryBuilder()
            ->select('i')
            ->from(ImageEntity::class, 'i')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($entities as $entity) {
            if ($entity->id !== null) {
                $byId[$entity->id] = Image::fromEntity($entity)->toArray();
            }
        }

        return array_values(array_filter(
            array_map(static fn (int $id): ?array => $byId[$id] ?? null, $ids),
            static fn (?array $row): bool => $row !== null
        ));
    }

    /**
     * @return list<array{uppercats: string, img_count: int}>
     */
    public function findRecentCategoriesForDate(SqlCondition $restrictCondition, string $dateAvailable, int $maxCats): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c.uppercats', 'COUNT(DISTINCT i.id) AS img_count')
            ->distinct()
            ->from(ImageEntity::class, 'i')
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'i.id = ic.imageId')
            ->innerJoin(CategoryEntity::class, 'c', Join::WITH, 'c.id = ic.categoryId')
            ->andWhere('i.dateAvailable = :dateAvailable')
            ->groupBy('ic.categoryId', 'c.uppercats')
            ->orderBy('img_count', 'DESC')
            ->setMaxResults($maxCats)
            ->setParameter('dateAvailable', $dateAvailable);
        self::applyCondition($qb, $restrictCondition);

        $rows = $qb->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'uppercats' => is_string($row['uppercats']) ? $row['uppercats'] : '',
                'img_count' => is_numeric($row['img_count']) ? (int) $row['img_count'] : 0,
            ];
        }

        return $result;
    }
}

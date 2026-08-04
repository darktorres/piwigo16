<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Piwigo\Category\CategoryEntity;
use Piwigo\Common\Dto\PaginatedResult;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Core\Env;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;
use Piwigo\Image\Projection\Image;
use Piwigo\Image\Projection\ImageFormat;
use Piwigo\Permission\PermissionCriteria;
use Piwigo\Permission\SqlCondition;

/**
 * Persistence layer for the image domain's own data-touching function from
 * `include/functions_picture.inc.php` -- the other 5 ported functions
 * (slideshow param encode/decode, PDF page counting) are pure computation
 * with no DB access of their own and live on {@see ImageService} instead,
 * same Repository=DB-only/Service=business-logic split as every other P19
 * domain.
 *
 * Owns `images` ({@see ImageEntity}) and `image_format`
 * ({@see ImageFormatEntity}) -- the single-row/simple-list methods
 * against those two tables go through DQL, as do two bulk id-list
 * methods against `images` itself (deleteImages()/touchLastmodified(),
 * both QueryBuilder delete()/update() with a bound `IN (:ids)`); every
 * other method here is a bulk id-list operation (raw string-concatenated
 * `IN (...)`, matching the original) or a cross-domain read/write into a
 * table another repository owns (`categories`, `image_category`,
 * `image_tag`, `comments`, `favorites`, `rate`, `caddie`) -- those stay
 * plain DBAL via $this->getEntityManager()->getConnection(), same "mixed
 * repository" shape Tag/Category's own conversions established, skewed
 * further toward "stays raw" given how few of this repository's real
 * methods are single-entity CRUD. `lounge`/`config` (the empty-lounge
 * lock flag) are read/written raw for the same reason -- `config`
 * specifically can't delegate to Config\ConfigRepository::upsert() the
 * way Admin\MenubarPageRenderer's own config write can (that one is a
 * plain upsert; the now-deleted Menu\MenubarLayoutRepository::saveLayout()
 * this mirrors was the same shape): tryAcquireLoungeLock() needs real
 * atomic INSERT-IGNORE claim semantics no find()+persist()+flush()
 * sequence can provide (a race would let two processes both believe
 * they won the lock), so it keeps the raw statement.
 *
 * @extends EntityRepository<ImageEntity>
 */
final class ImageRepository extends EntityRepository
{
    /**
     * Applies a permission/filter `SqlCondition` via `andWhere()`, binding
     * every one of its parameters -- same shared-helper shape as
     * `Notification\NotificationRepository::applyCondition()`/
     * `Tag\TagRepository::applyCondition()`.
     *
     * SQL-modernization audit, Item 14 Sub-phase B3 re-investigation found
     * every real caller of every one of this helper's own callers
     * (isImageAccessibleWithCondition/findRowWithCondition/
     * findRelatedCategoriesForImage/isImageCommentableWithCondition/
     * findVisibleCategoriesForImage/hasAccessibleImageWithAuthor/
     * isImageAccessibleViaCategoryWithCondition/
     * findCategoryLinksForImageIdsWithCondition) traces back to
     * {@see \Piwigo\Permission\PermissionService::getSqlConditionFandFAsCondition()}
     * -- Sub-phase C1 gave that method's own output a typed
     * {@see \Piwigo\Permission\PermissionCriteria} replacement, and every
     * method in this cluster now takes that DTO instead of a raw
     * SqlCondition, translating it to a bound fragment via
     * `PermissionCriteria`'s own `*Condition()` builders before reaching
     * this shared applier. This cluster stays on DBAL regardless (not a
     * DQL blocker this sub-phase resolves) -- `image_category`/`categories`
     * joins here are plain `Doctrine\DBAL\Query\QueryBuilder` queries, and
     * converting them to DQL is a separate, unrelated concern from the
     * permission-condition typing this sub-phase targets.
     */
    /**
     * Accepts either query-builder flavor -- {@see SqlCondition}'s own
     * `sql`/`parameters`/`types` shape applies identically via
     * `andWhere()`/`setParameter()` on both DBAL's and DQL's query
     * builders, confirmed empirically (Item 15 audit): a DQL consumer
     * just passes a DQL property path (e.g. `i.id`) into the same
     * {@see PermissionCriteria} `*Condition()` methods a DBAL consumer
     * already uses with a raw column name.
     */
    private static function applyCondition(QueryBuilder|\Doctrine\ORM\QueryBuilder $qb, SqlCondition $condition): void
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
     * Deliberately avoids bumping `lastmodified` (the original's own SQL
     * comment, preserved) -- an image's "last modified" timestamp should
     * reflect real edits, not visit counting. Bypasses the ORM's own
     * change-tracking (a per-entity `persist()`/`flush()` would only emit
     * an `UPDATE` for the columns it detects as dirty, i.e. just `hit` --
     * MySQL's own `ON UPDATE CURRENT_TIMESTAMP` on `lastmodified` would
     * then fire anyway since the row itself is being updated, silently
     * bumping it) via a DQL bulk `UPDATE`, which -- unlike a mapped entity
     * property write -- can express the self-assignment
     * `i.lastmodified = i.lastmodified` directly to suppress that, same
     * reasoning as Auth\AuthRepository::saveLastVisitFromHistory(). Clears
     * the identity map afterward since this bypasses the ORM for a row
     * {@see ImageEntity} may already have cached.
     *
     * Item 15 audit: converted to a DQL bulk `UPDATE` -- DQL supports both
     * the arithmetic (`i.hit = i.hit + 1`) and the self-assignment.
     */
    public function incrementVisitCounter(int $imageId): void
    {
        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->update(ImageEntity::class, 'i')
            ->set('i.hit', 'i.hit + 1')
            ->set('i.lastmodified', 'i.lastmodified')
            ->where('i.id = :id')
            ->setParameter('id', $imageId)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * Sets (or clears, when $coi is null) an image's crop-of-interest
     * 4-character code (admin/picture_coi.php, the only caller).
     */
    public function updateCoi(int $imageId, ?string $coi): void
    {
        $entity = $this->find($imageId);
        if ($entity === null) {
            return;
        }

        $entity->coi = $coi;
        $this->getEntityManager()
            ->flush();
    }

    /**
     * Applies whichever of name/author/comment/date_creation the caller
     * actually supplied -- Ws\PwgImages::addChunk()'s own "apply the
     * caller-supplied upload metadata fields, sparse" step. A null
     * parameter means "not supplied", not "clear this field" -- these 4
     * fields are never intentionally nulled through this path.
     */
    public function updateDescriptiveFields(
        int $imageId,
        ?string $name = null,
        ?string $author = null,
        ?string $comment = null,
        ?string $dateCreation = null,
    ): void {
        $entity = $this->find($imageId);
        if ($entity === null) {
            return;
        }

        if ($name !== null) {
            $entity->name = $name;
        }

        if ($author !== null) {
            $entity->author = $author;
        }

        if ($comment !== null) {
            $entity->comment = $comment;
        }

        if ($dateCreation !== null) {
            $entity->dateCreation = $dateCreation;
        }

        $this->getEntityManager()
            ->flush();
    }

    /**
     * @param array<int, int|string> $imageIds
     * @return list<array{image_id: int, ext: string}>
     */
    public function findFormatsByImageIds(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f.imageId AS image_id', 'f.ext AS ext')
            ->from(ImageFormatEntity::class, 'f')
            ->where('f.imageId IN (:imageIds)')
            ->setParameter('imageIds', $imageIds)
            ->getQuery()
            ->getResult();
    }

    public function findFormatById(int $formatId): ?ImageFormat
    {
        $entity = $this->getEntityManager()
            ->find(ImageFormatEntity::class, $formatId);

        return $entity === null ? null : ImageFormat::fromEntity($entity);
    }

    /**
     * Updates an existing format row's filesize -- Admin\Upload\
     * UploadService::addFormat()'s own re-add-same-format branch.
     */
    public function updateFormatFilesize(int $formatId, ?int $filesize): void
    {
        $entity = $this->getEntityManager()
            ->find(ImageFormatEntity::class, $formatId);
        if ($entity === null) {
            return;
        }

        $entity->filesize = $filesize;
        $this->getEntityManager()
            ->flush();
    }

    /**
     * Inserts a brand-new format row -- Admin\Upload\UploadService::
     * addFormat()'s own "no existing row for this (image, ext)" branch.
     */
    public function insertFormat(int $imageId, string $ext, ?int $filesize): int
    {
        $em = $this->getEntityManager();
        $entity = new ImageFormatEntity($imageId, $ext, $filesize);
        $em->persist($entity);
        $em->flush();

        assert($entity->formatId !== null);

        return $entity->formatId;
    }

    /**
     * Bulk format-row insert -- Controller\Admin\SiteUpdateSubController's
     * own filesystem-sync "add every newly-discovered format at once"
     * step, unlike insertFormat() above's single-row shape. Goes through
     * the ORM (one flush for the whole batch) rather than BatchWriter --
     * unlike Image\ImageRepository::massInsertImages()'s own dynamic
     * column-map reasoning, every row here is the same fixed
     * image_id/ext/filesize shape ImageFormatEntity already maps.
     *
     * @param  list<array{image_id: int, ext: string, filesize: ?int}>  $inserts
     */
    public function massInsertFormats(array $inserts): void
    {
        if ($inserts === []) {
            return;
        }

        $em = $this->getEntityManager();
        foreach ($inserts as $insert) {
            $em->persist(new ImageFormatEntity($insert['image_id'], $insert['ext'], $insert['filesize']));
        }

        $em->flush();
    }

    /**
     * image_id/ext for format rows matching $formatIds (their own primary
     * key, `format_id`, not `image_id`) -- Ws\PwgImages::formatsDelete()'s
     * own "which images/extensions are these formats for" lookup, before
     * deleting them via deleteFormatsByIds() below.
     *
     * @param list<int> $formatIds
     * @return list<array{image_id: int, ext: string}>
     */
    public function findImageIdsAndExtsByFormatIds(array $formatIds): array
    {
        if ($formatIds === []) {
            return [];
        }

        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f.imageId AS image_id', 'f.ext AS ext')
            ->from(ImageFormatEntity::class, 'f')
            ->where('f.formatId IN (:formatIds)')
            ->setParameter('formatIds', $formatIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * Deletes format rows by their own primary key (`format_id`, not
     * `image_id`) -- Controller\Admin\SiteUpdateSubController's own
     * "formats no longer present on disk" cleanup step.
     *
     * @param  list<int>  $formatIds
     */
    public function deleteFormatsByIds(array $formatIds): void
    {
        if ($formatIds === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(ImageFormatEntity::class, 'f')
            ->where('f.formatId IN (:formatIds)')
            ->setParameter('formatIds', $formatIds)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * @return list<ImageFormat>
     */
    public function findFormatsForImage(int $imageId): array
    {
        $entities = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f')
            ->from(ImageFormatEntity::class, 'f')
            ->where('f.imageId = :imageId')
            ->setParameter('imageId', $imageId)
            ->getQuery()
            ->getResult();

        return array_map(ImageFormat::fromEntity(...), $entities);
    }

    /**
     * @param array<int, int> $imageIds
     * @return list<ImageFormat>
     */
    public function findFullFormatsByImageIds(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        $entities = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f')
            ->from(ImageFormatEntity::class, 'f')
            ->where('f.imageId IN (:imageIds)')
            ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        return array_map(ImageFormat::fromEntity(...), $entities);
    }

    /**
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE id IN (...), no join DQL can't express.
     *
     * @param array<int, int|string> $imageIds
     * @return list<array{id: int, path: string, representative_ext: ?string}>
     */
    public function findPathsForFileDeletion(array $imageIds): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.id', 'i.path', 'i.representativeExt AS representative_ext')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', array_map(strval(...), $imageIds), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'path' => is_string($row['path']) ? $row['path'] : '',
                'representative_ext' => is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
            ];
        }

        return $result;
    }

    /**
     * Same 3 columns as {@see findPathsForFileDeletion()}, plus `level` --
     * Ws\PwgCategories::getList()'s own "does the viewer's privacy level
     * allow this thumbnail" check.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE id IN (...), no join DQL can't express.
     *
     * @param  list<int>  $imageIds
     * @return list<array{id: int, path: string, representative_ext: ?string, level: int}>
     */
    public function findPathsAndLevelForIds(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->select('i.id', 'i.path', 'i.representativeExt AS representative_ext', 'i.level')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', $imageIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'path' => is_string($row['path']) ? $row['path'] : '',
                'representative_ext' => is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
                'level' => is_numeric($row['level'] ?? null) ? (int) $row['level'] : 0,
            ];
        }

        return $result;
    }

    /**
     * Bulk-sets `level` for a batch of image ids -- Ws\PwgImages::
     * setPrivacyLevel()'s own WS write. Caller clears the EntityManager
     * afterward (same "caller clears" convention documented elsewhere,
     * e.g. CategoryService::setRepresentativeImage()) since this bypasses
     * the ORM.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table bulk
     * UPDATE, same `i.id IN (...)` shape as touchLastmodified() below;
     * still bypasses the ORM (a DQL UPDATE doesn't touch the identity
     * map either), so the "caller clears" contract above is unchanged.
     *
     * @param array<int, int> $imageIds
     * @return int affected row count
     */
    public function updateLevelForImages(array $imageIds, int $level): int
    {
        return $this->getEntityManager()
            ->createQueryBuilder()
            ->update(ImageEntity::class, 'i')
            ->set('i.level', ':level')
            ->where('i.id IN (:ids)')
            ->setParameter('level', $level, ParameterType::INTEGER)
            ->setParameter('ids', $imageIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
    }

    /**
     * Bulk-sets one scalar `images` text field to the same value across a
     * batch of image ids -- Admin\BatchManagerGlobalPageRenderer's own
     * per-action batch edit (author/name/date_creation), same "many ids,
     * one shared value" shape as updateLevelForImages() above but for a
     * text column, so $value is bound rather than interpolated.
     *
     * Item 15 audit: converted to real DQL -- $field is now
     * {@see ImageTextField}, a fixed compile-time-safe property path
     * instead of a runtime column-name string.
     *
     * @param array<int, int> $imageIds
     */
    public function updateTextFieldForImages(array $imageIds, ImageTextField $field, ?string $value): void
    {
        if ($imageIds === []) {
            return;
        }

        $property = match ($field) {
            ImageTextField::Author => 'i.author',
            ImageTextField::Name => 'i.name',
            ImageTextField::DateCreation => 'i.dateCreation',
        };

        $this->getEntityManager()
            ->createQueryBuilder()
            ->update(ImageEntity::class, 'i')
            ->set($property, ':value')
            ->where('i.id IN (:ids)')
            ->setParameter('value', $value)
            ->setParameter('ids', $imageIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
    }

    /**
     * Bulk per-row `images` field update, each id getting its own values --
     * Admin\BatchManagerUnitPageRenderer's own "unit mode" save, unlike
     * updateTextFieldForImages() above which applies one shared value to
     * every id. $dbfields/$datas/$flags match BatchWriter::massUpdate()'s
     * own shape directly (raw column names, one row per image; $flags is
     * Controller\Admin\SiteUpdateSubController's own metadata-sync
     * BatchWriter::SKIP_EMPTY toggle).
     *
     * Item 14 DQL audit: not a DQL-vs-DBAL question -- bulk per-row
     * UPDATE via BatchWriter (one statement for every row), not ORM
     * persist()/flush(); $dbfields' own column names are also
     * caller-supplied.
     *
     * @param array{primary: string[], update: string[]} $dbfields
     * @param array<int, array<string, mixed>> $datas
     */
    public function massUpdateFields(array $dbfields, array $datas, int $flags = 0): void
    {
        if ($datas === []) {
            return;
        }

        new BatchWriter($this->getEntityManager()->getConnection())
            ->massUpdate(Tables::images(), $dbfields, $datas, $flags);
    }

    /**
     * Applies a dynamic subset of `images` scalar fields, raw column names
     * as caller-supplied keys -- Ws\PwgImages::setInfo()'s own
     * "single_value_mode fill_if_empty/replace" business logic decides at
     * runtime which of name/author/comment/level/date_creation/file
     * actually changes, so this stays a generic column=>value bag rather
     * than typed parameters (unlike updateDescriptiveFields() above's
     * fixed 4-field shape). Bypasses the ORM -- caller must clear the
     * EntityManager afterward, same convention as updateLevelForImages()
     * above. Also reused (unrelated caller) by Admin\Upload\UploadService::
     * addUploadedFile()'s own re-upload branch -- same "dynamic column set,
     * caller already knows which fields changed" shape.
     *
     * Item 14 DQL audit: stays on DBAL -- $updates' own keys are
     * caller-supplied dynamic column names, not fixed DQL property
     * paths.
     *
     * @param array<string, mixed> $updates
     */
    public function updateFields(int $imageId, array $updates): void
    {
        if ($updates === []) {
            return;
        }

        new BatchWriter($this->getEntityManager()->getConnection())
            ->singleUpdate(Tables::images(), $updates, [
                'id' => $imageId,
            ]);
    }

    /**
     * Raw `images` INSERT, column names as caller-supplied keys -- Admin\
     * Upload\UploadService::addUploadedFile()'s own "brand-new photo" branch.
     * Stays raw DBAL rather than an ORM persist() (unlike Tag\TagRepository::
     * insert()'s equivalent) since the caller's $insert set is itself
     * dynamic (level/representative_ext only present conditionally),
     * mirroring updateFields() above rather than a fixed-shape entity
     * construction.
     *
     * Item 14 DQL audit: stays on DBAL -- $insert's own keys are
     * caller-supplied dynamic column names, not fixed DQL property
     * paths.
     *
     * @param array<string, mixed> $insert
     */
    public function insertImage(array $insert): int
    {
        $em = $this->getEntityManager();

        new BatchWriter($em->getConnection())
            ->singleInsert(Tables::images(), $insert);

        return (int) $em->getConnection()
            ->lastInsertId();
    }

    /**
     * Bulk `images` insert -- Controller\Admin\SiteUpdateSubController's
     * own filesystem-sync "add every newly-discovered photo at once" step.
     * Same "dynamic column map" reasoning as insertImage() above, just
     * batched, dbfields taken from the first row (the caller already
     * builds every row with the same keyset, same convention as
     * Tag\TagRepository::massInsertImageTags()).
     *
     * Item 14 DQL audit: not a DQL-vs-DBAL question -- bulk multi-row
     * INSERT via BatchWriter, not ORM persist()/flush(); the column set
     * is also caller-supplied.
     *
     * @param array<int, array<string, mixed>> $inserts
     */
    public function massInsertImages(array $inserts): void
    {
        if ($inserts === []) {
            return;
        }

        new BatchWriter($this->getEntityManager()->getConnection())
            ->massInsert(Tables::images(), array_keys($inserts[0]), $inserts);
    }

    /**
     * @param array<int, int|string> $ids
     */
    public function deleteImages(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(ImageEntity::class, 'i')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * Category ids for which one of $ids is the representative picture.
     *
     * Further SQL-modernization audit, Item 15G: converted to real DQL --
     * `categories` is a cross-repository-owned table, but `Category` and
     * `Image` are the same `L2aCoreDomain` deptrac layer, so this was
     * never a real boundary, only a repository-ownership convention
     * (checked deptrac.yaml's actual ruleset, not assumed).
     *
     * @param array<int, int|string> $ids
     * @return list<int>
     */
    public function findRepresentedCategoryIds(array $ids): array
    {
        $idsForDql = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $ids);

        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->createQueryBuilder()
                ->select('c.id')
                ->from(CategoryEntity::class, 'c')
                ->where('c.representativePictureId IN (:ids)')
                ->setParameter('ids', $idsForDql, ArrayParameterType::INTEGER)
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Item 14 DQL audit, re-corrected: `lounge` is now mapped
     * ({@see LoungeEntity}). Converted to real DQL -- single-table,
     * static ORDER BY.
     *
     * @return list<array{image_id: int, category_id: int}>
     */
    public function findLoungeRows(): array
    {
        $entities = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('l')
            ->from(LoungeEntity::class, 'l')
            ->orderBy('l.categoryId', 'ASC')
            ->addOrderBy('l.imageId', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (LoungeEntity $l): array => [
                'image_id' => $l->imageId,
                'category_id' => $l->categoryId->value,
            ],
            $entities
        );
    }

    /**
     * `date_available` for the oldest photo still in the lounge --
     * LoungeMaintenance::needsEmptying()'s own "is the oldest lounge photo
     * older than the max wait time" check. Returns null when the lounge is
     * empty.
     *
     * Real bug, found via the mutation sweep (2026-08-01): this used to
     * also select the DB server's own NOW() ("so age can be computed
     * without relying on PHP's own clock"), but date_available itself is
     * always written via Env::now() (see UploadService's own comment on
     * that exact same lesson) -- invisible to Env::now()'s PIWIGO_TEST_NOW
     * freeze, so once real time drifted away from a frozen PIWIGO_TEST_NOW,
     * every lounge photo looked far older than it really was and
     * needsEmptying() fired on literally every request. The caller now
     * computes age against Env::now() instead, matching date_available's
     * own clock source.
     *
     * Item 14 DQL audit, re-corrected: `lounge` is now mapped
     * ({@see LoungeEntity}). Converted to real DQL -- inner join into
     * this repository's own {@see ImageEntity}; setMaxResults(1) is
     * paired with getOneOrNullResult() per the audit's own gotcha #3.
     */
    public function findOldestLoungeAgeInfo(): ?string
    {
        $row = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('i.dateAvailable AS date_available')
            ->from(LoungeEntity::class, 'l')
            ->innerJoin(ImageEntity::class, 'i', \Doctrine\ORM\Query\Expr\Join::WITH, 'l.imageId = i.id')
            ->orderBy('l.imageId', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        $dateAvailable = $row['date_available'];

        return is_scalar($dateAvailable) ? (string) $dateAvailable : null;
    }

    /**
     * Number of lounge rows for $categoryId not yet linked into
     * `image_category` -- Ws\PwgImages::upload()'s own "how many photos
     * are still awaiting validation in this category" response field.
     *
     * Item 14 DQL audit, re-corrected: both `lounge` and `image_category`
     * are now mapped ({@see LoungeEntity}, {@see ImageCategoryEntity}).
     * Converted to real DQL -- the subquery targets the latter directly.
     */
    public function countLoungeImagesPendingForCategory(int $categoryId): int
    {
        $subQuery = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('ic.imageId')
            ->from(ImageCategoryEntity::class, 'ic')
            ->getDQL();

        $value = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(l.imageId)')
            ->from(LoungeEntity::class, 'l')
            ->where('l.categoryId = :categoryId')
            ->andWhere("l.imageId NOT IN ({$subQuery})")
            ->setParameter('categoryId', CategoryId::from($categoryId))
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Item 14 DQL audit, re-corrected: `lounge` is now mapped
     * ({@see LoungeEntity}). Converted to real DQL -- single-table bulk
     * DELETE.
     */
    public function deleteLoungeUpTo(int $maxImageId): void
    {
        $this->getEntityManager()
            ->createQueryBuilder()
            ->delete(LoungeEntity::class, 'l')
            ->where('l.imageId <= :maxImageId')
            ->setParameter('maxImageId', $maxImageId, ParameterType::INTEGER)
            ->getQuery()
            ->execute();
    }

    /**
     * Atomically claims the lounge-emptying lock via `INSERT IGNORE` --
     * a no-op if another process already holds it. json_encode()s
     * $lockValue (gap-closure Stage 1a-bis item 5: config.value is JSON
     * now) so it round-trips through CurrentConfig::emptyLoungeRunning()'s
     * own ConfigService::hydrate() read path too, not just
     * findLoungeLockValue() below -- a bare unquoted value would also
     * break this INSERT's own double-quote-delimited SQL literal.
     *
     * Stays raw DBAL rather than delegating to Config\ConfigRepository::
     * upsert() -- that method always finds-then-writes, which can't
     * reproduce this atomicity (two concurrent emptyLounge() runs could
     * otherwise both believe they'd won the lock). Clears the identity
     * map afterward since this bypasses the ORM for a row
     * Config\ConfigEntry may already have cached.
     *
     * Item 14 DQL audit: stays on DBAL -- DQL has no INSERT support at
     * all, and `config` is a cross-domain table
     * {@see \Piwigo\Config\ConfigRepository} owns besides.
     *
     * Item 16C: `Connection::insert()` (a plain, portable `INSERT`), not
     * MySQL-specific `INSERT IGNORE` text, and not `persist()`/`flush()`
     * either -- empirically verified that a caught
     * {@see UniqueConstraintViolationException} from a failed `flush()`
     * leaves the EntityManager permanently closed
     * (`Doctrine\ORM\UnitOfWork::commit()`'s own `finally` branch calls
     * `$em->close()` on any failure, and `clear()` cannot undo that),
     * which would break every other repository sharing this request's
     * EntityManager -- a real regression a losing race against another
     * process would trigger in normal operation, not just a theoretical
     * edge case. Plain DBAL `insert()` never touches the ORM's unit of
     * work, so a caught failure here has no such blast radius.
     */
    public function tryAcquireLoungeLock(string $lockValue): void
    {
        $encodedLockValue = json_encode($lockValue);
        assert($encodedLockValue !== false);

        $em = $this->getEntityManager();
        try {
            $em->getConnection()
                ->insert(Tables::config(), [
                    'param' => 'empty_lounge_running',
                    'value' => $encodedLockValue,
                ]);
        } catch (UniqueConstraintViolationException) {
            // Another process already holds the lock -- same "IGNORE"
            // semantic the raw INSERT IGNORE this replaces had.
        }
        $em->clear();
    }

    /**
     * Further SQL-modernization audit, Item 15G: converted to real DQL --
     * `config` is a cross-repository-owned table ({@see \Piwigo\Config\
     * ConfigEntry}), but `Config` is `L1Infrastructure` and `Image` is
     * `L2aCoreDomain`, an explicitly allowed downward dependency
     * (`L2aCoreDomain: [L1Infrastructure, L0Data]`), so this was never a
     * real boundary, only a repository-ownership convention (checked
     * deptrac.yaml's actual ruleset, not assumed) -- same correction as
     * {@see findRepresentedCategoryIds()} above.
     * {@see tryAcquireLoungeLock()} itself stays on DBAL regardless
     * (`INSERT IGNORE`, no DQL equivalent).
     */
    public function findLoungeLockValue(): ?string
    {
        $row = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('c.value')
            ->from(ConfigEntry::class, 'c')
            ->where('c.param = :param')
            ->setParameter('param', 'empty_lounge_running')
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        $value = $row['value'] ?? null;
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_string($decoded) ? $decoded : null;
    }

    /**
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- per the
     * audit's own gotcha #1, the selected `ic.categoryId` hydrates as a
     * real {@see CategoryId} under array hydration, so it's unwrapped
     * rather than treated as a raw int.
     *
     * @param array<int|string> $images real callers don't guarantee a list
     * @param array<int|string> $categories
     * @return array<int, int[]> category_id => list of already-associated image ids
     */
    public function findExistingAssociations(array $images, array $categories): array
    {
        $existing = [];

        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('ic.imageId', 'ic.categoryId')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.imageId IN (:images)')
            ->andWhere('ic.categoryId IN (:categories)')
            ->setParameter('images', array_map(strval(...), $images), ArrayParameterType::STRING)
            ->setParameter('categories', array_map(strval(...), $categories), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $categoryId = $row['categoryId'];
            $imageId = $row['imageId'];
            if (! $categoryId instanceof CategoryId || ! is_numeric($imageId)) {
                continue;
            }

            $existing[$categoryId->value][] = (int) $imageId;
        }

        return $existing;
    }

    /**
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- per the
     * audit's own gotcha #1, the selected `ic.categoryId` hydrates as a
     * real {@see CategoryId} under array hydration, so it's unwrapped
     * rather than treated as a raw int.
     *
     * @param array<int|string> $categories real callers don't guarantee a list
     * @return array<int|string, int> category_id => max rank
     */
    public function findMaxRanksByCategory(array $categories): array
    {
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('ic.categoryId', 'MAX(ic.rank) AS maxRank')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.rank IS NOT NULL')
            ->andWhere('ic.categoryId IN (:categories)')
            ->groupBy('ic.categoryId')
            ->setParameter('categories', array_map(strval(...), $categories), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $categoryId = $row['categoryId'];
            $maxRank = $row['maxRank'];
            if (! $categoryId instanceof CategoryId || ! is_numeric($maxRank)) {
                continue;
            }

            $result[$categoryId->value] = (int) $maxRank;
        }

        return $result;
    }

    /**
     * The original's own `array_filter(..., is_string(...))` relied on
     * mysqli's legacy fetch mode returning every column as a string; DBAL's
     * `fetchFirstColumn()` returns native ints for this `id` column, so
     * that same filter would silently discard every row instead of being a
     * no-op -- dropped here since the query itself already guarantees
     * numeric ids.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- inner join
     * into this repository's own {@see ImageEntity}.
     *
     * @param array<int, int|string> $images
     * @return list<int>
     */
    public function findDissociableImageIds(array $images, int|string $category): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->createQueryBuilder()
                ->select('i.id')
                ->from(ImageCategoryEntity::class, 'ic')
                ->innerJoin(ImageEntity::class, 'i', \Doctrine\ORM\Query\Expr\Join::WITH, 'ic.imageId = i.id')
                ->where('ic.categoryId = :category')
                ->andWhere('i.id IN (:images)')
                ->andWhere('(ic.categoryId != i.storageCategoryId OR i.storageCategoryId IS NULL)')
                ->setParameter('category', CategoryId::from((int) $category))
                ->setParameter('images', array_map(strval(...), $images), ArrayParameterType::STRING)
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-table
     * bulk DELETE.
     *
     * @param array<int, int> $imageIds
     */
    public function deleteImageCategoryLinks(array $imageIds, int|string $category): void
    {
        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(ImageCategoryEntity::class, 'ic')
            ->where('ic.categoryId = :category')
            ->andWhere('ic.imageId IN (:images)')
            ->setParameter('category', CategoryId::from((int) $category))
            ->setParameter('images', array_map(strval(...), $imageIds), ArrayParameterType::STRING)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * Breaks image_category links for one $imageId, scoped to a specific
     * set of $categoryIds -- Ws\PwgImages::addImageCategoryRelations()'s
     * own replace-mode cleanup of associations no longer present in the
     * caller's requested category list. Unlike deleteImageCategoryLinks()
     * above (many images, one category), this is one image, many
     * categories.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-table
     * bulk DELETE.
     *
     * @param list<int|string> $categoryIds
     */
    public function deleteImageCategoryLinksForCategoryIds(int $imageId, array $categoryIds): void
    {
        if ($categoryIds === []) {
            return;
        }

        $this->getEntityManager()
            ->createQueryBuilder()
            ->delete(ImageCategoryEntity::class, 'ic')
            ->where('ic.imageId = :imageId')
            ->andWhere('ic.categoryId IN (:categoryIds)')
            ->setParameter('imageId', $imageId, ParameterType::INTEGER)
            ->setParameter('categoryIds', array_map(strval(...), $categoryIds), ArrayParameterType::STRING)
            ->getQuery()
            ->execute();
    }

    /**
     * Breaks image_category links for $images to every album but their
     * storage album, optionally excluding $categories (an empty array
     * excludes nothing, matching the original's own conditional `AND
     * category_id NOT IN (...)` clause).
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}), but stayed on DBAL regardless at the
     * time -- this was a `DELETE ... JOIN`, and DQL's DELETE statement
     * doesn't support joins at all, mapped target or not.
     *
     * Item 16D: converted to real DQL via a 2-step SELECT-then-DELETE --
     * the only reason the original needed a JOIN at all was reading each
     * image's own `storage_category_id` (a column on `images`, not
     * `image_category`) to know which single link to spare. Step 1 reads
     * that per $images (a single-table SELECT, `ImageEntity` already
     * maps `storageCategoryId`); step 2 issues one single-table DQL
     * DELETE per image with that value bound as a plain scalar
     * parameter -- no JOIN needed once it's already in hand, same shape
     * as {@see deleteImageCategoryLinks()} just above. $images is
     * typically a handful of ids per real call (a single move/associate
     * action), so N small DELETEs costs nothing meaningful over one
     * multi-row statement.
     *
     * @param array<int, int|string> $images
     * @param array<int, int|string> $categories
     */
    public function deleteNonStorageCategoryLinks(array $images, array $categories): void
    {
        $em = $this->getEntityManager();

        $rows = $em->createQueryBuilder()
            ->select('i.id', 'i.storageCategoryId')
            ->from(ImageEntity::class, 'i')
            ->where('i.id IN (:images)')
            ->setParameter('images', array_map(strval(...), $images), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_numeric($row['id'] ?? null)) {
                continue;
            }

            $qb = $em->createQueryBuilder()
                ->delete(ImageCategoryEntity::class, 'ic')
                ->where('ic.imageId = :imageId')
                ->setParameter('imageId', (int) $row['id'], ParameterType::INTEGER);

            if ($categories !== []) {
                $qb->andWhere('ic.categoryId NOT IN (:categories)')
                    ->setParameter('categories', array_map(strval(...), $categories), ArrayParameterType::STRING);
            }

            // storage_category_id IS NULL -- every link for this image is
            // non-storage, no extra exclusion needed (matches the
            // original's own `storage_category_id IS NULL OR ...` half).
            if (is_numeric($row['storageCategoryId'] ?? null)) {
                $qb->andWhere('ic.categoryId != :storageCategoryId')
                    ->setParameter('storageCategoryId', (int) $row['storageCategoryId'], ParameterType::INTEGER);
            }

            $qb->getQuery()
                ->execute();
        }

        $em->clear();
    }

    /**
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE.
     *
     * @return list<int>
     */
    public function findImageIdsWithoutMd5sum(): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->createQueryBuilder('i')
                ->select('i.id')
                ->where('i.md5sum IS NULL')
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE id IN (...).
     *
     * @param array<int, int|string> $ids
     * @return array<int|string, string> id => path
     */
    public function findPathsForMd5sum(array $ids): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.id', 'i.path')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', array_map(strval(...), $ids), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        $paths = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_numeric($row['id'] ?? null)) {
                continue;
            }

            $paths[(int) $row['id']] = is_scalar($row['path']) ? (string) $row['path'] : '';
        }

        return $paths;
    }

    /**
     * path/file/md5sum/width/height/filesize for $imageId -- Ws\PwgImages::
     * addFile()'s own "what's the current state of this image, before we
     * merge in a bigger chunked upload" lookup.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE on the primary key, so at most one row can ever match;
     * setMaxResults(1) still paired with getOneOrNullResult() per the
     * audit's own gotcha #3 (it throws on >1 row otherwise).
     *
     * @return ?array{path: string, file: string, md5sum: ?string, width: ?int, height: ?int, filesize: ?int}
     */
    public function findUploadInfoById(int $imageId): ?array
    {
        $row = $this->createQueryBuilder('i')
            ->select('i.path', 'i.file', 'i.md5sum', 'i.width', 'i.height', 'i.filesize')
            ->where('i.id = :imageId')
            ->setParameter('imageId', $imageId, ParameterType::INTEGER)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        return [
            'path' => is_string($row['path']) ? $row['path'] : '',
            'file' => is_string($row['file']) ? $row['file'] : '',
            'md5sum' => is_string($row['md5sum'] ?? null) ? $row['md5sum'] : null,
            'width' => is_numeric($row['width'] ?? null) ? (int) $row['width'] : null,
            'height' => is_numeric($row['height'] ?? null) ? (int) $row['height'] : null,
            'filesize' => is_numeric($row['filesize'] ?? null) ? (int) $row['filesize'] : null,
        ];
    }

    /**
     * Whether at least one image has $value in $column -- Ws\PwgImages::
     * add()'s own upload-time uniqueness check ($column is one of
     * `md5sum`/`file`, selected from CurrentConfig::uniquenessMode(),
     * never caller-controlled).
     *
     * SQL-modernization audit / [SEC-20]: replaces the former
     * existsWithCondition(string $condition), which took an
     * already-quote-wrapped `"{$column} = '{$value}'"` fragment built by
     * the caller -- a real SQL injection, since $value there was
     * `Ws\PwgImages::add()`'s own `$params['original_sum']`/
     * `original_filename`, both registered with zero WS-level type
     * constraints (same root cause as findIdsByMd5sum()'s own [SEC-20]
     * fix above). The docblock's own former "not user input" claim was
     * wrong: only the *column choice* came from CurrentConfig, the value
     * itself was always raw client input. Fixed by taking the column and
     * value as separate parameters and binding the value.
     *
     * Item 15 audit: converted to real DQL -- $column is now
     * {@see ImageUniquenessColumn}, a fixed compile-time-safe property
     * path instead of a runtime column-name string.
     */
    public function existsWithColumnValue(ImageUniquenessColumn $column, string $value): bool
    {
        $property = match ($column) {
            ImageUniquenessColumn::Md5sum => 'i.md5sum',
            ImageUniquenessColumn::File => 'i.file',
        };

        $result = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(ImageEntity::class, 'i')
            ->where("{$property} = :value")
            ->setParameter('value', $value)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($result) && (int) $result > 0;
    }

    /**
     * Item 14 DQL audit: converted to real DQL -- single-table, no
     * WHERE.
     */
    public function countAllImages(): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Total `filesize` across every image -- Admin\InstallationStats's own
     * "disk_usage" summary figure (original photos only; format-file disk
     * usage is a separate figure, see {@see countAndSumFormats()}).
     */
    /**
     * Item 14 DQL audit: converted to real DQL -- SUM() is a standard
     * DQL aggregate function (unlike the MySQL-specific ones this audit
     * leaves alone), single-table, no WHERE.
     */
    public function sumFilesize(): int
    {
        $value = $this->createQueryBuilder('i')
            ->select('SUM(i.filesize)')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Row count and total `filesize` across every generated format file --
     * Admin\InstallationStats's own "nb_formats"/"formats_disk_usage"
     * summary figures.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table (this
     * repository's own {@see ImageFormatEntity}), no WHERE, both
     * aggregates in one round trip via getSingleResult().
     *
     * @return array{count: int, sum: int}
     */
    public function countAndSumFormats(): array
    {
        $row = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(f.formatId) AS cnt', 'SUM(f.filesize) AS total')
            ->from(ImageFormatEntity::class, 'f')
            ->getQuery()
            ->getSingleResult();

        if (! is_array($row)) {
            return [
                'count' => 0,
                'sum' => 0,
            ];
        }

        return [
            'count' => is_numeric($row['cnt'] ?? null) ? (int) $row['cnt'] : 0,
            'sum' => is_numeric($row['total'] ?? null) ? (int) $row['total'] : 0,
        ];
    }

    /**
     * Every image's id/file (unfiltered) -- Ws\PwgImages::
     * formatsSearchImage()'s own "build a filename-without-extension index
     * of every photo" scan.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, no
     * WHERE.
     *
     * @return list<array{id: int, file: string}>
     */
    public function findAllIdsAndFiles(): array
    {
        $result = [];
        foreach ($this->createQueryBuilder('i')->select('i.id', 'i.file')->getQuery()->getArrayResult() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'file' => is_string($row['file']) ? $row['file'] : '',
            ];
        }

        return $result;
    }

    /**
     * Every image_format row's image_id/ext (unfiltered) -- Ws\PwgImages::
     * formatsSearchImage()'s own "which formats already exist per image"
     * scan.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table (this
     * repository's own {@see ImageFormatEntity}), no WHERE.
     *
     * @return list<array{image_id: int, ext: string}>
     */
    public function findAllImageIdsAndExts(): array
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f.imageId AS image_id', 'f.ext AS ext')
            ->from(ImageFormatEntity::class, 'f');

        $result = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'image_id' => is_numeric($row['image_id']) ? (int) $row['image_id'] : 0,
                'ext' => is_string($row['ext']) ? $row['ext'] : '',
            ];
        }

        return $result;
    }

    /**
     * Earliest `date_available` among every image -- Admin\
     * InstallationStats::getInstallationDate()'s own last-resort
     * installation-date candidate.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, ORDER
     * BY + LIMIT DQL expresses directly; setMaxResults(1) paired with
     * getOneOrNullResult() per the audit's own gotcha #3.
     */
    public function findEarliestDateAvailable(): ?string
    {
        $row = $this->createQueryBuilder('i')
            ->select('i.dateAvailable AS date_available')
            ->orderBy('i.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        $dateAvailable = $row['date_available'];

        return is_string($dateAvailable) ? $dateAvailable : null;
    }

    /**
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-table,
     * no WHERE.
     */
    public function countImagesInCategories(): int
    {
        $count = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(DISTINCT ic.imageId)')
            ->from(ImageCategoryEntity::class, 'ic')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Total row count of `image_category` (every link, not distinct
     * images -- a different figure from {@see countImagesInCategories()}
     * above) -- Ws\PwgCore::getInfos()'s own "nb_image_category" summary
     * figure.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-table,
     * no WHERE.
     */
    public function countImageCategoryLinks(): int
    {
        $count = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(ic.imageId)')
            ->from(ImageCategoryEntity::class, 'ic')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Earliest `date_available` across every image -- Ws\PwgCore::
     * getInfos()'s own "first_date" summary figure, a different query
     * from {@see findEarliestDateAvailable()} above (that one is the
     * first-inserted image's own date, by id; this one is the minimum
     * date value regardless of which image it belongs to).
     */
    /**
     * Item 14 DQL audit: converted to real DQL -- MIN() is a standard
     * DQL aggregate function, single-table, no WHERE.
     */
    public function findMinDateAvailable(): ?string
    {
        $value = $this->createQueryBuilder('i')
            ->select('MIN(i.dateAvailable)')
            ->getQuery()
            ->getSingleScalarResult();

        return is_string($value) ? $value : null;
    }

    /**
     * Next free id and total row count, in one round trip --
     * Ws\PwgCore::getMissingDerivatives()'s own pagination-cursor
     * bootstrap (same MAX(id)+1 shape as {@see findNextId()} above, plus
     * COUNT(*) for the "nothing to do" early exit).
     *
     * Item 14 DQL audit: converted to real DQL -- MAX()/COUNT() are
     * standard DQL aggregate functions, and DQL allows plain arithmetic
     * on an aggregate result (`MAX(i.id) + 1`), single-table, no WHERE,
     * both aggregates in one round trip via getSingleResult().
     *
     * @return array{nextId: int, count: int}
     */
    public function findNextIdAndCount(): array
    {
        $row = $this->createQueryBuilder('i')
            ->select('MAX(i.id) + 1 AS nextId', 'COUNT(i.id) AS cnt')
            ->getQuery()
            ->getSingleResult();

        if (! is_array($row)) {
            return [
                'nextId' => 0,
                'count' => 0,
            ];
        }

        return [
            'nextId' => is_numeric($row['nextId'] ?? null) ? (int) $row['nextId'] : 0,
            'count' => is_numeric($row['cnt']) ? (int) $row['cnt'] : 0,
        ];
    }

    /**
     * Further SQL-modernization audit, Item 13: one page of images with id
     * below $startId, matching $criteria -- Ws\PwgCore::
     * getMissingDerivatives()'s own cursor-paginated scan, one real
     * caller.
     *
     * SQL-modernization audit, Item 14 Sub-phase C3: converted to real
     * DQL -- $criteria->filterCriteria is now a typed
     * {@see \Piwigo\Image\ImageFilterCriteria} (see that class's own
     * docblock for why this was previously a permanent-looking blocker),
     * applied here via {@see applyImageFilterCriteria()} against this
     * method's own `i` alias.
     *
     * @return list<array{id: mixed, path: mixed, representative_ext: mixed, width: mixed, height: mixed, rotation: mixed}>
     */
    public function findForMissingDerivatives(MissingDerivativesCriteria $criteria, int $startId, int $limit): array
    {
        $qb = $this->createQueryBuilder('i')
            ->select('i.id AS id', 'i.path AS path', 'i.representativeExt AS representative_ext', 'i.width AS width', 'i.height AS height', 'i.rotation AS rotation')
            ->where('i.id < :startId')
            ->setParameter('startId', $startId)
            ->orderBy('i.id', 'DESC')
            ->setMaxResults($limit);

        self::applyImageFilterCriteria($qb, $criteria->filterCriteria, 'i');

        if ($criteria->ids !== []) {
            $qb->andWhere('i.id IN (:ids)')
                ->setParameter('ids', $criteria->ids, ArrayParameterType::INTEGER);
        }

        $rows = $qb->getQuery()
            ->getArrayResult();

        // getArrayResult() is declared bare `mixed` by Doctrine itself, so
        // is_array($row) alone only narrows to array<array-key, mixed> --
        // rebuilt explicitly here into the known, string-keyed shape every
        // real row has (this query's own `AS` aliases above).
        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = [
                    'id' => $row['id'] ?? null,
                    'path' => $row['path'] ?? null,
                    'representative_ext' => $row['representative_ext'] ?? null,
                    'width' => $row['width'] ?? null,
                    'height' => $row['height'] ?? null,
                    'rotation' => $row['rotation'] ?? null,
                ];
            }
        }

        return $result;
    }

    /**
     * Applies an {@see ImageFilterCriteria}'s own up-to-11 range
     * predicates as `andWhere()`s against $alias's own columns -- shared by
     * every DQL-based consumer of the shared f_* WS filter set (see
     * {@see \Piwigo\Ws\WsHelper::stdImageSqlFilterCriteria()}'s own
     * docblock). Each entry is independently optional (null = no
     * restriction on that dimension).
     */
    private static function applyImageFilterCriteria(\Doctrine\ORM\QueryBuilder $qb, ImageFilterCriteria $criteria, string $alias): void
    {
        if ($criteria->minRate !== null) {
            $qb->andWhere($alias . '.ratingScore >= :filterMinRate')
                ->setParameter('filterMinRate', $criteria->minRate);
        }
        if ($criteria->maxRate !== null) {
            $qb->andWhere($alias . '.ratingScore <= :filterMaxRate')
                ->setParameter('filterMaxRate', $criteria->maxRate);
        }
        if ($criteria->minHit !== null) {
            $qb->andWhere($alias . '.hit >= :filterMinHit')
                ->setParameter('filterMinHit', $criteria->minHit);
        }
        if ($criteria->maxHit !== null) {
            $qb->andWhere($alias . '.hit <= :filterMaxHit')
                ->setParameter('filterMaxHit', $criteria->maxHit);
        }
        if ($criteria->minDateAvailable !== null) {
            $qb->andWhere($alias . '.dateAvailable >= :filterMinDateAvailable')
                ->setParameter('filterMinDateAvailable', $criteria->minDateAvailable);
        }
        if ($criteria->maxDateAvailable !== null) {
            $qb->andWhere($alias . '.dateAvailable < :filterMaxDateAvailable')
                ->setParameter('filterMaxDateAvailable', $criteria->maxDateAvailable);
        }
        if ($criteria->minDateCreated !== null) {
            $qb->andWhere($alias . '.dateCreation >= :filterMinDateCreated')
                ->setParameter('filterMinDateCreated', $criteria->minDateCreated);
        }
        if ($criteria->maxDateCreated !== null) {
            $qb->andWhere($alias . '.dateCreation < :filterMaxDateCreated')
                ->setParameter('filterMaxDateCreated', $criteria->maxDateCreated);
        }
        if ($criteria->minRatio !== null) {
            $qb->andWhere($alias . '.width / ' . $alias . '.height >= :filterMinRatio')
                ->setParameter('filterMinRatio', $criteria->minRatio);
        }
        if ($criteria->maxRatio !== null) {
            $qb->andWhere($alias . '.width / ' . $alias . '.height <= :filterMaxRatio')
                ->setParameter('filterMaxRatio', $criteria->maxRatio);
        }
        if ($criteria->maxLevel !== null) {
            $qb->andWhere($alias . '.level <= :filterMaxLevel')
                ->setParameter('filterMaxLevel', $criteria->maxLevel);
        }
    }

    /**
     * id/label(computed)/filesize/file/path/representative_ext for
     * $imageIds -- Ws\PwgCore::historySearch()'s own thumbnail/label
     * enrichment step, keyed by id.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE id IN (...). MySQL's `IF(name IS NULL, file, name)` is
     * rewritten as its exact DQL equivalent `COALESCE(name, file)`
     * (COALESCE returns the first non-null argument, which for two
     * arguments is exactly this IF()'s own null-check-and-fallback
     * shape) -- COALESCE is a standard DQL function, unlike IF() itself.
     *
     * @param  list<int|string>  $imageIds
     * @return array<int|string, array<string, mixed>>
     */
    public function findHistoryDisplayInfoByIds(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->select('i.id', 'COALESCE(i.name, i.file) AS label', 'i.filesize', 'i.file', 'i.path', 'i.representativeExt AS representative_ext')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', array_map(strval(...), $imageIds), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['id']) || (! is_int($row['id']) && ! is_string($row['id']))) {
                continue;
            }

            $byId[$row['id']] = [
                'id' => $row['id'],
                'label' => $row['label'] ?? null,
                'filesize' => $row['filesize'] ?? null,
                'file' => $row['file'] ?? null,
                'path' => $row['path'] ?? null,
                'representative_ext' => $row['representative_ext'] ?? null,
            ];
        }

        return $byId;
    }

    /**
     * Item 14 DQL audit, re-corrected: `lounge` is now mapped
     * ({@see LoungeEntity}). Converted to real DQL -- single-table, no
     * WHERE.
     *
     * @return list<int>
     */
    public function findLoungedImageIds(): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->createQueryBuilder()
                ->select('l.imageId')
                ->from(LoungeEntity::class, 'l')
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- LEFT JOIN
     * into this repository's own entity.
     *
     * @param list<int> $loungedIds
     * @return list<int>
     */
    public function findOrphanImageIds(array $loungedIds): array
    {
        $qb = $this->createQueryBuilder('i')
            ->select('i.id')
            ->leftJoin(ImageCategoryEntity::class, 'ic', \Doctrine\ORM\Query\Expr\Join::WITH, 'i.id = ic.imageId')
            ->where('ic.categoryId IS NULL')
            ->orderBy('i.id', 'ASC');

        if (count($loungedIds) > 0) {
            $qb->andWhere('i.id NOT IN (:loungedIds)')
                ->setParameter('loungedIds', $loungedIds, ArrayParameterType::INTEGER);
        }

        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->getQuery()->getSingleColumnResult()));
    }

    /**
     * @param array<int, int|string> $imageIds
     */
    public function touchLastmodified(array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->update(ImageEntity::class, 'i')
            ->set('i.lastmodified', ':now')
            ->where('i.id IN (:imageIds)')
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('imageIds', $imageIds)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    public function findById(int|string $imageId): ?Image
    {
        $entity = $this->find((int) $imageId);

        return $entity === null ? null : Image::fromEntity($entity);
    }

    /**
     * ImageDerivativeController (i.php)'s own source-file-to-image-row
     * lookup.
     */
    public function findByPath(string $path): ?Image
    {
        $entity = $this->findOneBy([
            'path' => $path,
        ]);

        return $entity === null ? null : Image::fromEntity($entity);
    }

    public function updateRotation(int $imageId, int $rotationCode): void
    {
        $entity = $this->find($imageId);
        if ($entity === null) {
            return;
        }

        $entity->rotation = $rotationCode;
        $this->getEntityManager()
            ->flush();
    }

    /**
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE id IN (...). Fetches full {@see ImageEntity} objects (plain
     * object hydration, the original's own `SELECT *`) and reuses
     * {@see \Piwigo\Image\Projection\Image::fromEntity()} rather than
     * fromRow() -- no custom Doctrine Type is in play here (every
     * ImageEntity column is a plain scalar type), so this is a direct
     * swap, not a Gotcha #1 situation.
     *
     * @param  list<int|string>  $ids
     * @return array<int, Image> keyed by image id -- PHP canonicalises a
     *   numeric-string array key back to an int key, so this is always
     *   int-keyed at runtime regardless of `$ids`' own element types.
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $entities = $this->createQueryBuilder('i')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', array_map(strval(...), $ids), ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($entities as $entity) {
            if ($entity->id !== null) {
                $byId[$entity->id] = Image::fromEntity($entity);
            }
        }

        return $byId;
    }

    /**
     * Item 14 DQL audit: not a DQL-vs-DBAL question -- bulk multi-row
     * INSERT via BatchWriter, not ORM persist()/flush(); `lounge` also
     * has no mapped Entity anywhere in this codebase.
     *
     * @param  list<array{image_id: int|string, category_id: int|string}>  $inserts
     */
    public function massInsertLounge(array $inserts): void
    {
        if ($inserts === []) {
            return;
        }

        new BatchWriter($this->getEntityManager()->getConnection())
            ->massInsert(Tables::lounge(), array_keys($inserts[0]), $inserts, [
                'ignore' => true,
            ]);
    }

    /**
     * $rank is optional per row -- Controller\Admin\SiteUpdateSubController's
     * own filesystem-sync insert omits it entirely (leaves it to the
     * schema's own DEFAULT), unlike ImageService::associateImagesToCategories()'s
     * own caller which always supplies it.
     *
     * Item 14 DQL audit: not a DQL-vs-DBAL question -- bulk multi-row
     * INSERT via BatchWriter, not ORM persist()/flush(); `image_category`
     * also has no mapped Entity anywhere in this codebase.
     *
     * @param  list<array{image_id: int|string, category_id: int|string, rank?: int|string}>  $inserts
     */
    public function massInsertImageCategory(array $inserts): void
    {
        if ($inserts === []) {
            return;
        }

        new BatchWriter($this->getEntityManager()->getConnection())
            ->massInsert(Tables::imageCategory(), array_keys($inserts[0]), $inserts);
        $this->getEntityManager()
            ->clear();
    }

    /**
     * Item 14 DQL audit: not a DQL-vs-DBAL question -- bulk per-row
     * UPDATE via BatchWriter, not ORM persist()/flush().
     *
     * @param  list<array{id: int|string, md5sum: string}>  $updates
     */
    public function massUpdateMd5sums(array $updates): void
    {
        new BatchWriter($this->getEntityManager()->getConnection())
            ->massUpdate(
                Tables::images(),
                [
                    'primary' => ['id'],
                    'update' => ['md5sum'],
                ],
                $updates
            );
        $this->getEntityManager()
            ->clear();
    }

    public function updateDimensions(int $imageId, int $width, int $height): void
    {
        $entity = $this->find($imageId);
        if ($entity === null) {
            return;
        }

        $entity->width = $width;
        $entity->height = $height;
        $this->getEntityManager()
            ->flush();
    }

    /**
     * Item 14 DQL audit: not a DQL-vs-DBAL question -- bulk per-row
     * UPDATE via BatchWriter, not ORM persist()/flush(); `image_category`
     * also has no mapped Entity anywhere in this codebase.
     *
     * @param  list<array{category_id: int|string, image_id: int|string, rank: int}>  $datas
     */
    public function massUpdateImageCategoryRanks(array $datas): void
    {
        new BatchWriter($this->getEntityManager()->getConnection())
            ->massUpdate(
                Tables::imageCategory(),
                [
                    'primary' => ['image_id', 'category_id'],
                    'update' => ['rank'],
                ],
                $datas
            );
        $this->getEntityManager()
            ->clear();
    }

    /**
     * Distinct categories among $imageIds where the link is virtual (the
     * category isn't that image's own storage_category_id, or the image
     * has no storage category at all) -- Admin\BatchManager\
     * FilterPanelRenderer's own "which categories can this selection be
     * dissociated from" listing. Stays raw (id-keyed rows, not a plain
     * list) since the caller's own `array_column(..., 'id', 'id')`
     * membership-set idiom is preserved unchanged at the call site.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- inner join
     * into this repository's own {@see ImageEntity}; per the audit's own
     * gotcha #1, the selected `ic.categoryId` hydrates as a real
     * {@see CategoryId} under array hydration, so it's unwrapped rather
     * than treated as a raw int.
     *
     * @param array<array-key, int|string|float|bool> $imageIds
     * @return list<array<string, mixed>>
     */
    public function findVirtuallyAssociatedCategoryRows(array $imageIds): array
    {
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('DISTINCT ic.categoryId AS id')
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin(ImageEntity::class, 'i', \Doctrine\ORM\Query\Expr\Join::WITH, 'i.id = ic.imageId')
            ->where('ic.imageId IN (:ids)')
            ->andWhere('(ic.categoryId != i.storageCategoryId OR i.storageCategoryId IS NULL)')
            ->setParameter('ids', array_map(strval(...), $imageIds), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'];
            $result[] = [
                'id' => $id instanceof CategoryId ? $id->value : $id,
            ];
        }

        return $result;
    }

    /**
     * Thumbnail-display rows for $categoryId, ordered by rank --
     * Admin\ElementSetRanksPageRenderer's own "sort_order" tab listing.
     * Stays raw (not a shaped array) since every row is handed straight
     * into {@see \Piwigo\Image\SrcImage}'s own constructor, which already
     * narrows each key itself.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- inner join
     * against this repository's own entity.
     *
     * @return list<array<string, mixed>>
     */
    public function findThumbnailRowsForCategoryOrderedByRank(int $categoryId): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.id', 'i.file', 'i.path', 'i.representativeExt AS representative_ext', 'i.width', 'i.height', 'i.rotation', 'i.name', 'ic.rank')
            ->innerJoin(ImageCategoryEntity::class, 'ic', \Doctrine\ORM\Query\Expr\Join::WITH, 'ic.imageId = i.id')
            ->where('ic.categoryId = :categoryId')
            ->orderBy('ic.rank')
            ->setParameter('categoryId', CategoryId::from($categoryId))
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = [
                    'id' => $row['id'] ?? null,
                    'file' => $row['file'] ?? null,
                    'path' => $row['path'] ?? null,
                    'representative_ext' => $row['representative_ext'] ?? null,
                    'width' => $row['width'] ?? null,
                    'height' => $row['height'] ?? null,
                    'rotation' => $row['rotation'] ?? null,
                    'name' => $row['name'] ?? null,
                    'rank' => $row['rank'] ?? null,
                ];
            }
        }

        return $result;
    }

    /**
     * image_id list for $categoryId ordered by rank ascending --
     * Ws\PwgImages::setRank()'s own multi-image "return the new order"
     * response.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-table,
     * static WHERE + ORDER BY.
     *
     * @return list<int|string>
     */
    public function findImageIdsOrderedByRankForCategory(int $categoryId): array
    {
        return array_values(array_filter(
            $this->getEntityManager()
                ->createQueryBuilder()
                ->select('ic.imageId')
                ->from(ImageCategoryEntity::class, 'ic')
                ->where('ic.categoryId = :categoryId')
                ->orderBy('ic.rank', 'ASC')
                ->setParameter('categoryId', CategoryId::from($categoryId))
                ->getQuery()
                ->getSingleColumnResult(),
            static fn (mixed $v): bool => is_int($v) || is_string($v)
        ));
    }

    /**
     * Whether $imageId is associated to $categoryId -- Ws\PwgImages::
     * setRank()'s own "is this image even in that category" guard.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-table,
     * static WHERE.
     */
    public function isImageInCategory(int $imageId, int $categoryId): bool
    {
        $value = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(ic.imageId)')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.imageId = :imageId')
            ->andWhere('ic.categoryId = :categoryId')
            ->setParameter('imageId', $imageId, ParameterType::INTEGER)
            ->setParameter('categoryId', CategoryId::from($categoryId))
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * Current highest `rank` for one category (singular -- unlike
     * findMaxRanksByCategory() above, which takes a batch) --
     * Ws\PwgImages::setRank()'s own "what's the current max rank" lookup.
     * Returns null when no image in this category has a rank set yet.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-table,
     * static WHERE; a bare aggregate with no GROUP BY always returns
     * exactly one row (NULL when nothing matches), so getSingleScalarResult()
     * never throws here.
     */
    public function findMaxRankForCategory(int $categoryId): ?int
    {
        $maxRank = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('MAX(ic.rank)')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.categoryId = :categoryId')
            ->setParameter('categoryId', CategoryId::from($categoryId))
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($maxRank) ? (int) $maxRank : null;
    }

    /**
     * Bumps `rank` by 1 for every image in $categoryId whose rank is >=
     * $rank -- Ws\PwgImages::setRank()'s own "make room" step before
     * inserting a new rank value. `image_category` isn't ORM-mapped, so
     * no caller-side EntityManager::clear() is needed after this write.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-table
     * bulk UPDATE; DQL's UPDATE SET clause supports the `ic.rank + 1`
     * self-referential arithmetic directly.
     */
    public function incrementRanksFromForCategory(int $categoryId, int $rank): void
    {
        $this->getEntityManager()
            ->createQueryBuilder()
            ->update(ImageCategoryEntity::class, 'ic')
            ->set('ic.rank', 'ic.rank + 1')
            ->where('ic.categoryId = :categoryId')
            ->andWhere('ic.rank IS NOT NULL')
            ->andWhere('ic.rank >= :rank')
            ->setParameter('categoryId', CategoryId::from($categoryId))
            ->setParameter('rank', $rank, ParameterType::INTEGER)
            ->getQuery()
            ->execute();
    }

    /**
     * Sets `rank` for one (imageId, categoryId) image_category row --
     * Ws\PwgImages::setRank()'s own final write.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-row
     * UPDATE on the composite primary key.
     */
    public function updateRankForImageInCategory(int $imageId, int $categoryId, int $rank): void
    {
        $this->getEntityManager()
            ->createQueryBuilder()
            ->update(ImageCategoryEntity::class, 'ic')
            ->set('ic.rank', ':rank')
            ->where('ic.imageId = :imageId')
            ->andWhere('ic.categoryId = :categoryId')
            ->setParameter('rank', $rank, ParameterType::INTEGER)
            ->setParameter('imageId', $imageId, ParameterType::INTEGER)
            ->setParameter('categoryId', CategoryId::from($categoryId))
            ->getQuery()
            ->execute();
    }

    /**
     * The category (id + uppercats) the most recently added image was
     * placed into -- Admin\PhotosAddDirectPageRenderer's own "default the
     * upload form to whichever album the last photo went into" lookup.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- joins this
     * repository's own {@see ImageEntity} plus the cross-domain
     * {@see \Piwigo\Category\CategoryEntity} (same L2aCoreDomain layer,
     * see this repository's own header docblock and
     * {@see ImageCategoryEntity}'s own docblock on that boundary).
     * setMaxResults(1) is paired with getOneOrNullResult() per the
     * audit's own gotcha #3. Per gotcha #1, the selected `ic.categoryId`
     * hydrates as a real {@see CategoryId} under array hydration.
     *
     * @return array{category_id: int|string, uppercats: string}|null
     */
    public function findMostRecentImageCategoryInfo(): ?array
    {
        $row = $this->createQueryBuilder('i')
            ->select('ic.categoryId', 'c.uppercats')
            ->innerJoin(ImageCategoryEntity::class, 'ic', \Doctrine\ORM\Query\Expr\Join::WITH, 'ic.imageId = i.id')
            ->innerJoin(CategoryEntity::class, 'c', \Doctrine\ORM\Query\Expr\Join::WITH, 'ic.categoryId = c.id')
            ->orderBy('i.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        $categoryId = $row['categoryId'];
        $uppercats = $row['uppercats'];
        if (! $categoryId instanceof CategoryId || ! is_string($uppercats)) {
            return null;
        }

        return [
            'category_id' => $categoryId->value,
            'uppercats' => $uppercats,
        ];
    }

    /**
     * Every distinct (width, height) pair among images that have both set
     * -- Controller\Admin\BatchManagerSubController's own dimension-filter
     * option aggregation.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE, `SELECT DISTINCT` DQL expresses directly.
     *
     * @return list<array<string, mixed>>
     */
    public function findDistinctDimensions(): array
    {
        $result = [];
        foreach ($this->createQueryBuilder('i')
            ->select('DISTINCT i.width AS width', 'i.height AS height')
            ->where('i.width IS NOT NULL')
            ->andWhere('i.height IS NOT NULL')
            ->getQuery()
            ->getArrayResult() as $row) {
            if (is_array($row)) {
                $result[] = [
                    'width' => $row['width'] ?? null,
                    'height' => $row['height'] ?? null,
                ];
            }
        }

        return $result;
    }

    /**
     * Every distinct filesize among images that have one set --
     * Controller\Admin\BatchManagerSubController's own filesize-filter
     * option aggregation.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE + GROUP BY on a real column (not an alias).
     *
     * @return list<array<string, mixed>>
     */
    public function findDistinctFilesizes(): array
    {
        $result = [];
        foreach ($this->createQueryBuilder('i')
            ->select('i.filesize AS filesize')
            ->where('i.filesize IS NOT NULL')
            ->groupBy('i.filesize')
            ->getQuery()
            ->getArrayResult() as $row) {
            if (is_array($row)) {
                $result[] = [
                    'filesize' => $row['filesize'] ?? null,
                ];
            }
        }

        return $result;
    }

    /**
     * Paginated thumbnail rows for the batch manager's global-mode grid --
     * Admin\BatchManagerGlobalPageRenderer's own listing. $orderBySql is a
     * raw " ORDER BY ..." fragment (CurrentConfig::orderBy()/
     * orderByInsideCategory(), a category's own image_order, or the
     * duplicates-fields ordering the caller computes) -- embedded as text
     * like every other order-by fragment in this codebase, not something
     * this extraction should re-litigate. $categoryId non-null additionally
     * joins imageCategory and restricts to that category.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}), but stays on DBAL regardless --
     * $orderBySql is a caller-composed raw SQL fragment spliced directly
     * into the query, which DQL has no way to embed.
     *
     * Item 15 audit, re-verified: this plan's own text speculated
     * $orderBySql "likely shares tokens with PhotoSortField or needs its
     * own small enum" -- wrong once traced to its real source
     * (`CurrentConfig::orderBy()`, free-form admin-configurable text, not
     * a bounded token set). No enum conversion here.
     *
     * @param array<array-key, int|string|float|bool> $imageIds
     * @return list<array<string, mixed>>
     */
    public function findBatchManagerThumbnails(array $imageIds, ?int $categoryId, string $orderBySql, int $limit, int $offset): array
    {
        $imagesTable = Tables::images();

        $query = <<<SQL
            SELECT id,path,representative_ext,file,filesize,level,name,width,height,rotation
            FROM {$imagesTable}
            SQL;
        $params = [
            'ids' => array_map(strval(...), $imageIds),
            'limit' => $limit,
            'offset' => $offset,
        ];
        $types = [
            'ids' => ArrayParameterType::STRING,
            'limit' => ParameterType::INTEGER,
            'offset' => ParameterType::INTEGER,
        ];

        if ($categoryId !== null) {
            $imageCategoryTable = Tables::imageCategory();
            $query .= <<<SQL

                JOIN {$imageCategoryTable} ON id = image_id
                SQL;
        }

        $query .= <<<SQL

            WHERE id IN (:ids)
            SQL;

        if ($categoryId !== null) {
            $query .= <<<SQL

                AND category_id = :categoryId
                SQL;
            $params['categoryId'] = $categoryId;
            $types['categoryId'] = ParameterType::INTEGER;
        }

        $query .= <<<SQL

            {$orderBySql}
            LIMIT :limit OFFSET :offset
            SQL;

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative($query, $params, $types);
    }

    /**
     * id + date_creation for $imageIds -- Admin\BatchManagerUnitPageRenderer's
     * own per-image form-submission save loop.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE id IN (...).
     *
     * @param array<array-key, int|string> $imageIds
     * @return list<array<string, mixed>>
     */
    public function findIdsAndDatesForBatchUnitSave(array $imageIds): array
    {
        $result = [];
        foreach ($this->createQueryBuilder('i')
            ->select('i.id', 'i.dateCreation AS date_creation')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', array_map(strval(...), $imageIds), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult() as $row) {
            if (is_array($row)) {
                $result[] = [
                    'id' => $row['id'] ?? null,
                    'date_creation' => $row['date_creation'] ?? null,
                ];
            }
        }

        return $result;
    }

    /**
     * Same dynamic pagination shape as findBatchManagerThumbnails() above,
     * but every column (`SELECT *`) -- Admin\BatchManagerUnitPageRenderer's
     * own per-image inline-edit grid needs far more columns than the
     * global-mode thumbnail grid does.
     *
     * Item 14 DQL audit, re-corrected: same reasons as
     * findBatchManagerThumbnails() above -- `image_category` is now
     * mapped ({@see ImageCategoryEntity}), but $orderBySql is a
     * caller-composed raw fragment DQL has no way to embed.
     *
     * Item 15 audit, re-verified: same "genuinely open-ended, not a small
     * token set" re-check as findBatchManagerThumbnails() above -- no
     * enum conversion here either.
     *
     * @param array<array-key, int|string|float|bool> $imageIds
     * @return list<array<string, mixed>>
     */
    public function findBatchManagerUnitRows(array $imageIds, ?int $categoryId, string $orderBySql, int $limit, int $offset): array
    {
        $imagesTable = Tables::images();

        $query = <<<SQL
            SELECT *
            FROM {$imagesTable}
            SQL;
        $params = [
            'ids' => array_map(strval(...), $imageIds),
            'limit' => $limit,
            'offset' => $offset,
        ];
        $types = [
            'ids' => ArrayParameterType::STRING,
            'limit' => ParameterType::INTEGER,
            'offset' => ParameterType::INTEGER,
        ];

        if ($categoryId !== null) {
            $imageCategoryTable = Tables::imageCategory();
            $query .= <<<SQL

                JOIN {$imageCategoryTable} ON id = image_id
                SQL;
        }

        $query .= <<<SQL

            WHERE id IN (:ids)
            SQL;

        if ($categoryId !== null) {
            $query .= <<<SQL

                AND category_id = :categoryId
                SQL;
            $params['categoryId'] = $categoryId;
            $types['categoryId'] = ParameterType::INTEGER;
        }

        $query .= <<<SQL

            {$orderBySql}
            LIMIT :limit OFFSET :offset
            SQL;

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative($query, $params, $types);
    }

    /**
     * Categories $imageId is linked to, with each category's own uppercats
     * and dir -- Admin\BatchManagerUnitPageRenderer's own per-image
     * "related albums" display.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- joins the
     * cross-domain {@see \Piwigo\Category\CategoryEntity} (same
     * L2aCoreDomain layer). Per the audit's own gotcha #1, the aliased
     * `ic.categoryId AS category_id` still hydrates as a real
     * {@see CategoryId} under array hydration despite the alias, so it's
     * unwrapped explicitly to preserve this method's own `int|string`
     * array-shape contract.
     *
     * @return list<array<string, mixed>>
     */
    public function findCategoryLinksForImage(int $imageId): array
    {
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('ic.categoryId AS category_id', 'c.uppercats', 'c.dir')
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin(CategoryEntity::class, 'c', \Doctrine\ORM\Query\Expr\Join::WITH, 'c.id = ic.categoryId')
            ->where('ic.imageId = :imageId')
            ->setParameter('imageId', $imageId, ParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $categoryId = $row['category_id'];
            $result[] = [
                'category_id' => $categoryId instanceof CategoryId ? $categoryId->value : $categoryId,
                'uppercats' => $row['uppercats'] ?? null,
                'dir' => $row['dir'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Bare category ids $imageId is linked to (no join) --
     * Admin\BatchManagerUnitPageRenderer's own "jump to" link permission
     * check, a separate query from findCategoryLinksForImage() above (same
     * image_id, no uppercats/dir needed here).
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-table,
     * static WHERE. Per the audit's own gotcha #4, `getSingleColumnResult()`
     * uses `HYDRATE_SCALAR_COLUMN`, which does NOT apply the `category_id`
     * custom Type -- the selected `ic.categoryId` comes back as a raw
     * scalar here, exactly what this method's own `list<int>` contract
     * wants, so no VO-unwrap is needed (unlike the array/object-hydrated
     * conversions elsewhere in this file).
     *
     * @return list<int>
     */
    public function findCategoryIdsForImage(int $imageId): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->createQueryBuilder()
                ->select('ic.categoryId')
                ->from(ImageCategoryEntity::class, 'ic')
                ->where('ic.imageId = :imageId')
                ->setParameter('imageId', $imageId, ParameterType::INTEGER)
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Ids of images uploaded before the 2022-12-08 issue-1827 fix, under
     * `./upload/`, capped at $limit -- Admin\Maintenance\
     * FilesystemIntegrityChecker::fsQuickCheck()'s own sampling pool for
     * that historical bug, merged with findImageIdsSample()'s general
     * random pool before the actual file_exists() check.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE (the date/path literals are fixed, not caller-controlled).
     *
     * @return list<int>
     */
    public function findIssue1827CandidateImageIds(int $limit): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->createQueryBuilder('i')
                ->select('i.id')
                ->where("i.dateAvailable < '2022-12-08 00:00:00'")
                ->andWhere("i.path LIKE './upload/%'")
                ->setMaxResults($limit)
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Every image id, capped at $limit, in whatever order the database
     * happens to return them -- FilesystemIntegrityChecker::fsQuickCheck()'s
     * own general sampling pool (the caller shuffles and slices further).
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, no
     * WHERE.
     *
     * @return list<int>
     */
    public function findImageIdsSample(int $limit): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->createQueryBuilder('i')
                ->select('i.id')
                ->setMaxResults($limit)
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Every path sharing its value with at least one other image row --
     * FilesystemIntegrityChecker::fsQuickCheck()'s own duplicate-path
     * detection (only the count matters to the caller).
     *
     * Item 14 DQL audit: converted to real DQL -- single-table,
     * GROUP BY/HAVING on a real column DQL expresses directly.
     *
     * @return list<string>
     */
    public function findDuplicatePaths(): array
    {
        return array_values(array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            $this->createQueryBuilder('i')
                ->select('i.path')
                ->groupBy('i.path')
                ->having('COUNT(i.id) > 1')
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Image ids referenced by `image_category` with no matching row in
     * `images` at all -- FilesystemIntegrityChecker::imagesIntegrity()'s
     * own orphaned-link detection.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- LEFT JOIN
     * FROM it into this repository's own {@see ImageEntity}.
     *
     * @return list<int>
     */
    public function findOrphanImageCategoryLinkIds(): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->createQueryBuilder()
                ->select('ic.imageId')
                ->from(ImageCategoryEntity::class, 'ic')
                ->leftJoin(ImageEntity::class, 'i', \Doctrine\ORM\Query\Expr\Join::WITH, 'i.id = ic.imageId')
                ->where('i.id IS NULL')
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Deletes every `image_category` row for $imageIds regardless of
     * category -- FilesystemIntegrityChecker::imagesIntegrity()'s own
     * orphaned-link cleanup, unlike deleteImageCategoryLinks() above which
     * is scoped to one category.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-table
     * bulk DELETE.
     *
     * @param list<int> $imageIds
     */
    public function deleteImageCategoryRowsForImageIds(array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }

        $this->getEntityManager()
            ->createQueryBuilder()
            ->delete(ImageCategoryEntity::class, 'ic')
            ->where('ic.imageId IN (:ids)')
            ->setParameter('ids', $imageIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
    }

    /**
     * id/file/level for $imageId (when positive) or the first image whose
     * file matches $imageFile (a `LIKE` pattern, `_`/`%` already escaped by
     * the caller) -- Controller\PictureController's own "resolve the
     * requested picture, by id or by filename" lookup.
     *
     * SQL-modernization audit / [SEC-20]: $imageFile used to splice raw
     * into the query -- a real, guest-reachable SQL injection. Traced to
     * its real source: Section\SectionInitializer::parseUrl() captures it
     * via `preg_match('/^(\d+-)?(.*)?$/', $token, ...)`, an unrestricted
     * capture of a raw picture.php URL path segment, and
     * Controller\PictureController's own "already escaped by the caller"
     * claim only neutralizes LIKE's `_`/`%` wildcards (`str_replace(['_',
     * '%'], ['/_', '/%'], ...)`), not SQL quote characters -- a filename
     * containing a literal `'` reached this method's raw SQL untouched.
     * Fixed as a bound parameter.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, the
     * branch picks between two static WHERE shapes (not a caller-
     * supplied fragment), both DQL expresses directly. `id` is the
     * primary key, so that branch can never match >1 row; the LIKE
     * branch's own setMaxResults(1) is paired with getOneOrNullResult()
     * per the audit's own gotcha #3 either way.
     *
     * @return array<string, mixed>|false
     */
    public function findByIdOrFilePattern(int $imageId, ?string $imageFile): array|false
    {
        $qb = $this->createQueryBuilder('i')
            ->select('i.id', 'i.file', 'i.level')
            ->setMaxResults(1);

        if ($imageId > 0) {
            $qb->where('i.id = :imageId')
                ->setParameter('imageId', $imageId, ParameterType::INTEGER);
        } else {
            assert($imageFile !== null && $imageFile !== '');
            $qb->where("i.file LIKE :imageFile ESCAPE '/'")
                ->setParameter('imageFile', $imageFile . '.%');
        }

        $row = $qb->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return false;
        }

        return [
            'id' => $row['id'] ?? null,
            'file' => $row['file'] ?? null,
            'level' => $row['level'] ?? null,
        ];
    }

    /**
     * Ids of images already at $filename within $categoryId -- Ws\
     * PwgImages::upload()'s own "update_mode" replace-existing lookup.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- inner join
     * against this repository's own entity.
     *
     * @return list<int>
     */
    public function findIdsByFilenameInCategory(string $filename, int $categoryId): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->createQueryBuilder('i')
                ->select('i.id')
                ->innerJoin(ImageCategoryEntity::class, 'ic', \Doctrine\ORM\Query\Expr\Join::WITH, 'ic.imageId = i.id')
                ->where('i.file = :filename')
                ->andWhere('ic.categoryId = :categoryId')
                ->setParameter('filename', $filename)
                ->setParameter('categoryId', CategoryId::from($categoryId))
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * `path` for $imageId, or null if it doesn't exist -- Ws\PwgImages::
     * checkFiles()'s own "does the client's local file match ours" lookup.
     */
    /**
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE on the primary key.
     */
    public function findPathById(int $imageId): ?string
    {
        $row = $this->createQueryBuilder('i')
            ->select('i.path AS path')
            ->where('i.id = :imageId')
            ->setParameter('imageId', $imageId, ParameterType::INTEGER)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        return is_array($row) && is_string($row['path']) ? $row['path'] : null;
    }

    /**
     * id/name/representative_ext/path for $imageId -- Ws\PwgImages::
     * upload()'s own "what does the just-uploaded/replaced photo look
     * like" lookup, used to build the response's thumbnail URLs.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE on the primary key.
     *
     * @return ?array{id: int, name: ?string, representative_ext: ?string, path: string}
     */
    public function findUploadResultInfoById(int $imageId): ?array
    {
        $row = $this->createQueryBuilder('i')
            ->select('i.id', 'i.name', 'i.representativeExt AS representative_ext', 'i.path')
            ->where('i.id = :imageId')
            ->setParameter('imageId', $imageId, ParameterType::INTEGER)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        return [
            'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
            'name' => is_string($row['name'] ?? null) ? $row['name'] : null,
            'representative_ext' => is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
            'path' => is_string($row['path']) ? $row['path'] : '',
        ];
    }

    /**
     * Number of images linked to $categoryId -- Ws\PwgImages::upload()'s
     * own "how many photos are now in this category" response field.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-table,
     * static WHERE.
     */
    public function countImagesInCategory(int $categoryId): int
    {
        $value = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(ic.imageId)')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.categoryId = :categoryId')
            ->setParameter('categoryId', CategoryId::from($categoryId))
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Whether $imageId is reachable via at least one category satisfying
     * $criteria -- Controller\PictureController's own "can this image
     * still be accessed differently" fallback check, and Ws\PwgImages::
     * rate()'s own accessibility gate.
     *
     * SQL-modernization audit, Item 14 Sub-phase C1: converted to a typed
     * {@see PermissionCriteria} -- both real callers traced directly
     * (their own surrounding code, not just their own
     * `getSqlConditionFandFAsCondition()` field list): PictureController's
     * caller omitted the old `forbidden_images => 'id'` field, but only
     * because it had already independently confirmed `$row['level'] <=
     * $user_level` a few lines above for this exact same image -- applying
     * $criteria->maxLevel here unconditionally re-confirms the same
     * already-true fact for that caller (a harmless redundant check, not a
     * behavior change) while correctly gating Ws\PwgImages::rate()'s own
     * caller, which never performed that check itself.
     *
     * Item 15 audit: converted to real DQL -- both `images`/`image_category`
     * are mapped, and {@see PermissionCriteria}'s fragments needed no
     * changes (see {@see applyCondition()}'s own docblock).
     */
    public function isImageAccessibleWithCondition(int $imageId, PermissionCriteria $criteria): bool
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('i.id')
            ->from(ImageEntity::class, 'i')
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'i.id = ic.imageId')
            ->where('i.id = :imageId')
            ->setMaxResults(1)
            ->setParameter('imageId', $imageId, ParameterType::INTEGER);

        self::applyCondition($qb, SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('ic.categoryId'),
            $criteria->maxLevelCondition('i.level'),
        ));

        return $qb->getQuery()
            ->getSingleColumnResult() !== [];
    }

    /**
     * Every column of $imageId's own row, if it satisfies $criteria --
     * Ws\PwgImages::getInfo()'s own image lookup.
     *
     * SQL-modernization audit, Item 14 Sub-phase C1: converted to a typed
     * {@see PermissionCriteria} -- the one real caller passed only
     * `visible_images => 'id'` to the old `getSqlConditionFandFAsCondition()`,
     * but that method's own `visible_images` case falls through into
     * `forbidden_images` with no `break` (see its own docblock/that
     * method's body) -- with fieldName `'id'`, this also applies the
     * images-table's own `level <= x` check, so both visibleImageIds and
     * maxLevel apply here, not visibleImageIds alone.
     *
     * Item 15 audit: stays on DBAL despite {@see PermissionCriteria} no
     * longer being a blocker (see {@see applyCondition()}'s own
     * docblock) -- the real remaining blocker is `SELECT *` itself: this
     * row is {@see \Piwigo\Ws\PwgImages::getInfo()}'s own public WS
     * response shape, read/re-emitted with its raw snake_case column
     * names (`$image_row['rating_score']`, etc.) as real external API
     * contract. DQL always hydrates through the entity's own (camelCase)
     * property names, never the raw column name -- reproducing the exact
     * original row shape would mean hand-mapping every one of
     * `ImageEntity`'s ~25 properties back to its raw column name, real
     * effort for no real gain on a query that's already fully bound,
     * injection-safe DBAL.
     *
     * @return ?array<string, mixed>
     */
    public function findRowWithCondition(int $imageId, PermissionCriteria $criteria): ?array
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('*')
            ->from(Tables::images())
            ->where('id = :imageId')
            ->setMaxResults(1)
            ->setParameter('imageId', $imageId, ParameterType::INTEGER);

        self::applyCondition($qb, SqlCondition::combine(
            'AND',
            $criteria->visibleImagesCondition('id'),
            $criteria->maxLevelCondition('level'),
        ));

        $row = $qb->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * Categories $imageId belongs to that satisfy $criteria, with each
     * category's own display-relevant columns (including `commentable`,
     * unlike findVisibleCategoriesForImage() below) -- Ws\PwgImages::
     * getInfo()'s own "related categories" block.
     *
     * SQL-modernization audit, Item 14 Sub-phase C1: converted to a typed
     * {@see PermissionCriteria} -- the one real caller only ever applies
     * $criteria->forbiddenCategoryIds, against `ic.category_id`.
     *
     * Item 15 audit: converted to real DQL -- unlike
     * {@see findRowWithCondition()}, this selects a fixed, hand-picked
     * column set (not `SELECT *`), so mapping each one back to its raw
     * row key is a small, bounded rename rather than an open-ended
     * `ImageEntity`-wide serializer. `commentable` hydrates as a real
     * `bool` now (was a raw DBAL int before) -- confirmed safe: the one
     * real caller ({@see \Piwigo\Ws\PwgImages::getInfo()}) already
     * `(bool)`-casts it and `unset()`s the key immediately after, before
     * the row ever reaches its own JSON response.
     *
     * @return list<array<string, mixed>>
     */
    public function findRelatedCategoriesForImage(int $imageId, PermissionCriteria $criteria): array
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('c.id', 'c.name', 'c.permalink', 'c.uppercats', 'c.globalRank AS global_rank', 'c.commentable')
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin(CategoryEntity::class, 'c', Join::WITH, 'ic.categoryId = c.id')
            ->where('ic.imageId = :imageId')
            ->setParameter('imageId', $imageId, ParameterType::INTEGER);

        self::applyCondition($qb, $criteria->forbiddenCategoriesCondition('ic.categoryId'));

        $result = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => $row['id'] ?? null,
                'name' => $row['name'] ?? null,
                'permalink' => $row['permalink'] ?? null,
                'uppercats' => $row['uppercats'] ?? null,
                'global_rank' => $row['global_rank'] ?? null,
                'commentable' => $row['commentable'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Whether $imageId belongs to at least one commentable category
     * satisfying $criteria -- Ws\PwgImages::addComment()'s own "can this
     * image receive a comment" check.
     *
     * SQL-modernization audit, Item 14 Sub-phase C1: converted to a typed
     * {@see PermissionCriteria} -- the one real caller applies
     * forbiddenCategoryIds/visibleCategoryIds against `c.id`. It also
     * passed `visible_images => 'image_id'` to the old
     * `getSqlConditionFandFAsCondition()`, whose own `visible_images` case
     * falls through into `forbidden_images` with no `break` -- with
     * fieldName `'image_id'` (not `'id'`/`'i.id'`), that's the
     * `image_access_list` branch, not the level check, so
     * visibleImageIds/imageAccessIds both apply against `ic.image_id`.
     *
     * Item 15 audit: converted to real DQL -- {@see PermissionCriteria}'s
     * fragments needed no changes (see {@see applyCondition()}'s own
     * docblock).
     */
    public function isImageCommentableWithCondition(int $imageId, PermissionCriteria $criteria): bool
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('DISTINCT ic.imageId')
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin(CategoryEntity::class, 'c', Join::WITH, 'ic.categoryId = c.id')
            ->where('c.commentable = :true')
            ->andWhere('ic.imageId = :imageId')
            ->setParameter('true', true)
            ->setParameter('imageId', $imageId, ParameterType::INTEGER);

        self::applyCondition($qb, SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('c.id'),
            $criteria->visibleCategoriesCondition('c.id'),
            $criteria->visibleImagesCondition('ic.imageId'),
            $criteria->imageAccessCondition('ic.imageId'),
        ));

        return $qb->getQuery()
            ->getSingleColumnResult() !== [];
    }

    /**
     * Categories $imageId belongs to that satisfy $criteria, with each
     * category's own display-relevant columns -- Controller\
     * PictureController's own "related categories" block, ordered by
     * CategoryService::compareByGlobalRank() afterwards (not here).
     *
     * SQL-modernization audit, Item 14 Sub-phase C1: converted to a typed
     * {@see PermissionCriteria} -- the one real caller applies
     * forbiddenCategoryIds/visibleCategoryIds against `c.id`.
     *
     * Item 15 audit: converted to real DQL. `commentable`/`visible`
     * hydrate as real `bool` now (were raw DBAL ints before) -- confirmed
     * safe: {@see \Piwigo\Picture\PictureCommentRenderer::render()}'s own
     * `commentable` read already `(bool)`-casts it, and `visible` has no
     * strict-typed reader in either real consumer
     * ({@see \Piwigo\Controller\PictureController}/
     * `PictureCommentRenderer`).
     *
     * @return list<array<string, mixed>>
     */
    public function findVisibleCategoriesForImage(int $imageId, PermissionCriteria $criteria): array
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('c.id', 'c.uppercats', 'c.commentable', 'c.visible', 'c.status', 'c.globalRank AS global_rank')
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin(CategoryEntity::class, 'c', Join::WITH, 'ic.categoryId = c.id')
            ->where('ic.imageId = :imageId')
            ->setParameter('imageId', $imageId, ParameterType::INTEGER);

        self::applyCondition($qb, SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('c.id'),
            $criteria->visibleCategoriesCondition('c.id'),
        ));

        $result = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => $row['id'] ?? null,
                'uppercats' => $row['uppercats'] ?? null,
                'commentable' => $row['commentable'] ?? null,
                'visible' => $row['visible'] ?? null,
                'status' => $row['status'] ?? null,
                'global_rank' => $row['global_rank'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Ids of the real (INNER JOINed, so never-orphaned) categories
     * $imageId is associated with -- Admin\PictureModifyPageRenderer's
     * own "associate to albums" checkbox list, a different query from
     * findCategoryIdsForImage() above (that one has no join, so it can
     * include ids for categories that no longer exist).
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- joins the
     * cross-domain {@see \Piwigo\Category\CategoryEntity} (same
     * L2aCoreDomain layer).
     *
     * @return list<int>
     */
    public function findAssociatedCategoryIds(int $imageId): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->createQueryBuilder()
                ->select('c.id')
                ->from(CategoryEntity::class, 'c')
                ->innerJoin(ImageCategoryEntity::class, 'ic', \Doctrine\ORM\Query\Expr\Join::WITH, 'c.id = ic.categoryId')
                ->where('ic.imageId = :imageId')
                ->setParameter('imageId', $imageId, ParameterType::INTEGER)
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Ids of every image with $md5sum -- Admin\Upload\UploadService's own
     * upload-time duplicate detection.
     *
     * SQL-modernization audit / [SEC-20]: $md5sum used to splice raw into
     * the query -- a real SQL injection. Traced to its real source:
     * Ws\PwgImages::add()'s own `$params['original_sum']`, registered
     * with zero WS-level type constraints (`'original_sum' => []` in
     * WsDefaultMethods.php, unlike e.g. `level`'s
     * `WsParamType::INT|POSITIVE`) -- a fully free-form,
     * caller-controlled string, despite its "md5sum" name. Fixed as a
     * bound parameter.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE.
     *
     * @return list<int>
     */
    public function findIdsByMd5sum(string $md5sum): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->createQueryBuilder('i')
                ->select('i.id')
                ->where('i.md5sum = :md5sum')
                ->setParameter('md5sum', $md5sum)
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * id keyed by md5sum, for a batch of md5sums -- Ws\PwgImages::exist()'s
     * own bulk "which of these already-uploaded checksums exist" check.
     * Parameter-bound (unlike the original's raw string interpolation of
     * client-supplied md5sum values -- an injection risk fixed as part of
     * this migration).
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE md5sum IN (...).
     *
     * @param list<string> $md5sums
     * @return array<string, int>
     */
    public function findIdsByMd5sums(array $md5sums): array
    {
        if ($md5sums === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->select('i.id', 'i.md5sum')
            ->where('i.md5sum IN (:md5sums)')
            ->setParameter('md5sums', $md5sums, ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        $idByMd5sum = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['md5sum'] ?? null) && is_numeric($row['id'] ?? null)) {
                $idByMd5sum[$row['md5sum']] = (int) $row['id'];
            }
        }

        return $idByMd5sum;
    }

    /**
     * id keyed by filename, for a batch of filenames -- Ws\PwgImages::
     * exist()'s own bulk "which of these filenames already exist" check.
     * Parameter-bound, same injection-risk fix as findIdsByMd5sums() above.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE file IN (...).
     *
     * @param list<string> $filenames
     * @return array<string, int>
     */
    public function findIdsByFilenames(array $filenames): array
    {
        if ($filenames === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->select('i.id', 'i.file')
            ->where('i.file IN (:filenames)')
            ->setParameter('filenames', $filenames, ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        $idByFilename = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['file'] ?? null) && is_numeric($row['id'] ?? null)) {
                $idByFilename[$row['file']] = (int) $row['id'];
            }
        }

        return $idByFilename;
    }

    /**
     * The format_id of $imageId's existing format row for $ext, if any --
     * Admin\Upload\UploadService's own "update an existing format vs
     * insert a new one" check.
     */
    /**
     * Item 14 DQL audit: converted to real DQL -- single-table (this
     * repository's own {@see ImageFormatEntity}), static WHERE;
     * setMaxResults(1) paired with getOneOrNullResult() per the audit's
     * own gotcha #3 (the original had no unique constraint on
     * (image_id, ext) to rely on).
     */
    public function findFormatIdByImageAndExt(int $imageId, string $ext): ?int
    {
        $row = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f.formatId AS formatId')
            ->from(ImageFormatEntity::class, 'f')
            ->where('f.imageId = :imageId')
            ->andWhere('f.ext = :ext')
            ->setParameter('imageId', $imageId)
            ->setParameter('ext', $ext)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        return is_array($row) && is_numeric($row['formatId']) ? (int) $row['formatId'] : null;
    }

    /**
     * Whether at least one accessible image (satisfying $criteria) has a
     * non-null author -- Controller\SearchController's own "does this
     * gallery even have authors, for this user" check.
     *
     * SQL-modernization audit, Item 14 Sub-phase C1: converted to a typed
     * {@see PermissionCriteria} -- the one real caller applies
     * forbiddenCategoryIds/visibleCategoryIds against `ic.category_id` and
     * visibleImageIds against `i.id`. It also passed `visible_images =>
     * 'id'` to the old `getSqlConditionFandFAsCondition()`, whose own
     * `visible_images` case falls through into `forbidden_images` with no
     * `break` -- with fieldName `'id'`, that's the images-table's own
     * `level <= x` check, so maxLevel applies here too, against `i.level`.
     *
     * Item 15 audit: converted to real DQL -- {@see PermissionCriteria}'s
     * fragments needed no changes (see {@see applyCondition()}'s own
     * docblock).
     */
    public function hasAccessibleImageWithAuthor(PermissionCriteria $criteria): bool
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('i.id')
            ->from(ImageEntity::class, 'i')
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'ic.imageId = i.id')
            ->andWhere('i.author IS NOT NULL')
            ->setMaxResults(1);

        self::applyCondition($qb, SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('ic.categoryId'),
            $criteria->visibleCategoriesCondition('ic.categoryId'),
            $criteria->visibleImagesCondition('i.id'),
            $criteria->maxLevelCondition('i.level'),
        ));

        return $qb->getQuery()
            ->getSingleColumnResult() !== [];
    }

    /**
     * Whether $imageId is reachable via at least one of its own categories
     * satisfying $criteria -- Controller\ActionController's own
     * download-permission check. A different query shape from
     * isImageAccessibleWithCondition() above (this one starts from
     * `categories`, joined onto `image_category` by category_id, filtered
     * by image_id -- that one starts from `images`).
     *
     * SQL-modernization audit, Item 14 Sub-phase C1: converted to a typed
     * {@see PermissionCriteria} -- the one real caller applies
     * forbiddenCategoryIds against `ic.category_id` and (the field name it
     * passed was `image_id`, a foreign-key column, never the images
     * table's own `id`) imageAccessIds against `ic.image_id`, not
     * maxLevel.
     *
     * Item 15 audit: converted to real DQL -- {@see PermissionCriteria}'s
     * fragments needed no changes (see {@see applyCondition()}'s own
     * docblock).
     */
    public function isImageAccessibleViaCategoryWithCondition(int $imageId, PermissionCriteria $criteria): bool
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('c.id')
            ->from(CategoryEntity::class, 'c')
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'ic.categoryId = c.id')
            ->where('ic.imageId = :imageId')
            ->setMaxResults(1)
            ->setParameter('imageId', $imageId, ParameterType::INTEGER);

        self::applyCondition($qb, SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('ic.categoryId'),
            $criteria->imageAccessCondition('ic.imageId'),
        ));

        return $qb->getQuery()
            ->getSingleColumnResult() !== [];
    }

    /**
     * Further SQL-modernization audit, Item 13: every column of every
     * image matching $criteria, joined against `image_category` and
     * deduplicated by image id -- Ws\PwgCategories::getImages()'s own
     * paginated listing. $criteria's 3 conditions (filter/category scope/
     * visible-images permission) are combined internally via
     * SqlCondition::combine(), replacing the caller-built `list<string>
     * $whereClauses` this used to take. $orderByClause is still an
     * already-built, trusted SQL fragment -- same "caller composes
     * trusted ORDER BY text" contract as
     * {@see \Piwigo\Comment\CommentRepository::findForImage()}'s own
     * $order.
     *
     * Phase 5 Item 19: `SQL_CALC_FOUND_ROWS`/`FOUND_ROWS()` replaced with
     * `COUNT(*) OVER() AS total_count`, computed in the same query as the
     * row data instead of a second round-trip coupled to connection
     * state -- `GROUP BY i.id` here (not `DISTINCT`), so the window
     * function (evaluated after GROUP BY, before LIMIT/OFFSET) reports
     * the exact same total the old mechanism did. `total_count` is
     * stripped back out of each row before returning -- `i.*` never
     * included it before this change.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}), but stays on DBAL regardless --
     * $criteria->filterCriteria is now a typed
     * {@see \Piwigo\Image\ImageFilterCriteria} -- translated to a raw
     * fragment here via its own `toSqlCondition()`, since this method
     * itself can't become DQL: `i.*` is a whole-row selection DQL can't
     * express (no fixed property list), and `$orderByClause` is a
     * caller-composed trusted SQL fragment.
     *
     * @return PaginatedResult<array<string, mixed>>
     */
    public function findWithConditionsPaginated(CategoryImagesCriteria $criteria, string $orderByClause, int $limit, int $offset): PaginatedResult
    {
        $conn = $this->getEntityManager()
            ->getConnection();

        $combined = SqlCondition::combine(
            'AND',
            $criteria->filterCriteria->toSqlCondition('i.'),
            new SqlCondition('category_id IN (:categoryIds)', [
                'categoryIds' => $criteria->categoryIds,
            ], [
                'categoryIds' => ArrayParameterType::INTEGER,
            ]),
            $criteria->visibleImagesCondition,
        );

        $imagesTable = Tables::images();
        $imageCategoryTable = Tables::imageCategory();

        $sql = <<<SQL
            SELECT i.*, COUNT(*) OVER() AS total_count
            FROM {$imagesTable} i
                INNER JOIN {$imageCategoryTable} ON i.id=image_id
            WHERE {$combined->sql}
            GROUP BY i.id
            {$orderByClause}
            LIMIT :limit
            OFFSET :offset
            SQL;
        $params = $combined->parameters;
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        $types = $combined->types;
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        $rows = $conn->fetchAllAssociative($sql, $params, $types);

        $total = $rows !== [] && is_numeric($rows[0]['total_count'] ?? null) ? (int) $rows[0]['total_count'] : 0;
        $rows = array_map(static function (array $row): array {
            unset($row['total_count']);

            return $row;
        }, $rows);

        return new PaginatedResult($rows, $total);
    }

    /**
     * Whether an image with this id exists -- Ws\PwgCategories::
     * setRepresentative()'s own existence check.
     */
    /**
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE on the primary key.
     */
    public function existsById(int $id): bool
    {
        $value = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * Which of $ids are real image ids -- Ws\PwgImages::syncMetadata()'s
     * own "filter the caller's list down to images that actually exist"
     * step.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE id IN (...).
     *
     * @param array<int|string> $ids
     * @return list<int>
     */
    public function findExistingIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->createQueryBuilder('i')
                ->select('i.id')
                ->where('i.id IN (:ids)')
                ->setParameter('ids', array_map(strval(...), $ids), ArrayParameterType::STRING)
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * image_id/category_id link rows for $imageIds matching $condition --
     * Ws\PwgCategories::getImages()'s own "which albums (that the caller
     * may see) is each returned photo linked to" step.
     *
     * SQL-modernization audit, Item 14 Sub-phase C1: converted to a typed
     * {@see PermissionCriteria} -- the one real caller only ever applies
     * forbiddenCategoryIds, against the unqualified `category_id` (single
     * table, no join, so no alias needed). The old call site's own
     * `forceOneCondition: true` is a no-op here regardless: an empty
     * criteria field already produces an empty SqlCondition that
     * applyCondition() silently skips, the same net effect as adding a
     * harmless `1 = 1`. $imageIds' own CSV splice also bound.
     *
     * Further SQL-modernization audit, Item 15G: converted to real DQL --
     * {@see ImageCategoryEntity} is mapped and {@see PermissionCriteria}
     * needs no API changes, same finding as every other consumer in this
     * file. `categoryId` hydrates as a {@see CategoryId} VO (the entity's
     * own custom Doctrine type), unwrapped below same as every other
     * `category_id` read in this codebase.
     *
     * @param  list<int>  $imageIds
     * @return list<array{image_id: int, category_id: int}>
     */
    public function findCategoryLinksForImageIdsWithCondition(array $imageIds, PermissionCriteria $criteria): array
    {
        if ($imageIds === []) {
            return [];
        }

        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('ic.imageId', 'ic.categoryId')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.imageId IN (:imageIds)')
            ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);

        self::applyCondition($qb, $criteria->forbiddenCategoriesCondition('ic.categoryId'));

        $result = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'image_id' => is_numeric($row['imageId']) ? (int) $row['imageId'] : 0,
                'category_id' => $row['categoryId'] instanceof CategoryId ? $row['categoryId']->value : (is_numeric($row['categoryId']) ? (int) $row['categoryId'] : 0),
            ];
        }

        return $result;
    }

    /**
     * Next free id -- Controller\Admin\SiteUpdateSubController's own
     * manual-id assignment for directory-synced images (mirrors the
     * retired MysqliDb::nextval()).
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, no
     * WHERE. MySQL's `IF(MAX(id)+1 IS NULL, 1, MAX(id)+1)` is rewritten
     * as its exact DQL equivalent `COALESCE(MAX(id)+1, 1)` (MAX(id)+1 is
     * null only when the table is empty, in which case COALESCE falls
     * through to the same literal 1 the original's own IF() branch
     * returned) -- COALESCE is a standard DQL function, unlike IF()
     * itself.
     */
    public function findNextId(): int
    {
        $next = $this->createQueryBuilder('i')
            ->select('COALESCE(MAX(i.id) + 1, 1)')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($next) ? (int) $next : 1;
    }

    /**
     * path keyed by id, for every image whose storage_category_id is in
     * $categoryIds -- Controller\Admin\SiteUpdateSubController's own
     * "which files does the DB already know about, for these directory-
     * synced categories" step.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE storage_category_id IN (...).
     *
     * @param  list<int|string>  $categoryIds
     * @return array<int, string> keyed by id
     */
    public function findIdsAndPathsByStorageCategoryIds(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->select('i.id', 'i.path')
            ->where('i.storageCategoryId IN (:categoryIds)')
            ->setParameter('categoryIds', array_map(strval(...), $categoryIds), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'];
            if (! is_numeric($id) || ! is_string($row['path'])) {
                continue;
            }

            $byId[(int) $id] = $row['path'];
        }

        return $byId;
    }

    /**
     * Distinct image ids linked (via image_category) to any of
     * $categoryIds -- Admin\BatchManager\FilterResolver's own
     * "categories" prefilter.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- single-table,
     * static WHERE.
     *
     * @param list<int> $categoryIds
     * @return list<int>
     */
    public function findIdsInCategories(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->createQueryBuilder()
                ->select('DISTINCT ic.imageId')
                ->from(ImageCategoryEntity::class, 'ic')
                ->where('ic.categoryId IN (:ids)')
                ->setParameter('ids', $categoryIds, ArrayParameterType::INTEGER)
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Every image id NOT linked (via image_category) to any of
     * $categoryIds -- an empty $categoryIds returns every image,
     * unfiltered. Admin\BatchManager\FilterResolver's own
     * "no_virtual_album" prefilter (paired with
     * CategoryRepository::findIdsByDirNull() above).
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}). Converted to real DQL -- the
     * non-empty branch's `NOT IN (...)` subquery is expressed as a real
     * DQL subquery (DQL supports a parenthesized `SELECT ...` inside a
     * `NOT IN`, same as SQL).
     *
     * @param list<int> $categoryIds
     * @return list<int>
     */
    public function findIdsNotInCategories(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return array_values(array_map(
                static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
                $this->createQueryBuilder('i')
                    ->select('i.id')
                    ->getQuery()
                    ->getSingleColumnResult()
            ));
        }

        $subQuery = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('DISTINCT ic.imageId')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.categoryId IN (:ids)')
            ->getDQL();

        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->createQueryBuilder('i')
                ->select('i.id')
                ->where("i.id NOT IN ({$subQuery})")
                ->setParameter('ids', $categoryIds, ArrayParameterType::INTEGER)
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Ids of every image added on the same day as the most recently
     * added one -- Admin\BatchManager\FilterResolver's own "last_import"
     * prefilter. Returns [] when there are no images at all.
     *
     * Item 14 DQL audit, corrected: the original note claimed
     * `SqlDialect::getRecentPeriodExpression()`'s own `SUBDATE(...,
     * INTERVAL ...)` had no DQL equivalent -- wrong, same corrected
     * `DATE_SUB()` finding as {@see
     * \Piwigo\Comment\CommentRepository::countRecentComments()}.
     * Converted to real DQL -- single-table, no join.
     *
     * @return list<int>
     */
    public function findIdsAddedSameDayAsLatest(): array
    {
        $lastDate = $this->createQueryBuilder('i')
            ->select('MAX(i.dateAvailable)')
            ->getQuery()
            ->getSingleScalarResult();

        if (! is_string($lastDate) || $lastDate === '') {
            return [];
        }

        $ids = $this->createQueryBuilder('i')
            ->select('i.id')
            ->where("i.dateAvailable BETWEEN DATE_SUB(:lastDate, 1, 'day') AND :lastDate")
            ->setParameter('lastDate', $lastDate)
            ->getQuery()
            ->getSingleColumnResult();

        $result = [];
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $result[] = (int) $id;
            }
        }

        return $result;
    }

    /**
     * Ids of every image with no linked tag -- Admin\BatchManager\
     * FilterResolver's own "no_tag" prefilter.
     *
     * Item 14 DQL audit: stays on DBAL -- `image_tag` is entity-mapped
     * ({@see \Piwigo\Tag\ImageTagEntity}), but it's a cross-domain table
     * {@see \Piwigo\Tag\TagRepository} owns, not this repository's own
     * entity; same "cross-domain table stays plain DBAL" boundary this
     * class's own header docblock documents.
     *
     * @return list<int>
     */
    public function findIdsWithNoTag(): array
    {
        $imagesTable = Tables::images();
        $imageTagTable = Tables::imageTag();

        // pgsql support pass: real bug found live -- no ORDER BY, so row
        // order was never guaranteed; MySQL and PostgreSQL return this
        // exact join's row order differently with none specified.
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->getConnection()
                ->fetchFirstColumn(<<<SQL
                    SELECT id FROM {$imagesTable} LEFT JOIN {$imageTagTable} ON id = image_id WHERE tag_id IS NULL ORDER BY id
                    SQL)
        );
    }

    /**
     * Ids of images that share the same value across every column in
     * $fields with at least one other image -- Admin\BatchManager\
     * FilterResolver's own "duplicates" prefilter. GROUP_CONCAT truncates
     * at 1024 chars by default, so a duplicate group larger than ~250 ids
     * silently loses members -- a pre-existing limitation, not introduced
     * here.
     *
     * Item 15 audit: converted to real DQL -- $fields is now
     * {@see ImageDuplicateField}[], each case mapping to a fixed
     * compile-time-safe property path via match(), unblocking the
     * `groupBy()`/`GROUP_CONCAT()` combination Item 14's own audit had
     * left on DBAL (the property-path list itself is now always
     * enum-derived, never a raw caller-supplied column-name list).
     *
     * @param list<ImageDuplicateField> $fields
     * @return list<int>
     */
    public function findIdsGroupedByDuplicateFields(array $fields): array
    {
        if ($fields === []) {
            return [];
        }

        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('GROUP_CONCAT(i.id) AS ids')
            ->from(ImageEntity::class, 'i');

        if (in_array(ImageDuplicateField::Md5sum, $fields, true)) {
            $qb->where('i.md5sum IS NOT NULL');
        }

        $groupByProperties = array_map(
            static fn (ImageDuplicateField $field): string => 'i.' . match ($field) {
                ImageDuplicateField::File => 'file',
                ImageDuplicateField::Md5sum => 'md5sum',
                ImageDuplicateField::DateCreation => 'dateCreation',
                ImageDuplicateField::Width => 'width',
                ImageDuplicateField::Height => 'height',
            },
            $fields
        );

        $qb->groupBy(implode(', ', $groupByProperties))
            ->having('COUNT(i.id) > 1');

        $idLists = $qb->getQuery()
            ->getSingleColumnResult();

        $ids = [];
        foreach ($idLists as $idList) {
            if (! is_string($idList)) {
                continue;
            }
            foreach (explode(',', rtrim($idList, ',')) as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
        }

        return $ids;
    }

    /**
     * Image ids matching already-built $whereClauses (ANDed together; []
     * means unfiltered), in $orderBySql order -- Admin\BatchManager\
     * FilterResolver's own several id-only prefilters (all_photos/level/
     * dimension/filesize). Same "caller composes trusted fragments"
     * contract as findWithConditionsPaginated() above: $whereClauses are
     * trusted SQL boolean expressions, $params are the bound values
     * referenced by any named placeholders inside them.
     *
     * SQL-modernization audit, Item 14 Sub-phase B3 re-investigation:
     * $whereClauses turned out to be a genuinely finite set of shapes at
     * every real caller ({@see \Piwigo\Admin\BatchManager\FilterResolver}'s
     * own all_photos/level/dimension/filesize prefilters), but
     * $orderBySql traces back to {@see \Piwigo\Config\CurrentConfig::
     * orderBy()}/{@see \Piwigo\Config\CurrentConfig::orderByInsideCategory()}
     * -- an admin-configurable raw "ORDER BY ..." string with no bounded
     * shape (not one of a small fixed set the way
     * {@see \Piwigo\Category\CategoryRepository::findActivePermalinksList()}'s
     * own caller-composed ORDER BY was), matching this plan's own
     * Context-section note that a real multi-dialect `CurrentConfig::
     * orderBy()` rewrite is Item 16's territory. DQL requires the whole
     * query expressible in DQL, not just the WHERE half, so this stays on
     * DBAL -- $whereClauses and $orderBySql are caller-composed raw SQL
     * fragments, not DQL property-path expressions.
     *
     * @param list<string> $whereClauses
     * @param array<string, int|float|string> $params
     * @return list<int>
     */
    public function findIdsWithConditions(array $whereClauses, array $params, string $orderBySql): array
    {
        $imagesTable = Tables::images();
        $whereSql = $whereClauses === [] ? '' : 'WHERE ' . implode(' AND ', $whereClauses);

        // pgsql support pass: real bugs found live -- (a) an empty
        // $orderBySql applied no ordering at all, so row order was never
        // guaranteed (MySQL and PostgreSQL disagreed on it); defaults to
        // `ORDER BY id` for a deterministic result. (b) $orderBySql
        // traces back to CurrentConfig::orderBy(), admin-settable raw SQL
        // text that can legitimately be `ORDER BY RAND()` -- same real
        // gap already fixed for CategoryRepository's own raw-DBAL
        // fallback ("function rand() does not exist" against a real
        // Postgres server otherwise).
        $orderBySql = $orderBySql === '' ? 'ORDER BY id' : str_ireplace(
            'RAND()',
            \Piwigo\Db\SqlDialect::randomFunction() . '()',
            $orderBySql
        );

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->getConnection()
                ->executeQuery(
                    <<<SQL
                        SELECT id FROM {$imagesTable} {$whereSql} {$orderBySql}
                        SQL
                    ,
                    $params,
                )->fetchFirstColumn()
        );
    }

    /**
     * Distinct image ids linked to any category in $categoryIdsCsv (an
     * already-built comma-separated category id list, or the literal
     * "-1" sentinel meaning "none"), added on/after $recentPeriodExpr (an
     * already-built SqlDialect date expression) -- Filter\FilterService's
     * own recent-content filter computation. Same "caller composes
     * trusted fragments" contract as findWithConditionsPaginated() above.
     *
     * Item 14 DQL audit, re-corrected: `image_category` is now mapped
     * ({@see ImageCategoryEntity}), but stays on DBAL regardless --
     * $recentPeriodExpr is a caller-composed raw SQL date-arithmetic
     * fragment, spliced directly into the WHERE clause; DQL has no way
     * to embed an already-built trusted SQL fragment like this one.
     *
     * @return list<int>
     */
    public function findIdsVisibleInCategoriesRecentlyAvailable(string $categoryIdsCsv, string $recentPeriodExpr): array
    {
        $imageCategoryTable = Tables::imageCategory();
        $imagesTable = Tables::images();
        $categoryIds = array_map(intval(...), explode(',', $categoryIdsCsv));

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->getConnection()
                ->fetchFirstColumn(
                    <<<SQL
                    SELECT DISTINCT image_id
                    FROM {$imageCategoryTable} INNER JOIN {$imagesTable} ON image_id = id
                    WHERE category_id IN (:categoryIds)
                        AND date_available >= {$recentPeriodExpr}
                    SQL
                    ,
                    [
                        'categoryIds' => $categoryIds,
                    ],
                    [
                        'categoryIds' => ArrayParameterType::INTEGER,
                    ],
                )
        );
    }

    /**
     * Most recent `date_available` among every image -- Admin\
     * PiwigoInfosSender's own "much faster" fallback when no sync-added
     * photo exists (see findAddMethodBreakdown() below). Descending
     * counterpart of findEarliestDateAvailable() above.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, ORDER
     * BY + LIMIT DQL expresses directly; setMaxResults(1) paired with
     * getOneOrNullResult() per the audit's own gotcha #3.
     */
    public function findMostRecentDateAvailable(): ?string
    {
        $row = $this->createQueryBuilder('i')
            ->select('i.dateAvailable AS date_available')
            ->orderBy('i.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        $value = $row['date_available'];

        return is_string($value) ? $value : null;
    }

    /**
     * Number of images with a non-null `storage_category_id` (added via
     * filesystem sync, not the API) -- Admin\PiwigoInfosSender's own
     * "is it worth running the slower sync-vs-api breakdown query" guard.
     */
    /**
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * WHERE.
     */
    public function countWithStorageCategory(): int
    {
        $value = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.storageCategoryId IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Per add-method (sync = filesystem sync, api = everything else)
     * counts and most recent `date_available` -- Admin\PiwigoInfosSender's
     * own "how were most photos added" telemetry breakdown.
     *
     * SQL-modernization audit, Item 14 Sub-phase B5 Tier 2: converted to
     * real DQL -- MySQL's `IF(storage_category_id IS NULL, 'api', 'sync')`
     * has no portable DQL equivalent, and reusing it as the `GROUP BY` key
     * doesn't translate either (DQL can't group by a SELECT alias the way
     * MySQL can, and repeating the full CASE expression in GROUP BY was
     * judged too fragile to trust without a Postgres install to verify
     * against). Fetches `storageCategoryId`/`dateAvailable` per row instead
     * and groups in PHP -- only 2 buckets, and this is an admin-telemetry
     * call, not a hot path, so scanning every image row is an acceptable
     * trade. `date_available` values are ISO `Y-m-d H:i:s` strings, so a
     * plain PHP string comparison reproduces MAX()'s ordering exactly.
     *
     * @return list<array{add_method: string, last_added_on: ?string, nb_files: int}>
     */
    public function findAddMethodBreakdown(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.storageCategoryId AS storage_category_id', 'i.dateAvailable AS date_available')
            ->getQuery()
            ->getArrayResult();

        $byMethod = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $addMethod = ($row['storage_category_id'] ?? null) === null ? 'api' : 'sync';
            $dateAvailable = is_string($row['date_available'] ?? null) ? $row['date_available'] : null;

            if (! isset($byMethod[$addMethod])) {
                $byMethod[$addMethod] = [
                    'add_method' => $addMethod,
                    'last_added_on' => null,
                    'nb_files' => 0,
                ];
            }

            $byMethod[$addMethod]['nb_files']++;

            if ($dateAvailable !== null && ($byMethod[$addMethod]['last_added_on'] === null || $dateAvailable > $byMethod[$addMethod]['last_added_on'])) {
                $byMethod[$addMethod]['last_added_on'] = $dateAvailable;
            }
        }

        return array_values($byMethod);
    }

    /**
     * Per-extension row count and total filesize across every image --
     * Controller\Admin\IntroSubController's own storage chart and Admin\
     * PiwigoInfosSender's own telemetry breakdown, both keyed by ext.
     *
     * SQL-modernization audit, Item 14 Sub-phase B5 Tier 2: converted to
     * real DQL -- MySQL's `SUBSTRING_INDEX(path, ".", -1)` has no portable
     * DQL equivalent, and it was also the `GROUP BY` key (`ext` is a
     * computed alias, not a real column, and DQL can't group by a SELECT
     * alias). Fetches `path`/`filesize` per row instead and groups in PHP
     * -- an admin-telemetry call, not a hot path, so scanning every image
     * row is an acceptable trade. The extension extraction below
     * reproduces `SUBSTRING_INDEX(path, ".", -1)`'s exact semantics
     * (substring after the last `.`, or the whole string when there's no
     * `.` at all) rather than reusing {@see \Piwigo\Core\StringHelper::
     * getExtension()}, which returns `''` for the no-dot case instead --
     * a real behavioral difference from the original SQL, even though no
     * real image path is expected to lack an extension.
     *
     * @return list<array{ext: string, counter: int, filesize: int}>
     */
    public function findExtensionBreakdown(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.path AS path', 'i.filesize AS filesize')
            ->getQuery()
            ->getArrayResult();

        $byExtension = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $path = is_string($row['path'] ?? null) ? $row['path'] : '';
            $dotPosition = strrpos($path, '.');
            $ext = $dotPosition === false ? $path : substr($path, $dotPosition + 1);
            $filesize = is_numeric($row['filesize'] ?? null) ? (int) $row['filesize'] : 0;

            if (! isset($byExtension[$ext])) {
                $byExtension[$ext] = [
                    'ext' => $ext,
                    'counter' => 0,
                    'filesize' => 0,
                ];
            }

            $byExtension[$ext]['counter']++;
            $byExtension[$ext]['filesize'] += $filesize;
        }

        return array_values($byExtension);
    }

    /**
     * Per-extension row count and total filesize across every generated
     * format file -- Controller\Admin\IntroSubController's own storage
     * chart "Formats" bucket. Id-list sibling of findExtensionBreakdown()
     * above, but against `image_format`, not `images`.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table (this
     * repository's own {@see ImageFormatEntity}), GROUP BY on a real
     * column (`ext`, not an alias) -- unlike findExtensionBreakdown()
     * above (SUBSTRING_INDEX()-derived `ext` alias), which stays on
     * DBAL.
     *
     * @return list<array{ext: string, counter: int, filesize: int}>
     */
    public function findFormatExtensionBreakdown(): array
    {
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f.ext AS ext', 'COUNT(f.formatId) AS counter', 'SUM(f.filesize) AS filesize')
            ->from(ImageFormatEntity::class, 'f')
            ->groupBy('f.ext')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = [
                'ext' => is_string($row['ext']) ? $row['ext'] : '',
                'counter' => is_numeric($row['counter']) ? (int) $row['counter'] : 0,
                'filesize' => is_numeric($row['filesize']) ? (int) $row['filesize'] : 0,
            ];
        }

        return $result;
    }
}

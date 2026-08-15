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
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\Md5Sum;
use Piwigo\Common\ValueObject\Permalink;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Config\ConfigEntry;
use Piwigo\Core\Env;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\SqlDialect;
use Piwigo\Image\Projection\AddMethodBreakdown;
use Piwigo\Image\Projection\ExtensionBreakdown;
use Piwigo\Image\Projection\FormatCountSum;
use Piwigo\Image\Projection\Image;
use Piwigo\Image\Projection\ImageCategoryLink;
use Piwigo\Image\Projection\ImageFormat;
use Piwigo\Image\Projection\ImageIdExt;
use Piwigo\Image\Projection\ImageIdFile;
use Piwigo\Image\Projection\ImageLookupRow;
use Piwigo\Image\Projection\MissingDerivativeRow;
use Piwigo\Image\Projection\MostRecentCategoryInfo;
use Piwigo\Image\Projection\NextIdCount;
use Piwigo\Image\Projection\PathRepresentativeExt;
use Piwigo\Image\Projection\PathRepresentativeExtLevel;
use Piwigo\Image\Projection\UploadInfo;
use Piwigo\Image\Projection\UploadResultInfo;
use Piwigo\Permission\PermissionCriteria;
use Piwigo\Permission\SqlCondition;

/**
 * Persistence layer for the image domain's own data-touching function from
 * `include/functions_picture.inc.php` -- the other 5 ported functions
 * (slideshow param encode/decode, PDF page counting) are pure computation
 * with no DB access of their own and live on {@see ImageService} instead,
 * same Repository=DB-only/Service=business-logic split every domain in
 * this codebase follows.
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
 * plain upsert): tryAcquireLoungeLock() needs real atomic INSERT-IGNORE
 * claim semantics no find()+persist()+flush() sequence can provide (a
 * race would let two processes both believe they won the lock), so it
 * keeps the raw statement.
 *
 * @extends EntityRepository<ImageEntity>
 */
final class ImageRepository extends EntityRepository
{
    /**
     * Applies a permission/filter `SqlCondition` via `andWhere()`, binding
     * every one of its parameters -- same shared-helper shape as
     * `Notification\NotificationRepository::applyCondition()`/
     * `Tag\TagRepository::applyCondition()`. Every real caller
     * (isImageAccessibleWithCondition/findRowWithCondition/
     * findRelatedCategoriesForImage/isImageCommentableWithCondition/
     * findVisibleCategoriesForImage/hasAccessibleImageWithAuthor/
     * isImageAccessibleViaCategoryWithCondition/
     * findCategoryLinksForImageIdsWithCondition) takes a typed
     * {@see \Piwigo\Permission\PermissionCriteria} DTO and translates it
     * to a bound fragment via that DTO's own `*Condition()` builders
     * before reaching this shared applier.
     *
     * Accepts either query-builder flavor -- {@see SqlCondition}'s own
     * `sql`/`parameters`/`types` shape applies identically via
     * `andWhere()`/`setParameter()` on both DBAL's and DQL's query
     * builders: a DQL consumer passes a DQL property path (e.g. `i.id`)
     * into the same {@see PermissionCriteria} `*Condition()` methods a
     * DBAL consumer uses with a raw column name.
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
     * Deliberately avoids bumping `lastmodified` -- an image's "last
     * modified" timestamp should reflect real edits, not visit counting.
     * A DQL bulk `UPDATE` that never mentions `lastmodified` at all
     * leaves it untouched (no schema-level auto-bump exists anymore, see
     * `Piwigo\Db\LastModifiedListener`'s own docblock), and bypasses the
     * ORM entirely, so clears the identity map afterward since this
     * bypasses the ORM for a row {@see ImageEntity} may already have
     * cached.
     */
    public function incrementVisitCounter(ImageId $imageId): void
    {
        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->update(ImageEntity::class, 'i')
            ->set('i.hit', 'i.hit + 1')
            ->where('i.id = :id')
            ->setParameter('id', $imageId->value)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * Sets (or clears, when $coi is null) an image's crop-of-interest
     * 4-character code (admin/picture_coi.php, the only caller).
     */
    public function updateCoi(ImageId $imageId, ?string $coi): void
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
     * actually supplied -- Ws\Images::addChunk()'s own "apply the
     * caller-supplied upload metadata fields, sparse" step. A null
     * parameter means "not supplied", not "clear this field" -- these 4
     * fields are never intentionally nulled through this path.
     */
    public function updateDescriptiveFields(
        ImageId $imageId,
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
            $entity->dateCreation = SqlDateTime::from($dateCreation);
        }

        $this->getEntityManager()
            ->flush();
    }

    /**
     * @param array<int, int|string> $imageIds
     * @return list<ImageIdExt>
     */
    public function findFormatsByImageIds(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        // f.imageId is the custom `image_id` Doctrine Type -- a partial
        // DQL select of a custom-typed field still hydrates through that
        // Type, so it comes back as ImageId here, not int. Unwrapped
        // below to keep this method's own documented int contract.
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f.imageId AS image_id', 'f.ext AS ext')
            ->from(ImageFormatEntity::class, 'f')
            ->where('f.imageId IN (:imageIds)')
            ->setParameter('imageIds', $imageIds)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): ImageIdExt => new ImageIdExt(
                $row['image_id'] instanceof ImageId ? $row['image_id']->value : (is_numeric($row['image_id']) ? (int) $row['image_id'] : 0),
                $row['ext'],
            ),
            $rows
        );
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
    public function insertFormat(ImageId $imageId, string $ext, ?int $filesize): int
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
            $em->persist(new ImageFormatEntity(ImageId::from($insert['image_id']), $insert['ext'], $insert['filesize']));
        }

        $em->flush();
    }

    /**
     * image_id/ext for format rows matching $formatIds (their own primary
     * key, `format_id`, not `image_id`) -- Ws\Images::formatsDelete()'s
     * own "which images/extensions are these formats for" lookup, before
     * deleting them via deleteFormatsByIds() below.
     *
     * @param list<int> $formatIds
     * @return list<ImageIdExt>
     */
    public function findImageIdsAndExtsByFormatIds(array $formatIds): array
    {
        if ($formatIds === []) {
            return [];
        }

        // f.imageId is the custom `image_id` Doctrine Type -- a partial
        // DQL select of a custom-typed field still hydrates through that
        // Type (same gotcha as scalar enum selects), so it comes back as
        // ImageId here, not int. Unwrapped below to keep this method's
        // own documented int contract for its WS-layer consumer.
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f.imageId AS image_id', 'f.ext AS ext')
            ->from(ImageFormatEntity::class, 'f')
            ->where('f.formatId IN (:formatIds)')
            ->setParameter('formatIds', $formatIds)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): ImageIdExt => new ImageIdExt(
                $row['image_id'] instanceof ImageId ? $row['image_id']->value : (is_numeric($row['image_id']) ? (int) $row['image_id'] : 0),
                $row['ext'],
            ),
            $rows
        );
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
    public function findFormatsForImage(ImageId $imageId): array
    {
        $entities = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f')
            ->from(ImageFormatEntity::class, 'f')
            ->where('f.imageId = :imageId')
            ->setParameter('imageId', $imageId->value)
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
     * @param array<int, int|string> $imageIds
     * @return list<PathRepresentativeExt>
     */
    public function findPathsForFileDeletion(array $imageIds): array
    {
        // i.id is the custom `image_id` Doctrine Type -- a partial DQL
        // select of a custom-typed field still hydrates through that
        // Type, so it comes back as ImageId here, not int. Unwrapped
        // below to keep this method's own documented contract (id/path
        // feed real file-deletion logic, so silently defaulting to 0/''
        // on a narrowing miss would be a real data-loss risk, not just a
        // lint nitpick).
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

            $result[] = new PathRepresentativeExt(
                id: $row['id'] instanceof ImageId ? $row['id']->value : (is_numeric($row['id']) ? (int) $row['id'] : 0),
                path: is_string($row['path']) ? $row['path'] : '',
                representativeExt: is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
            );
        }

        return $result;
    }

    /**
     * Same 3 columns as {@see findPathsForFileDeletion()}, plus `level` --
     * Ws\Categories::getList()'s own "does the viewer's privacy level
     * allow this thumbnail" check.
     *
     * @param  list<int>  $imageIds
     * @return list<PathRepresentativeExtLevel>
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

            $result[] = new PathRepresentativeExtLevel(
                id: $row['id'] instanceof ImageId ? $row['id']->value : (is_numeric($row['id']) ? (int) $row['id'] : 0),
                path: is_string($row['path']) ? $row['path'] : '',
                representativeExt: is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
                level: is_numeric($row['level'] ?? null) ? (int) $row['level'] : 0,
            );
        }

        return $result;
    }

    /**
     * Bulk-sets `level` for a batch of image ids -- Ws\Images::
     * setPrivacyLevel()'s own WS write. Caller clears the EntityManager
     * afterward (same "caller clears" convention documented elsewhere,
     * e.g. CategoryService::setRepresentativeImage()) since this bypasses
     * the ORM -- a DQL bulk `UPDATE` doesn't touch the identity map
     * either.
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
     * @param array{primary: string[], update: string[]} $dbfields
     * @param array<int, array<string, mixed>> $datas
     */
    public function massUpdateFields(array $dbfields, array $datas, int $flags = 0): void
    {
        if ($datas === []) {
            return;
        }

        $now = Env::now()->format('Y-m-d H:i:s');
        $dbfields['update'][] = 'lastmodified';
        new BatchWriter($this->getEntityManager()->getConnection())
            ->massUpdate('images', $dbfields, array_map(static fn (array $data): array => [
                ...$data,
                'lastmodified' => $now,
            ], $datas), $flags);
    }

    /**
     * Applies a dynamic subset of `images` scalar fields, raw column names
     * as caller-supplied keys -- Ws\Images::setInfo()'s own
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
     * @param array<string, mixed> $updates
     */
    public function updateFields(ImageId $imageId, array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $updates['lastmodified'] = Env::now()->format('Y-m-d H:i:s');
        new BatchWriter($this->getEntityManager()->getConnection())
            ->singleUpdate('images', $updates, [
                'id' => $imageId->value,
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
     * @param array<string, mixed> $insert
     */
    public function insertImage(array $insert): int
    {
        $em = $this->getEntityManager();

        new BatchWriter($em->getConnection())
            ->singleInsert('images', $insert);

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
     * @param array<int, array<string, mixed>> $inserts
     */
    public function massInsertImages(array $inserts): void
    {
        if ($inserts === []) {
            return;
        }

        new BatchWriter($this->getEntityManager()->getConnection())
            ->massInsert('images', array_keys($inserts[0]), $inserts);
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
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * Category ids for which one of $ids is the representative picture.
     *
     * `categories` is a cross-repository-owned table, but `Category` and
     * `Image` are the same `L2aCoreDomain` deptrac layer, so querying it
     * directly here is architecturally legal per deptrac.yaml's ruleset.
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
     * @return list<ImageCategoryLink>
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
            static fn (LoungeEntity $l): ImageCategoryLink => new ImageCategoryLink($l->imageId->value, $l->categoryId->value),
            $entities
        );
    }

    /**
     * `date_available` for the oldest photo still in the lounge --
     * LoungeMaintenance::needsEmptying()'s own "is the oldest lounge photo
     * older than the max wait time" check. Returns null when the lounge is
     * empty.
     *
     * `date_available` is always written via Env::now(), so the caller
     * must compute age against Env::now() too, not PHP's real wall-clock
     * time or the DB server's own NOW() -- both are invisible to
     * Env::now()'s PIWIGO_TEST_NOW freeze and would otherwise drift away
     * from a frozen test clock.
     */
    public function findOldestLoungeAgeInfo(): ?string
    {
        $row = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('i.dateAvailable AS date_available')
            ->from(LoungeEntity::class, 'l')
            ->innerJoin(ImageEntity::class, 'i', Join::WITH, 'l.imageId = i.id')
            ->orderBy('l.imageId', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        $dateAvailable = $row['date_available'];

        return $dateAvailable instanceof SqlDateTime ? $dateAvailable->value : null;
    }

    /**
     * Number of lounge rows for $categoryId not yet linked into
     * `image_category` -- Ws\Images::upload()'s own "how many photos
     * are still awaiting validation in this category" response field.
     */
    public function countLoungeImagesPendingForCategory(CategoryId $categoryId): int
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
            ->setParameter('categoryId', $categoryId)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

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
     * $lockValue so it round-trips through CurrentConfig::emptyLoungeRunning()'s
     * own ConfigService::hydrate() read path too, not just
     * findLoungeLockValue() below -- a bare unquoted value would also
     * break this INSERT's own double-quote-delimited SQL literal.
     *
     * Stays raw DBAL rather than delegating to Config\ConfigRepository::
     * upsert() -- that method always finds-then-writes, which can't
     * reproduce this atomicity (two concurrent emptyLounge() runs could
     * otherwise both believe they'd won the lock). DQL has no INSERT
     * support at all besides, and `config` is a cross-domain table
     * {@see \Piwigo\Config\ConfigRepository} owns. Clears the identity
     * map afterward since this bypasses the ORM for a row
     * Config\ConfigEntry may already have cached.
     *
     * Uses `Connection::insert()` (a plain, portable `INSERT`), not
     * MySQL-specific `INSERT IGNORE` text, and not `persist()`/`flush()`
     * either: a caught {@see UniqueConstraintViolationException} from a
     * failed `flush()` leaves the EntityManager permanently closed
     * (`Doctrine\ORM\UnitOfWork::commit()`'s own `finally` branch calls
     * `$em->close()` on any failure, and `clear()` cannot undo that),
     * which would break every other repository sharing this request's
     * EntityManager. Plain DBAL `insert()` never touches the ORM's unit
     * of work, so a caught failure here has no such blast radius.
     */
    public function tryAcquireLoungeLock(string $lockValue): void
    {
        $encodedLockValue = json_encode($lockValue);
        assert($encodedLockValue !== false);

        $em = $this->getEntityManager();
        try {
            $em->getConnection()
                ->insert('config', [
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
     * `config` is a cross-repository-owned table ({@see \Piwigo\Config\
     * ConfigEntry}), but `Config` is `L1Infrastructure` and `Image` is
     * `L2aCoreDomain`, an explicitly allowed downward dependency
     * (`L2aCoreDomain: [L1Infrastructure, L0Data]`), so querying it
     * directly here is architecturally legal. {@see tryAcquireLoungeLock()}
     * itself stays on DBAL (`INSERT IGNORE`, no DQL equivalent).
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
     * The selected `ic.categoryId` hydrates as a real {@see CategoryId}
     * under array hydration, so it's unwrapped below rather than treated
     * as a raw int.
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
            if (! $categoryId instanceof CategoryId || ! $imageId instanceof ImageId) {
                continue;
            }

            $existing[$categoryId->value][] = $imageId->value;
        }

        return $existing;
    }

    /**
     * The selected `ic.categoryId` hydrates as a real {@see CategoryId}
     * under array hydration, so it's unwrapped below rather than treated
     * as a raw int.
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
     * The query already guarantees numeric ids, so no `is_string()`
     * filtering is applied here.
     *
     * @param array<int, int|string> $images
     * @return list<int>
     */
    public function findDissociableImageIds(array $images, int|string $category): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => $v instanceof ImageId ? $v->value : (is_numeric($v) ? (int) $v : 0),
            $this->getEntityManager()
                ->createQueryBuilder()
                ->select('i.id')
                ->from(ImageCategoryEntity::class, 'ic')
                ->innerJoin(ImageEntity::class, 'i', Join::WITH, 'ic.imageId = i.id')
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
     * set of $categoryIds -- Ws\Images::addImageCategoryRelations()'s
     * own replace-mode cleanup of associations no longer present in the
     * caller's requested category list. Unlike deleteImageCategoryLinks()
     * above (many images, one category), this is one image, many
     * categories.
     *
     * @param list<int|string> $categoryIds
     */
    public function deleteImageCategoryLinksForCategoryIds(ImageId $imageId, array $categoryIds): void
    {
        if ($categoryIds === []) {
            return;
        }

        $this->getEntityManager()
            ->createQueryBuilder()
            ->delete(ImageCategoryEntity::class, 'ic')
            ->where('ic.imageId = :imageId')
            ->andWhere('ic.categoryId IN (:categoryIds)')
            ->setParameter('imageId', $imageId)
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
     * Implemented as a 2-step SELECT-then-DELETE since DQL's DELETE
     * statement doesn't support joins: step 1 reads each image's own
     * `storage_category_id` (a column on `images`, not `image_category`)
     * to know which single link to spare; step 2 issues one single-table
     * DQL DELETE per image with that value bound as a plain scalar
     * parameter, same shape as {@see deleteImageCategoryLinks()} just
     * above. $images is typically a handful of ids per real call (a
     * single move/associate action), so N small DELETEs costs nothing
     * meaningful over one multi-row statement.
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
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof ImageId) {
                continue;
            }

            $qb = $em->createQueryBuilder()
                ->delete(ImageCategoryEntity::class, 'ic')
                ->where('ic.imageId = :imageId')
                ->setParameter('imageId', $row['id']->value, ParameterType::INTEGER);

            if ($categories !== []) {
                $qb->andWhere('ic.categoryId NOT IN (:categories)')
                    ->setParameter('categories', array_map(strval(...), $categories), ArrayParameterType::STRING);
            }

            // storage_category_id IS NULL -- every link for this image is
            // non-storage, no extra exclusion needed (matches the
            // original's own `storage_category_id IS NULL OR ...` half).
            if (($row['storageCategoryId'] ?? null) instanceof CategoryId) {
                $qb->andWhere('ic.categoryId != :storageCategoryId')
                    ->setParameter('storageCategoryId', $row['storageCategoryId']->value, ParameterType::INTEGER);
            }

            $qb->getQuery()
                ->execute();
        }

        $em->clear();
    }

    /**
     * @return list<int>
     */
    public function findImageIdsWithoutMd5sum(): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => $v instanceof ImageId ? $v->value : (is_numeric($v) ? (int) $v : 0),
            $this->createQueryBuilder('i')
                ->select('i.id')
                ->where('i.md5sum IS NULL')
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
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
            if (! is_array($row) || ! ($row['id'] ?? null) instanceof ImageId) {
                continue;
            }

            $paths[$row['id']->value] = is_scalar($row['path']) ? (string) $row['path'] : '';
        }

        return $paths;
    }

    /**
     * path/file/md5sum/width/height/filesize for $imageId -- Ws\Images::
     * addFile()'s own "what's the current state of this image, before we
     * merge in a bigger chunked upload" lookup.
     *
     * setMaxResults(1) is paired with getOneOrNullResult() even though the
     * WHERE is on the primary key -- getOneOrNullResult() throws if more
     * than one row matches.
     */
    public function findUploadInfoById(ImageId $imageId): ?UploadInfo
    {
        $row = $this->createQueryBuilder('i')
            ->select('i.path', 'i.file', 'i.md5sum', 'i.width', 'i.height', 'i.filesize')
            ->where('i.id = :imageId')
            ->setParameter('imageId', $imageId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        return new UploadInfo(
            path: is_string($row['path']) ? $row['path'] : '',
            file: is_string($row['file']) ? $row['file'] : '',
            md5sum: ($row['md5sum'] ?? null) instanceof Md5Sum ? $row['md5sum']->value : (is_string($row['md5sum'] ?? null) ? $row['md5sum'] : null),
            width: is_numeric($row['width'] ?? null) ? (int) $row['width'] : null,
            height: is_numeric($row['height'] ?? null) ? (int) $row['height'] : null,
            filesize: is_numeric($row['filesize'] ?? null) ? (int) $row['filesize'] : null,
        );
    }

    /**
     * Whether at least one image has $value in $column -- Ws\Images::
     * add()'s own upload-time uniqueness check ($column is one of
     * `md5sum`/`file`, selected from CurrentConfig::uniquenessMode(),
     * never caller-controlled; $value is always raw, untrusted client
     * input and must be bound as a parameter, never spliced into SQL).
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
     */
    public function countAndSumFormats(): FormatCountSum
    {
        $row = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(f.formatId) AS cnt', 'SUM(f.filesize) AS total')
            ->from(ImageFormatEntity::class, 'f')
            ->getQuery()
            ->getSingleResult();

        if (! is_array($row)) {
            return new FormatCountSum(0, 0);
        }

        return new FormatCountSum(
            count: is_numeric($row['cnt'] ?? null) ? (int) $row['cnt'] : 0,
            sum: is_numeric($row['total'] ?? null) ? (int) $row['total'] : 0,
        );
    }

    /**
     * Every image's id/file (unfiltered) -- Ws\Images::
     * formatsSearchImage()'s own "build a filename-without-extension index
     * of every photo" scan.
     *
     * @return list<ImageIdFile>
     */
    public function findAllIdsAndFiles(): array
    {
        $result = [];
        foreach ($this->createQueryBuilder('i')->select('i.id', 'i.file')->getQuery()->getArrayResult() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = new ImageIdFile(
                id: $row['id'] instanceof ImageId ? $row['id']->value : (is_numeric($row['id']) ? (int) $row['id'] : 0),
                file: is_string($row['file']) ? $row['file'] : '',
            );
        }

        return $result;
    }

    /**
     * Every image_format row's image_id/ext (unfiltered) -- Ws\Images::
     * formatsSearchImage()'s own "which formats already exist per image"
     * scan.
     *
     * @return list<ImageIdExt>
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

            $result[] = new ImageIdExt(
                imageId: $row['image_id'] instanceof ImageId ? $row['image_id']->value : (is_numeric($row['image_id']) ? (int) $row['image_id'] : 0),
                ext: is_string($row['ext']) ? $row['ext'] : '',
            );
        }

        return $result;
    }

    /**
     * Earliest `date_available` among every image -- Admin\
     * InstallationStats::getInstallationDate()'s own last-resort
     * installation-date candidate.
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

        return $dateAvailable instanceof SqlDateTime ? $dateAvailable->value : null;
    }

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
     * above) -- Ws\Core::getInfos()'s own "nb_image_category" summary
     * figure.
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
     * Earliest `date_available` across every image -- Ws\Core::
     * getInfos()'s own "first_date" summary figure, a different query
     * from {@see findEarliestDateAvailable()} above (that one is the
     * first-inserted image's own date, by id; this one is the minimum
     * date value regardless of which image it belongs to).
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
     * Ws\Core::getMissingDerivatives()'s own pagination-cursor
     * bootstrap (same MAX(id)+1 shape as {@see findNextId()} above, plus
     * COUNT(*) for the "nothing to do" early exit).
     */
    public function findNextIdAndCount(): NextIdCount
    {
        $row = $this->createQueryBuilder('i')
            ->select('MAX(i.id) + 1 AS nextId', 'COUNT(i.id) AS cnt')
            ->getQuery()
            ->getSingleResult();

        if (! is_array($row)) {
            return new NextIdCount(0, 0);
        }

        return new NextIdCount(
            nextId: is_numeric($row['nextId'] ?? null) ? (int) $row['nextId'] : 0,
            count: is_numeric($row['cnt']) ? (int) $row['cnt'] : 0,
        );
    }

    /**
     * One page of images with id below $startId, matching $criteria --
     * Ws\Core::getMissingDerivatives()'s own cursor-paginated scan, one
     * real caller. $criteria->filterCriteria is a typed
     * {@see \Piwigo\Image\ImageFilterCriteria}, applied here via
     * {@see applyImageFilterCriteria()} against this method's own `i`
     * alias.
     *
     * @return list<MissingDerivativeRow>
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
                $result[] = new MissingDerivativeRow(
                    id: ($row['id'] ?? null) instanceof ImageId ? $row['id']->value : (is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0),
                    path: is_string($row['path'] ?? null) ? $row['path'] : null,
                    representativeExt: is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
                    width: is_numeric($row['width'] ?? null) ? (int) $row['width'] : null,
                    height: is_numeric($row['height'] ?? null) ? (int) $row['height'] : null,
                    rotation: is_numeric($row['rotation'] ?? null) ? (int) $row['rotation'] : null,
                );
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
        // width/height are plain integer columns, and while MySQL's `/`
        // operator always computes in decimal context, PostgreSQL's `/`
        // on two integer operands truncates to an integer (same fix
        // applied in SearchService's and FilterResolver's own ratio
        // filters -- see SearchService's own docblock for a worked
        // 200/150 example). `width * 1.0` forces decimal-context
        // arithmetic on both platforms without needing a DQL CAST.
        // NULLIF(height, 0) additionally guards a genuinely zero height
        // (a real, if degenerate, row) -- MySQL's `/` silently returns
        // NULL for a zero divisor, but Postgres raises a "division by
        // zero" error instead, same as SearchService's own identical
        // ratio-bucket clause.
        if ($criteria->minRatio !== null) {
            $qb->andWhere($alias . '.width * 1.0 / NULLIF(' . $alias . '.height, 0) >= :filterMinRatio')
                ->setParameter('filterMinRatio', $criteria->minRatio);
        }
        if ($criteria->maxRatio !== null) {
            $qb->andWhere($alias . '.width * 1.0 / NULLIF(' . $alias . '.height, 0) <= :filterMaxRatio')
                ->setParameter('filterMaxRatio', $criteria->maxRatio);
        }
        if ($criteria->maxLevel !== null) {
            $qb->andWhere($alias . '.level <= :filterMaxLevel')
                ->setParameter('filterMaxLevel', $criteria->maxLevel);
        }
    }

    /**
     * id/label(computed)/filesize/file/path/representative_ext for
     * $imageIds -- Ws\Core::historySearch()'s own thumbnail/label
     * enrichment step, keyed by id. `label` is never null: COALESCE()
     * only falls back to `file`, itself a non-nullable column.
     * `filesize`/`representative_ext` are nullable; `file`/`path` are not
     * (see ImageEntity's own property types).
     *
     * @param  list<int|string>  $imageIds
     * @return array<int, array{id: int, label: string, filesize: ?int, file: string, path: string, representative_ext: ?string}>
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
            $id = is_array($row) ? ($row['id'] ?? null) : null;
            $label = is_array($row) ? ($row['label'] ?? null) : null;
            $file = is_array($row) ? ($row['file'] ?? null) : null;
            $path = is_array($row) ? ($row['path'] ?? null) : null;
            if (! $id instanceof ImageId || ! is_string($label) || ! is_string($file) || ! is_string($path)) {
                continue;
            }

            $filesize = $row['filesize'] ?? null;
            $representativeExt = $row['representative_ext'] ?? null;

            $byId[$id->value] = [
                'id' => $id->value,
                'label' => $label,
                'filesize' => is_int($filesize) ? $filesize : null,
                'file' => $file,
                'path' => $path,
                'representative_ext' => is_string($representativeExt) ? $representativeExt : null,
            ];
        }

        return $byId;
    }

    /**
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
     * @param list<int> $loungedIds
     * @return list<int>
     */
    public function findOrphanImageIds(array $loungedIds): array
    {
        $qb = $this->createQueryBuilder('i')
            ->select('i.id')
            ->leftJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'i.id = ic.imageId')
            ->where('ic.categoryId IS NULL')
            ->orderBy('i.id', 'ASC');

        if (count($loungedIds) > 0) {
            $qb->andWhere('i.id NOT IN (:loungedIds)')
                ->setParameter('loungedIds', $loungedIds, ArrayParameterType::INTEGER);
        }

        return array_values(array_map(static fn (mixed $v): int => $v instanceof ImageId ? $v->value : (is_numeric($v) ? (int) $v : 0), $qb->getQuery()->getSingleColumnResult()));
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

    public function findById(ImageId $imageId): ?Image
    {
        $entity = $this->find($imageId);

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

    public function updateRotation(ImageId $imageId, int $rotationCode): void
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
     * Fetches full {@see ImageEntity} objects and reuses
     * {@see \Piwigo\Image\Projection\Image::fromEntity()} rather than
     * fromRow(). `i.id` uses the custom `image_id` Doctrine Type --
     * still a direct swap, `setParameter()`/`IN (:ids)` bind against the
     * raw scalar column regardless of the mapped PHP-side type.
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
                $byId[$entity->id->value] = Image::fromEntity($entity);
            }
        }

        return $byId;
    }

    /**
     * Bulk multi-row INSERT via BatchWriter, not ORM persist()/flush().
     *
     * @param  list<array{image_id: int|string, category_id: int|string}>  $inserts
     */
    public function massInsertLounge(array $inserts): void
    {
        if ($inserts === []) {
            return;
        }

        new BatchWriter($this->getEntityManager()->getConnection())
            ->massInsert('lounge', array_keys($inserts[0]), $inserts, [
                'ignore' => true,
            ]);
    }

    /**
     * $rank is optional per row -- Controller\Admin\SiteUpdateSubController's
     * own filesystem-sync insert omits it entirely (leaves it to the
     * schema's own DEFAULT), unlike ImageService::associateImagesToCategories()'s
     * own caller which always supplies it. Bulk multi-row INSERT via
     * BatchWriter, not ORM persist()/flush().
     *
     * @param  list<array{image_id: int|string, category_id: int|string, rank?: int|string}>  $inserts
     */
    public function massInsertImageCategory(array $inserts): void
    {
        if ($inserts === []) {
            return;
        }

        new BatchWriter($this->getEntityManager()->getConnection())
            ->massInsert('image_category', array_keys($inserts[0]), $inserts);
        $this->getEntityManager()
            ->clear();
    }

    /**
     * @param  list<array{id: int|string, md5sum: string}>  $updates
     */
    public function massUpdateMd5sums(array $updates): void
    {
        $now = Env::now()->format('Y-m-d H:i:s');
        new BatchWriter($this->getEntityManager()->getConnection())
            ->massUpdate(
                'images',
                [
                    'primary' => ['id'],
                    'update' => ['md5sum', 'lastmodified'],
                ],
                array_map(static fn (array $data): array => [
                    ...$data,
                    'lastmodified' => $now,
                ], $updates)
            );
        $this->getEntityManager()
            ->clear();
    }

    public function updateDimensions(int $imageId, int $width, int $height): void
    {
        $imageIdVo = ImageId::tryFrom($imageId);
        $entity = $imageIdVo instanceof ImageId ? $this->find($imageIdVo) : null;
        if ($entity === null) {
            return;
        }

        $entity->width = $width;
        $entity->height = $height;
        $this->getEntityManager()
            ->flush();
    }

    /**
     * Bulk per-row UPDATE via BatchWriter, not ORM persist()/flush().
     *
     * @param  list<array{category_id: int|string, image_id: int|string, rank: int}>  $datas
     */
    public function massUpdateImageCategoryRanks(array $datas): void
    {
        new BatchWriter($this->getEntityManager()->getConnection())
            ->massUpdate(
                'image_category',
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
     * The selected `ic.categoryId` hydrates as a real {@see CategoryId}
     * under array hydration, so it's unwrapped below rather than treated
     * as a raw int.
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
            ->innerJoin(ImageEntity::class, 'i', Join::WITH, 'i.id = ic.imageId')
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
     * @return list<array<string, mixed>>
     */
    public function findThumbnailRowsForCategoryOrderedByRank(CategoryId $categoryId): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.id', 'i.file', 'i.path', 'i.representativeExt AS representative_ext', 'i.width', 'i.height', 'i.rotation', 'i.name', 'ic.rank')
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'ic.imageId = i.id')
            ->where('ic.categoryId = :categoryId')
            ->orderBy('ic.rank')
            ->setParameter('categoryId', $categoryId)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = [
                    'id' => ($row['id'] ?? null) instanceof ImageId ? $row['id']->value : ($row['id'] ?? null),
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
     * Ws\Images::setRank()'s own multi-image "return the new order"
     * response.
     *
     * @return list<int|string>
     */
    public function findImageIdsOrderedByRankForCategory(CategoryId $categoryId): array
    {
        return array_values(array_filter(
            $this->getEntityManager()
                ->createQueryBuilder()
                ->select('ic.imageId')
                ->from(ImageCategoryEntity::class, 'ic')
                ->where('ic.categoryId = :categoryId')
                ->orderBy('ic.rank', 'ASC')
                ->setParameter('categoryId', $categoryId)
                ->getQuery()
                ->getSingleColumnResult(),
            static fn (mixed $v): bool => is_int($v) || is_string($v)
        ));
    }

    /**
     * Whether $imageId is associated to $categoryId -- Ws\Images::
     * setRank()'s own "is this image even in that category" guard.
     */
    public function isImageInCategory(ImageId $imageId, CategoryId $categoryId): bool
    {
        $value = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(ic.imageId)')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.imageId = :imageId')
            ->andWhere('ic.categoryId = :categoryId')
            ->setParameter('imageId', $imageId)
            ->setParameter('categoryId', $categoryId)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * Current highest `rank` for one category (singular -- unlike
     * findMaxRanksByCategory() above, which takes a batch) --
     * Ws\Images::setRank()'s own "what's the current max rank" lookup.
     * Returns null when no image in this category has a rank set yet.
     *
     * A bare aggregate with no GROUP BY always returns exactly one row
     * (NULL when nothing matches), so getSingleScalarResult() never
     * throws here.
     */
    public function findMaxRankForCategory(CategoryId $categoryId): ?int
    {
        $maxRank = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('MAX(ic.rank)')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.categoryId = :categoryId')
            ->setParameter('categoryId', $categoryId)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($maxRank) ? (int) $maxRank : null;
    }

    /**
     * Bumps `rank` by 1 for every image in $categoryId whose rank is >=
     * $rank -- Ws\Images::setRank()'s own "make room" step before
     * inserting a new rank value.
     */
    public function incrementRanksFromForCategory(CategoryId $categoryId, int $rank): void
    {
        $this->getEntityManager()
            ->createQueryBuilder()
            ->update(ImageCategoryEntity::class, 'ic')
            ->set('ic.rank', 'ic.rank + 1')
            ->where('ic.categoryId = :categoryId')
            ->andWhere('ic.rank IS NOT NULL')
            ->andWhere('ic.rank >= :rank')
            ->setParameter('categoryId', $categoryId)
            ->setParameter('rank', $rank, ParameterType::INTEGER)
            ->getQuery()
            ->execute();
    }

    /**
     * Sets `rank` for one (imageId, categoryId) image_category row --
     * Ws\Images::setRank()'s own final write.
     */
    public function updateRankForImageInCategory(ImageId $imageId, CategoryId $categoryId, int $rank): void
    {
        $this->getEntityManager()
            ->createQueryBuilder()
            ->update(ImageCategoryEntity::class, 'ic')
            ->set('ic.rank', ':rank')
            ->where('ic.imageId = :imageId')
            ->andWhere('ic.categoryId = :categoryId')
            ->setParameter('rank', $rank, ParameterType::INTEGER)
            ->setParameter('imageId', $imageId)
            ->setParameter('categoryId', $categoryId)
            ->getQuery()
            ->execute();
    }

    /**
     * The category (id + uppercats) the most recently added image was
     * placed into -- Admin\PhotosAddDirectPageRenderer's own "default the
     * upload form to whichever album the last photo went into" lookup.
     *
     * The selected `ic.categoryId` hydrates as a real {@see CategoryId}
     * under array hydration.
     */
    public function findMostRecentImageCategoryInfo(): ?MostRecentCategoryInfo
    {
        $row = $this->createQueryBuilder('i')
            ->select('ic.categoryId', 'c.uppercats')
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'ic.imageId = i.id')
            ->innerJoin(CategoryEntity::class, 'c', Join::WITH, 'ic.categoryId = c.id')
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

        return new MostRecentCategoryInfo($categoryId->value, $uppercats);
    }

    /**
     * Every distinct (width, height) pair among images that have both set
     * -- Controller\Admin\BatchManagerSubController's own dimension-filter
     * option aggregation.
     *
     * @return list<array{width: int, height: int}> -- the WHERE clause
     *   below guarantees both are non-null (ImageEntity::$width/$height are
     *   otherwise nullable columns)
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
            if (is_array($row) && is_int($row['width'] ?? null) && is_int($row['height'] ?? null)) {
                $result[] = [
                    'width' => $row['width'],
                    'height' => $row['height'],
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
     * @return list<array{filesize: int}> -- the WHERE clause below
     *   guarantees non-null (ImageEntity::$filesize is otherwise a
     *   nullable column)
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
            if (is_array($row) && is_int($row['filesize'] ?? null)) {
                $result[] = [
                    'filesize' => $row['filesize'],
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
     * Stays on DBAL -- $orderBySql is a caller-composed raw SQL fragment
     * spliced directly into the query, which DQL has no way to embed.
     *
     * @param array<array-key, int|string|float|bool> $imageIds
     * @return list<array<string, mixed>>
     */
    public function findBatchManagerThumbnails(array $imageIds, ?int $categoryId, string $orderBySql, int $limit, int $offset): array
    {
        $imagesTable = 'images';

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
            $imageCategoryTable = 'image_category';
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
     * @param array<array-key, int|string> $imageIds
     * @return list<array{id: int, date_creation: ?string}>
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
            $id = is_array($row) ? ($row['id'] ?? null) : null;
            if ($id instanceof ImageId) {
                $dateCreation = $row['date_creation'] ?? null;
                $result[] = [
                    'id' => $id->value,
                    'date_creation' => $dateCreation instanceof SqlDateTime ? $dateCreation->value : null,
                ];
            }
        }

        return $result;
    }

    /**
     * Same dynamic pagination shape as findBatchManagerThumbnails() above,
     * but every column of `images` (`images.*`) --
     * Admin\BatchManagerUnitPageRenderer's own per-image inline-edit grid
     * reads far more columns than the global-mode thumbnail grid does.
     *
     * Qualified as `images.*`, not a bare `SELECT *`: the conditional JOIN
     * below would otherwise add `image_category`'s own `image_id`/
     * `category_id`/`rank` to the row whenever $categoryId is non-null,
     * making the row's column set depend on that argument.
     *
     * The JOIN itself is load-bearing beyond filtering: $orderBySql may be
     * `` ORDER BY `rank` ASC `` (the "Manual sort order" entry in
     * Controller\Admin\ConfigurationSubController's own $sort_fields, kept
     * for order_by_inside_category and writable onto categories.image_order
     * by Admin\ElementSetRanksPageRenderer), and `rank` lives only on
     * `image_category`. Replacing the JOIN with an `id IN (SELECT ...)`
     * filter compiles but fails at runtime with "Unknown column 'rank' in
     * 'order clause'".
     *
     * Stays on DBAL -- $orderBySql is a caller-composed raw fragment DQL
     * has no way to embed.
     *
     * @param array<array-key, int|string|float|bool> $imageIds
     * @return list<array<string, mixed>>
     */
    public function findBatchManagerUnitRows(array $imageIds, ?int $categoryId, string $orderBySql, int $limit, int $offset): array
    {
        $query = <<<SQL
            SELECT images.*
            FROM images
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
            $query .= <<<SQL

                JOIN image_category ON id = image_id
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
     * The aliased `ic.categoryId AS category_id` still hydrates as a real
     * {@see CategoryId} under array hydration despite the alias, so it's
     * unwrapped explicitly below. `uppercats` is a non-nullable column;
     * `dir` is nullable (see CategoryEntity's own property types).
     *
     * @return list<array{category_id: int, uppercats: string, dir: ?string}>
     */
    public function findCategoryLinksForImage(ImageId $imageId): array
    {
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('ic.categoryId AS category_id', 'c.uppercats', 'c.dir')
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin(CategoryEntity::class, 'c', Join::WITH, 'c.id = ic.categoryId')
            ->where('ic.imageId = :imageId')
            ->setParameter('imageId', $imageId)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $categoryId = $row['category_id'];
            $uppercats = $row['uppercats'] ?? null;
            $dir = $row['dir'] ?? null;
            if (! $categoryId instanceof CategoryId || ! is_string($uppercats)) {
                continue;
            }

            $result[] = [
                'category_id' => $categoryId->value,
                'uppercats' => $uppercats,
                'dir' => is_string($dir) ? $dir : null,
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
     * `getSingleColumnResult()` uses `HYDRATE_SCALAR_COLUMN`, which does
     * NOT apply the `category_id` custom Type -- the selected
     * `ic.categoryId` comes back as a raw scalar here, exactly what this
     * method's own `list<int>` contract wants, so no VO-unwrap is needed
     * (unlike the array/object-hydrated conversions elsewhere in this
     * file).
     *
     * @return list<int>
     */
    public function findCategoryIdsForImage(ImageId $imageId): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->createQueryBuilder()
                ->select('ic.categoryId')
                ->from(ImageCategoryEntity::class, 'ic')
                ->where('ic.imageId = :imageId')
                ->setParameter('imageId', $imageId)
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Ids of images uploaded before the 2022-12-08 issue-1827 fix, under
     * `./upload/`, capped at $limit -- Admin\Maintenance\
     * FilesystemIntegrityChecker::fsQuickCheck()'s own sampling pool for
     * that issue, merged with findImageIdsSample()'s general random pool
     * before the actual file_exists() check.
     *
     * @return list<int>
     */
    public function findIssue1827CandidateImageIds(int $limit): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => $v instanceof ImageId ? $v->value : (is_numeric($v) ? (int) $v : 0),
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
     * @return list<int>
     */
    public function findImageIdsSample(int $limit): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => $v instanceof ImageId ? $v->value : (is_numeric($v) ? (int) $v : 0),
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
                ->leftJoin(ImageEntity::class, 'i', Join::WITH, 'i.id = ic.imageId')
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
     * file matches $imageFile (a `LIKE` pattern) -- Controller\
     * PictureController's own "resolve the requested picture, by id or by
     * filename" lookup. $imageFile is guest-reachable, untrusted input
     * (Section\SectionInitializer::parseUrl() captures it from a raw
     * picture.php URL path segment); the caller's own `_`/`%` escaping
     * only neutralizes LIKE wildcards, not SQL quote characters, so
     * $imageFile must always be bound as a parameter here, never spliced
     * into the query.
     */
    public function findByIdOrFilePattern(int $imageId, ?string $imageFile): ImageLookupRow|false
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

        if (! is_array($row) || ! ($row['id'] ?? null) instanceof ImageId || ! is_string($row['file'] ?? null)
            || ! is_numeric($row['level'] ?? null)) {
            return false;
        }

        return new ImageLookupRow(
            id: $row['id'],
            file: $row['file'],
            level: (int) $row['level'],
        );
    }

    /**
     * Ids of images already at $filename within $categoryId -- Ws\
     * Images::upload()'s own "update_mode" replace-existing lookup.
     *
     * @return list<int>
     */
    public function findIdsByFilenameInCategory(string $filename, CategoryId $categoryId): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => $v instanceof ImageId ? $v->value : (is_numeric($v) ? (int) $v : 0),
            $this->createQueryBuilder('i')
                ->select('i.id')
                ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'ic.imageId = i.id')
                ->where('i.file = :filename')
                ->andWhere('ic.categoryId = :categoryId')
                ->setParameter('filename', $filename)
                ->setParameter('categoryId', $categoryId)
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * `path` for $imageId, or null if it doesn't exist -- Ws\Images::
     * checkFiles()'s own "does the client's local file match ours" lookup.
     * Real DQL -- single-table, static WHERE on the primary key.
     */
    public function findPathById(ImageId $imageId): ?string
    {
        $row = $this->createQueryBuilder('i')
            ->select('i.path AS path')
            ->where('i.id = :imageId')
            ->setParameter('imageId', $imageId)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        return is_string($row['path']) ? $row['path'] : null;
    }

    /**
     * id/name/representative_ext/path for $imageId -- Ws\Images::
     * upload()'s own "what does the just-uploaded/replaced photo look
     * like" lookup, used to build the response's thumbnail URLs.
     */
    public function findUploadResultInfoById(ImageId $imageId): ?UploadResultInfo
    {
        $row = $this->createQueryBuilder('i')
            ->select('i.id', 'i.name', 'i.representativeExt AS representative_ext', 'i.path')
            ->where('i.id = :imageId')
            ->setParameter('imageId', $imageId)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        return new UploadResultInfo(
            id: $row['id'] instanceof ImageId ? $row['id']->value : (is_numeric($row['id']) ? (int) $row['id'] : 0),
            name: is_string($row['name'] ?? null) ? $row['name'] : null,
            representativeExt: is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
            path: is_string($row['path']) ? $row['path'] : '',
        );
    }

    /**
     * Number of images linked to $categoryId -- Ws\Images::upload()'s
     * own "how many photos are now in this category" response field.
     */
    public function countImagesInCategory(CategoryId $categoryId): int
    {
        $value = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(ic.imageId)')
            ->from(ImageCategoryEntity::class, 'ic')
            ->where('ic.categoryId = :categoryId')
            ->setParameter('categoryId', $categoryId)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Whether $imageId is reachable via at least one category satisfying
     * $criteria -- Controller\PictureController's own "can this image
     * still be accessed differently" fallback check, and Ws\Images::
     * rate()'s own accessibility gate. Applying $criteria->maxLevel here
     * is a harmless redundant check for PictureController's own caller
     * (which already independently confirms `$row['level'] <=
     * $user_level` a few lines above for this exact same image), while
     * correctly gating Ws\Images::rate()'s own caller, which performs
     * no such check itself.
     */
    public function isImageAccessibleWithCondition(ImageId $imageId, PermissionCriteria $criteria): bool
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('i.id')
            ->from(ImageEntity::class, 'i')
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'i.id = ic.imageId')
            ->where('i.id = :imageId')
            ->setMaxResults(1)
            ->setParameter('imageId', $imageId);

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
     * Ws\Images\GetInfoHandler's own image lookup. Both
     * $criteria->visibleImageIds and $criteria->maxLevel apply here (not
     * visibleImageIds alone).
     *
     * Stays on DBAL -- the blocker is `SELECT *` itself: this row is
     * {@see \Piwigo\Ws\Images\GetInfoHandler}'s own public WS response
     * shape, read/re-emitted with its raw snake_case column names
     * (`$image_row['rating_score']`, etc.) as real external API contract.
     * DQL always hydrates through the entity's own (camelCase) property
     * names, never the raw column name -- reproducing the exact original
     * row shape would mean hand-mapping every one of `ImageEntity`'s ~25
     * properties back to its raw column name, real effort for no real
     * gain on a query that's already fully bound, injection-safe DBAL.
     *
     * @return ?array<string, mixed>
     */
    public function findRowWithCondition(ImageId $imageId, PermissionCriteria $criteria): ?array
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('*')
            ->from('images')
            ->where('id = :imageId')
            ->setMaxResults(1)
            ->setParameter('imageId', $imageId->value, ParameterType::INTEGER);

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
     * unlike findVisibleCategoriesForImage() below) -- Ws\Images\
     * GetInfoHandler's own "related categories" block.
     *
     * `commentable` hydrates as a real `bool` -- safe because the one
     * real caller ({@see \Piwigo\Ws\Images\GetInfoHandler}) already
     * `(bool)`-casts it and `unset()`s the key immediately after, before
     * the row ever reaches its own JSON response. `c.id`/`c.permalink`
     * are custom-Typed (`category_id`/`permalink`), so `getArrayResult()`
     * (Gotcha #1) returns real VO instances for them, unwrapped below.
     *
     * @return list<array<string, mixed>>
     */
    public function findRelatedCategoriesForImage(ImageId $imageId, PermissionCriteria $criteria): array
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('c.id', 'c.name', 'c.permalink', 'c.uppercats', 'c.globalRank AS global_rank', 'c.commentable')
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin(CategoryEntity::class, 'c', Join::WITH, 'ic.categoryId = c.id')
            ->where('ic.imageId = :imageId')
            ->setParameter('imageId', $imageId);

        self::applyCondition($qb, $criteria->forbiddenCategoriesCondition('ic.categoryId'));

        $result = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            $permalink = $row['permalink'] ?? null;
            $result[] = [
                'id' => $id instanceof CategoryId ? $id->value : $id,
                'name' => $row['name'] ?? null,
                'permalink' => $permalink instanceof Permalink ? $permalink->value : $permalink,
                'uppercats' => $row['uppercats'] ?? null,
                'global_rank' => $row['global_rank'] ?? null,
                'commentable' => $row['commentable'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Whether $imageId belongs to at least one commentable category
     * satisfying $criteria -- Ws\Images::addComment()'s own "can this
     * image receive a comment" check. $criteria->visibleImageIds and
     * $criteria->imageAccessIds both apply here, against `ic.imageId`
     * (not maxLevel).
     */
    public function isImageCommentableWithCondition(ImageId $imageId, PermissionCriteria $criteria): bool
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('DISTINCT ic.imageId')
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin(CategoryEntity::class, 'c', Join::WITH, 'ic.categoryId = c.id')
            ->where('c.commentable = :true')
            ->andWhere('ic.imageId = :imageId')
            ->setParameter('true', true)
            ->setParameter('imageId', $imageId);

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
     * `commentable`/`visible` hydrate as real `bool` -- safe because
     * {@see \Piwigo\Picture\PictureCommentRenderer::render()}'s own
     * `commentable` read already `(bool)`-casts it, and `visible` has no
     * strict-typed reader in either real consumer
     * ({@see \Piwigo\Controller\PictureController}/
     * `PictureCommentRenderer`). `c.id` is custom-Typed (`category_id`) --
     * {@see \Piwigo\Controller\PictureController}'s own `is_numeric()`
     * read of `$related_categories[0]['id']` needs the unwrapped int.
     *
     * @return list<array<string, mixed>>
     */
    public function findVisibleCategoriesForImage(ImageId $imageId, PermissionCriteria $criteria): array
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('c.id', 'c.uppercats', 'c.commentable', 'c.visible', 'c.status', 'c.globalRank AS global_rank')
            ->from(ImageCategoryEntity::class, 'ic')
            ->innerJoin(CategoryEntity::class, 'c', Join::WITH, 'ic.categoryId = c.id')
            ->where('ic.imageId = :imageId')
            ->setParameter('imageId', $imageId);

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

            $id = $row['id'] ?? null;
            $result[] = [
                'id' => $id instanceof CategoryId ? $id->value : $id,
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
     * @return list<int>
     */
    public function findAssociatedCategoryIds(ImageId $imageId): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->createQueryBuilder()
                ->select('c.id')
                ->from(CategoryEntity::class, 'c')
                ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'c.id = ic.categoryId')
                ->where('ic.imageId = :imageId')
                ->setParameter('imageId', $imageId)
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * Ids of every image with $md5sum -- Admin\Upload\UploadService's own
     * upload-time duplicate detection. $md5sum is a fully free-form,
     * caller-controlled string despite its name (registered with zero
     * WS-level type constraints in WsDefaultMethods.php) and must be
     * bound as a parameter, never spliced into the query.
     *
     * @return list<int>
     */
    public function findIdsByMd5sum(string $md5sum): array
    {
        return array_values(array_map(
            static fn (mixed $v): int => $v instanceof ImageId ? $v->value : (is_numeric($v) ? (int) $v : 0),
            $this->createQueryBuilder('i')
                ->select('i.id')
                ->where('i.md5sum = :md5sum')
                ->setParameter('md5sum', $md5sum)
                ->getQuery()
                ->getSingleColumnResult()
        ));
    }

    /**
     * id keyed by md5sum, for a batch of md5sums -- Ws\Images::exist()'s
     * own bulk "which of these already-uploaded checksums exist" check.
     * $md5sums are client-supplied and parameter-bound.
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
            if (is_array($row) && ($row['md5sum'] ?? null) instanceof Md5Sum && ($row['id'] ?? null) instanceof ImageId) {
                $idByMd5sum[$row['md5sum']->value] = $row['id']->value;
            }
        }

        return $idByMd5sum;
    }

    /**
     * id keyed by filename, for a batch of filenames -- Ws\Images::
     * exist()'s own bulk "which of these filenames already exist" check.
     * $filenames are client-supplied and parameter-bound.
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
            if (is_array($row) && is_string($row['file'] ?? null) && ($row['id'] ?? null) instanceof ImageId) {
                $idByFilename[$row['file']] = $row['id']->value;
            }
        }

        return $idByFilename;
    }

    /**
     * The format_id of $imageId's existing format row for $ext, if any --
     * Admin\Upload\UploadService's own "update an existing format vs
     * insert a new one" check.
     *
     * There's no unique constraint on (image_id, ext), so more than one
     * row could in principle match; setMaxResults(1) is paired with
     * getOneOrNullResult() to avoid a throw in that case.
     */
    public function findFormatIdByImageAndExt(ImageId $imageId, string $ext): ?int
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
     * $criteria->forbiddenCategoryIds/visibleCategoryIds apply against
     * `ic.categoryId`, visibleImageIds and maxLevel apply against `i.id`/
     * `i.level`.
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
     * $criteria->forbiddenCategoryIds applies against `ic.categoryId` and
     * imageAccessIds against `ic.imageId`, not maxLevel.
     */
    public function isImageAccessibleViaCategoryWithCondition(ImageId $imageId, PermissionCriteria $criteria): bool
    {
        $qb = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('c.id')
            ->from(CategoryEntity::class, 'c')
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'ic.categoryId = c.id')
            ->where('ic.imageId = :imageId')
            ->setMaxResults(1)
            ->setParameter('imageId', $imageId);

        self::applyCondition($qb, SqlCondition::combine(
            'AND',
            $criteria->forbiddenCategoriesCondition('ic.categoryId'),
            $criteria->imageAccessCondition('ic.imageId'),
        ));

        return $qb->getQuery()
            ->getSingleColumnResult() !== [];
    }

    /**
     * Every column of every image matching $criteria, joined against
     * `image_category` and deduplicated by image id -- Ws\Categories::
     * getImages()'s own paginated listing. $criteria's 3 conditions
     * (filter/category scope/visible-images permission) are combined
     * internally via SqlCondition::combine(). $orderByClause is an
     * already-built, trusted SQL fragment -- same "caller composes
     * trusted ORDER BY text" contract as
     * {@see \Piwigo\Comment\CommentRepository::findForImage()}'s own
     * $order.
     *
     * `SQL_CALC_FOUND_ROWS`/`FOUND_ROWS()` is replaced by
     * `COUNT(*) OVER() AS total_count`, computed in the same query as the
     * row data instead of a second round-trip coupled to connection
     * state -- `GROUP BY i.id` here (not `DISTINCT`), so the window
     * function (evaluated after GROUP BY, before LIMIT/OFFSET) reports
     * the correct total. `total_count` is stripped back out of each row
     * before returning, since `i.*` doesn't include it.
     *
     * Stays on DBAL -- `i.*` is a whole-row selection DQL can't express
     * (no fixed property list), and `$orderByClause` is a caller-composed
     * trusted SQL fragment.
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

        $imagesTable = 'images';
        $imageCategoryTable = 'image_category';

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
     * Whether an image with this id exists -- Ws\Categories::
     * setRepresentative()'s own existence check.
     * Real DQL -- single-table, static WHERE on the primary key.
     */
    public function existsById(ImageId $id): bool
    {
        $value = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * Which of $ids are real image ids -- Ws\Images::syncMetadata()'s
     * own "filter the caller's list down to images that actually exist"
     * step.
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
     * Ws\Categories::getImages()'s own "which albums (that the caller
     * may see) is each returned photo linked to" step. The one real
     * caller only ever applies forbiddenCategoryIds, against the
     * unqualified `category_id`. `categoryId` hydrates as a
     * {@see CategoryId} VO, unwrapped below.
     *
     * @param  list<int>  $imageIds
     * @return list<ImageCategoryLink>
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

            $result[] = new ImageCategoryLink(
                imageId: $row['imageId'] instanceof ImageId ? $row['imageId']->value : (is_numeric($row['imageId']) ? (int) $row['imageId'] : 0),
                categoryId: $row['categoryId'] instanceof CategoryId ? $row['categoryId']->value : (is_numeric($row['categoryId']) ? (int) $row['categoryId'] : 0),
            );
        }

        return $result;
    }

    /**
     * Next free id -- Controller\Admin\SiteUpdateSubController's own
     * manual-id assignment for directory-synced images (mirrors the
     * retired MysqliDb::nextval()).
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
            if (! $id instanceof ImageId || ! is_string($row['path'])) {
                continue;
            }

            $byId[$id->value] = $row['path'];
        }

        return $byId;
    }

    /**
     * Distinct image ids linked (via image_category) to any of
     * $categoryIds -- Admin\BatchManager\FilterResolver's own
     * "categories" prefilter.
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
     * @param list<int> $categoryIds
     * @return list<int>
     */
    public function findIdsNotInCategories(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return array_values(array_map(
                static fn (mixed $v): int => $v instanceof ImageId ? $v->value : (is_numeric($v) ? (int) $v : 0),
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
            static fn (mixed $v): int => $v instanceof ImageId ? $v->value : (is_numeric($v) ? (int) $v : 0),
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
            if ($id instanceof ImageId) {
                $result[] = $id->value;
            } elseif (is_numeric($id)) {
                $result[] = (int) $id;
            }
        }

        return $result;
    }

    /**
     * Ids of every image with no linked tag -- Admin\BatchManager\
     * FilterResolver's own "no_tag" prefilter.
     *
     * Stays on DBAL -- `image_tag` is entity-mapped
     * ({@see \Piwigo\Tag\ImageTagEntity}), but it's a cross-domain table
     * {@see \Piwigo\Tag\TagRepository} owns, not this repository's own
     * entity; same "cross-domain table stays plain DBAL" boundary this
     * class's own header docblock documents.
     *
     * @return list<int>
     */
    public function findIdsWithNoTag(): array
    {
        $imagesTable = 'images';
        $imageTagTable = 'image_tag';

        // Row order is otherwise unguaranteed; MySQL and PostgreSQL return
        // this exact join's row order differently with no ORDER BY.
        return $this->getEntityManager()
            ->getConnection()
            ->fetchFirstColumn(<<<SQL
                SELECT id FROM {$imagesTable} LEFT JOIN {$imageTagTable} ON id = image_id WHERE tag_id IS NULL ORDER BY id
                SQL);
    }

    /**
     * Ids of images that share the same value across every column in
     * $fields with at least one other image -- Admin\BatchManager\
     * FilterResolver's own "duplicates" prefilter. GROUP_CONCAT truncates
     * at 1024 chars by default, so a duplicate group larger than ~250 ids
     * silently loses members.
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
     * referenced by any named placeholders inside them. $orderBySql
     * traces back to {@see \Piwigo\Config\CurrentConfig::orderBy()}/
     * {@see \Piwigo\Config\CurrentConfig::orderByInsideCategory()} -- an
     * admin-configurable raw "ORDER BY ..." string with no bounded shape.
     *
     * Stays on DBAL -- DQL requires the whole query expressible in DQL,
     * not just the WHERE half, and $whereClauses/$orderBySql are
     * caller-composed raw SQL fragments, not DQL property-path
     * expressions.
     *
     * @param list<string> $whereClauses
     * @param array<string, int|float|string> $params
     * @return list<int>
     */
    public function findIdsWithConditions(array $whereClauses, array $params, string $orderBySql): array
    {
        $imagesTable = 'images';
        $whereSql = $whereClauses === [] ? '' : 'WHERE ' . implode(' AND ', $whereClauses);

        // An empty $orderBySql defaults to `ORDER BY id` for a
        // deterministic result across platforms. $orderBySql traces back
        // to CurrentConfig::orderBy(), admin-settable raw SQL text that
        // can legitimately be `ORDER BY RAND()` -- rewritten via
        // SqlDialect::randomFunction() (same handling as
        // CategoryRepository's own raw-DBAL fallback) since PostgreSQL has
        // no `RAND()` function.
        $orderBySql = $orderBySql === '' ? 'ORDER BY id' : str_ireplace(
            'RAND()',
            SqlDialect::randomFunction(),
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
     * {@see \Piwigo\Db\NoMatchSentinel::ID_STRING} meaning "none"), added
     * on/after $recentPeriodExpr (an already-built SqlDialect date
     * expression) -- Filter\FilterService's own recent-content filter
     * computation. Same "caller composes trusted fragments" contract as
     * findWithConditionsPaginated() above.
     *
     * Stays on DBAL -- $recentPeriodExpr is a caller-composed raw SQL
     * date-arithmetic fragment, spliced directly into the WHERE clause;
     * DQL has no way to embed an already-built trusted SQL fragment like
     * this one.
     *
     * @return list<int>
     */
    public function findIdsVisibleInCategoriesRecentlyAvailable(string $categoryIdsCsv, string $recentPeriodExpr): array
    {
        $imageCategoryTable = 'image_category';
        $imagesTable = 'images';
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

        return $value instanceof SqlDateTime ? $value->value : null;
    }

    /**
     * Number of images with a non-null `storage_category_id` (added via
     * filesystem sync, not the API) -- Admin\PiwigoInfosSender's own
     * "is it worth running the slower sync-vs-api breakdown query" guard.
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
     * Fetches `storageCategoryId`/`dateAvailable` per row and groups in
     * PHP rather than SQL, since MySQL's `IF(storage_category_id IS
     * NULL, 'api', 'sync')` has no portable DQL equivalent and DQL can't
     * group by a SELECT alias -- only 2 buckets, and this is an
     * admin-telemetry call, not a hot path, so scanning every image row
     * is an acceptable trade. `date_available` values are ISO
     * `Y-m-d H:i:s` strings, so a plain PHP string comparison reproduces
     * MAX()'s ordering exactly.
     *
     * @return list<AddMethodBreakdown>
     */
    public function findAddMethodBreakdown(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.storageCategoryId AS storage_category_id', 'i.dateAvailable AS date_available')
            ->getQuery()
            ->getArrayResult();

        // Accumulated in 2 parallel mutable maps (AddMethodBreakdown
        // itself is readonly, so the running nb_files count / last_added_on
        // max can't be mutated in place on an already-constructed instance)
        // -- the DTOs themselves are only built once, in the final loop
        // below.
        $nbFilesByMethod = [];
        $lastAddedOnByMethod = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $addMethod = ($row['storage_category_id'] ?? null) === null ? 'api' : 'sync';
            $dateAvailableRaw = $row['date_available'] ?? null;
            $dateAvailable = $dateAvailableRaw instanceof SqlDateTime ? $dateAvailableRaw->value : null;

            $nbFilesByMethod[$addMethod] = ($nbFilesByMethod[$addMethod] ?? 0) + 1;

            $currentLastAddedOn = $lastAddedOnByMethod[$addMethod] ?? null;
            if ($dateAvailable !== null && ($currentLastAddedOn === null || $dateAvailable > $currentLastAddedOn)) {
                $lastAddedOnByMethod[$addMethod] = $dateAvailable;
            }
        }

        $result = [];
        foreach ($nbFilesByMethod as $addMethod => $nbFiles) {
            $result[] = new AddMethodBreakdown($addMethod, $lastAddedOnByMethod[$addMethod] ?? null, $nbFiles);
        }

        return $result;
    }

    /**
     * Per-extension row count and total filesize across every image --
     * Controller\Admin\IntroSubController's own storage chart and Admin\
     * PiwigoInfosSender's own telemetry breakdown, both keyed by ext.
     *
     * Fetches `path`/`filesize` per row and groups in PHP rather than
     * SQL, since MySQL's `SUBSTRING_INDEX(path, ".", -1)` has no portable
     * DQL equivalent and `ext` (a computed alias, not a real column) can't
     * be a DQL `GROUP BY` key -- an admin-telemetry call, not a hot path,
     * so scanning every image row is an acceptable trade. The extension
     * extraction below reproduces `SUBSTRING_INDEX(path, ".", -1)`'s
     * exact semantics (substring after the last `.`, or the whole string
     * when there's no `.` at all) rather than reusing
     * {@see \Piwigo\Core\StringHelper::getExtension()}, which returns
     * `''` for the no-dot case instead.
     *
     * @return list<ExtensionBreakdown>
     */
    public function findExtensionBreakdown(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.path AS path', 'i.filesize AS filesize')
            ->getQuery()
            ->getArrayResult();

        // Accumulated in 2 parallel mutable maps -- see
        // findAddMethodBreakdown()'s own docblock for why (ExtensionBreakdown
        // is readonly too).
        $counterByExt = [];
        $filesizeByExt = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $pathValue = $row['path'] ?? null;
            $path = is_string($pathValue) ? $pathValue : '';
            $dotPosition = strrpos($path, '.');
            $ext = $dotPosition === false ? $path : substr($path, $dotPosition + 1);
            $filesize = is_numeric($row['filesize'] ?? null) ? (int) $row['filesize'] : 0;

            if (! isset($counterByExt[$ext])) {
                $counterByExt[$ext] = 0;
                $filesizeByExt[$ext] = 0;
            }

            $counterByExt[$ext]++;
            $filesizeByExt[$ext] += $filesize;
        }

        $result = [];
        foreach ($counterByExt as $ext => $counter) {
            $result[] = new ExtensionBreakdown($ext, $counter, $filesizeByExt[$ext]);
        }

        return $result;
    }

    /**
     * Per-extension row count and total filesize across every generated
     * format file -- Controller\Admin\IntroSubController's own storage
     * chart "Formats" bucket. Id-list sibling of findExtensionBreakdown()
     * above, but against `image_format`, not `images`. Unlike that
     * sibling, `ext` here is a real column (not a SUBSTRING_INDEX()-derived
     * alias), so this one groups directly in DQL.
     *
     * @return list<ExtensionBreakdown>
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

            $result[] = new ExtensionBreakdown(
                ext: is_string($row['ext']) ? $row['ext'] : '',
                counter: is_numeric($row['counter']) ? (int) $row['counter'] : 0,
                filesize: is_numeric($row['filesize']) ? (int) $row['filesize'] : 0,
            );
        }

        return $result;
    }
}

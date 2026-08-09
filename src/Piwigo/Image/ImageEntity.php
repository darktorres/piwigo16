<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\Md5Sum;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `images` table (`piwigo_images` once Piwigo\Db\TablePrefixListener
 * applies db_prefix at metadata-load time). `dateMetadataUpdate` stays
 * plain ?string, not a VO -- matches Image\Projection\Image's own
 * already-documented decision (every real consumer wants the raw DB
 * DATETIME string form, and unlike `dateAvailable`/`dateCreation` below,
 * no real code anywhere reads this column back through the entity).
 *
 * `dateAvailable`/`dateCreation` are `SqlDateTime`-typed via the strict
 * `sql_datetime` Doctrine Type, same as `lastmodified` below. They used
 * to go through a lenient `sql_datetime_graceful` Type instead
 * (`Db\Type\GracefulSqlDateTimeType`, since deleted), added when tracing
 * every real write path for the VO/DTO typing campaign turned up
 * `Metadata\MetadataService::getSyncExifData()` (line ~335) confirming a
 * pre-existing row could carry a real MySQL zero-date
 * ('0000-00-00 00:00:00') sentinel that `SqlDateTime::from()`'s
 * calendar-round-trip validation would reject. That Type only protected
 * the narrow ORM `find()` read path though -- most real reads
 * (`Controller\PictureController`, most of `ImageRepository`) go
 * through raw DBAL and were never covered by it. Migration
 * `Version20260809083506` backfills every surviving sentinel to real
 * `NULL` instead, closing the gap for every consumer at once.
 *
 * The one real entity-level write path,
 * {@see \Piwigo\Image\ImageRepository::updateDescriptiveFields()}, has
 * always used the strict `SqlDateTime::from()` (not `tryFrom()`) before
 * assignment. Unlike `Users\UserListCriteria::$minRegister`/`$maxRegister`
 * (blocked on adding upstream WS validation first, no DB-level backstop
 * at all), `date_creation`/`date_available` are real `datetime`/
 * `timestamp` columns (not VARCHAR) even under the pre-VO `string`
 * Doctrine mapping -- the DB driver itself already rejects or silently
 * zero-dates a malformed value on write today, so moving that same
 * rejection earlier (a clear PHP exception instead of a DB-level error
 * or silent corruption) is not a new regression at the WS boundary
 * (`Ws\PwgImages`'s 3 `date_creation` call sites), just an earlier,
 * clearer failure of a write that was never actually safe.
 *
 * `lastmodified` doesn't share the zero-date risk and is strict-typed
 * (`sql_datetime`) -- `NOT NULL DEFAULT CURRENT_TIMESTAMP ON
 * UPDATE CURRENT_TIMESTAMP` means the DB server always populates a real,
 * well-formed timestamp for it (unlike `dateAvailable`/`dateCreation`,
 * which are nullable and EXIF/IPTC-sourced).
 * `new ImageEntity(...)` is never constructed in PHP anywhere in this
 * codebase (every real image row is inserted via raw DBAL) -- the 2 real
 * DQL `UPDATE ... SET i.lastmodified = ...` sites both bind a plain
 * string, which works against a custom-Typed column regardless (confirmed
 * live throughout this phase).
 *
 * The single-row-by-id ImageRepository methods
 * (findById/findByPath/updateCoi/updateRotation/updateDimensions) go
 * through this entity via find()/findOneBy(), and two bulk id-list
 * methods (deleteImages()/touchLastmodified()) also target it via
 * QueryBuilder delete()/update() DQL -- every other bulk/list/
 * dynamic-fragment method (the large majority) stays plain DBAL via
 * $this->getEntityManager()->getConnection(), same "mixed repository"
 * shape Tag/Category's own conversions already established, just
 * skewed further toward "stays raw" given how much of this
 * repository's real method list is bulk id-list operations rather
 * than single-entity CRUD.
 */
#[ORM\Entity(repositoryClass: ImageRepository::class)]
#[ORM\Table(name: 'images')]
final class ImageEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'image_id')]
    public ?ImageId $id = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 255)]
        public string $file,
        #[ORM\Column(name: 'date_available', type: 'sql_datetime', length: 19, nullable: true)]
        public ?SqlDateTime $dateAvailable,
        #[ORM\Column(name: 'date_creation', type: 'sql_datetime', length: 19, nullable: true)]
        public ?SqlDateTime $dateCreation,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        public ?string $name,
        #[ORM\Column(type: 'text', nullable: true)]
        public ?string $comment,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        public ?string $author,
        #[ORM\Column(type: 'integer')]
        public int $hit,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $filesize,
        #[ORM\Column(type: 'smallint', nullable: true)]
        public ?int $width,
        #[ORM\Column(type: 'smallint', nullable: true)]
        public ?int $height,
        #[ORM\Column(type: 'string', length: 4, nullable: true)]
        public ?string $coi,
        #[ORM\Column(name: 'representative_ext', type: 'string', length: 4, nullable: true)]
        public ?string $representativeExt,
        #[ORM\Column(name: 'date_metadata_update', type: 'string', length: 10, nullable: true)]
        public ?string $dateMetadataUpdate,
        #[ORM\Column(name: 'rating_score', type: 'float', nullable: true)]
        public ?float $ratingScore,
        // `path` is root-relative for uploaded photos, but absolute for
        // locally site-synced photos (LocalSiteReader/
        // SiteUpdateSubController -- galleries_url itself is seeded as an
        // absolute path by InstallWizard), so it can't be a VO that
        // guarantees relativity; every real consumer that resolves it to
        // a filesystem location checks its own leading '/' first (see
        // MetadataService::getSyncMetadata()/ImagePathHelper::getElementPath()).
        #[ORM\Column(type: 'string', length: 255)]
        public string $path,
        #[ORM\Column(name: 'storage_category_id', type: 'category_id', nullable: true)]
        public ?CategoryId $storageCategoryId,
        #[ORM\Column(type: 'smallint')]
        public int $level,
        #[ORM\Column(type: 'md5sum', length: 32, nullable: true)]
        public ?Md5Sum $md5sum,
        #[ORM\Column(name: 'added_by', type: 'user_id', nullable: true)]
        public ?UserId $addedBy,
        #[ORM\Column(type: 'smallint', nullable: true)]
        public ?int $rotation,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $latitude,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $longitude,
        #[ORM\Column(type: 'sql_datetime', length: 19)]
        public SqlDateTime $lastmodified,
    ) {}
}

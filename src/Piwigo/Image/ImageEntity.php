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
 * applies db_prefix at metadata-load time). `dateAvailable`/`dateCreation`/
 * `dateMetadataUpdate` stay plain ?string, not \DateTimeImmutable --
 * matches Image\Projection\Image's own already-documented decision (every
 * real consumer wants the raw DB DATETIME string form).
 *
 * Re-examined fresh during the typed-primitives adoption campaign
 * (Common\ValueObject\SqlDate/SqlDateTime now exist): traced every
 * real consumer of these 3 properties and found none do arithmetic or
 * typed comparison -- Image\Projection\Image::fromArray()/toArray() just
 * round-trip the raw string unchanged for template/JSON output.
 * SqlDateTime::from() would add real construction-time calendar
 * validation, which is a behavior change with legacy-data risk (a
 * pre-existing MySQL zero-date row would throw on hydration, not just on
 * a new write) for zero real benefit given how these fields are actually
 * consumed. Decision reaffirmed, not revisited again without new
 * information.
 *
 * `lastmodified` doesn't share that risk and is `SqlDateTime`-typed
 * (Phase 5) -- `NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE
 * CURRENT_TIMESTAMP` means the DB server always populates a real,
 * well-formed timestamp for it (unlike the 3 EXIF/IPTC-sourced columns
 * above, which can carry a genuine pre-existing zero-date sentinel).
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
        #[ORM\Column(name: 'date_available', type: 'string', length: 19, nullable: true)]
        public ?string $dateAvailable,
        #[ORM\Column(name: 'date_creation', type: 'string', length: 19, nullable: true)]
        public ?string $dateCreation,
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

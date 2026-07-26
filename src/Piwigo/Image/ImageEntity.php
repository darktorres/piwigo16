<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `images` table (`piwigo_images` once Piwigo\Db\TablePrefixListener
 * applies db_prefix at metadata-load time). `dateAvailable`/`dateCreation`/
 * `dateMetadataUpdate`/`lastmodified` stay plain ?string/string, not
 * \DateTimeImmutable -- matches Image\Projection\Image's own
 * already-documented decision (every real consumer wants the raw DB
 * DATETIME string form).
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
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

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
        #[ORM\Column(type: 'string', length: 255)]
        public string $path,
        #[ORM\Column(name: 'storage_category_id', type: 'smallint', nullable: true)]
        public ?int $storageCategoryId,
        #[ORM\Column(type: 'smallint')]
        public int $level,
        #[ORM\Column(type: 'string', length: 32, nullable: true)]
        public ?string $md5sum,
        #[ORM\Column(name: 'added_by', type: 'integer', nullable: true)]
        public ?int $addedBy,
        #[ORM\Column(type: 'smallint', nullable: true)]
        public ?int $rotation,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $latitude,
        #[ORM\Column(type: 'float', nullable: true)]
        public ?float $longitude,
        #[ORM\Column(type: 'string', length: 19)]
        public string $lastmodified,
    ) {}
}

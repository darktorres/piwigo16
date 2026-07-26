<?php

declare(strict_types=1);

namespace Piwigo\Comment;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `comments` table (`piwigo_comments` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time).
 * `date`/`validationDate` stay plain ?string, not \DateTimeImmutable --
 * every real consumer wants the raw DB DATETIME string form. `validated`
 * is a real boolean column (Comment domain Stage 1a).
 */
#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comments')]
final class CommentEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(name: 'image_id', type: 'integer')]
        public int $imageId,
        #[ORM\Column(type: 'string', length: 19, nullable: true)]
        public ?string $date,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        public ?string $author,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        public ?string $email,
        #[ORM\Column(name: 'author_id', type: 'integer', nullable: true)]
        public ?int $authorId,
        #[ORM\Column(name: 'anonymous_id', type: 'string', length: 45)]
        public string $anonymousId,
        #[ORM\Column(name: 'website_url', type: 'string', length: 255, nullable: true)]
        public ?string $websiteUrl,
        #[ORM\Column(type: 'text', nullable: true)]
        public ?string $content,
        #[ORM\Column(type: 'boolean')]
        public bool $validated,
        #[ORM\Column(name: 'validation_date', type: 'string', length: 19, nullable: true)]
        public ?string $validationDate,
    ) {}
}

<?php

declare(strict_types=1);

namespace Piwigo\Comment;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `comments` table. `date`/`validationDate` are `SqlDateTime`-typed
 * -- both real write paths
 * (`CommentRepository::insert()`/`update()`/`validate()`) trace to an
 * `Env::now()`-derived value. `validated` is a real boolean column.
 * `author_id` is `UserId`-typed -- `fk_comments_author_id` is a real
 * constraint onto `users.id` (`ON DELETE SET NULL`, so a genuinely NULL
 * value means an anonymous/guest comment, not an oversight).
 * `Comment\Projection\Comment::$authorId` stays plain `?int` regardless --
 * see that class's own docblock.
 *
 * `id`'s `comment_id` column type is a custom Doctrine Type
 * ({@see \Piwigo\Db\Type\CommentIdType}, registered in
 * EntityManagerFactory::build()) -- same underlying `INT` SQL, but
 * hydrates straight into a real CommentId VO instead of a raw int.
 */
#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comments')]
final class CommentEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'comment_id')]
    public ?CommentId $id = null;

    public function __construct(
        #[ORM\Column(name: 'image_id', type: 'image_id')]
        public ImageId $imageId,
        #[ORM\Column(type: 'sql_datetime', length: 19, nullable: true)]
        public ?SqlDateTime $date,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        public ?string $author,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        public ?string $email,
        #[ORM\Column(name: 'author_id', type: 'user_id', nullable: true)]
        public ?UserId $authorId,
        #[ORM\Column(name: 'anonymous_id', type: 'string', length: 45)]
        public string $anonymousId,
        #[ORM\Column(name: 'website_url', type: 'string', length: 255, nullable: true)]
        public ?string $websiteUrl,
        #[ORM\Column(type: 'text', nullable: true)]
        public ?string $content,
        #[ORM\Column(type: 'boolean')]
        public bool $validated,
        #[ORM\Column(name: 'validation_date', type: 'sql_datetime', length: 19, nullable: true)]
        public ?SqlDateTime $validationDate,
    ) {}
}

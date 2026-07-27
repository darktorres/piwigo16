<?php

declare(strict_types=1);

namespace Piwigo\Comment\Projection;

/**
 * Typed row shape for `piwigo_comments` (P17-23 Stage 1b, Comment domain --
 * `docs/PLAN.md`'s own "7 Entity types, 73 projection shapes"
 * reference). `fromRow()` centralises the `is_string($row['x']) ? ... :
 * default` narrowing {@see \Piwigo\Picture\PictureCommentRenderer}'s own
 * render() loop used to duplicate for itself, same shape as
 * {@see \Piwigo\Category\Projection\Category}.
 *
 * Scoped to {@see \Piwigo\Comment\CommentRepository::findForImage()}'s own
 * column list, the sole row-returning method on that repository -- every
 * other method there is a scalar/aggregate read (countForImage(),
 * countAvailableWithConditions(), etc.), same "no pure `SELECT *` reader
 * exists, so the projection matches the one real query instead" shape as
 * {@see \Piwigo\Search\Projection\Search}. `userEmail` is genuinely part of
 * that query's own shape (a `LEFT JOIN` onto `piwigo_users`, aliased
 * `user_email`) -- baked in as a real typed property rather than deferred to
 * a raw array, since `findForImage()` has exactly one real caller and this
 * is precisely the shape it needs.
 *
 * `date`/`content`/`author`/`authorId`/`email`/`websiteUrl`/`userEmail` stay
 * nullable, matching the column's own real DEFAULT NULL (or, for
 * `userEmail`, the LEFT JOIN's own "no matching user row" case) --
 * `validated` is the one NOT NULL column, so it isn't.
 */
final readonly class Comment
{
    public function __construct(
        public int $id,
        public ?string $author,
        public ?int $authorId,
        public ?string $userEmail,
        public ?string $date,
        public int $imageId,
        public ?string $websiteUrl,
        public ?string $email,
        public ?string $content,
        public bool $validated,
    ) {}

    /**
     * @param array<string, mixed> $row a {@see \Piwigo\Comment\CommentRepository::findForImage()} row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            author: is_string($row['author'] ?? null) ? $row['author'] : null,
            authorId: is_numeric($row['author_id'] ?? null) ? (int) $row['author_id'] : null,
            userEmail: is_string($row['user_email'] ?? null) ? $row['user_email'] : null,
            date: is_string($row['date'] ?? null) ? $row['date'] : null,
            imageId: is_numeric($row['image_id'] ?? null) ? (int) $row['image_id'] : 0,
            websiteUrl: is_string($row['website_url'] ?? null) ? $row['website_url'] : null,
            email: is_string($row['email'] ?? null) ? $row['email'] : null,
            content: is_string($row['content'] ?? null) ? $row['content'] : null,
            validated: is_numeric($row['validated'] ?? null) ? (bool) (int) $row['validated'] : false,
        );
    }

    /**
     * @return array{id: int, author: ?string, author_id: ?int, user_email: ?string,
     *   date: ?string, image_id: int, website_url: ?string, email: ?string,
     *   content: ?string, validated: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'author' => $this->author,
            'author_id' => $this->authorId,
            'user_email' => $this->userEmail,
            'date' => $this->date,
            'image_id' => $this->imageId,
            'website_url' => $this->websiteUrl,
            'email' => $this->email,
            'content' => $this->content,
            'validated' => $this->validated,
        ];
    }
}

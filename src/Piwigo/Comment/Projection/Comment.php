<?php

declare(strict_types=1);

namespace Piwigo\Comment\Projection;

use Piwigo\Common\ValueObject\CommentId;

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
 *
 * `id` is `CommentId`, not `?CommentId` -- unlike Group's own `fromRow()`
 * (zero real callers when it was typed), this one has exactly one, real,
 * production caller ({@see \Piwigo\Comment\CommentRepository::findForImage()}),
 * whose `com.id` column is always the table's own NOT NULL primary key --
 * a missing/invalid id can't actually occur for a real fetched row, so
 * `CommentId::from()` throwing on that (structurally impossible) case is
 * safe. `authorId` stays plain `?int`, not `UserId` -- see CommentEntity's
 * own docblock.
 */
final readonly class Comment
{
    public function __construct(
        public CommentId $id,
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
     * @param array<array-key, mixed> $row a {@see \Piwigo\Comment\CommentRepository::findForImage()}
     *   row -- straight from DQL array hydration since Item 14 Sub-phase
     *   C4 (Doctrine's own `getArrayResult()` return type is bare
     *   `mixed[]`, so this can't be declared `array<string, mixed>` the
     *   way DBAL's own `fetchAllAssociative()` rows could)
     */
    public static function fromRow(array $row): self
    {
        // `id` is `com.id`, a custom-Typed CommentId under DQL array
        // hydration (this domain's own gotcha #4 territory) -- accept
        // either the VO directly or a raw scalar.
        $idValue = $row['id'] ?? null;
        $id = $idValue instanceof CommentId ? $idValue : CommentId::tryFrom($idValue);
        if ($id === null) {
            throw new \InvalidArgumentException(sprintf('Expected a positive comment id, got %s', get_debug_type($idValue)));
        }

        // `validated` is a real boolean column -- DQL array hydration
        // gives a native bool, not the 0/1 a raw DBAL driver read used to.
        $validatedValue = $row['validated'] ?? null;
        $validated = is_bool($validatedValue) ? $validatedValue : is_numeric($validatedValue) && (int) $validatedValue !== 0;

        return new self(
            id: $id,
            author: is_string($row['author'] ?? null) ? $row['author'] : null,
            authorId: is_numeric($row['author_id'] ?? null) ? (int) $row['author_id'] : null,
            userEmail: is_string($row['user_email'] ?? null) ? $row['user_email'] : null,
            date: is_string($row['date'] ?? null) ? $row['date'] : null,
            imageId: is_numeric($row['image_id'] ?? null) ? (int) $row['image_id'] : 0,
            websiteUrl: is_string($row['website_url'] ?? null) ? $row['website_url'] : null,
            email: is_string($row['email'] ?? null) ? $row['email'] : null,
            content: is_string($row['content'] ?? null) ? $row['content'] : null,
            validated: $validated,
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
            'id' => $this->id->value,
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

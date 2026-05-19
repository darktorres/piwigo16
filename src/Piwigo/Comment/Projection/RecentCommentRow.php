<?php

declare(strict_types=1);

namespace Piwigo\Comment\Projection;

use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\MysqlDateTime;
use Piwigo\Common\ValueObject\UserId;

/**
 * Projection for the admin "Recent comments" page — comments joined with
 * `image_category` (gives the album the comment is shown under) and the
 * configurable users table (gives the commenter's email).
 */
final readonly class RecentCommentRow
{
    public function __construct(
        public CommentId $commentId,
        public ImageId $imageId,
        public CategoryId $categoryId,
        public ?string $author,
        public ?UserId $authorId,
        public ?string $userEmail,
        public ?string $email,
        public ?MysqlDateTime $date,
        public ?string $websiteUrl,
        public ?string $content,
        public bool $validated,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $commentIdRaw = $row['comment_id'] ?? null;
        if (!is_numeric($commentIdRaw)) {
            throw new \InvalidArgumentException('Recent comment row is missing required `comment_id` field');
        }
        $imageIdRaw = $row['image_id'] ?? null;
        if (!is_numeric($imageIdRaw)) {
            throw new \InvalidArgumentException('Recent comment row is missing required `image_id` field');
        }
        $categoryIdRaw = $row['category_id'] ?? null;
        if (!is_numeric($categoryIdRaw)) {
            throw new \InvalidArgumentException('Recent comment row is missing required `category_id` field');
        }
        $validatedRaw = $row['validated'] ?? false;
        return new self(
            commentId:  CommentId::from((int) $commentIdRaw),
            imageId:    ImageId::from((int) $imageIdRaw),
            categoryId: CategoryId::from((int) $categoryIdRaw),
            author:     is_string($row['author'] ?? null) ? $row['author'] : null,
            authorId:   UserId::tryFrom($row['author_id'] ?? null),
            userEmail:  is_string($row['user_email'] ?? null) ? $row['user_email'] : null,
            email:      is_string($row['email'] ?? null) ? $row['email'] : null,
            date:       MysqlDateTime::tryFrom($row['date'] ?? null),
            websiteUrl: is_string($row['website_url'] ?? null) ? $row['website_url'] : null,
            content:    is_string($row['content'] ?? null) ? $row['content'] : null,
            validated:  is_bool($validatedRaw) ? $validatedRaw : (is_numeric($validatedRaw) ? (int) $validatedRaw !== 0 : false),
        );
    }
}

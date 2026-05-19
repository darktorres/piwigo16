<?php

declare(strict_types=1);

namespace Piwigo\Comment\Projection;

use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\MysqlDateTime;
use Piwigo\Common\ValueObject\UserId;

/**
 * Projection for the picture-page comment listing — comments joined with the
 * configurable users table for the commenter's email (`user_email` next to
 * the per-comment `email` fallback for guest comments).
 */
final readonly class PictureCommentRow
{
    public function __construct(
        public CommentId $id,
        public ?string $author,
        public ?UserId $authorId,
        public ?string $userEmail,
        public ?MysqlDateTime $date,
        public ImageId $imageId,
        public ?string $websiteUrl,
        public ?string $email,
        public ?string $content,
        public bool $validated,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw = $row['id'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('Comment row is missing required `id` field');
        }
        $imageIdRaw = $row['image_id'] ?? null;
        if (!is_numeric($imageIdRaw)) {
            throw new \InvalidArgumentException('Comment row is missing required `image_id` field');
        }
        $validatedRaw = $row['validated'] ?? false;
        return new self(
            id:         CommentId::from((int) $idRaw),
            author:     is_string($row['author'] ?? null) ? $row['author'] : null,
            authorId:   UserId::tryFrom($row['author_id'] ?? null),
            userEmail:  is_string($row['user_email'] ?? null) ? $row['user_email'] : null,
            date:       MysqlDateTime::tryFrom($row['date'] ?? null),
            imageId:    ImageId::from((int) $imageIdRaw),
            websiteUrl: is_string($row['website_url'] ?? null) ? $row['website_url'] : null,
            email:      is_string($row['email'] ?? null) ? $row['email'] : null,
            content:    is_string($row['content'] ?? null) ? $row['content'] : null,
            validated:  is_bool($validatedRaw) ? $validatedRaw : (is_numeric($validatedRaw) ? (int) $validatedRaw !== 0 : false),
        );
    }
}

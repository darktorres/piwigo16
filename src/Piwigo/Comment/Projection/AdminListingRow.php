<?php

declare(strict_types=1);

namespace Piwigo\Comment\Projection;

use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\MysqlDateTime;
use Piwigo\Common\ValueObject\UserId;

/**
 * Projection for `pwg.userComments.getList` — comments joined with the parent
 * image (path, file, representative_ext, date_available) and user_infos
 * (status + username). Lighter than the picture-page row (no email columns).
 */
final readonly class AdminListingRow
{
    public function __construct(
        public CommentId $id,
        public ImageId $imageId,
        public ?MysqlDateTime $date,
        public ?string $author,
        public ?UserId $authorId,
        public ?string $username,
        public ?string $status,
        public ?string $content,
        public ?string $path,
        public ?string $representativeExt,
        public ?string $file,
        public ?MysqlDateTime $dateAvailable,
        public bool $validated,
        public string $anonymousId,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw = $row['id'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('AdminListingRow is missing required `id` field');
        }
        $imageIdRaw = $row['image_id'] ?? null;
        if (!is_numeric($imageIdRaw)) {
            throw new \InvalidArgumentException('AdminListingRow is missing required `image_id` field');
        }
        $validatedRaw = $row['validated'] ?? false;
        return new self(
            id:                CommentId::from((int) $idRaw),
            imageId:           ImageId::from((int) $imageIdRaw),
            date:              MysqlDateTime::tryFrom($row['date'] ?? null),
            author:            is_string($row['author'] ?? null) ? $row['author'] : null,
            authorId:          UserId::tryFrom($row['author_id'] ?? null),
            username:          is_string($row['username'] ?? null) ? $row['username'] : null,
            status:            is_string($row['status'] ?? null) ? $row['status'] : null,
            content:           is_string($row['content'] ?? null) ? $row['content'] : null,
            path:              is_string($row['path'] ?? null) ? $row['path'] : null,
            representativeExt: is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
            file:              is_string($row['file'] ?? null) ? $row['file'] : null,
            dateAvailable:     MysqlDateTime::tryFrom($row['date_available'] ?? null),
            validated:         is_bool($validatedRaw) ? $validatedRaw : (is_numeric($validatedRaw) ? (int) $validatedRaw !== 0 : false),
            anonymousId:       is_string($row['anonymous_id'] ?? null) ? $row['anonymous_id'] : '',
        );
    }
}

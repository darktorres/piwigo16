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
        $idRaw          = $row['id'] ?? null;
        $imageIdRaw     = $row['image_id'] ?? null;
        $authorRaw      = $row['author'] ?? null;
        $usernameRaw    = $row['username'] ?? null;
        $statusRaw      = $row['status'] ?? null;
        $contentRaw     = $row['content'] ?? null;
        $pathRaw        = $row['path'] ?? null;
        $reprExtRaw     = $row['representative_ext'] ?? null;
        $fileRaw        = $row['file'] ?? null;
        $anonymousIdRaw = $row['anonymous_id'] ?? null;
        $validatedRaw   = $row['validated'] ?? false;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('AdminListingRow is missing required `id` field');
        }
        if (!is_numeric($imageIdRaw)) {
            throw new \InvalidArgumentException('AdminListingRow is missing required `image_id` field');
        }
        return new self(
            id:                CommentId::from((int) $idRaw),
            imageId:           ImageId::from((int) $imageIdRaw),
            date:              MysqlDateTime::tryFrom($row['date'] ?? null),
            author:            is_string($authorRaw) ? $authorRaw : null,
            authorId:          UserId::tryFrom($row['author_id'] ?? null),
            username:          is_string($usernameRaw) ? $usernameRaw : null,
            status:            is_string($statusRaw) ? $statusRaw : null,
            content:           is_string($contentRaw) ? $contentRaw : null,
            path:              is_string($pathRaw) ? $pathRaw : null,
            representativeExt: is_string($reprExtRaw) ? $reprExtRaw : null,
            file:              is_string($fileRaw) ? $fileRaw : null,
            dateAvailable:     MysqlDateTime::tryFrom($row['date_available'] ?? null),
            validated:         is_bool($validatedRaw) ? $validatedRaw : (is_numeric($validatedRaw) ? (int) $validatedRaw !== 0 : false),
            anonymousId:       is_string($anonymousIdRaw) ? $anonymousIdRaw : '',
        );
    }
}

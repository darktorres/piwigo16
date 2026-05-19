<?php

declare(strict_types=1);

namespace Piwigo\Comment\Entity;

use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\MysqlDateTime;
use Piwigo\Common\ValueObject\UserId;

/**
 * Typed entity wrapping one row of the `comments` table.
 */
final readonly class Comment
{
    public function __construct(
        public CommentId $id,
        public ImageId $imageId,
        public ?MysqlDateTime $date,
        public ?string $author,
        public ?string $email,
        public ?UserId $authorId,
        public string $anonymousId,
        public ?string $websiteUrl,
        public ?string $content,
        public bool $validated,
        public ?MysqlDateTime $validationDate,
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
        return new self(
            id:             CommentId::from((int) $idRaw),
            imageId:        ImageId::from((int) $imageIdRaw),
            date:           MysqlDateTime::tryFrom($row['date'] ?? null),
            author:         self::nullableStr($row, 'author'),
            email:          self::nullableStr($row, 'email'),
            authorId:       UserId::tryFrom($row['author_id'] ?? null),
            anonymousId:    is_string($row['anonymous_id'] ?? null) ? $row['anonymous_id'] : '',
            websiteUrl:     self::nullableStr($row, 'website_url'),
            content:        self::nullableStr($row, 'content'),
            validated:      self::bool($row, 'validated'),
            validationDate: MysqlDateTime::tryFrom($row['validation_date'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'id'              => $this->id->value,
            'image_id'        => $this->imageId->value,
            'date'            => $this->date?->value,
            'author'          => $this->author,
            'email'           => $this->email,
            'author_id'       => $this->authorId?->value,
            'anonymous_id'    => $this->anonymousId,
            'website_url'     => $this->websiteUrl,
            'content'         => $this->content,
            'validated'       => $this->validated ? 1 : 0,
            'validation_date' => $this->validationDate?->value,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function nullableStr(array $row, string $key): ?string
    {
        $v = $row[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    /** @param array<string, mixed> $row */
    private static function bool(array $row, string $key): bool
    {
        $v = $row[$key] ?? null;
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v !== 0;
        }
        return false;
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findAdminListForWs()}'s own
 * row shape -- {@see \Piwigo\Ws\Categories\GetAdminListHandler}'s real (and
 * only) consumer, its paginated admin category rollup. Same raw
 * `Connection::fetchAllAssociative()` DBAL row reasoning as
 * {@see CategoryListForWsRow} -- narrowed to each real `categories`-table
 * column type once here, not scattered through the WS consumer.
 *
 * `toArray()` exists for that consumer: it splices several derived keys
 * (`nb_images`, `fullname`, `name_raw`, `comment_raw`, ...) onto each row
 * before building the final WS response, so it `toArray()`s the whole
 * paginated result once, up front.
 */
final readonly class CategoryAdminListForWsRow
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $comment,
        public string $uppercats,
        public ?string $globalRank,
        public ?string $dir,
        public string $status,
        public ?string $imageOrder,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            name: is_string($row['name'] ?? null) ? $row['name'] : '',
            comment: is_string($row['comment'] ?? null) ? $row['comment'] : null,
            uppercats: is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
            globalRank: is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
            dir: is_string($row['dir'] ?? null) ? $row['dir'] : null,
            status: is_string($row['status'] ?? null) ? $row['status'] : '',
            imageOrder: is_string($row['image_order'] ?? null) ? $row['image_order'] : null,
        );
    }

    /**
     * @return array{id: int, name: string, comment: ?string, uppercats: string, global_rank: ?string, dir: ?string, status: string, image_order: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'comment' => $this->comment,
            'uppercats' => $this->uppercats,
            'global_rank' => $this->globalRank,
            'dir' => $this->dir,
            'status' => $this->status,
            'image_order' => $this->imageOrder,
        ];
    }
}

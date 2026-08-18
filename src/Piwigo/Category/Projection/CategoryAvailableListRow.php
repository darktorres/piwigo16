<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findAvailableList()}'s own row
 * shape -- {@see \Piwigo\Controller\Api\Categories\CategoryAvailableListController}'s
 * real consumer (via {@see \Piwigo\Category\CategoryService::getAvailableList()}),
 * its paginated category rollup. `findAvailableList()` is DQL-backed;
 * its own VO-typed fields (`id`/`status`/`permalink`) are unwrapped back
 * to plain scalars before `fromRow()` ever sees them (see
 * `CategoryRepository::unwrapCategoryListRowVoFields()`), so this stays
 * a flat `array<string, mixed>` row shape either way -- each field is
 * narrowed to its real `categories`-table column type at construction
 * time here, once, instead of scattering the same `is_numeric()`/
 * `is_string()` guards through the response-building consumer.
 *
 * `toArray()` exists for that consumer: it splices a large number of
 * derived keys (`nb_images`, `url`, `user_representative_picture_id`,
 * `name_raw`, `comment_raw`, ...) onto each row before building the final
 * JSON response, so it `toArray()`s the whole paginated result once, up
 * front.
 */
final readonly class CategoryAvailableListRow
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $comment,
        public ?string $permalink,
        public string $status,
        public string $uppercats,
        public ?string $globalRank,
        public ?int $idUppercat,
        public ?int $representativePictureId,
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
            permalink: is_string($row['permalink'] ?? null) ? $row['permalink'] : null,
            status: is_string($row['status'] ?? null) ? $row['status'] : '',
            uppercats: is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
            globalRank: is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
            idUppercat: is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
            representativePictureId: is_numeric($row['representative_picture_id'] ?? null) ? (int) $row['representative_picture_id'] : null,
            imageOrder: is_string($row['image_order'] ?? null) ? $row['image_order'] : null,
        );
    }

    /**
     * @return array{id: int, name: string, comment: ?string, permalink: ?string, status: string, uppercats: string, global_rank: ?string, id_uppercat: ?int, representative_picture_id: ?int, image_order: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'comment' => $this->comment,
            'permalink' => $this->permalink,
            'status' => $this->status,
            'uppercats' => $this->uppercats,
            'global_rank' => $this->globalRank,
            'id_uppercat' => $this->idUppercat,
            'representative_picture_id' => $this->representativePictureId,
            'image_order' => $this->imageOrder,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/**
 * `pwg.categories.delete` input DTO.
 *
 * Mirrors the gallery's bulk-delete admin form: the category id list
 * is accepted as either an array or a separator-joined string;
 * photoDeletionMode controls what happens to photos that become
 * orphaned by the album removal.
 */
final readonly class DeleteParams implements WsParams
{
    public const string MODE_NO_DELETE     = 'no_delete';
    public const string MODE_DELETE_ORPHANS = 'delete_orphans';
    public const string MODE_FORCE_DELETE   = 'force_delete';

    /** @param list<int<1, max>> $categoryIds */
    public function __construct(
        public array $categoryIds,
        public string $photoDeletionMode,
        public string $pwgToken,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $pwgToken = $raw['pwg_token'] ?? null;
        if (!is_string($pwgToken)) {
            throw new WsParamException('Missing pwg_token');
        }
        $modes             = [self::MODE_NO_DELETE, self::MODE_DELETE_ORPHANS, self::MODE_FORCE_DELETE];
        $photoDeletionMode = is_string($raw['photo_deletion_mode'] ?? null) ? $raw['photo_deletion_mode'] : '';
        if (!in_array($photoDeletionMode, $modes, true)) {
            throw new WsParamException('[ws_categories_delete] invalid parameter photo_deletion_mode "' . $photoDeletionMode . '", possible values are {' . implode(', ', $modes) . '}.');
        }

        $rawCatId = $raw['category_id'] ?? null;
        if (is_array($rawCatId)) {
            $catIdList = $rawCatId;
        } elseif (is_string($rawCatId)) {
            $split     = preg_split('/[\s,;\|]/', $rawCatId, -1, PREG_SPLIT_NO_EMPTY);
            $catIdList = $split !== false ? $split : [];
        } else {
            $catIdList = [];
        }
        $categoryIds = array_values(array_filter(
            array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $catIdList),
            static fn (int $v): bool => $v > 0,
        ));

        return new self(
            categoryIds:       $categoryIds,
            photoDeletionMode: $photoDeletionMode,
            pwgToken:          $pwgToken,
        );
    }
}

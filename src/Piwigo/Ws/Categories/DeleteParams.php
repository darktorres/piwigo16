<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Categories;

use Exception;
use Piwigo\Ws\WsParams;

/**
 * `pwg.categories.delete` input DTO. `category_id`: `ACCEPT_ARRAY` (not
 * `FORCE_ARRAY`), no 'default' key -- mandatory, always present, accepts
 * either a scalar or a bracket-array caller value; split/coerced to a
 * `list<int>` of positive ids here, same as the god-class method this
 * replaces. `photo_deletion_mode`: no 'type' flag, non-null default --
 * always a plain string. `pwg_token`: no 'default' key, no flags --
 * mandatory, always present, plain string.
 */
final readonly class DeleteParams implements WsParams
{
    /**
     * @param list<int> $categoryIds
     */
    public function __construct(
        public array $categoryIds,
        public string $photoDeletionMode,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $photoDeletionMode = $raw['photo_deletion_mode'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            categoryIds: self::intList($raw['category_id'] ?? null),
            photoDeletionMode: is_string($photoDeletionMode) ? $photoDeletionMode : 'delete_orphans',
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } elseif (is_scalar($raw)) {
            $split = preg_split('/[\s,;\|]/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
            if ($split === false) {
                throw new Exception('delete(): preg_split() failed');
            }
            $parts = $split;
        } else {
            $parts = [];
        }

        $ids = [];
        foreach ($parts as $part) {
            $id = is_numeric($part) ? (int) $part : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}

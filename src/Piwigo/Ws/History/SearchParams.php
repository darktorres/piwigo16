<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\History;

use Piwigo\Ws\WsParams;

/**
 * `pwg.history.search` input DTO. `start`/`end`/`filename`/`ip`: no
 * type flag, null default -- string|null. `types`: `FORCE_ARRAY`,
 * non-null array default, no type flag -- always an array (never
 * coerced element-wise). `user_id`: no type flag, non-null int default
 * (-1) -- int if the default is used, otherwise the raw uncoerced
 * request string. `image_id`: `WsParamType::ID`, null default --
 * int|null in principle, but `Server::checkType()`'s own int/positive
 * coercion deliberately skips an empty-string param (`elseif ($param
 * !== '')`), so the real, uncoerced string '' also reaches here
 * whenever a caller sends the key with no value (a real browser
 * client, unlike a WS caller that just omits the key entirely).
 * `display_thumbnail`: no type flag, non-null string default -- always
 * string. `pageNumber`: `WsParamType::INT|POSITIVE`, null default --
 * int|null.
 *
 * @since 13
 */
final readonly class SearchParams implements WsParams
{
    /**
     * @param list<string> $types
     */
    public function __construct(
        public ?string $start,
        public ?string $end,
        public array $types,
        public int|string $userId,
        public int|string|null $imageId,
        public ?string $filename,
        public ?string $ip,
        public string $displayThumbnail,
        public ?int $pageNumber,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $start = $raw['start'] ?? null;
        $end = $raw['end'] ?? null;
        $userId = $raw['user_id'] ?? null;
        $imageId = $raw['image_id'] ?? null;
        $filename = $raw['filename'] ?? null;
        $ip = $raw['ip'] ?? null;
        $displayThumbnail = $raw['display_thumbnail'] ?? null;
        $pageNumber = $raw['pageNumber'] ?? null;

        return new self(
            start: is_string($start) ? $start : null,
            end: is_string($end) ? $end : null,
            types: self::stringList($raw['types'] ?? null),
            userId: (is_int($userId) || is_string($userId)) ? $userId : -1,
            imageId: (is_int($imageId) || is_string($imageId)) ? $imageId : null,
            filename: is_string($filename) ? $filename : null,
            ip: is_string($ip) ? $ip : null,
            displayThumbnail: is_string($displayThumbnail) ? $displayThumbnail : 'display_thumbnail_classic',
            pageNumber: is_int($pageNumber) ? $pageNumber : null,
        );
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $values = [];
        foreach ($raw as $v) {
            if (is_string($v)) {
                $values[] = $v;
            }
        }
        return $values;
    }
}

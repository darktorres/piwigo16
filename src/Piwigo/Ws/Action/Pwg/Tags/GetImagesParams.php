<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Ws\WsParams;

/**
 * `pwg.tags.getImages` input DTO.
 *
 * `tagIds`/`tagUrlNames`/`tagNames` are coerced to string lists to
 * match `TagService::findTags()` signature (it joins them into SQL via
 * placeholder binding). The WsHelper SQL filter/order continue to read
 * from raw `$params`.
 */
final readonly class GetImagesParams implements WsParams
{
    /**
     * @param list<string> $tagIds
     * @param list<string> $tagUrlNames
     * @param list<string> $tagNames
     */
    public function __construct(
        public array $tagIds,
        public array $tagUrlNames,
        public array $tagNames,
        public bool $tagModeAnd,
        public int $perPage,
        public int $page,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $strList = static function (mixed $v): array {
            if (!is_array($v)) {
                return [];
            }
            return array_values(array_map(
                static fn (mixed $x): string => is_scalar($x) ? (string) $x : '0',
                $v,
            ));
        };
        return new self(
            tagIds:      $strList($raw['tag_id']       ?? null),
            tagUrlNames: $strList($raw['tag_url_name'] ?? null),
            tagNames:    $strList($raw['tag_name']     ?? null),
            tagModeAnd:  (bool) ($raw['tag_mode_and'] ?? false),
            perPage:     is_numeric($raw['per_page']   ?? null) ? (int) $raw['per_page'] : 0,
            page:        is_numeric($raw['page']       ?? null) ? (int) $raw['page'] : 0,
        );
    }
}

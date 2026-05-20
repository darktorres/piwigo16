<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Ws\WsParams;

/**
 * `pwg.categories.getAdminList` input DTO.
 *
 * `additionalOutput` is the comma-split, trimmed token list — the
 * handler only inspects it via in_array() so the parsed list is the
 * useful form.
 */
final readonly class GetAdminListParams implements WsParams
{
    /** @param list<string> $additionalOutput */
    public function __construct(
        public int $catId,
        public bool $recursive,
        public ?string $search,
        public array $additionalOutput,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $searchIn   = $raw['search'] ?? null;
        $addOutIn   = $raw['additional_output'] ?? null;
        $addOutStr  = is_string($addOutIn) ? $addOutIn : '';
        $tokens     = $addOutStr === '' ? [] : array_map(trim(...), explode(',', $addOutStr));
        return new self(
            catId:            is_numeric($raw['cat_id'] ?? null) ? (int) $raw['cat_id'] : 0,
            recursive:        (bool) ($raw['recursive'] ?? false),
            search:           is_string($searchIn) && $searchIn !== '' ? $searchIn : null,
            additionalOutput: $tokens,
        );
    }
}

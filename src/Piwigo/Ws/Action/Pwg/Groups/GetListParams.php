<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Groups;

use Piwigo\Ws\WsParams;

/** `pwg.groups.getList` input DTO. */
final readonly class GetListParams implements WsParams
{
    /** @param list<int> $groupIds */
    public function __construct(
        public string $order,
        public ?string $name,
        public array $groupIds,
        public int $perPage,
        public int $page,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $orderIn = $raw['order'] ?? null;
        $nameIn  = $raw['name']  ?? null;
        $rawIds  = $raw['group_id'] ?? null;
        $groupIds = is_array($rawIds) && count($rawIds) > 0
            ? array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rawIds))
            : [];
        return new self(
            order:    is_string($orderIn) ? $orderIn : '',
            name:     is_string($nameIn) && $nameIn !== '' ? $nameIn : null,
            groupIds: $groupIds,
            perPage:  is_numeric($raw['per_page'] ?? null) ? (int) $raw['per_page'] : 0,
            page:     is_numeric($raw['page']     ?? null) ? (int) $raw['page'] : 0,
        );
    }
}

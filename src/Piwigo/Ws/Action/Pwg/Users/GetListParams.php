<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Ws\WsParams;

/**
 * `pwg.users.getList` input DTO.
 *
 * `display` is the raw string (or `'none'` default). Handler still
 * runs the basics/all/only_id expansion logic since each token has
 * domain-specific aliasing.
 */
final readonly class GetListParams implements WsParams
{
    /**
     * @param list<int>    $userIds
     * @param list<string> $statuses
     * @param list<int>    $groupIds
     * @param list<int>    $excludeIds
     */
    public function __construct(
        public string $order,
        public array $userIds,
        public ?string $username,
        public ?string $filter,
        public ?string $minRegister,
        public ?string $maxRegister,
        public array $statuses,
        public mixed $minLevel,
        public mixed $maxLevel,
        public array $groupIds,
        public array $excludeIds,
        public string $display,
        public int $perPage,
        public int $page,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $orderIn   = $raw['order']       ?? null;
        $usernameIn = $raw['username']    ?? null;
        $filterIn  = $raw['filter']      ?? null;
        $minRegIn  = $raw['min_register'] ?? null;
        $maxRegIn  = $raw['max_register'] ?? null;
        $displayIn = $raw['display']     ?? null;
        $idsRaw   = is_array($raw['user_id'] ?? null) ? $raw['user_id'] : [];
        $userIds   = array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $idsRaw,
        ));
        $statusRaw = is_array($raw['status'] ?? null) ? $raw['status'] : [];
        $statuses  = array_values(array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0',
            $statusRaw,
        ));
        $groupRaw  = is_array($raw['group_id'] ?? null) ? $raw['group_id'] : [];
        $groupIds  = array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $groupRaw,
        ));
        $excludeRaw = is_array($raw['exclude'] ?? null) ? $raw['exclude'] : [];
        $excludeIds = array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $excludeRaw,
        ));
        return new self(
            order:       is_string($orderIn) ? $orderIn : '',
            userIds:     $userIds,
            username:    is_string($usernameIn) && $usernameIn !== '' ? $usernameIn : null,
            filter:      is_string($filterIn)   && $filterIn   !== '' ? $filterIn : null,
            minRegister: is_string($minRegIn)   && $minRegIn   !== '' ? $minRegIn : null,
            maxRegister: is_string($maxRegIn)   && $maxRegIn   !== '' ? $maxRegIn : null,
            statuses:    $statuses,
            minLevel:    $raw['min_level'] ?? null,
            maxLevel:    $raw['max_level'] ?? null,
            groupIds:    $groupIds,
            excludeIds:  $excludeIds,
            display:     is_string($displayIn) ? $displayIn : 'none',
            perPage:     is_numeric($raw['per_page'] ?? null) ? (int) $raw['per_page'] : 0,
            page:        is_numeric($raw['page']     ?? null) ? (int) $raw['page'] : 0,
        );
    }
}

<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Activity;

use Piwigo\Ws\WsParams;

/**
 * `pwg.activity.getList` input DTO. `page`: `WsParamType::INT|POSITIVE`,
 * null default -- int|null (never sent as '' by this method's own JS
 * caller, unlike uid/id below). `uid`/`id`: `WsParamType::ID`, null
 * default -- `Server::checkType()` deliberately skips type coercion for
 * an empty-string value on an OPTIONAL param (matches legacy
 * ws_core.inc.php's own `PwgServer::checkType()` byte-for-byte), so a
 * present-but-empty 'uid'/'id' arrives here as the raw string '', not
 * int|null; a genuinely-provided value is coerced to int by that same
 * checkType() call -- kept `int|string|null` here rather than narrowed,
 * so `GetListHandler` can reproduce the original's own `is_int(...)`
 * guard exactly. `offset`: `WsParamType::INT|POSITIVE`, default 0
 * (non-null) -- always int. `date_min`/`date_max`/`object`/`action`: no
 * type flag, null default -- string|null.
 */
final readonly class GetListParams implements WsParams
{
    public function __construct(
        public ?int $page,
        public int $offset,
        public int|string|null $uid,
        public ?string $dateMin,
        public ?string $dateMax,
        public int|string|null $id,
        public ?string $object,
        public ?string $action,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $page = $raw['page'] ?? null;
        $offset = $raw['offset'] ?? null;
        $uid = $raw['uid'] ?? null;
        $dateMin = $raw['date_min'] ?? null;
        $dateMax = $raw['date_max'] ?? null;
        $id = $raw['id'] ?? null;
        $object = $raw['object'] ?? null;
        $action = $raw['action'] ?? null;

        return new self(
            page: is_int($page) ? $page : null,
            offset: is_int($offset) ? $offset : 0,
            uid: (is_int($uid) || is_string($uid)) ? $uid : null,
            dateMin: is_string($dateMin) ? $dateMin : null,
            dateMax: is_string($dateMax) ? $dateMax : null,
            id: (is_int($id) || is_string($id)) ? $id : null,
            object: is_string($object) ? $object : null,
            action: is_string($action) ? $action : null,
        );
    }
}

<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Extensions;

use Piwigo\Ws\WsParams;

/**
 * `pwg.extensions.update` input DTO. `type`/`id`/`revision`/`pwg_token`
 * are all mandatory (no 'default' key, no 'type' flag), always present
 * as scalars by the time this runs. `reactivate` is not a registered
 * param at all -- reachable only through `UpdateHandler`'s own
 * self-redirect, which appends it as a raw extra query param -- so it's
 * read directly off the raw request array here, the same way the
 * god-class method it replaces did.
 */
final readonly class UpdateParams implements WsParams
{
    public function __construct(
        public string $type,
        public string $id,
        public string $revision,
        public string $pwgToken,
        public bool $reactivateRequested,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $type = $raw['type'] ?? null;
        $id = $raw['id'] ?? null;
        $revision = $raw['revision'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            type: is_string($type) ? $type : '',
            id: is_string($id) ? $id : '',
            revision: is_string($revision) ? $revision : '',
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
            reactivateRequested: array_key_exists('reactivate', $raw),
        );
    }
}

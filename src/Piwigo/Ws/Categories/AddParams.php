<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Categories;

use Piwigo\Ws\WsParams;

/**
 * `pwg.categories.add` input DTO. `name` has no 'default' key in the
 * registration -- mandatory, always present. `pwg_token` is `OPTIONAL`
 * with no 'default' key -- may be entirely absent (`null` here means
 * "not provided", matching the original `isset($params['pwg_token'])`
 * guard).
 */
final readonly class AddParams implements WsParams
{
    public function __construct(
        public string $name,
        public ?int $parent,
        public ?string $comment,
        public bool $visible,
        public ?string $status,
        public bool $commentable,
        public ?string $position,
        public ?string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $name = $raw['name'] ?? null;
        $parent = $raw['parent'] ?? null;
        $comment = $raw['comment'] ?? null;
        $visible = $raw['visible'] ?? null;
        $status = $raw['status'] ?? null;
        $commentable = $raw['commentable'] ?? null;
        $position = $raw['position'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            name: is_string($name) ? $name : '',
            parent: is_int($parent) ? $parent : null,
            comment: is_string($comment) ? $comment : null,
            visible: is_bool($visible) ? $visible : true,
            status: is_string($status) ? $status : null,
            commentable: is_bool($commentable) ? $commentable : true,
            position: is_string($position) ? $position : null,
            pwgToken: is_string($pwgToken) ? $pwgToken : null,
        );
    }
}

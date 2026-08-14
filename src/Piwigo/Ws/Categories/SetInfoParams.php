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
 * `pwg.categories.setInfo` input DTO. `category_id`: no 'default' key --
 * mandatory, always present, `WsParamType::ID` guarantees a plain int.
 * `name`/`comment`/`status`/`visible`/`commentable`/
 * `apply_commentable_to_subalbums`: none has a 'type' flag (`visible`
 * and `commentable` are validated by hand against `/^(true|false)$/i`
 * in the handler, not coerced by `WsParamType::BOOL`) -- all have a null
 * default so string|null, always present. `pwg_token`: `OPTIONAL` with
 * no 'default' key -- may be entirely absent (`null` here means "not
 * provided", matching the original `isset($params['pwg_token'])`
 * guard).
 */
final readonly class SetInfoParams implements WsParams
{
    public function __construct(
        public int $categoryId,
        public ?string $name,
        public ?string $comment,
        public ?string $status,
        public ?string $visible,
        public ?string $commentable,
        public ?string $applyCommentableToSubalbums,
        public ?string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $categoryId = $raw['category_id'] ?? null;
        $name = $raw['name'] ?? null;
        $comment = $raw['comment'] ?? null;
        $status = $raw['status'] ?? null;
        $visible = $raw['visible'] ?? null;
        $commentable = $raw['commentable'] ?? null;
        $applyCommentableToSubalbums = $raw['apply_commentable_to_subalbums'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            categoryId: is_int($categoryId) ? $categoryId : 0,
            name: is_string($name) ? $name : null,
            comment: is_string($comment) ? $comment : null,
            status: is_string($status) ? $status : null,
            visible: is_string($visible) ? $visible : null,
            commentable: is_string($commentable) ? $commentable : null,
            applyCommentableToSubalbums: is_string($applyCommentableToSubalbums) ? $applyCommentableToSubalbums : null,
            pwgToken: is_string($pwgToken) ? $pwgToken : null,
        );
    }
}

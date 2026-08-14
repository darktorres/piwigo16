<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Piwigo\Ws\WsParams;
use Stringable;

/**
 * `pwg.images.addComment` input DTO. `image_id`: `WsParamType::ID`, no
 * 'default' key -- mandatory, always int. `content`/`key`: no 'default'
 * key -- mandatory, always string. `author`: registered with a
 * dynamically-computed default (`$accessControl->isAGuest() ? 'guest' :
 * $currentUser->get()->username` -- see `WsDefaultMethods`'s own
 * registration) -- when that default is used, the raw value here is a
 * `Username` value object (`Stringable`), not a plain string, so
 * `fromArray()` accepts either.
 */
final readonly class AddCommentParams implements WsParams
{
    public function __construct(
        public int $imageId,
        public string $author,
        public string $content,
        public string $key,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $imageId = $raw['image_id'] ?? null;
        $author = $raw['author'] ?? null;
        $content = $raw['content'] ?? null;
        $key = $raw['key'] ?? null;

        return new self(
            imageId: is_int($imageId) ? $imageId : 0,
            author: match (true) {
                is_string($author) => $author,
                $author instanceof Stringable => (string) $author,
                default => 'guest',
            },
            content: is_string($content) ? $content : '',
            key: is_string($key) ? $key : '',
        );
    }
}

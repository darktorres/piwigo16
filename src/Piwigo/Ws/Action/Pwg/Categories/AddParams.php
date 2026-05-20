<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Common\Enum\Privacy;
use Piwigo\Ws\WsParams;

/** `pwg.categories.add` input DTO. */
final readonly class AddParams implements WsParams
{
    public function __construct(
        public string $name,
        public ?string $comment,
        public int|string|null $parent,
        public ?Privacy $status,
        public ?string $position,
        public ?string $pwgToken,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $name     = is_string($raw['name']    ?? null) ? $raw['name'] : '';
        $comment  = is_string($raw['comment'] ?? null) ? $raw['comment'] : null;
        if ($comment === '') {
            $comment = null;
        }
        $parent = null;
        if (isset($raw['parent'])) {
            $parent = is_numeric($raw['parent']) ? (int) $raw['parent'] : (is_string($raw['parent']) ? $raw['parent'] : null);
        }
        $status = is_string($raw['status'] ?? null) ? Privacy::tryFrom($raw['status']) : null;
        $position = is_string($raw['position'] ?? null) && in_array($raw['position'], ['first', 'last'], true) ? $raw['position'] : null;
        $pwgToken = is_string($raw['pwg_token'] ?? null) ? $raw['pwg_token'] : null;
        return new self(
            name:     $name,
            comment:  $comment,
            parent:   $parent,
            status:   $status,
            position: $position,
            pwgToken: $pwgToken,
        );
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Ws\WsParams;

/**
 * `pwg.categories.setInfo` input DTO.
 *
 * Carries every optional column update + the apply-to-subalbums flag.
 * `pwgTokenRaw` is the *raw* incoming value so the handler can replicate
 * the historical "html descriptions only when token present" behavior.
 */
final readonly class SetInfoParams implements WsParams
{
    public function __construct(
        public int $categoryId,
        public ?string $pwgToken,
        public ?string $status,
        public ?string $visibleRaw,
        public ?string $commentableRaw,
        public ?string $name,
        public ?string $comment,
        public bool $applyCommentableToSubalbums,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $pwgTokenIn   = $raw['pwg_token'] ?? null;
        $pwgToken     = is_string($pwgTokenIn) ? $pwgTokenIn : null;
        $statusIn     = $raw['status'] ?? null;
        $status       = is_string($statusIn) && $statusIn !== '' ? $statusIn : null;
        $visibleIn    = $raw['visible'] ?? null;
        $visibleRaw   = is_scalar($visibleIn) ? (string) $visibleIn : null;
        $commentIn    = $raw['commentable'] ?? null;
        $commentable  = is_scalar($commentIn) ? (string) $commentIn : null;
        $nameIn       = $raw['name'] ?? null;
        $name         = is_scalar($nameIn) ? (string) $nameIn : null;
        $commentTxtIn = $raw['comment'] ?? null;
        $comment      = is_scalar($commentTxtIn) ? (string) $commentTxtIn : null;
        $applyToSub   = (bool) ($raw['apply_commentable_to_subalbums'] ?? false);
        return new self(
            categoryId:                  is_numeric($raw['category_id'] ?? null) ? (int) $raw['category_id'] : 0,
            pwgToken:                    $pwgToken,
            status:                      $status,
            visibleRaw:                  $visibleRaw,
            commentableRaw:              $commentable,
            name:                        $name,
            comment:                     $comment,
            applyCommentableToSubalbums: $applyToSub,
        );
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Comments;

use Piwigo\Ws\WsParams;

/** `pwg.userComments.getList` input DTO — admin moderation filter form. */
final readonly class GetListParams implements WsParams
{
    public function __construct(
        public string $status,
        public int $perPage,
        public int $page,
        public ?int $authorId,
        public ?int $imageId,
        public ?string $fMinDate,
        public ?string $fMaxDate,
        public ?string $search,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $statusIn  = $raw['status']   ?? null;
        $minDateIn = $raw['f_min_date'] ?? null;
        $maxDateIn = $raw['f_max_date'] ?? null;
        $searchIn  = $raw['search']   ?? null;
        return new self(
            status:   is_string($statusIn) ? $statusIn : '',
            perPage:  is_numeric($raw['per_page'] ?? null) ? (int) $raw['per_page'] : 0,
            page:     is_numeric($raw['page']     ?? null) ? (int) $raw['page'] : 0,
            authorId: is_numeric($raw['author_id'] ?? null) ? (int) $raw['author_id'] : null,
            imageId:  is_numeric($raw['image_id']  ?? null) ? (int) $raw['image_id'] : null,
            fMinDate: is_string($minDateIn) && $minDateIn !== '' ? $minDateIn : null,
            fMaxDate: is_string($maxDateIn) && $maxDateIn !== '' ? $maxDateIn : null,
            search:   is_string($searchIn) && $searchIn !== '' ? $searchIn : null,
        );
    }
}

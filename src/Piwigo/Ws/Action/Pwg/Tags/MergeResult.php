<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Ws\WsResult;

/** `pwg.tags.merge` output DTO. */
final readonly class MergeResult implements WsResult
{
    /**
     * @param list<int> $deletedTagIds       all the merge-input tag ids (incl. destination_tag_id; the admin UI uses this to remove them from the tag list)
     * @param list<int> $imagesInMergedTag   resulting image set of the destination tag after the merge
     */
    public function __construct(
        public int $destinationTag,
        public array $deletedTagIds,
        public array $imagesInMergedTag,
    ) {
    }

    /** @return array{destination_tag: int, deleted_tag: list<int>, images_in_merged_tag: list<int>} */
    #[\Override]
    public function toArray(): array
    {
        return [
            'destination_tag'      => $this->destinationTag,
            'deleted_tag'          => $this->deletedTagIds,
            'images_in_merged_tag' => $this->imagesInMergedTag,
        ];
    }
}

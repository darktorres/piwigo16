<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg;

use Piwigo\Ws\WsResult;

/**
 * `pwg.getMissingDerivatives` output DTO.
 *
 * `urls` is the list of derivative URLs that don't have an on-disk
 * file yet. `nextPageCursor` is the last-seen image id from the
 * current page (used by the client to request the next page); null
 * means "no more pages".
 */
final readonly class GetMissingDerivativesResult implements WsResult
{
    /** @param list<string> $urls */
    public function __construct(
        public array $urls,
        public ?int $nextPageCursor,
    ) {
    }

    /** @return array{next_page?: int, urls: list<string>} */
    #[\Override]
    public function toArray(): array
    {
        $out = [];
        if ($this->nextPageCursor !== null) {
            $out['next_page'] = $this->nextPageCursor;
        }
        $out['urls'] = $this->urls;
        return $out;
    }
}

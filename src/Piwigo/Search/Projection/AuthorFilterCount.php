<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * One row of the search sidebar's author filter: an author name and how
 * many photos in the current filter scope carry it (P58-A).
 *
 * `$author` is a plain `string`. The producing query groups by
 * `i.author IS NOT NULL`, but the row set is read back out of the
 * persistent cache pool as mixed data, so a non-string is normalized to
 * `''` there -- which is what the template rendered for it anyway, since
 * `stripTags(null)` and `stripTags('')` are both the empty string.
 */
final readonly class AuthorFilterCount
{
    public function __construct(
        public string $author,
        public int $counter,
    ) {}
}

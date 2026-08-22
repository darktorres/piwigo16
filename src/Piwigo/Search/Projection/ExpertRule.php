<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * `$search['fields']['expert']` -- a raw quicksearch-syntax escape
 * hatch, deliberately undocumented (see {@see \Piwigo\Controller\Api\
 * Images\ImageFilteredSearchCreateController}'s own docblock, which
 * drops it entirely from the REST surface). Only
 * {@see \Piwigo\Search\SearchService::getRegularSearchResults()} reads
 * it; neither real producer ({@see \Piwigo\Controller\SearchController},
 * {@see \Piwigo\Controller\Api\Images\
 * ImageFilteredSearchCreateController}) ever builds one -- it can only
 * exist on a search saved by some other, unmodeled path.
 */
final class ExpertRule
{
    public function __construct(
        public string $string = '',
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $string = $row['string'] ?? null;

        return new self(
            string: is_string($string) ? $string : '',
        );
    }

    /**
     * @return array{string: string}
     */
    public function toArray(): array
    {
        return [
            'string' => $this->string,
        ];
    }
}

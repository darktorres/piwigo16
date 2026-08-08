<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Search\SearchFilterRenderer::renderDateFilter()} -- a
 * shared helper called twice, once per `date_posted`/`date_created`
 * filter, each with its own pair of template variable names. Those 2
 * real call sites are the only 2 literal combinations that exist, so
 * `$listKey`/`$counterKey` are modeled as literal-union fields rather
 * than a genuinely dynamic key.
 */
final readonly class SearchDateFilterPageContext implements TemplatePageContext
{
    /**
     * @param 'LIST_DATE_POSTED'|'LIST_DATE_CREATED' $listKey
     * @param 'DATE_POSTED'|'DATE_CREATED' $counterKey
     * @param array<array-key, mixed> $listOfDates
     * @param array<string, array{label: string, counter: mixed}> $counters
     */
    public function __construct(
        public string $listKey,
        public string $counterKey,
        public array $listOfDates,
        public array $counters,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            $this->listKey => $this->listOfDates,
            $this->counterKey => $this->counters,
        ];
    }
}

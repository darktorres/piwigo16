<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Piwigo\Db\SqlFragment;
use Piwigo\Search\Rules\SearchRules;
use Piwigo\Template\Template;

final class FilterRenderContext
{
    public SearchQuery $mySearch;
    /** @var array{list: string, bounds: array<string, mixed>, selected: array<string, mixed>}|null */
    public ?array $filesize = null;
    /** @var array{list: string, bounds: array<string, mixed>, selected: array<string, mixed>}|null */
    public ?array $height = null;
    /** @var array{list: string, bounds: array<string, mixed>, selected: array<string, mixed>}|null */
    public ?array $width = null;
    /** @var array<string, string>|null */
    public ?array $fullnameOf = null;

    /** @param array<string, array{access: bool, default: bool}> $displayFilters */
    public function __construct(
        SearchQuery $mySearch,
        public readonly array $displayFilters,
        public readonly string $userId,
        public readonly string $userCacheTime,
        public readonly SqlFragment $forbidden,
        public readonly SearchRules $rules,
        public readonly Template $template,
        public readonly ?string $searchRaw,
    ) {
        $this->mySearch = $mySearch;
    }
}

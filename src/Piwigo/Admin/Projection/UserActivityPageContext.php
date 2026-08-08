<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\UserActivityPageRenderer::render()}.
 */
final readonly class UserActivityPageContext implements TemplatePageContext
{
    /**
     * @param array<array-key, string> $cacheKeys
     * @param list<array{id: int, username: string, nb_lines: int}> $ulist
     * @param array{min: string, max: string} $activityDates
     * @param list<array{object: string, action: string, counter: int, value: string}> $actions
     */
    public function __construct(
        public string $adminPageTitle,
        public string $pwgToken,
        public bool $inherit,
        public array $cacheKeys,
        public array $ulist,
        public int $nbUsers,
        public array $activityDates,
        public string|false $additionalFiltType,
        public ?string $additionalFiltName,
        public ?string $additionalFiltValue,
        public array $actions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'PWG_TOKEN' => $this->pwgToken,
            'INHERIT' => $this->inherit,
            'CACHE_KEYS' => $this->cacheKeys,
            'ulist' => $this->ulist,
            'nb_users' => $this->nbUsers,
            'ACTIVITY_DATES' => $this->activityDates,
            'ADDITIONAL_FILT' => [
                'type' => $this->additionalFiltType,
                'name' => $this->additionalFiltName,
                'value' => $this->additionalFiltValue,
            ],
            'ACTIONS' => $this->actions,
        ];
    }
}

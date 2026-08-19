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
     * @param list<UserActivityUserRow> $ulist
     * @param list<UserActivityActionRow> $actions
     */
    public function __construct(
        public string $adminPageTitle,
        public string $pwgToken,
        public bool $inherit,
        public array $cacheKeys,
        public array $ulist,
        public int $nbUsers,
        public ActivityDateRange $activityDates,
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
            'CSRF_TOKEN' => $this->pwgToken,
            'INHERIT' => $this->inherit,
            'CACHE_KEYS' => $this->cacheKeys,
            'ulist' => array_map(static fn (UserActivityUserRow $row): array => $row->toArray(), $this->ulist),
            'nb_users' => $this->nbUsers,
            'ACTIVITY_DATES' => $this->activityDates->toArray(),
            'ADDITIONAL_FILT' => [
                'type' => $this->additionalFiltType,
                'name' => $this->additionalFiltName,
                'value' => $this->additionalFiltValue,
            ],
            'ACTIONS' => array_map(static fn (UserActivityActionRow $row): array => $row->toArray(), $this->actions),
        ];
    }
}

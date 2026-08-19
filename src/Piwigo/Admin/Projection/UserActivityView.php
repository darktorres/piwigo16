<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `user_activity.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\UserActivityPageRenderer::render()}. No `$inherit` or
 * `$csrfToken` field -- the template's own body (and its own
 * `user_activity.js`) never reference either.
 */
#[Template('user_activity.latte')]
final readonly class UserActivityView implements View
{
    /**
     * @param array<array-key, string> $cacheKeys
     * @param list<array{id: int, username: string, nb_lines: int}> $ulist
     * @param array{min: string, max: string} $activityDates
     * @param list<array{object: string, action: string, counter: int, value: string}> $actions
     */
    public function __construct(
        public array $cacheKeys,
        public array $ulist,
        public int $nbUsers,
        public array $activityDates,
        public string|false $additionalFiltType,
        public ?string $additionalFiltName,
        public ?string $additionalFiltValue,
        public array $actions,
    ) {}
}

<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `group_list.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\GroupListPageRenderer::render()}. `$groups` is always
 * included (even empty) since `group_list.latte` reads it with
 * `{if !empty($groups)}`, not `isset()`. No `$addAction` field, and
 * each `$groups` row carries only `id`/`name`/`members`/`isDefault`
 * -- confirmed dead in `group_list.latte`'s own real body:
 * `F_ADD_ACTION` and the per-row `L_MEMBERS`/`NB_MEMBERS`/`U_DELETE`/
 * `U_PERM`/`U_USERS`/`U_ISDEFAULT` keys have zero real references
 * (the group manager UI is entirely client-side, driven by
 * `group_list.js` against the exposed `cache_key_users`/`root_url`/
 * `csrf_token` data instead).
 */
#[Template('group_list.latte')]
final readonly class GroupListView implements View
{
    /**
     * @param array<array-key, string> $cacheKeys
     * @param list<array{id: int, name: string, members: string, isDefault: bool}> $groups
     */
    public function __construct(
        public string $pwgToken,
        public array $cacheKeys,
        public array $groups,
    ) {}
}

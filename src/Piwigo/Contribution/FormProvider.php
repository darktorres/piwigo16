<?php

declare(strict_types=1);

namespace Piwigo\Contribution;

/**
 * A titled group of fields a plugin contributes to the profile-edit
 * form as its own labeled section -- the typed replacement for the
 * dead `$PLUGINS_PROFILE`/`$plugin_block` mechanism removed from
 * `profile_content.latte`/`standard_pages/profile.latte` (see
 * `ProfileField`'s own commit): that mechanism let a plugin supply an
 * arbitrary `{include $plugin_block['template']}` path, exactly the
 * escape hatch P43 exists to close. A `FormProvider` instead reuses
 * `ProfileField`'s own typed, no-raw-HTML field shape, just grouped
 * under one heading -- no new rendering primitive, no raw markup.
 */
final readonly class FormProvider
{
    /**
     * @param list<ProfileField> $fields
     */
    public function __construct(
        public string $title,
        public array $fields,
        public int $order = 50,
    ) {}
}

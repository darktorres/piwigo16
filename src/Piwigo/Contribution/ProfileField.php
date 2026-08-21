<?php

declare(strict_types=1);

namespace Piwigo\Contribution;

/**
 * A single labeled `<input>` a plugin contributes to the register or
 * profile-edit form -- the typed replacement for a hand-written
 * `set_prefilter('register', ...)`/`set_prefilter('profile_content', ...)`
 * patch, which every real plugin doing this today (`AddInfousers`,
 * `CustomUsersFields`) hand-writes against the raw HTML, inserting
 * before the form's own `<p class="bottomButtons">`.
 *
 * One field per contribution (matching `PictureInfoRow`'s
 * one-row-per-contribution shape), not a whole arbitrary fieldset --
 * a plugin contributing several fields registers several
 * `ProfileField`s. `$value` is the field's initial/submitted value,
 * always escaped; there is no raw-HTML variant (see `PictureInfoRow`'s
 * own docblock for why).
 */
final readonly class ProfileField
{
    public function __construct(
        public string $label,
        public string $name,
        public FieldType $type = FieldType::Text,
        public string $value = '',
        public int $order = 50,
    ) {}
}

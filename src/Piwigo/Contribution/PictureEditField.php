<?php

declare(strict_types=1);

namespace Piwigo\Contribution;

/**
 * A single labeled `<input>` a plugin contributes to the admin picture-edit
 * form ("properties" tab, `PictureModifyView`/`picture_modify.latte`) --
 * the typed replacement for a hand-written `set_prefilter('picture_modify',
 * ...)` patch, which `edit_filename_12.a` (the one real plugin doing this)
 * hand-writes against the raw HTML, inserting right before the form's own
 * `<p><strong>{'Title'|@translate}</strong>...</p>` block.
 *
 * Same one-field-per-contribution shape as `ProfileField` (a plugin
 * contributing several fields registers several `PictureEditField`s),
 * reusing its `FieldType` for the same reason: no new rendering primitive
 * for a second form that needs the identical text/checkbox distinction.
 * `$value` is always escaped, same as `ProfileField` -- no raw-HTML
 * variant.
 *
 * Deliberately doesn't cover `edit_filename`'s own submit-side rename
 * logic -- that's a real, already-existing, currently-unused typed event
 * (`Piwigo\Admin\Event\PictureModifyBeforeUpdate`, the direct replacement
 * for legacy's `picture_modify_before_update` filter), not something this
 * class needs to do anything about.
 */
final readonly class PictureEditField
{
    public function __construct(
        public string $label,
        public string $name,
        public FieldType $type = FieldType::Text,
        public string $value = '',
        public int $order = 50,
    ) {}
}

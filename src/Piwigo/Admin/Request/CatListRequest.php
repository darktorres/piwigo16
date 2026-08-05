<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for CatListPageRenderer::render()
 * (page slug "cat_list") -- P26/SEC-40 Request DTO. `parent_id`'s own
 * `InputValidator::validate()` call runs unconditionally, matching the
 * original exactly. `photoDeletionMode` defaults to `'no_delete'`,
 * overridden only when `photo_deletion_mode` is present and a string --
 * same normalization the original applied inline.
 */
final readonly class CatListRequest
{
    private function __construct(
        public bool $isCsrfCheckRequired,
        public ?int $parentId,
        public ?int $deleteId,
        public string $photoDeletionMode,
        public bool $isSubmitAdd,
        public string $virtualName,
    ) {}

    public static function fromGlobals(InputValidator $inputValidator): self
    {
        return self::fromArrays($_GET, $_POST, $inputValidator);
    }

    /**
     * @param array<int|string, mixed> $get
     * @param array<int|string, mixed> $post
     */
    public static function fromArrays(array $get, array $post, InputValidator $inputValidator): self
    {
        $inputValidator
            ->validate('parent_id', $get, false, ValidationPattern::ID);

        $parent_id = null;
        if (isset($get['parent_id']) and is_numeric($get['parent_id'])) {
            $parent_id = (int) $get['parent_id'];
        }

        $delete_id = null;
        if (isset($get['delete']) and is_numeric($get['delete'])) {
            $delete_id = (int) $get['delete'];
        }

        $photo_deletion_mode = 'no_delete';
        if (isset($get['photo_deletion_mode']) and is_string($get['photo_deletion_mode'])) {
            $photo_deletion_mode = $get['photo_deletion_mode'];
        }

        $virtual_name = $post['virtual_name'] ?? null;
        $virtual_name = is_string($virtual_name) ? $virtual_name : '';

        return new self(
            $post !== [] || isset($get['delete']),
            $parent_id,
            $delete_id,
            $photo_deletion_mode,
            isset($post['submitAdd']),
            $virtual_name,
        );
    }
}

<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for BatchManagerGlobalPageRenderer::render()
 * (the "global" mode tab of the "batch_manager" page slug) -- P26/SEC-40
 * Request DTO.
 *
 * `post` retains the raw `$_POST` array -- same "expose the raw bag"
 * shape as the sibling `BatchManagerUnitRequest::$post`: each action
 * branch (`add_tags`/`del_tags`/`associate`/`move`/`dissociate`/`author`/
 * `title`/`date_creation`/`level`/`confirm_deletion`/`del_derivatives_type`/
 * `regenerateSuccess`/`regenerateError`) reads its own differently-shaped
 * `$_POST` field only when `selectAction` selects that branch, with no
 * fixed property set that would cover all of them. The "current
 * selection" ingredients (`nbPhotosDeletedPresent`/`nbPhotosDeleted`/
 * `isSetSelected`/`wholeSet`/`selectionPresent`/`selection`) are split out
 * as their own fields, matching `BatchManagerUnitRequest` exactly -- the
 * actual collection-building loop (including `whole_set`'s own per-element
 * `preg_match()` hard-reject via `HtmlRenderingInterface::fatalError()`)
 * stays at the call site, same split as that sibling DTO.
 *
 * `render()` builds a local working copy of `post` and mutates that
 * instead of the superglobal, so that when the `remove_author`/
 * `remove_title` companion checkbox is set, every later read of
 * `author`/`title` in the same `render()` call (the per-image datas
 * loop) sees the override.
 */
final readonly class BatchManagerGlobalRequest
{
    /**
     * @param array<int|string, mixed> $selection
     * @param array<int|string, mixed> $post
     */
    private function __construct(
        public bool $isSubmitted,
        public string $selectAction,
        public bool $nbPhotosDeletedPresent,
        public int $nbPhotosDeleted,
        public bool $isSetSelected,
        public string $wholeSet,
        public bool $selectionPresent,
        public array $selection,
        public array $post,
        public string $page,
        public bool $displayRequested,
        public ?string $displayRaw,
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
            ->validate('del_tags', $post, true, ValidationPattern::ID);
        $inputValidator
            ->validate('associate', $post, true, ValidationPattern::ID);
        $inputValidator
            ->validate('move', $post, false, ValidationPattern::ID);
        $inputValidator
            ->validate('dissociate', $post, false, ValidationPattern::ID);

        $nb_photos_deleted_present = isset($post['nb_photos_deleted']);
        $nb_photos_deleted = 0;
        if ($nb_photos_deleted_present) {
            $inputValidator
                ->validate('nb_photos_deleted', $post, false, '/^\d+$/');
            $nb_photos_deleted = is_numeric($post['nb_photos_deleted']) ? (int) $post['nb_photos_deleted'] : 0;
        }

        $whole_set_raw = $post['whole_set'] ?? null;
        $whole_set = isset($post['whole_set']) && is_string($whole_set_raw) ? $whole_set_raw : '';

        $selection_raw = $post['selection'] ?? null;
        $selection_present = isset($post['selection']) && is_array($selection_raw);
        $selection = $selection_present ? $selection_raw : [];

        $select_action_raw = $post['selectAction'] ?? null;
        $select_action = is_string($select_action_raw) ? $select_action_raw : '';

        $page_raw = $get['page'] ?? null;
        $page = is_string($page_raw) ? $page_raw : '';

        $display_raw = $get['display'] ?? null;
        $display_requested = isset($get['display']) && $display_raw !== '' && $display_raw !== '0';
        $display = ($display_requested && is_string($display_raw)) ? $display_raw : null;

        return new self(
            isset($post['submit']),
            $select_action,
            $nb_photos_deleted_present,
            $nb_photos_deleted,
            isset($post['setSelected']),
            $whole_set,
            $selection_present,
            $selection,
            $post,
            $page,
            $display_requested,
            $display,
        );
    }
}

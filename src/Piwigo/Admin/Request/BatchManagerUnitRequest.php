<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for BatchManagerUnitPageRenderer::render()
 * (the "unit" mode tab of the "batch_manager" page slug).
 *
 * `post` retains the raw `$_POST` array -- the per-image form fields
 * (`name-{id}`, `author-{id}`, `level-{id}`, `description-{id}`,
 * `date_creation-{id}`, `tags-{id}`) are dynamically keyed by each row's
 * own id, discovered only after a DB query the renderer runs itself, so
 * there is no fixed set of named properties this DTO could expose for
 * them -- same "expose the raw bag, let the caller do dynamic lookups"
 * shape as `Ws\Request\WsRawRequest::$params`. The original never
 * validated or type-narrowed these fields either (they're written
 * straight through to `BatchWriter::massUpdate()`), so `post` keeps that
 * exact behavior.
 *
 * `whole_set`'s own per-element `preg_match()` hard-reject
 * (`HtmlRenderingInterface::fatalError()`, a direct side effect) and
 * `selection`'s complete absence of validation both stay at the call
 * site, matching the original's own if/elseif branching exactly.
 */
final readonly class BatchManagerUnitRequest
{
    /**
     * @param array<int|string, mixed> $post
     * @param array<int|string, mixed> $selection
     */
    private function __construct(
        public bool $isSubmitted,
        public string $elementIds,
        public array $post,
        public bool $nbPhotosDeletedPresent,
        public int $nbPhotosDeleted,
        public bool $isSetSelected,
        public string $wholeSet,
        public bool $selectionPresent,
        public array $selection,
        public bool $displayRequested,
        public int $display,
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
        $is_submitted = isset($post['submit']);
        $element_ids = '';
        if ($is_submitted) {
            $inputValidator
                ->validate('element_ids', $post, false, '/^\d+(,\d+)*$/');
            $element_ids_raw = $post['element_ids'] ?? null;
            $element_ids = is_string($element_ids_raw) ? $element_ids_raw : '';
        }

        $nb_photos_deleted_present = isset($post['nb_photos_deleted']);
        $nb_photos_deleted = 0;
        if ($nb_photos_deleted_present) {
            $inputValidator
                ->validate('nb_photos_deleted', $post, false, '/^\d+$/');
            $nb_photos_deleted = is_numeric($post['nb_photos_deleted']) ? (int) $post['nb_photos_deleted'] : 0;
        }

        $whole_set_raw = $post['whole_set'] ?? null;
        $whole_set = is_string($whole_set_raw) ? $whole_set_raw : '';

        $selection_raw = $post['selection'] ?? null;
        $selection_present = isset($post['selection']) && is_array($selection_raw);
        $selection = $selection_present ? $selection_raw : [];

        $display_raw = $get['display'] ?? null;
        $display_requested = isset($get['display']) && $display_raw !== '' && $display_raw !== '0';
        $display = $display_requested && is_numeric($display_raw) ? intval($display_raw) : 0;

        return new self(
            $is_submitted,
            $element_ids,
            $post,
            $nb_photos_deleted_present,
            $nb_photos_deleted,
            isset($post['setSelected']),
            $whole_set,
            $selection_present,
            $selection,
            $display_requested,
            $display,
        );
    }
}
